<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReservationBookRangeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function makeOn(array $setup, CarbonImmutable $day, string $name): void
    {
        $start = $day->setTime(19, 0);
        Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => $name,
        ]);
    }

    public function test_presets_and_custom_range_filter_the_book(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;
        $now = CarbonImmutable::now($tz);

        $this->makeOn($setup, $now, 'GastHeute');
        $this->makeOn($setup, $now->subDays(3), 'GastVor3Tagen');
        $this->makeOn($setup, $now->subDays(40), 'GastVor40Tagen');
        $this->clearTenantContext();

        // today
        $this->actingAs($admin)->get('/admin/reservations?range=today')
            ->assertOk()->assertSee('GastHeute')->assertDontSee('GastVor3Tagen')->assertDontSee('GastVor40Tagen');

        // last 7 days → today + 3 days ago, not 40
        $this->actingAs($admin)->get('/admin/reservations?range=last_7_days')
            ->assertOk()->assertSee('GastHeute')->assertSee('GastVor3Tagen')->assertDontSee('GastVor40Tagen');

        // all → everything
        $this->actingAs($admin)->get('/admin/reservations?range=all')
            ->assertOk()->assertSee('GastHeute')->assertSee('GastVor3Tagen')->assertSee('GastVor40Tagen');

        // custom range covering only the 40-days-ago entry
        $d = $now->subDays(40);
        $this->actingAs($admin)->get('/admin/reservations?range=custom&from='.$d->subDay()->toDateString().'&to='.$d->addDay()->toDateString())
            ->assertOk()->assertSee('GastVor40Tagen')->assertDontSee('GastHeute');
    }

    public function test_default_view_shows_today_only(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $now = CarbonImmutable::now($setup['location']->timezone);
        $this->makeOn($setup, $now, 'GastHeute');
        $this->makeOn($setup, $now->subDays(5), 'GastVor5Tagen');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/reservations')
            ->assertOk()->assertSee('GastHeute')->assertDontSee('GastVor5Tagen');
    }
}
