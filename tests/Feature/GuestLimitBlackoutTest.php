<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\BlackoutPeriod;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Eine Sperrzeit mit „Max. Gäste" (reduce_covers_to) begrenzt die Personenzahl
 * in ihrem Zeitraum – in jedem Kapazitätsmodus. Gezählt werden die Personen
 * aller aktiven, sich überschneidenden Reservierungen. Mit Raum zählen nur
 * Reservierungen an Tischen dieses Raums, und die Grenze betrifft nur Buchungen
 * in diesem Raum.
 *
 * Bisher wirkte die Grenze nur in den Modi „Plätze" und „gemischt" und nur ohne
 * Raum. Im Standardmodus „Tische" und bei jeder Raumgrenze blieb sie wirkungslos.
 */
class GuestLimitBlackoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function tomorrowAt(string $time, string $tz = 'Europe/Berlin'): CarbonImmutable
    {
        return CarbonImmutable::now($tz)->addDay()->setTimeFromTimeString($time);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function guestLimit(array $setup, CarbonImmutable $start, int $limit, ?int $roomId = null): void
    {
        BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'room_id' => $roomId,
            'reduce_covers_to' => $limit,
            'starts_at' => $start->subHour()->utc(),
            'ends_at' => $start->addHours(3)->utc(),
            'reason' => 'Kleine Besetzung',
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<int, int>  $tableIds
     */
    private function reserve(array $setup, CarbonImmutable $start, int $partySize, array $tableIds): Reservation
    {
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => $partySize,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'manual',
            'guest_name_snapshot' => 'Gast',
        ]);
        $reservation->tables()->attach($tableIds);

        return $reservation;
    }

    /**
     * Zwei Räume: A mit zwei 1–4er-Tischen, B mit einem 1–6er. Die kleinste
     * passende Wahl für 2 Personen liegt damit in Raum A.
     *
     * @return array<string, mixed>
     */
    private function twoRooms(): array
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4], ['min' => 1, 'max' => 4]]);
        $roomB = Room::factory()->create(['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id]);
        $tableB = RestaurantTable::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'room_id' => $roomB->id,
            'name' => 'B1',
            'min_capacity' => 1,
            'max_capacity' => 6,
        ]);

        return $setup + ['roomB' => $roomB, 'tableB' => $tableB];
    }

    // ── Grenze für den ganzen Betrieb ──────────────────────────────────────

    public function test_location_limit_applies_in_table_mode(): void
    {
        $setup = $this->createTenantSetup(); // Vorgabe: nach Tischen
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][1]->id]);
        $this->guestLimit($setup, $start, 4);

        $service = app(ReservationAvailabilityService::class);

        $voll = $service->checkExact($setup['location'], $start, 2);
        $this->assertFalse($voll['available'], 'Freie Tische gibt es, aber 3 + 2 Personen überschreiten die Grenze 4.');
        $this->assertSame('covers_full', $voll['reason']);

        $this->assertTrue($service->checkExact($setup['location'], $start, 1)['available'], '3 + 1 Person passt genau.');
    }

    public function test_location_limit_marks_slot_unavailable_in_table_mode(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][1]->id]);
        $this->guestLimit($setup, $start, 4);

        $slot = collect(app(ReservationAvailabilityService::class)->slotsFor($setup['location'], $start->startOfDay(), 2))
            ->firstWhere('time', '19:00');

        $this->assertFalse($slot['available']);
        $this->assertSame('covers_full', $slot['reason']);
    }

    public function test_location_limit_blocks_a_manually_chosen_table_in_table_mode(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][1]->id]);
        $this->guestLimit($setup, $start, 4);

        $grund = app(ReservationAvailabilityService::class)->bookingBlockReason(
            $setup['location'], $start, $start->utc(), $start->addHours(2)->utc(),
            [$setup['tables'][0]->id], ['party_size' => 2],
        );

        $this->assertSame('covers_full', $grund);
    }

    public function test_location_limit_still_works_in_person_mode(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['capacity_mode' => 'person', 'max_covers_per_slot' => null]);
        $setup['location']->refresh();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, []);
        $this->guestLimit($setup, $start, 5);

        $service = app(ReservationAvailabilityService::class);

        $this->assertSame('covers_full', $service->checkExact($setup['location'], $start, 3)['reason']);
        $this->assertTrue($service->checkExact($setup['location'], $start, 2)['available']);
    }

    public function test_reschedule_does_not_count_the_moved_reservation_against_the_limit(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $eigene = $this->reserve($setup, $start, 3, [$setup['tables'][1]->id]);
        $this->guestLimit($setup, $start, 4);

        $verschoben = app(ReservationLifecycleService::class)
            ->reschedule($eigene, $this->tomorrowAt('19:30'), null, null, 'staff');

        $this->assertTrue($verschoben->start_at->equalTo($this->tomorrowAt('19:30')->utc()));
    }

    // ── Grenze für einen Raum ──────────────────────────────────────────────

    public function test_room_limit_moves_the_party_to_another_room(): void
    {
        $setup = $this->twoRooms();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][0]->id]);
        $this->guestLimit($setup, $start, 4, $setup['room']->id);

        $check = app(ReservationAvailabilityService::class)->checkExact($setup['location'], $start, 2);

        $this->assertTrue($check['available']);
        $this->assertSame([$setup['tableB']->id], $check['table_ids'], 'Raum A ist mit 3 + 2 Personen über der Grenze 4.');
    }

    public function test_room_limit_leaves_nothing_free_when_the_other_room_is_full(): void
    {
        $setup = $this->twoRooms();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][0]->id]);
        $this->reserve($setup, $start, 2, [$setup['tableB']->id]);
        $this->guestLimit($setup, $start, 4, $setup['room']->id);

        $check = app(ReservationAvailabilityService::class)->checkExact($setup['location'], $start, 2);

        $this->assertFalse($check['available'], 'Tisch A2 ist frei, Raum A aber über der Grenze.');
        $this->assertSame('no_table', $check['reason']);
    }

    public function test_room_limit_applies_in_hybrid_mode(): void
    {
        $setup = $this->twoRooms();
        $setup['location']->settings->update(['capacity_mode' => 'hybrid', 'max_covers_per_slot' => null]);
        $setup['location']->refresh();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][0]->id]);
        $this->guestLimit($setup, $start, 4, $setup['room']->id);

        $check = app(ReservationAvailabilityService::class)->checkExact($setup['location'], $start, 2);

        $this->assertSame([$setup['tableB']->id], $check['table_ids']);
    }

    public function test_room_limit_blocks_a_manually_chosen_table_in_that_room(): void
    {
        $setup = $this->twoRooms();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][0]->id]);
        $this->guestLimit($setup, $start, 4, $setup['room']->id);

        $service = app(ReservationAvailabilityService::class);
        $ende = $start->addHours(2)->utc();

        $this->assertSame('covers_full', $service->bookingBlockReason(
            $setup['location'], $start, $start->utc(), $ende, [$setup['tables'][1]->id], ['party_size' => 2],
        ));
        $this->assertNull($service->bookingBlockReason(
            $setup['location'], $start, $start->utc(), $ende, [$setup['tableB']->id], ['party_size' => 2],
        ), 'Raum B hat keine Grenze.');
        $this->assertNull($service->bookingBlockReason(
            $setup['location'], $start, $start->utc(), $ende, [$setup['tables'][1]->id], ['party_size' => 1],
        ), '3 + 1 Person passt in Raum A.');
    }

    public function test_room_limit_counts_only_reservations_in_that_room(): void
    {
        $setup = $this->twoRooms();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 5, [$setup['tableB']->id]);
        $this->guestLimit($setup, $start, 4, $setup['room']->id);

        $grund = app(ReservationAvailabilityService::class)->bookingBlockReason(
            $setup['location'], $start, $start->utc(), $start->addHours(2)->utc(),
            [$setup['tables'][0]->id], ['party_size' => 4],
        );

        $this->assertNull($grund, 'Die 5 Personen in Raum B zählen für Raum A nicht.');
    }

    public function test_admin_floorplan_marks_tables_of_a_full_room_blocked(): void
    {
        $setup = $this->twoRooms();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][0]->id]);
        $this->guestLimit($setup, $start, 4, $setup['room']->id);
        $this->clearTenantContext();

        $tables = collect($this->actingAs($admin)
            ->getJson('/admin/reservations/floorplan-availability?date='.$start->toDateString().'&time=19:00&party_size=2')
            ->assertOk()
            ->json('rooms'))
            ->flatMap(fn ($r) => $r['tables'])
            ->keyBy('id');

        $this->assertSame('blocked', $tables[$setup['tables'][1]->id]['status']);
        $this->assertSame('available', $tables[$setup['tableB']->id]['status']);
    }

    public function test_public_floorplan_marks_tables_of_a_full_room_unavailable(): void
    {
        $setup = $this->twoRooms();
        $setup['location']->settings->update(['public_floorplan_enabled' => true]);
        $start = $this->tomorrowAt('19:00');
        $this->reserve($setup, $start, 3, [$setup['tables'][0]->id]);
        $this->guestLimit($setup, $start, 4, $setup['room']->id);
        $this->clearTenantContext();

        $tables = collect($this->getJson('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/floorplan?date='.$start->toDateString().'&time=19:00&party_size=2')
            ->assertOk()
            ->json('rooms'))
            ->flatMap(fn ($r) => $r['tables'])
            ->keyBy('id');

        $this->assertSame('unavailable', $tables[$setup['tables'][1]->id]['status']);
        $this->assertSame('available', $tables[$setup['tableB']->id]['status']);
    }
}
