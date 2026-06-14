<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BoardTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Freeze to a fixed weekday afternoon so "today + 2h" stays today.
        $this->travelTo(CarbonImmutable::parse('2026-06-15 12:00:00')); // 14:00 Europe/Berlin
    }

    private function todayReservation(int $locationId, int $tenantId, ReservationStatus $status = ReservationStatus::Confirmed): Reservation
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addHours(2);

        return Reservation::create([
            'tenant_id' => $tenantId,
            'location_id' => $locationId,
            'party_size' => 3,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addMinutes(120)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => $status,
            'source' => 'online',
            'guest_name_snapshot' => 'Board Gast',
        ]);
    }

    public function test_board_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/board')
            ->assertOk()
            ->assertSee('Live-Board')
            ->assertSee('Vollbild', false);
    }

    public function test_board_data_returns_today_reservation(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->todayReservation($setup['location']->id, $setup['tenant']->id);
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->getJson('/admin/board/data')
            ->assertOk()
            ->assertJsonStructure(['now', 'is_salon', 'kpis' => ['today', 'covers', 'open_requests'], 'new', 'timeline']);

        $names = collect($response->json('timeline'))->pluck('name');
        $this->assertContains('Board Gast', $names);
    }

    public function test_requested_booking_appears_in_new_and_needs_action(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->todayReservation($setup['location']->id, $setup['tenant']->id, ReservationStatus::Requested);
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->getJson('/admin/board/data')->assertOk();
        $new = collect($response->json('new'))->firstWhere('name', 'Board Gast');

        $this->assertNotNull($new);
        $this->assertTrue($new['needs_action']);
        $this->assertSame('confirmed', $new['actions'][0]['status']);
    }

    public function test_board_data_includes_floorplan_with_table_status(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        // Seat a guest at the first table → that table must read "occupied".
        $reservation = $this->todayReservation($setup['location']->id, $setup['tenant']->id, ReservationStatus::Seated);
        $start = CarbonImmutable::now('Europe/Berlin')->subHour();
        $reservation->update(['start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc()]);
        $reservation->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->getJson('/admin/board/data')->assertOk();

        $rooms = $response->json('floorplan');
        $this->assertIsArray($rooms);
        $this->assertNotEmpty($rooms);

        $tables = collect($rooms[0]['tables']);
        $this->assertArrayHasKey('plan_width', $rooms[0]);
        $occupied = $tables->firstWhere('id', $setup['tables'][0]->id);
        $this->assertSame('occupied', $occupied['status']);
        $this->assertSame('Board Gast', $occupied['guest']);

        // A table without a reservation stays free.
        $free = $tables->firstWhere('id', $setup['tables'][1]->id);
        $this->assertSame('free', $free['status']);
    }

    public function test_floorplan_table_carries_reservation_details_and_actions(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $reservation = $this->todayReservation($setup['location']->id, $setup['tenant']->id, ReservationStatus::Seated);
        $start = CarbonImmutable::now('Europe/Berlin')->subMinutes(30);
        $reservation->update([
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'seated_at' => $start->utc(),
            'guest_phone_snapshot' => '+49 30 999',
        ]);
        $reservation->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->getJson('/admin/board/data')->assertOk()
            ->assertJson(['can_walkin' => true]);

        $rooms = $response->json('floorplan');
        $table = collect($rooms[0]['tables'])->firstWhere('id', $setup['tables'][0]->id);

        $this->assertCount(1, $table['reservations']);
        $res = $table['reservations'][0];
        $this->assertSame('Board Gast', $res['name']);
        $this->assertSame('+49 30 999', $res['phone']);
        $this->assertTrue($res['is_current']);
        $this->assertNotNull($res['seated_since']);
        // Seated reservation offers an "Auschecken" (completed) action.
        $this->assertSame('completed', $res['actions'][0]['status']);
    }

    public function test_walkin_can_be_placed_from_board_as_json(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
            'name' => 'Spontan',
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertNotEmpty($response->json('code'));
        $this->assertDatabaseHas('reservations', [
            'location_id' => $setup['location']->id,
            'source' => 'walk_in',
            'guest_name_snapshot' => 'Spontan',
        ]);
    }

    public function test_salon_board_has_no_floorplan(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => 'salon']);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->getJson('/admin/board/data')
            ->assertOk()
            ->assertJson(['floorplan' => null]);
    }

    public function test_transition_via_json_seats_guest(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $reservation = $this->todayReservation($setup['location']->id, $setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson("/admin/reservations/{$reservation->id}/transition", [
            'status' => 'seated',
        ])->assertOk()->assertJson(['ok' => true, 'status' => 'seated']);

        $this->assertSame(ReservationStatus::Seated, $reservation->fresh()->status);
    }
}
