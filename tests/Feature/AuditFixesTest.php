<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditFixesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_table_with_upcoming_reservation_cannot_be_deleted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];

        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Gast',
        ]);
        $reservation->tables()->attach($table->id);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->delete("/admin/settings/tables/{$table->id}")
            ->assertSessionHasErrors('table');

        $this->assertNotNull($table->fresh());
    }

    public function test_table_without_upcoming_reservation_can_be_deleted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->delete("/admin/settings/tables/{$table->id}")
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue($table->fresh()->trashed());
    }

    public function test_table_with_only_past_reservation_can_be_deleted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];

        $start = CarbonImmutable::now($setup['location']->timezone)->subDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Completed, 'source' => 'online',
            'guest_name_snapshot' => 'Gast',
        ]);
        $reservation->tables()->attach($table->id);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->delete("/admin/settings/tables/{$table->id}")
            ->assertSessionDoesntHaveErrors();

        $this->assertTrue($table->fresh()->trashed());
    }

    public function test_admin_cannot_view_guest_from_another_tenant(): void
    {
        $ownTenant = $this->createTenantSetup();
        $admin = $this->createMember($ownTenant['tenant'], 'tenant_owner');

        $otherTenant = Tenant::factory()->create(['plan_id' => Plan::factory()]);
        $otherGuest = Guest::create([
            'tenant_id' => $otherTenant->id,
            'first_name' => 'Fremd', 'last_name' => 'Gast',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->get("/admin/guests/{$otherGuest->id}")
            ->assertNotFound();
    }
}
