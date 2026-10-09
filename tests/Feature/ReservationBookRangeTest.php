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

    /**
     * Ein unleserliches Datum in der Adresse (manipuliert oder Fuzzer) brach
     * die Liste vorher mit einem 500 ab, weil die Datenbank den Wert nicht als
     * Datum lesen konnte. Jetzt kommt eine Prüfmeldung.
     */
    public function test_a_malformed_date_filter_is_rejected_cleanly(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        foreach (["2026-10-07' OR '1'='1", 'gestern', '2026-13-99', '07.10.2026'] as $bad) {
            $this->actingAs($admin)->get('/admin/reservations?from='.urlencode($bad))
                ->assertStatus(302)
                ->assertSessionHasErrors('from');
        }

        // Gültiges Datum geht weiterhin durch.
        $this->actingAs($admin)->get('/admin/reservations?from=2026-10-01&to=2026-10-31')
            ->assertOk();
    }

    /**
     * Derselbe Riegel für den CSV-Export: kaputtes from/until -> Prüfmeldung
     * statt 500, gültige Grenzen liefern den Download.
     */
    public function test_the_export_validates_its_date_bounds(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/reservations/export?from=2026-10-07%27%20OR%20%271%27%3D%271&until=2026-10-07')
            ->assertStatus(302)
            ->assertSessionHasErrors('from');

        $this->actingAs($admin)->get('/admin/reservations/export?until=kaputt')
            ->assertStatus(302)
            ->assertSessionHasErrors('until');

        $this->actingAs($admin)->get('/admin/reservations/export?from=2026-10-01&until=2026-10-31')
            ->assertOk();
    }

    public function test_default_view_shows_all_bookings(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $now = CarbonImmutable::now($setup['location']->timezone);
        $this->makeOn($setup, $now, 'GastHeute');
        $this->makeOn($setup, $now->subDays(5), 'GastVor5Tagen');
        $this->makeOn($setup, $now->subDays(120), 'GastVor120Tagen');
        $this->makeOn($setup, $now->addDays(30), 'GastIn30Tagen');
        $this->clearTenantContext();

        // Default (no range) shows the whole book: past, today and future.
        $this->actingAs($admin)->get('/admin/reservations')
            ->assertOk()
            ->assertSee('GastHeute')
            ->assertSee('GastVor5Tagen')
            ->assertSee('GastVor120Tagen')
            ->assertSee('GastIn30Tagen');
    }
}
