<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\MarketingCampaign;
use App\Models\MarketingSend;
use App\Models\Reservation;
use App\Services\MarketingCampaignService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Geburtstagsgrüsse: einer pro Jahr, jedes Jahr.
 *
 * Beide Fehler dieser Reihe standen hier: erst der doppelte Gruss zum
 * Jahreswechsel, dann — beim Beheben — die Unterdrückung jedes zweiten Jahres,
 * weil der Vorfilter auch den Schlüssel des Vorjahres prüfte.
 */
class BirthdayCampaignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function campaign(array $setup, int $offsetDays = 0): MarketingCampaign
    {
        return MarketingCampaign::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Geburtstag',
            'type' => 'birthday',
            'offset_days' => $offsetDays,
            'min_visits' => 0,
            'subject' => 'Alles Gute',
            'body' => 'Hallo {{name}}',
            'is_active' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function guestBornOn(array $setup, string $geburtstag): Guest
    {
        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Klara', 'last_name' => 'Meier',
            'email' => 'klara@example.test',
            'marketing_consent' => true,
            'birthday' => $geburtstag,
            'visit_count' => 1,
            'last_visit_at' => now()->subMonths(2),
        ]);

        $besuch = CarbonImmutable::now($setup['location']->timezone)->subMonths(2)->setTime(19, 0);
        Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $guest->id,
            'party_size' => 2,
            'reservation_date' => $besuch->toDateString(),
            'start_at' => $besuch->utc(),
            'end_at' => $besuch->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Completed,
            'source' => 'online',
            'guest_name_snapshot' => 'Klara Meier',
        ]);

        return $guest;
    }

    /**
     * Der Kern: drei Jahre hintereinander, drei Grüsse. Der Vorfilter prüfte
     * auch den Schlüssel des Vorjahres und unterdrückte damit jedes zweite -
     * für JEDEN Gast, ganzjährig, nicht nur zum Jahreswechsel.
     */
    public function test_a_guest_gets_a_greeting_every_single_year(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $kampagne = $this->campaign($setup);
        $this->guestBornOn($setup, '1990-06-15');

        $dienst = app(MarketingCampaignService::class);
        $versandt = [];

        foreach ([2026, 2027, 2028] as $jahr) {
            $versandt[$jahr] = $dienst->run($kampagne, CarbonImmutable::create($jahr, 6, 15, 0, 0, 0, $setup['location']->timezone));
        }

        $this->assertSame([2026 => 1, 2027 => 1, 2028 => 1], $versandt);
    }

    /**
     * Und trotzdem nur einer pro Jahr: Das Nachholfenster greift bis zu drei
     * Tage zurueck, ein zweiter Lauf am Folgetag darf nicht erneut schreiben.
     */
    public function test_a_second_run_the_next_day_does_not_write_again(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $kampagne = $this->campaign($setup);
        $this->guestBornOn($setup, '1990-06-15');

        $dienst = app(MarketingCampaignService::class);
        $tz = $setup['location']->timezone;

        $this->assertSame(1, $dienst->run($kampagne, CarbonImmutable::create(2026, 6, 15, 0, 0, 0, $tz)));
        $this->assertSame(0, $dienst->run($kampagne, CarbonImmutable::create(2026, 6, 16, 0, 0, 0, $tz)));
        $this->assertSame(1, MarketingSend::withoutGlobalScopes()->count());
    }

    /**
     * Der Fall, der das Nachholfenster ueberhaupt erst gebraucht hat: Ein
     * ausgefallener Lauf am 31.12. wird am 1.1. nachgeholt - genau einmal, mit
     * dem Schluessel des alten Jahres.
     */
    public function test_a_new_years_eve_birthday_is_caught_up_exactly_once(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $kampagne = $this->campaign($setup);
        $this->guestBornOn($setup, '1990-12-31');

        $dienst = app(MarketingCampaignService::class);
        $tz = $setup['location']->timezone;

        // Der Lauf am 31.12. ist ausgefallen; erst der 1.1. kommt durch.
        $this->assertSame(1, $dienst->run($kampagne, CarbonImmutable::create(2027, 1, 1, 0, 0, 0, $tz)));
        $this->assertSame(0, $dienst->run($kampagne, CarbonImmutable::create(2027, 1, 2, 0, 0, 0, $tz)));

        $eintrag = MarketingSend::withoutGlobalScopes()->sole();
        $this->assertSame('b-2026', $eintrag->reference, 'Der Gruss haengt am Anlass, nicht am Lauftag.');
    }

    /**
     * Und lief der 31.12. durch, darf der 1.1. nicht nachlegen.
     */
    public function test_a_new_years_eve_birthday_is_not_greeted_twice(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $kampagne = $this->campaign($setup);
        $this->guestBornOn($setup, '1990-12-31');

        $dienst = app(MarketingCampaignService::class);
        $tz = $setup['location']->timezone;

        $this->assertSame(1, $dienst->run($kampagne, CarbonImmutable::create(2026, 12, 31, 0, 0, 0, $tz)));
        $this->assertSame(0, $dienst->run($kampagne, CarbonImmutable::create(2027, 1, 1, 0, 0, 0, $tz)));
        $this->assertSame(1, MarketingSend::withoutGlobalScopes()->count());
    }

    /**
     * Ein Gast mit Geburtstag Anfang Januar gehoert ins laufende Jahr, nicht
     * ins vorige - sonst schoebe die Jahreswechsel-Regel ihn ein Jahr zurueck.
     */
    public function test_an_early_january_birthday_belongs_to_the_current_year(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $kampagne = $this->campaign($setup);
        $this->guestBornOn($setup, '1990-01-05');

        $dienst = app(MarketingCampaignService::class);
        $tz = $setup['location']->timezone;

        $this->assertSame(1, $dienst->run($kampagne, CarbonImmutable::create(2027, 1, 5, 0, 0, 0, $tz)));
        $this->assertSame('b-2027', MarketingSend::withoutGlobalScopes()->sole()->reference);

        // Und im Folgejahr wieder.
        $this->assertSame(1, $dienst->run($kampagne, CarbonImmutable::create(2028, 1, 5, 0, 0, 0, $tz)));
    }
}
