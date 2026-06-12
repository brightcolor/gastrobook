<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\BlackoutPeriod;
use App\Models\TableCombination;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function tomorrowAt(string $time, string $tz = 'Europe/Berlin'): CarbonImmutable
    {
        return CarbonImmutable::now($tz)->addDay()->setTimeFromTimeString($time);
    }

    public function test_slot_inside_opening_hours_is_available(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $check = app(ReservationAvailabilityService::class)
            ->checkExact($setup['location'], $this->tomorrowAt('19:00'), 2);

        $this->assertTrue($check['available']);
        $this->assertNotEmpty($check['table_ids']);
    }

    public function test_slot_outside_opening_hours_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $check = app(ReservationAvailabilityService::class)
            ->checkExact($setup['location'], $this->tomorrowAt('09:00'), 2);

        $this->assertFalse($check['available']);
        $this->assertSame('outside_opening_hours', $check['reason']);
    }

    public function test_blackout_blocks_booking(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $start = $this->tomorrowAt('19:00');
        BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'starts_at' => $start->subHours(2)->utc(),
            'ends_at' => $start->addHours(4)->utc(),
            'reason' => 'Private Feier',
        ]);

        $check = app(ReservationAvailabilityService::class)
            ->checkExact($setup['location'], $start, 2);

        $this->assertFalse($check['available']);
        $this->assertSame('blackout', $check['reason']);
    }

    public function test_min_lead_time_is_enforced_for_online(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['min_lead_minutes' => 24 * 60]);
        $setup['location']->refresh();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $check = app(ReservationAvailabilityService::class)
            ->checkExact($setup['location'], $this->tomorrowAt('12:00'), 2, ['online' => true]);

        $this->assertFalse($check['available']);
    }

    public function test_double_booking_is_prevented(): void
    {
        // Single table for 2 guests
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $lifecycle = app(ReservationLifecycleService::class);

        $lifecycle->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $this->tomorrowAt('19:00'),
            'source' => 'manual',
            'guest_name' => 'Erster Gast',
        ]);

        $this->expectException(ValidationException::class);

        $lifecycle->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $this->tomorrowAt('19:30'), // overlaps 19:00-21:00
            'source' => 'manual',
            'guest_name' => 'Zweiter Gast',
        ]);
    }

    public function test_smallest_fitting_table_is_assigned(): void
    {
        $setup = $this->createTenantSetup([
            ['min' => 1, 'max' => 8],
            ['min' => 1, 'max' => 2],
            ['min' => 2, 'max' => 4],
        ]);
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $this->tomorrowAt('19:00'),
            'source' => 'manual',
            'guest_name' => 'Paar',
        ]);

        // Smallest fitting table is the 2-seater (T2)
        $this->assertSame(['T2'], $reservation->tables->pluck('name')->all());
    }

    public function test_table_combination_is_used_for_large_groups(): void
    {
        $setup = $this->createTenantSetup([
            ['min' => 1, 'max' => 4],
            ['min' => 1, 'max' => 4],
        ]);
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $combo = TableCombination::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'T1+T2',
            'min_capacity' => 5,
            'max_capacity' => 8,
        ]);
        $combo->tables()->sync([$setup['tables'][0]->id, $setup['tables'][1]->id]);

        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 6,
            'start_local' => $this->tomorrowAt('19:00'),
            'source' => 'manual',
            'guest_name' => 'Große Gruppe',
        ]);

        $this->assertCount(2, $reservation->tables);
    }

    public function test_alternatives_are_offered_when_fully_booked(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $this->actAsTenant($setup['tenant'], $setup['location']);

        app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $this->tomorrowAt('19:00'),
            'source' => 'manual',
            'guest_name' => 'Gast',
        ]);

        $alternatives = app(ReservationAvailabilityService::class)
            ->alternatives($setup['location'], $this->tomorrowAt('19:00'), 2);

        // 19:00-21:00 blocked → earlier/later slots offered
        $this->assertNotEmpty($alternatives['same_day']);
        $this->assertNotContains('19:00', $alternatives['same_day']);
    }

    public function test_person_based_capacity_mode_limits_covers(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['capacity_mode' => 'person', 'max_covers_per_slot' => 4]);
        $setup['location']->refresh();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $lifecycle = app(ReservationLifecycleService::class);

        $lifecycle->create($setup['location'], [
            'party_size' => 3,
            'start_local' => $this->tomorrowAt('19:00'),
            'source' => 'manual',
            'guest_name' => 'Gruppe A',
        ]);

        $check = app(ReservationAvailabilityService::class)
            ->checkExact($setup['location'], $this->tomorrowAt('19:00'), 2);

        $this->assertFalse($check['available']);
        $this->assertSame('covers_full', $check['reason']);
    }

    public function test_status_transition_rules_are_enforced(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $lifecycle = app(ReservationLifecycleService::class);

        $reservation = $lifecycle->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $this->tomorrowAt('19:00'),
            'source' => 'manual',
            'guest_name' => 'Gast',
        ]);

        $lifecycle->transition($reservation, ReservationStatus::Seated);
        $lifecycle->transition($reservation, ReservationStatus::Completed);

        $this->assertSame(ReservationStatus::Completed, $reservation->refresh()->status);
        $this->assertCount(3, $reservation->statusHistories); // created + 2 transitions

        $this->expectException(ValidationException::class);
        $lifecycle->transition($reservation, ReservationStatus::Seated); // completed is terminal
    }
}
