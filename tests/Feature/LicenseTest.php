<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\LicenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    // -------------------------------------------------------------------------
    // LicenseService unit tests (no HTTP)
    // -------------------------------------------------------------------------

    public function test_hosted_mode_always_valid(): void
    {
        config(['license.self_hosted' => false]);

        $status = app(LicenseService::class)->check();

        $this->assertTrue($status->valid);
        $this->assertFalse($status->selfHosted);
        $this->assertFalse($status->isHardLocked());
    }

    public function test_missing_license_file_returns_invalid(): void
    {
        config([
            'license.self_hosted' => true,
            'license.file' => '/nonexistent/license.json',
        ]);
        Cache::forget('swayy.license.status');

        $status = app(LicenseService::class)->check();

        $this->assertFalse($status->valid);
        $this->assertTrue($status->selfHosted);
        $this->assertStringContainsString('Keine Lizenzdatei', (string) $status->error);
    }

    public function test_valid_license_file_passes_in_non_production(): void
    {
        // In non-production the signature check is bypassed (dev mode).
        // We just test that a well-formed file is accepted.
        config([
            'license.self_hosted' => true,
            'license.file' => $this->writeTempLicense([
                'id' => 'lic_test',
                'licensee' => 'Test GmbH',
                'email' => 'test@example.de',
                'plan' => 'professional',
                'max_tenants' => 1,
                'max_locations' => 3,
                'max_tables' => 100,
                'max_users' => 10,
                'features' => ['reservations', 'waitlist', 'floor_plan'],
                'issued_at' => '2026-01-01',
                'expires_at' => now()->addYear()->toDateString(),
            ]),
        ]);
        Cache::forget('swayy.license.status');

        $status = app(LicenseService::class)->check();

        $this->assertTrue($status->valid);
        $this->assertSame('professional', $status->plan);
        $this->assertSame('Test GmbH', $status->licensee);
        $this->assertFalse($status->inGracePeriod);
        $this->assertFalse($status->isHardLocked());
    }

    public function test_expired_license_is_invalid_after_grace(): void
    {
        config([
            'license.self_hosted' => true,
            'license.grace_days' => 14,
            'license.file' => $this->writeTempLicense([
                'id' => 'lic_expired',
                'licensee' => 'Alt GmbH',
                'email' => 'alt@example.de',
                'plan' => 'starter',
                'max_tenants' => 1,
                'max_locations' => 1,
                'max_tables' => 20,
                'max_users' => 3,
                'features' => ['reservations'],
                'issued_at' => '2024-01-01',
                'expires_at' => now()->subDays(20)->toDateString(), // 20 days past
            ]),
        ]);
        Cache::forget('swayy.license.status');

        $status = app(LicenseService::class)->check();

        $this->assertFalse($status->valid);
        $this->assertFalse($status->inGracePeriod);
        $this->assertTrue($status->isHardLocked());
    }

    public function test_expired_license_within_grace_allows_access(): void
    {
        config([
            'license.self_hosted' => true,
            'license.grace_days' => 14,
            'license.file' => $this->writeTempLicense([
                'id' => 'lic_grace',
                'licensee' => 'Grace GmbH',
                'email' => 'grace@example.de',
                'plan' => 'starter',
                'max_tenants' => 1,
                'max_locations' => 1,
                'max_tables' => 20,
                'max_users' => 3,
                'features' => ['reservations'],
                'issued_at' => '2024-01-01',
                'expires_at' => now()->subDays(5)->toDateString(), // 5 days past, within 14-day grace
            ]),
        ]);
        Cache::forget('swayy.license.status');

        $status = app(LicenseService::class)->check();

        $this->assertFalse($status->valid);
        $this->assertTrue($status->inGracePeriod);
        $this->assertFalse($status->isHardLocked()); // grace = not hard-locked
    }

    // -------------------------------------------------------------------------
    // Middleware HTTP tests
    // -------------------------------------------------------------------------

    public function test_admin_accessible_without_license_check_in_hosted_mode(): void
    {
        config(['license.self_hosted' => false]);

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin')->assertOk(); // renders dashboard – not 402
    }

    public function test_admin_returns_402_when_hard_locked(): void
    {
        config([
            'license.self_hosted' => true,
            'license.grace_days' => 14,
            'license.file' => $this->writeTempLicense([
                'id' => 'lic_hardlock',
                'licensee' => 'X',
                'email' => 'x@x.de',
                'plan' => 'starter',
                'max_tenants' => 1,
                'max_locations' => 1,
                'max_tables' => 20,
                'max_users' => 3,
                'features' => [],
                'issued_at' => '2024-01-01',
                'expires_at' => now()->subDays(20)->toDateString(),
            ]),
        ]);
        Cache::forget('swayy.license.status');

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin')
            ->assertStatus(402)
            ->assertSee('Lizenz abgelaufen');
    }

    public function test_public_booking_page_still_works_when_hard_locked(): void
    {
        config([
            'license.self_hosted' => true,
            'license.file' => '/nonexistent/license.json',
        ]);
        Cache::forget('swayy.license.status');

        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        // The public booking page must remain accessible even if the license is invalid.
        $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /** Write a temp license file without a real signature (dev bypass). */
    private function writeTempLicense(array $data): string
    {
        $path = tempnam(sys_get_temp_dir(), 'swayy_lic_').'.json';
        file_put_contents($path, json_encode($data));

        return $path;
    }
}
