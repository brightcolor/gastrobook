<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class InternalFloorplanPickerTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_floorplan_availability_flags_occupied_and_unsuitable_tables(): void
    {
        $setup = $this->createTenantSetup(); // tables: 1–2, 2–4, 4–8
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        [$small, $medium, $large] = $setup['tables'];

        // Existing reservation occupies the medium table tomorrow 19:00–21:00.
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 3,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Belegt', 'code' => 'R-BUSY1', 'manage_token' => str_repeat('a', 48),
        ]);
        $r->tables()->attach($medium->id);
        $this->clearTenantContext();

        $resp = $this->actingAs($admin)->getJson(
            '/admin/reservations/floorplan-availability?date='.$start->toDateString().'&time=19:30&party_size=3'
        );
        $resp->assertOk();

        $tables = collect($resp->json('rooms.0.tables'))->keyBy('id');
        $this->assertSame('occupied', $tables[$medium->id]['status']);
        $this->assertSame('unsuitable', $tables[$small->id]['status'], '3 Personen passen nicht an den 1-2er');
        $this->assertSame('unsuitable', $tables[$large->id]['status'], '3 Personen unter min_capacity 4');
    }

    public function test_floorplan_availability_shows_free_tables(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $date = CarbonImmutable::now($setup['location']->timezone)->addDay()->toDateString();
        $resp = $this->actingAs($admin)->getJson(
            "/admin/reservations/floorplan-availability?date={$date}&time=19:00&party_size=2"
        );
        $resp->assertOk();

        $statuses = collect($resp->json('rooms.0.tables'))->pluck('status');
        $this->assertTrue($statuses->contains('available'));
        $this->assertFalse($statuses->contains('occupied'));
    }

    public function test_requires_reservations_create_permission(): void
    {
        $setup = $this->createTenantSetup();
        $readonly = $this->createMember($setup['tenant'], 'readonly');
        $this->clearTenantContext();

        $date = CarbonImmutable::now()->addDay()->toDateString();
        $this->actingAs($readonly)
            ->getJson("/admin/reservations/floorplan-availability?date={$date}&time=19:00&party_size=2")
            ->assertForbidden();
    }
}
