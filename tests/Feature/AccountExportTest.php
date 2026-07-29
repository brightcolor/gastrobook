<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\IntegrationConnection;
use App\Models\Location;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\AccountExportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AccountExportTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function seedData(array $setup): Reservation
    {
        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Maria', 'last_name' => 'Schmidt', 'email' => 'maria@example.test',
        ]);

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'guest_id' => $guest->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Maria Schmidt', 'code' => 'R-EXPORT',
            'manage_token' => 'GEHEIMES-TOKEN-NICHT-EXPORTIEREN',
        ]);
        $reservation->tables()->attach($setup['tables'][0]->id);

        return $reservation;
    }

    public function test_export_contains_the_business_data(): void
    {
        $setup = $this->createTenantSetup();
        $this->seedData($setup);

        $data = app(AccountExportService::class)->export($setup['tenant']);

        $this->assertSame('swayy-account-export', $data['format']);
        $this->assertNotEmpty($data['locations']);
        $this->assertNotEmpty($data['tables']);
        $this->assertNotEmpty($data['opening_hours']);
        $this->assertCount(1, $data['guests']);
        $this->assertCount(1, $data['reservations']);
        // table assignment survives the move via names
        $this->assertSame([$setup['tables'][0]->name], $data['reservations'][0]['table_names']);
        $this->assertSame('Maria Schmidt', $data['reservations'][0]['guest_name_snapshot']);
    }

    public function test_export_never_contains_secrets(): void
    {
        $setup = $this->createTenantSetup();
        $this->seedData($setup);

        IntegrationConnection::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'provider' => 'stripe',
            'status' => 'active',
            'credentials_encrypted' => 'sk_live_SUPERGEHEIM',
        ]);

        $json = json_encode(app(AccountExportService::class)->export($setup['tenant']));

        $this->assertStringNotContainsString('GEHEIMES-TOKEN-NICHT-EXPORTIEREN', $json);
        $this->assertStringNotContainsString('sk_live_SUPERGEHEIM', $json);
        $this->assertStringNotContainsString('manage_token', $json);
        $this->assertStringNotContainsString('credentials_encrypted', $json);
    }

    public function test_export_is_scoped_to_the_own_tenant(): void
    {
        $own = $this->createTenantSetup();
        $this->seedData($own);

        // A second business with its own guest must not leak into the export.
        $otherTenant = Tenant::factory()->create(['plan_id' => Plan::factory()]);
        Location::factory()->create(['tenant_id' => $otherTenant->id, 'name' => 'Fremder Standort']);
        Guest::withoutGlobalScopes()->create([
            'tenant_id' => $otherTenant->id, 'first_name' => 'Fremd', 'last_name' => 'Gast',
            'email' => 'fremd@example.test',
        ]);

        $json = json_encode(app(AccountExportService::class)->export($own['tenant']));

        $this->assertStringNotContainsString('fremd@example.test', $json);
        $this->assertStringNotContainsString('Fremder Standort', $json);
        $this->assertStringContainsString('maria@example.test', $json);
    }

    public function test_only_the_owner_may_download(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $manager = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/account/export')->assertForbidden();

        $response = $this->actingAs($owner)->get('/admin/account/export');
        $response->assertOk();
        $response->assertHeader('content-type', 'application/json; charset=utf-8');
        $this->assertStringContainsString('.json', $response->headers->get('content-disposition'));
    }

    public function test_download_is_logged(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/account/export')->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $setup['tenant']->id,
            'action' => 'account.exported',
        ]);
    }
}
