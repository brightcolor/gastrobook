<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Mail\GuestLinkMail;
use App\Models\Guest;
use App\Models\GuestAuthToken;
use App\Models\Reservation;
use App\Services\GuestAuthService;
use App\Services\GuestProfileService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Welchem Gastprofil eine Buchung zufällt und wohin der Bestätigungslink geht.
 *
 * Die Telefonnummer aus dem öffentlichen Formular kann jeder eintippen. Ordnet
 * sie online ein Profil zu, bekommt ein Fremder mit der Nummer eines Gastes
 * dessen Profil: Seine Adresse rückt in eine leere Lücke, der Anmeldelink fürs
 * Kundenkonto geht an ihn, und er sieht alle Buchungen des Gastes. Online zählt
 * deshalb nur die E-Mail-Adresse, und der Bestätigungslink geht an die Adresse,
 * die der Buchende eingegeben hat.
 */
class OnlineGuestMatchingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const NUMMER = '+49 151 00000000';

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $daten
     */
    private function bestandsgast(array $setup, array $daten = []): Guest
    {
        return Guest::withoutGlobalScopes()->create($daten + [
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Erika',
            'last_name' => 'Muster',
            'phone' => self::NUMMER,
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function bucheOnline(array $setup, string $mail, int $inTagen = 3): Reservation
    {
        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays($inTagen)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Erika Muster',
            'email' => $mail,
            'phone' => self::NUMMER,
            'privacy_accepted' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Reservation::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    // ── Bestätigungslink ──────────────────────────────────────────────────

    /**
     * Der Fall vom 10.10.2026: bekannte Nummer, neue Adresse. Der Link ging an
     * die alte Adresse, die Buchung blieb unbestätigt.
     */
    public function test_der_bestaetigungslink_geht_an_die_eingegebene_adresse(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['require_email_confirmation' => true]);
        $this->bestandsgast($setup, ['email' => 'alt@example.test']);
        $this->clearTenantContext();

        $this->bucheOnline($setup, 'neu@example.test');

        Mail::assertSent(GuestLinkMail::class, fn (GuestLinkMail $m) => $m->hasTo('neu@example.test'));
        Mail::assertNotSent(GuestLinkMail::class, fn (GuestLinkMail $m) => $m->hasTo('alt@example.test'));
    }

    /**
     * Auch wenn Profil und Buchung auseinanderlaufen, etwa weil das Personal
     * die Adresse im Profil geändert hat: Der Link gehört zur Buchung.
     */
    public function test_der_link_folgt_der_adresse_der_buchung(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $gast = $this->bestandsgast($setup, ['email' => 'profil@example.test']);
        $reservation = $this->offeneBuchung($setup, $gast, 'buchung@example.test');
        $this->clearTenantContext();

        app(GuestAuthService::class)->sendVerification($gast, $reservation);

        Mail::assertSent(GuestLinkMail::class, fn (GuestLinkMail $m) => $m->hasTo('buchung@example.test'));
        Mail::assertNotSent(GuestLinkMail::class, fn (GuestLinkMail $m) => $m->hasTo('profil@example.test'));
    }

    /**
     * Der Klick bestätigt die Adresse aus der Buchung. Steht im Profil eine
     * andere, bleibt diese unbestätigt; die Buchung selbst gilt trotzdem.
     */
    public function test_der_klick_bestaetigt_nur_die_adresse_aus_der_buchung(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['require_email_confirmation' => true, 'auto_confirm' => true]);
        $gast = $this->bestandsgast($setup, ['email' => 'profil@example.test']);
        $reservation = $this->offeneBuchung($setup, $gast, 'buchung@example.test');
        $token = app(GuestAuthService::class)->issue($gast, 'verify', $reservation->id, 1440);
        $this->clearTenantContext();

        $this->get('/konto/verify/'.$token)->assertOk();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertNull($gast->fresh()->email_verified_at, 'Der Klick hat eine Adresse bestätigt, die der Buchende nie angegeben hat.');
    }

    public function test_der_klick_bestaetigt_dieselbe_adresse_in_anderer_schreibweise(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['require_email_confirmation' => true, 'auto_confirm' => true]);
        $gast = $this->bestandsgast($setup, ['email' => 'erika@example.test']);
        $reservation = $this->offeneBuchung($setup, $gast, ' Erika@Example.TEST ');
        $token = app(GuestAuthService::class)->issue($gast, 'verify', $reservation->id, 1440);
        $this->clearTenantContext();

        $this->get('/konto/verify/'.$token)->assertOk();

        $this->assertNotNull($gast->fresh()->email_verified_at);
    }

    // ── Zuordnung online ──────────────────────────────────────────────────

    public function test_bekannte_nummer_mit_neuer_adresse_bekommt_ein_eigenes_profil(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $bestand = $this->bestandsgast($setup, ['email' => 'alt@example.test']);
        $this->clearTenantContext();

        $reservation = $this->bucheOnline($setup, 'neu@example.test');

        $this->assertNotSame($bestand->id, $reservation->guest_id);
        $this->assertSame('neu@example.test', Guest::withoutGlobalScopes()->findOrFail($reservation->guest_id)->email);
        $this->assertSame('alt@example.test', $bestand->fresh()->email);
    }

    /**
     * Der Missbrauchsfall: Der Gast hat nur eine Telefonnummer im Profil. Ein
     * Fremder bucht mit dieser Nummer und seiner Adresse und fordert danach den
     * Anmeldelink fürs Kundenkonto an.
     */
    public function test_eine_fremde_nummer_uebernimmt_kein_profil(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $opfer = $this->bestandsgast($setup, ['marketing_consent' => true, 'marketing_consent_at' => now()]);
        $this->clearTenantContext();

        $this->bucheOnline($setup, 'fremd@example.test');
        $this->post('/konto/'.$setup['tenant']->slug, ['email' => 'fremd@example.test'])->assertOk();

        $opfer->refresh();
        $this->assertNull($opfer->email, 'Die Adresse des Fremden steht im Profil des Gastes.');
        $this->assertTrue($opfer->marketing_consent, 'Die Buchung des Fremden hat die Einwilligung des Gastes widerrufen.');
        $this->assertSame(0, Reservation::withoutGlobalScopes()->where('guest_id', $opfer->id)->count());
        $this->assertSame(0, GuestAuthToken::withoutGlobalScopes()
            ->where('purpose', 'login')
            ->where('guest_id', $opfer->id)
            ->count(), 'Der Anmeldelink führt ins Kundenkonto des Gastes.');
    }

    public function test_eine_bekannte_adresse_landet_online_im_bestehenden_profil(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $bestand = $this->bestandsgast($setup, ['email' => 'erika@example.test', 'phone' => '+49 40 999999']);
        $this->clearTenantContext();

        $reservation = $this->bucheOnline($setup, 'Erika@Example.test');

        $this->assertSame($bestand->id, $reservation->guest_id);
        $this->assertSame(1, Guest::withoutGlobalScopes()->count());
    }

    // ── Zuordnung durch das Personal ──────────────────────────────────────

    public function test_das_personal_ordnet_weiter_ueber_die_telefonnummer_zu(): void
    {
        $setup = $this->createTenantSetup();
        $bestand = $this->bestandsgast($setup, ['email' => 'alt@example.test']);
        $this->clearTenantContext();

        $gast = app(GuestProfileService::class)->findOrCreate($setup['tenant'], [
            'name' => 'Erika Muster',
            'phone' => self::NUMMER,
            'source' => 'phone',
        ]);

        $this->assertSame($bestand->id, $gast->id);
    }

    /**
     * Die Quellen, die über die Telefonnummer zuordnen dürfen, kommen aus der
     * Einstellung. Mit einer anderen Liste als der Vorgabe ordnet „phone“
     * nicht mehr zu, „manual“ weiterhin.
     */
    public function test_nur_eingetragene_quellen_ordnen_ueber_die_telefonnummer_zu(): void
    {
        config(['swayy.guest_profiles.phone_match_sources' => ['manual']]);

        $setup = $this->createTenantSetup();
        $bestand = $this->bestandsgast($setup);
        $this->clearTenantContext();

        $profile = app(GuestProfileService::class);
        $perTelefon = $profile->findOrCreate($setup['tenant'], ['name' => 'Erika Muster', 'phone' => self::NUMMER, 'source' => 'phone']);
        $vonHand = $profile->findOrCreate($setup['tenant'], ['name' => 'Erika Muster', 'phone' => self::NUMMER, 'source' => 'manual']);

        $this->assertNotSame($bestand->id, $perTelefon->id);
        $this->assertSame($bestand->id, $vonHand->id);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function offeneBuchung(array $setup, Guest $gast, string $mail): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(2)->setTime(19, 0);

        return Reservation::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $gast->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addMinutes(120)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Requested,
            'source' => 'online',
            'guest_name_snapshot' => 'Erika Muster',
            'guest_email_snapshot' => $mail,
        ]);
    }
}
