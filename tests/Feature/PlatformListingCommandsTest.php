<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PlatformListingCommandsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_plans_command_lists_active_plans(): void
    {
        Plan::factory()->create(['key' => 'pro', 'name' => 'Profi-Tarif', 'is_active' => true]);

        $this->artisan('swayy:plans')
            ->expectsOutputToContain('Profi-Tarif')
            ->assertSuccessful();
    }

    public function test_reservations_command_unknown_tenant_fails(): void
    {
        $this->artisan('swayy:reservations', ['--tenant' => 'gibt-es-nicht'])->assertFailed();
    }

    public function test_stats_command_runs(): void
    {
        $this->createTenantSetup();
        $this->clearTenantContext();

        $this->artisan('swayy:stats')
            ->expectsOutputToContain('Mandanten gesamt')
            ->assertSuccessful();
    }

    public function test_billing_requests_command_runs(): void
    {
        $this->artisan('swayy:billing-requests')->assertSuccessful();
    }
}
