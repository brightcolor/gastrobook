<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Ist eine Saison gesetzt, beginnt der Kalender der Buchungsseite am ersten
 * Saisontag und endet am letzten buchbaren Tag im Buchungshorizont. Tage davor
 * lassen sich gar nicht erst auswählen. Gebucht werden kann trotzdem schon
 * heute – nur eben für Tage in der Saison.
 */
class SeasonCalendarTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function addMarket(array $setup): void
    {
        // Weihnachtsmarkt: 23. November bis 10. Januar, über den Jahreswechsel.
        $setup['location']->seasonPeriods()->create([
            'tenant_id' => $setup['tenant']->id,
            'start_month' => 11, 'start_day' => 23, 'end_month' => 1, 'end_day' => 10,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function freezeToOctober(array $setup): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-07 10:00', $setup['location']->timezone));
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function bookingUrl(array $setup): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
    }

    public function test_the_booking_calendar_starts_on_the_first_season_day(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['max_advance_days' => 365]);
        $this->addMarket($setup);
        $this->clearTenantContext();
        $this->freezeToOctober($setup);

        $this->get($this->bookingUrl($setup))
            ->assertOk()
            ->assertSee('min="2026-11-23"', false)
            ->assertSee('max="2027-01-10"', false)
            ->assertSee('value="2026-11-23"', false);
    }

    public function test_without_a_season_the_calendar_is_unchanged(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['max_advance_days' => 90]);
        $this->clearTenantContext();
        $this->freezeToOctober($setup);

        $this->get($this->bookingUrl($setup))
            ->assertOk()
            ->assertSee('min="2026-10-07"', false)
            ->assertSee('max="2027-01-05"', false);
    }

    public function test_the_reschedule_calendar_is_trimmed_too(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['max_advance_days' => 365]);
        $this->addMarket($setup);

        $start = CarbonImmutable::parse('2026-12-15 19:00', $setup['location']->timezone);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();
        $this->freezeToOctober($setup);

        $this->get(route('booking.reschedule', ['code' => $reservation->code, 'token' => $reservation->manage_token]))
            ->assertOk()
            ->assertSee('min="2026-11-23"', false)
            ->assertSee('max="2027-01-10"', false);
    }
}
