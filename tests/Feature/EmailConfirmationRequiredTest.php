<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\ExpireUnconfirmedReservations;
use App\Jobs\ExpireUnpaidReservations;
use App\Models\DepositRule;
use App\Models\Guest;
use App\Models\GuestAuthToken;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Buchungen auf Vorrat: Wer fuenf Termine will, muss fuenf Mails oeffnen.
 *
 * Frueher galt die Bestaetigung nur beim ersten Mal - gegen genau dieses
 * Muster war sie damit wirkungslos, denn Vorratsbuchungen kommen von jemandem,
 * der schon einmal gebucht hat.
 */
class EmailConfirmationRequiredTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function requireConfirmation(array $setup): void
    {
        $setup['location']->settings()->update(['require_email_confirmation' => true]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function bookOnline(array $setup, int $inTagen = 3, string $mail = 'kessler@example.test'): void
    {
        // Ohne assertRedirect braeche ein abgewiesenes Formular erst spaeter
        // und an ganz anderer Stelle - naemlich dort, wo die Reservierung
        // gesucht wird, die es nie gab. Telefon ist ab Werk ein Pflichtfeld.
        $this->post(
            '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug,
            $this->bookingPayload($setup, $inTagen, $mail)
        )->assertRedirect();
    }

    /**
     * @param  array<string, mixed>  $setup
     * @return array<string, mixed>
     */
    private function bookingPayload(array $setup, int $inTagen = 3, string $mail = 'kessler@example.test'): array
    {
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays($inTagen);

        return [
            'date' => $tag->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Frau Kessler',
            'email' => $mail,
            'phone' => '+49 451 123456',
            'privacy_accepted' => '1',
        ];
    }

    public function test_an_online_booking_waits_for_the_confirmation_link(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);

        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(ReservationStatus::Requested, $reservation->status);

        // Der Link haengt an genau dieser Buchung.
        $this->assertSame(1, GuestAuthToken::withoutGlobalScopes()
            ->where('purpose', 'verify')
            ->where('reservation_id', $reservation->id)
            ->count());
    }

    /**
     * Der Kern der Aenderung: Auch wer seine Adresse laengst bestaetigt hat,
     * muss jede weitere Buchung wieder bestaetigen.
     */
    public function test_a_known_guest_has_to_confirm_every_single_booking(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup, 3);

        // Adresse gilt ab jetzt als bestaetigt.
        Guest::withoutGlobalScopes()->firstOrFail()->update(['email_verified_at' => now()]);

        $this->bookOnline($setup, 5);
        $this->bookOnline($setup, 7);

        $this->assertSame(3, Reservation::withoutGlobalScopes()->count());
        $this->assertSame(3, Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Requested->value)
            ->count(), 'Eine spaetere Buchung wurde ohne Bestaetigung durchgelassen.');
        $this->assertSame(3, GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->count());
    }

    public function test_clicking_the_link_confirms_the_booking(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);

        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();
        $this->get('/konto/verify/'.$token->token)->assertOk();

        $this->assertSame(
            ReservationStatus::Confirmed,
            Reservation::withoutGlobalScopes()->firstOrFail()->status
        );
    }

    // ── Was der Gast nach dem Klick bekommt ───────────────────────────────

    /**
     * Beim Anlegen wird die Buchungsmail zurueckgehalten, solange die Adresse
     * offen ist. Der Klick muss sie nachholen, sonst hat der Gast nichts
     * Schriftliches ueber seinen Termin.
     */
    public function test_after_the_click_the_guest_gets_the_booking_confirmation(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(0, $this->buchungsmails($reservation), 'Vor dem Klick ging schon eine Buchungsmail raus.');

        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();
        $this->get('/konto/verify/'.$token->token)->assertOk();

        $this->assertSame(1, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'reservation_confirmed')
            ->count());
    }

    /**
     * Gibt der Betrieb jede Buchung selbst frei, bleibt die Buchung nach dem
     * Klick eine Anfrage. Auch dann braucht der Gast die Eingangsbestaetigung,
     * die beim Anlegen zurueckgehalten wurde.
     */
    public function test_after_the_click_a_request_gets_its_receipt(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'require_email_confirmation' => true,
            'auto_confirm' => false,
        ]);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();

        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();
        $this->get('/konto/verify/'.$token->token)->assertOk();

        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
        $this->assertSame(1, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'reservation_requested')
            ->count(), 'Nach dem Klick kam keine Eingangsbestaetigung.');
    }

    /**
     * Ein zweiter Klick auf denselben Link (oder ein Mailscanner davor) darf
     * keine zweite Eingangsbestaetigung ausloesen.
     */
    public function test_a_second_click_sends_no_second_receipt(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'require_email_confirmation' => true,
            'auto_confirm' => false,
        ]);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();

        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();
        $this->get('/konto/verify/'.$token->token)->assertOk();
        $this->get('/konto/verify/'.$token->token);

        $this->assertSame(1, $this->buchungsmails($reservation));
    }

    /**
     * Bei Freigabe von Hand erfaehrt der Gast auf jeder Station, dass der
     * Betrieb die Anfrage noch persoenlich prueft: nach dem Absenden, nach
     * dem Klick und in der Mail.
     */
    public function test_with_manual_approval_the_guest_learns_that_the_team_decides(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'require_email_confirmation' => true,
            'auto_confirm' => false,
        ]);
        $this->clearTenantContext();

        $this->followingRedirects()->post(
            '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug,
            $this->bookingPayload($setup)
        )->assertOk()
            ->assertSee('Fast geschafft!')
            ->assertSee('persönlich an')
            ->assertDontSee('ist Ihr Tisch reserviert');

        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();
        $this->get('/konto/verify/'.$token->token)
            ->assertOk()
            ->assertSee('ist bei uns angekommen')
            ->assertSee('persönlich an')
            ->assertDontSee('wird bearbeitet');

        $log = NotificationLog::withoutGlobalScopes()->where('template_key', 'reservation_requested')->firstOrFail();
        $this->assertSame('Ihre Anfrage ist angekommen – '.$setup['location']->name, $log->subject);
    }

    /**
     * Mit Sofortbestaetigung bleibt die bisherige Ansage: Erst der Klick
     * reserviert den Tisch.
     */
    public function test_with_auto_confirm_the_page_says_the_click_reserves_the_table(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->followingRedirects()->post(
            '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug,
            $this->bookingPayload($setup)
        )->assertOk()
            ->assertSee('ist Ihr Tisch reserviert')
            ->assertDontSee('persönlich an');
    }

    private function buchungsmails(Reservation $reservation): int
    {
        return NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->whereIn('template_key', ['reservation_confirmed', 'reservation_requested'])
            ->count();
    }

    // ── Aufraeumen ────────────────────────────────────────────────────────

    /**
     * Ohne diesen Lauf blockierten unbestaetigte Buchungen den Tisch fuer
     * immer - die Massnahme haette das Problem verschlimmert statt geloest.
     */
    public function test_an_unconfirmed_booking_releases_the_table_after_the_link_expired(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();

        GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')
            ->update(['expires_at' => now()->subMinute()]);

        (new ExpireUnconfirmedReservations)->handle(app(ReservationLifecycleService::class));

        $frisch = $reservation->fresh();
        $this->assertSame(ReservationStatus::Expired, $frisch->status);
        $this->assertFalse($frisch->status->isActive(), 'Der Tisch ist weiterhin belegt.');
    }

    public function test_a_link_that_is_still_valid_keeps_the_booking(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);

        (new ExpireUnconfirmedReservations)->handle(app(ReservationLifecycleService::class));

        $this->assertSame(
            ReservationStatus::Requested,
            Reservation::withoutGlobalScopes()->firstOrFail()->status
        );
    }

    /**
     * Anfragen, die aus einem anderen Grund offen sind - etwa weil der Betrieb
     * jede Buchung selbst freigibt -, gehen den Lauf nichts an.
     */
    public function test_a_request_without_a_confirmation_link_is_left_alone(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        // Keine E-Mail-Bestaetigung, dafuer Anfragebetrieb.
        $setup['location']->settings()->update([
            'require_email_confirmation' => false,
            'auto_confirm' => false,
        ]);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(ReservationStatus::Requested, $reservation->status);

        (new ExpireUnconfirmedReservations)->handle(app(ReservationLifecycleService::class));

        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
    }

    /**
     * Hat der Gast doch noch bestaetigt, darf der Lauf nichts mehr anfassen.
     */
    public function test_a_used_link_protects_the_booking(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();

        GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->update([
            'expires_at' => now()->subMinute(),
            'used_at' => now()->subMinutes(2),
        ]);

        (new ExpireUnconfirmedReservations)->handle(app(ReservationLifecycleService::class));

        $this->assertNotSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    // ── Anzahlung und Bestaetigung zusammen ───────────────────────────────

    /**
     * @param  array<string, mixed>  $setup
     */
    private function depositRule(array $setup): void
    {
        DepositRule::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Alle',
            'type' => 'deposit',
            'amount_per_person_minor' => 1000,
            'flat_amount_minor' => 0,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'cancel_unpaid_automatically' => true,
            'is_active' => true,
        ]);
    }

    /**
     * Die Zahlungsfrist darf nicht laufen, solange die Aufforderung
     * zurueckgehalten wird. Sonst bucht jemand um 22 Uhr, liest die Mail um 8
     * Uhr - und der Tisch ist seit neun Stunden weg.
     */
    public function test_the_payment_deadline_does_not_start_before_the_guest_is_asked(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->depositRule($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);

        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->status);
        $this->assertNull($reservation->payment_due_at, 'Die Frist laeuft, bevor der Gast gefragt wurde.');

        // Erst der Klick startet sie - und schickt die Aufforderung.
        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();
        $this->get('/konto/verify/'.$token->token)->assertOk();

        $frisch = $reservation->fresh();
        $this->assertNotNull($frisch->payment_due_at);
        $this->assertTrue($frisch->payment_due_at->isFuture());
        $this->assertSame(1, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'payment_pending')
            ->count());
    }

    /**
     * Ohne Frist duerfte die Buchung nicht ewig haengen: Der Aufraeumlauf muss
     * sie ueber den abgelaufenen Bestaetigungslink finden, auch wenn sie auf
     * PaymentPending steht statt auf Requested.
     */
    public function test_an_unconfirmed_deposit_booking_still_releases_the_table(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->depositRule($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->status);

        GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')
            ->update(['expires_at' => now()->subMinute()]);

        (new ExpireUnconfirmedReservations)->handle(app(ReservationLifecycleService::class));

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    /**
     * An eine Aufforderung erinnern, die nie ankam, waere Unsinn.
     */
    public function test_no_payment_reminder_before_the_request_went_out(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->depositRule($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();

        // Frist von Hand setzen, als waere sie laengst halb um.
        $reservation->forceFill([
            'created_at' => now()->subMinutes(40),
            'payment_due_at' => now()->addMinutes(20),
        ])->saveQuietly();

        (new ExpireUnpaidReservations)->handle(app(ReservationLifecycleService::class));

        $this->assertSame(0, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'payment_reminder')
            ->count());
    }

    /**
     * Klickt der Gast erst nach Ablauf, darf die Seite nicht behaupten, die
     * Buchung werde bearbeitet.
     */
    public function test_a_late_click_says_the_booking_is_gone(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $this->bookOnline($setup);
        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();
        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();

        GuestAuthToken::withoutGlobalScopes()->where('id', $token->id)
            ->update(['expires_at' => now()->subMinute()]);
        (new ExpireUnconfirmedReservations)->handle(app(ReservationLifecycleService::class));
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);

        // Der Link ist abgelaufen, die Seite muss trotzdem etwas Sinnvolles sagen.
        GuestAuthToken::withoutGlobalScopes()->where('id', $token->id)
            ->update(['expires_at' => now()->addHour()]);

        $this->get('/konto/verify/'.$token->token)
            ->assertOk()
            ->assertSee('nicht mehr gültig')
            ->assertDontSee('wird bearbeitet');
    }

    // ── Was der Gast sieht ────────────────────────────────────────────────

    public function test_the_confirmation_page_says_what_is_still_missing(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $this->requireConfirmation($setup);
        $this->clearTenantContext();

        $antwort = $this->followingRedirects()->post(
            '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug,
            $this->bookingPayload($setup)
        );

        $antwort->assertOk()
            ->assertSee('Fast geschafft!')
            ->assertSee('kessler@example.test')
            ->assertSee('Spam-')
            ->assertSee('24 Stunden');
    }
}
