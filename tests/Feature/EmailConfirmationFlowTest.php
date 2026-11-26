<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Mail\TemplatedMail;
use App\Models\DepositRule;
use App\Models\GuestAuthToken;
use App\Models\Location;
use App\Models\LocationSettings;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Services\GuestAuthService;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Der Weg einer Online-Buchung mit Bestätigungslink:
 *
 * 1. Der Gast schickt seinen Wunsch ab und bekommt nur die Mail mit dem Link.
 * 2. Erst der Klick macht daraus eine Anfrage (Freigabe von Hand) oder eine
 *    bestätigte Reservierung (sofortige Bestätigung).
 * 3. Erst dann erfährt das Team davon.
 *
 * Und der Gast liest das alles schon vor dem Absenden.
 */
class EmailConfirmationFlowTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $settings
     */
    private function configure(array $setup, array $settings): void
    {
        $setup['location']->settings()->update(array_merge([
            'require_email_confirmation' => true,
            'owner_notification_enabled' => true,
            'owner_notification_email' => 'team@betrieb.test',
        ], $settings));
        $this->clearTenantContext();
    }

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $setup, array $overrides = []): array
    {
        return array_merge([
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Max Mustermann',
            'email' => 'max@example.test',
            'phone' => '+49 451 123456',
            'privacy_accepted' => '1',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function book(array $setup): Reservation
    {
        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, $this->payload($setup))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        return Reservation::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function click(Reservation $reservation): void
    {
        $token = GuestAuthToken::withoutGlobalScopes()
            ->where('purpose', 'verify')
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();
        $this->get('/konto/verify/'.$token->token)->assertOk();
    }

    private function teamMails(): int
    {
        return Mail::queued(TemplatedMail::class, fn ($m) => $m->hasTo('team@betrieb.test')
            && str_contains($m->mailSubject, 'Neue Reservierung'))->count();
    }

    // ── Freigabe von Hand ─────────────────────────────────────────────────

    public function test_with_manual_approval_the_click_turns_the_booking_into_a_request(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => false]);

        $reservation = $this->book($setup);

        $this->assertSame(ReservationStatus::PendingConfirmation, $reservation->status);
        $this->assertTrue($reservation->status->isActive(), 'Der Tisch muss bis zum Klick gehalten werden.');
        $this->assertSame(0, $this->teamMails(), 'Das Team hörte von der Buchung, bevor der Gast geklickt hat.');

        $this->click($reservation);

        $this->assertSame(ReservationStatus::Requested, $reservation->fresh()->status);
        $this->assertSame(1, $this->teamMails());
        Mail::assertQueued(TemplatedMail::class, fn ($m) => $m->hasTo('team@betrieb.test')
            && str_contains($m->mailBody, 'Sie wartet auf'));
    }

    public function test_the_team_approves_the_request_and_the_guest_gets_the_confirmation(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => false]);

        $reservation = $this->book($setup);
        $this->click($reservation);

        app(ReservationLifecycleService::class)
            ->transition($reservation->fresh(), ReservationStatus::Confirmed);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(1, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'reservation_confirmed')
            ->count());
    }

    // ── Sofortige Bestätigung ─────────────────────────────────────────────

    public function test_with_auto_confirm_the_click_confirms_and_then_tells_the_team(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => true]);

        $reservation = $this->book($setup);
        $this->assertSame(0, $this->teamMails());

        $this->click($reservation);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(1, $this->teamMails());
        Mail::assertQueued(TemplatedMail::class, fn ($m) => $m->hasTo('team@betrieb.test')
            && str_contains($m->mailBody, 'Sie steht schon in'));
    }

    public function test_without_email_confirmation_the_team_hears_right_away(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => false, 'auto_confirm' => true]);

        $reservation = $this->book($setup);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
        $this->assertSame(1, $this->teamMails());
    }

    public function test_a_used_link_sends_no_second_team_mail(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => false]);

        $reservation = $this->book($setup);
        $token = GuestAuthToken::withoutGlobalScopes()->where('reservation_id', $reservation->id)->firstOrFail();

        $this->get('/konto/verify/'.$token->token)->assertOk();
        $this->get('/konto/verify/'.$token->token);

        $this->assertSame(1, $this->teamMails());
        $this->assertNull(app(GuestAuthService::class)->consume($token->token, 'verify'));
    }

    public function test_with_a_deposit_the_team_hears_after_the_click(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
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
        $this->configure($setup, ['auto_confirm' => true]);

        $reservation = $this->book($setup);
        $this->assertSame(0, $this->teamMails());

        $this->click($reservation);

        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
        $this->assertSame(1, $this->teamMails());
    }

    /**
     * Buchungen von vor v1.139.0 warten als Anfrage auf den Klick. Der Klick
     * bestätigt sie weiter, und das Team kennt sie schon.
     */
    public function test_an_older_booking_waiting_as_request_still_works(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => true]);

        $reservation = $this->book($setup);
        $reservation->forceFill(['status' => ReservationStatus::Requested])->saveQuietly();

        $this->click($reservation);

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        $this->assertSame(0, $this->teamMails());
    }

    public function test_the_team_rejects_while_waiting_and_a_late_click_changes_nothing(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => true]);

        $reservation = $this->book($setup);
        app(ReservationLifecycleService::class)
            ->transition($reservation, ReservationStatus::Rejected);

        $this->click($reservation);

        $this->assertSame(ReservationStatus::Rejected, $reservation->fresh()->status);
        $this->assertSame(0, $this->teamMails());
    }

    // ── Was das Team sieht ────────────────────────────────────────────────

    public function test_a_booking_waiting_for_the_click_is_no_open_request_yet(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => false]);

        $reservation = $this->book($setup);

        $this->assertSame(0, Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Requested->value)->count());
        $this->assertSame('Wartet auf E-Mail-Bestätigung', $reservation->status->label());

        $this->click($reservation);

        $this->assertSame(1, Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Requested->value)->count());
    }

    // ── Was der Gast vor dem Absenden liest ───────────────────────────────

    /**
     * @param  array<string, mixed>  $setup
     */
    private function bookingPage(array $setup): TestResponse
    {
        return $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)->assertOk();
    }

    public function test_the_form_announces_link_and_approval_with_manual_approval(): void
    {
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => false]);

        $this->bookingPage($setup)
            ->assertSee('So geht es weiter')
            ->assertSee('eine E-Mail mit einem Bestätigungslink')
            ->assertSee('Wir prüfen sie und schicken Ihnen dann die Reservierungsbestätigung')
            ->assertSee('Der Link gilt 24 Stunden')
            ->assertSee('Reservierung anfragen');
    }

    public function test_the_form_announces_the_link_with_auto_confirm(): void
    {
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => true]);

        $this->bookingPage($setup)
            ->assertSee('So geht es weiter')
            ->assertSee('Ihr Tisch ist reserviert, sobald Sie den Link anklicken')
            ->assertSee('Jetzt reservieren')
            ->assertDontSee('Reservierung anfragen');
    }

    public function test_the_form_announces_the_approval_without_link(): void
    {
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => false, 'auto_confirm' => false, 'guest_address' => 'du']);

        $this->bookingPage($setup)
            ->assertSee('Wir prüfen jede Anfrage persönlich und')
            ->assertSee('schicken dir dann die Reservierungsbestätigung per E-Mail')
            ->assertDontSee('Bestätigungslink')
            ->assertSee('Reservierung anfragen');
    }

    public function test_the_form_says_nothing_extra_with_instant_confirmation(): void
    {
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => false, 'auto_confirm' => true]);

        $this->bookingPage($setup)
            ->assertDontSee('So geht es weiter')
            ->assertSee('Jetzt reservieren');
    }

    public function test_the_link_validity_follows_the_setting(): void
    {
        config(['swayy.guest_auth.verify_minutes' => 120]);
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['auto_confirm' => true]);

        $this->bookingPage($setup)->assertSee('Der Link gilt 2 Stunden');
    }

    // ── Ohne Adresse kein Durchschlupf ────────────────────────────────────

    public function test_the_email_is_required_while_confirmation_is_on(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['field_rules' => ['email' => 'optional']]);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, $this->payload($setup, ['email' => '']))
            ->assertSessionHasErrors('email');
        $this->assertSame(0, Reservation::withoutGlobalScopes()->count());
    }

    public function test_the_email_stays_optional_without_confirmation(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => false, 'field_rules' => ['email' => 'optional']]);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, $this->payload($setup, ['email' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());
    }

    // ── Vorgabe ab Werk ───────────────────────────────────────────────────

    public function test_a_new_location_asks_for_email_confirmation_by_default(): void
    {
        $setup = $this->createTenantSetup();
        $location = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);

        $location->settings()->create(['tenant_id' => $setup['tenant']->id]);
        $this->assertTrue((bool) LocationSettings::withoutGlobalScopes()
            ->where('location_id', $location->id)->value('require_email_confirmation'));

        $this->assertTrue((new LocationSettings)->require_email_confirmation);
    }
}
