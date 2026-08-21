<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\ExpireUnconfirmedReservations;
use App\Models\Guest;
use App\Models\GuestAuthToken;
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
