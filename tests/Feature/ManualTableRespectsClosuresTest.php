<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use App\Models\SpecialOpeningHour;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Regression: booking a MANUALLY chosen table must still respect blackouts,
 * closed special days and opening hours. Previously the manual-table branch in
 * create() skipped all of these, so a picked table could book a closed slot.
 */
class ManualTableRespectsClosuresTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function book(array $setup, CarbonImmutable $startLocal, int $tableId)
    {
        return app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $startLocal,
            'source' => 'manual',
            'guest_name' => 'Gast',
            'table_ids' => [$tableId],
        ]);
    }

    public function test_manual_table_blocked_by_location_blackout(): void
    {
        $setup = $this->createTenantSetup(); // opening hours 12:00–23:00 daily
        $tz = $setup['location']->timezone;
        $start = CarbonImmutable::now($tz)->addDay()->setTime(19, 0);

        BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'room_id' => null, 'reduce_covers_to' => null,
            'starts_at' => $start->copy()->subHour()->utc(),
            'ends_at' => $start->copy()->addHours(3)->utc(),
            'reason' => 'Betriebsfeier',
        ]);
        $this->clearTenantContext();

        $this->expectException(ValidationException::class);
        $this->book($setup, $start, $setup['tables'][0]->id);
    }

    public function test_manual_table_blocked_on_closed_special_day(): void
    {
        $setup = $this->createTenantSetup();
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDays(2);

        SpecialOpeningHour::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'date' => $day->toDateString(), 'closed' => true, 'label' => 'Feiertag',
        ]);
        $this->clearTenantContext();

        $this->expectException(ValidationException::class);
        $this->book($setup, $day->setTime(19, 0), $setup['tables'][0]->id);
    }

    public function test_manual_table_blocked_outside_opening_hours(): void
    {
        $setup = $this->createTenantSetup(); // open 12:00–23:00
        $tz = $setup['location']->timezone;

        $this->clearTenantContext();

        $this->expectException(ValidationException::class);
        // 03:00 is well outside the opening window
        $this->book($setup, CarbonImmutable::now($tz)->addDay()->setTime(3, 0), $setup['tables'][0]->id);
    }

    public function test_manual_table_succeeds_within_open_hours(): void
    {
        $setup = $this->createTenantSetup();
        $tz = $setup['location']->timezone;
        $this->clearTenantContext();

        $reservation = $this->book($setup, CarbonImmutable::now($tz)->addDay()->setTime(19, 0), $setup['tables'][0]->id);

        $this->assertNotNull($reservation->id);
        $this->assertTrue($reservation->tables->contains($setup['tables'][0]->id));
    }
}
