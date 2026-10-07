<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Außerhalb der buchbaren Saison nimmt die Online-Buchung keine Reservierung an
 * – weder zeigt die Slot-Liste Termine, noch geht eine Buchung durch. Ohne
 * Saisonfenster bleibt alles wie bisher.
 */
class SeasonAvailabilityTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Beide Testtage sind Mittwoche – gleiche Öffnungszeiten, nur die Saison unterscheidet. */
    private const IN_SEASON = '2026-07-01';   // innerhalb 1. Apr – 31. Okt

    private const OFF_SEASON = '2026-12-02';   // außerhalb

    /**
     * @param  array<string, mixed>  $setup
     */
    private function setupWithSeason(array $setup): void
    {
        $setup['location']->settings()->update(['max_advance_days' => 365, 'min_lead_minutes' => 0]);
        $setup['location']->seasonPeriods()->create([
            'tenant_id' => $setup['tenant']->id,
            'start_month' => 4, 'start_day' => 1, 'end_month' => 10, 'end_day' => 31,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function slotsUrl(array $setup, string $date): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/slots?date='.$date.'&party_size=2';
    }

    private function freezeToSummer(string $tz): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00', $tz));
    }

    public function test_a_date_outside_the_season_has_no_slots(): void
    {
        $setup = $this->createTenantSetup();
        $this->setupWithSeason($setup);
        $this->clearTenantContext();
        $this->freezeToSummer($setup['location']->timezone);

        $this->assertNotEmpty($this->getJson($this->slotsUrl($setup, self::IN_SEASON))->assertOk()->json('slots'));
        $this->assertEmpty($this->getJson($this->slotsUrl($setup, self::OFF_SEASON))->assertOk()->json('slots'));
    }

    public function test_without_a_season_every_date_has_slots(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['max_advance_days' => 365, 'min_lead_minutes' => 0]);
        $this->clearTenantContext();
        $this->freezeToSummer($setup['location']->timezone);

        $this->assertNotEmpty($this->getJson($this->slotsUrl($setup, self::OFF_SEASON))->assertOk()->json('slots'));
    }

    public function test_a_booking_outside_the_season_is_refused(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->setupWithSeason($setup);
        $this->clearTenantContext();
        $this->freezeToSummer($setup['location']->timezone);

        $url = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
        $daten = [
            'time' => '19:00', 'party_size' => 2, 'name' => 'Frau Kessler',
            'email' => 'kessler@example.test', 'phone' => '+49 30 000000', 'privacy_accepted' => '1',
        ];

        $this->post($url, $daten + ['date' => self::OFF_SEASON])->assertSessionHasErrors();
        $this->assertDatabaseCount('reservations', 0);

        $this->post($url, $daten + ['date' => self::IN_SEASON])->assertRedirect();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }
}
