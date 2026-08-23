<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\OpeningHour;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Wartelisten-Angebote.
 *
 * Ein Angebot haelt den Platz nicht - es sagt ihn nur zu. Der Betrieb darf
 * denselben Abend also mehreren Wartenden anbieten, solange Platz da ist; und
 * "Gast jetzt platzieren" darf daran nie scheitern, denn dort steht der Gast
 * bereits am Tresen.
 */
class WaitlistOfferTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * "Sofort platzieren" prueft gegen die Uhrzeit des Laufs - der Gast steht
     * ja jetzt da. Mit den 12:00-23:00 des Standardaufbaus waren diese Faelle
     * dreizehn von vierundzwanzig Stunden rot, ohne dass sich am Code etwas
     * geaendert haette: lokal gruen, in der Nacht-CI rot.
     *
     * @param  array<string, mixed>  $setup
     */
    private function openAroundTheClock(array $setup): void
    {
        OpeningHour::withoutGlobalScopes()
            ->where('location_id', $setup['location']->id)
            ->update(['opens_at' => '00:00', 'closes_at' => '23:59']);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function entry(array $setup, string $name, int $party = 2): WaitlistEntry
    {
        return WaitlistEntry::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_name' => $name,
            'guest_email' => mb_strtolower($name).'@example.test',
            'party_size' => $party,
            'desired_date' => CarbonImmutable::now($setup['location']->timezone)->addDay()->toDateString(),
            'status' => 'waiting',
        ]);
    }

    /**
     * Vierzig Tische, zwei Wartende: Beide duerfen ein Angebot bekommen. Eine
     * Sperre auf "irgendein offenes Angebot im selben Zeitfenster" haette den
     * Betrieb auf ein einziges Angebot pro Abend eingedampft.
     */
    public function test_two_guests_can_hold_an_offer_for_the_same_evening(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([
            ['min' => 1, 'max' => 4], ['min' => 1, 'max' => 4], ['min' => 1, 'max' => 4],
        ]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        foreach ([$anna, $bert] as $eintrag) {
            $this->actingAs($admin)
                ->post('/admin/waitlist/'.$eintrag->id.'/offer', ['time' => '19:00'])
                ->assertRedirect()
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, WaitlistOffer::withoutGlobalScopes()->where('status', 'open')->count());
    }

    /**
     * Ist wirklich nichts mehr frei, wird das Angebot abgelehnt - eine Zusage
     * "Ein Tisch ist frei geworden" ohne Tisch waere schlimmer als keine.
     */
    public function test_an_offer_is_refused_when_nothing_is_left(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        // Der einzige Tisch ist damit zugesagt.
        $this->actingAs($admin)
            ->post('/admin/waitlist/'.$bert->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasErrors('time');
    }

    /**
     * Und ein abgelehnter Anlauf darf dem Gast nicht SEIN EIGENES gueltiges
     * Angebot nehmen: Der Link in seiner Mail waere danach tot, ohne dass es
     * jemand merkt.
     *
     * Genau dieser Gast ist der Fall - das Schliessen der alten Angebote ist
     * auf seinen Eintrag gefiltert, ein fremdes stand nie zur Debatte. Anna
     * bekommt den letzten Tisch, der wird belegt, und danach versucht das
     * Personal, Anna ein zweites Mal anzubieten.
     */
    public function test_a_refused_offer_leaves_the_guests_own_offer_intact(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        // Der Tisch geht anderweitig weg.
        $start = CarbonImmutable::parse($anna->desired_date->toDateString().' 19:00', $setup['location']->timezone);
        Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'staff',
            'guest_name_snapshot' => 'Clara',
        ])->tables()->attach($setup['tables'][0]->id);

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasErrors('time');

        $this->assertSame(1, WaitlistOffer::withoutGlobalScopes()
            ->where('waitlist_entry_id', $anna->id)
            ->where('status', 'open')
            ->count(), 'Annas gueltiges Angebot ist verschwunden.');
        $this->assertSame('offered', $anna->fresh()->status);
    }

    /**
     * Zwei Raeume, in jedem ein Vierertisch, zwei Vierergruppen: Beide duerfen
     * ein Angebot bekommen.
     *
     * Die Plaetze der anderen auf die eigene Gruppe zu addieren, fragte nach
     * einem Achtertisch. Den gibt es hier nicht - und quer ueber zwei Raeume
     * laesst sich auch keiner zusammenstellen. Der zweite Gast wurde abgewiesen,
     * waehrend ein Vierertisch leer stand.
     */
    public function test_two_parties_of_four_fit_into_two_rooms(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $zweiterRaum = Room::factory()->create([
            'location_id' => $setup['location']->id,
            'tenant_id' => $setup['tenant']->id,
        ]);
        RestaurantTable::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'room_id' => $zweiterRaum->id,
            'name' => 'T2', 'min_capacity' => 1, 'max_capacity' => 4,
        ]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna', 4);
        $bert = $this->entry($setup, 'Bert', 4);
        $this->clearTenantContext();

        foreach ([$anna, $bert] as $eintrag) {
            $this->actingAs($admin)
                ->post('/admin/waitlist/'.$eintrag->id.'/offer', ['time' => '19:00'])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, WaitlistOffer::withoutGlobalScopes()->where('status', 'open')->count());
    }

    /**
     * Ein grosser Tisch, zwei kleine Gruppen: nur EIN Angebot.
     *
     * Die Summe zweier Zweiergruppen passt rechnerisch an einen Zehnertisch -
     * in Wirklichkeit setzt sich die erste Gruppe daran, und die zweite steht
     * mit einer Zusage da, die niemand einloesen kann.
     */
    public function test_one_big_table_is_promised_only_once(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 10]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna', 2);
        $bert = $this->entry($setup, 'Bert', 2);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasErrors('time');

        // Und das Angebot haelt fest, WELCHEN Tisch es verspricht.
        $offer = WaitlistOffer::withoutGlobalScopes()->where('waitlist_entry_id', $anna->id)->sole();
        $this->assertSame([$setup['tables'][0]->id], $offer->table_ids);
    }

    /**
     * Ein Angebot aus der Zeit vor den festgehaltenen Tischen sperrt keinen -
     * es darf trotzdem nicht wirkungslos sein.
     *
     * Solche Zeilen fallen sonst durch beide Raster: keine Tische fuer die
     * Sperrliste, und der Deckenzaehler laeuft im Tischmodus gar nicht. Beim
     * Ausrollen stuende der gerade behobene Fehler damit acht Stunden lang
     * wieder offen - so lange lebt ein Angebot hoechstens.
     */
    public function test_an_offer_without_recorded_tables_still_counts(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');

        // Wie ein Angebot aus v1.125.0: offen, aber ohne table_ids.
        $start = CarbonImmutable::parse($anna->desired_date->toDateString().' 19:00', $setup['location']->timezone);
        WaitlistOffer::create([
            'tenant_id' => $setup['tenant']->id,
            'waitlist_entry_id' => $anna->id,
            'offered_start_at' => $start->utc(),
            'offered_end_at' => $start->addHours(2)->utc(),
            'offer_expires_at' => now()->addHour(),
            'table_ids' => null,
            'status' => 'open',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasErrors('time');
    }

    /**
     * Und das Angebot loest ein, was es verspricht.
     *
     * Die Zusage prueft ohne `online`, das Annehmen buchte mit - ein Tisch, der
     * nicht online buchbar ist, wurde zugesagt und war beim Klick "nicht mehr
     * frei". Der Gast bekam eine Fehlermeldung fuer einen Tisch, der die ganze
     * Zeit dastand.
     */
    public function test_an_offer_for_a_table_that_is_not_online_bookable_can_be_accepted(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $setup['tables'][0]->update(['online_bookable' => false]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        $offer = WaitlistOffer::withoutGlobalScopes()->where('waitlist_entry_id', $anna->id)->sole();
        $this->assertSame([$setup['tables'][0]->id], $offer->table_ids);

        $reservation = app(WaitlistService::class)->acceptOffer($offer);

        $this->assertSame('accepted', $anna->fresh()->status);
        $this->assertSame([$setup['tables'][0]->id], $reservation->tables()->pluck('restaurant_tables.id')->all());
    }

    /**
     * Ein entfernter Eintrag nimmt sein Angebot mit. Blieb es offen, hielt es
     * bis zu acht Stunden Tische fuer jemanden frei, den niemand mehr erwartet -
     * und der Annehmen-Link in seiner Mail funktionierte weiter.
     */
    public function test_removing_an_entry_closes_its_open_offer(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/cancel')->assertRedirect();

        $this->assertSame(0, WaitlistOffer::withoutGlobalScopes()->where('status', 'open')->count());

        // Und der Tisch ist damit wieder zu haben.
        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();
    }

    /**
     * Ein Angebot fuer die Zeit nach Mitternacht gehoert auf den Folgetag.
     * Aus dem Wunschtag zusammengesetzt landete es einen Abend zu frueh - der
     * Gast las in der Mail ein Datum, das der Betrieb nie gemeint hatte.
     */
    public function test_a_staff_offer_after_midnight_lands_on_the_next_day(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        OpeningHour::withoutGlobalScopes()
            ->where('location_id', $setup['location']->id)
            ->update(['opens_at' => '18:00', 'closes_at' => '02:00']);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $folgetag = $anna->desired_date->copy()->addDay()->toDateString();
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', [
            'time' => '00:30', 'slot_date' => $folgetag,
        ])->assertSessionHasNoErrors();

        $offer = WaitlistOffer::withoutGlobalScopes()->sole();
        $this->assertSame(
            $folgetag,
            CarbonImmutable::parse($offer->offered_start_at)->setTimezone($setup['location']->timezone)->toDateString()
        );

        // Ein beliebiger anderer Tag geht nicht.
        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', [
            'time' => '00:30', 'slot_date' => $anna->desired_date->copy()->addDays(9)->toDateString(),
        ])->assertSessionHasErrors('slot_date');
    }

    /**
     * "Gast jetzt platzieren" steht am Tresen. Ein offenes Angebot bei jemand
     * anderem darf das nie aufhalten.
     */
    public function test_seating_a_guest_now_works_despite_another_open_offer(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $this->openAroundTheClock($setup);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $anna = $this->entry($setup, 'Anna');
        $bert = $this->entry($setup, 'Bert');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$anna->id.'/offer', ['time' => '19:00'])
            ->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/seat')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', $bert->fresh()->status);
        $this->assertDatabaseHas('reservations', [
            'location_id' => $setup['location']->id,
            'guest_name_snapshot' => 'Bert',
        ]);
    }

    /**
     * Ein abgelaufenes, noch nicht aufgeraeumtes Angebot darf "jetzt
     * platzieren" nicht blockieren - acceptOffer wiese es als "nicht mehr
     * gueltig" ab.
     */
    public function test_seating_a_guest_now_ignores_an_expired_offer(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $this->openAroundTheClock($setup);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $bert = $this->entry($setup, 'Bert');
        WaitlistOffer::create([
            'tenant_id' => $setup['tenant']->id,
            'waitlist_entry_id' => $bert->id,
            'offered_start_at' => now()->subHours(3),
            'offered_end_at' => now()->subHour(),
            'offer_expires_at' => now()->subHours(2),
            'status' => 'open',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/waitlist/'.$bert->id.'/seat')
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('accepted', $bert->fresh()->status);
    }
}
