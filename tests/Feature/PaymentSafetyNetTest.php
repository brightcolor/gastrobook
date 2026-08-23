<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\ExpireUnpaidReservations;
use App\Mail\TemplatedMail;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Services\RefundService;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die Netze unter dem Geldweg.
 *
 * Jeder Fall hier stand vorher offen: eine Zahlung, die als bezahlt galt,
 * ohne dass Geld floss; ein Gast, der seine Buchung nie mehr loswurde; eine
 * Frist, die eine bezahlte Buchung ueberschrieb; eine Erstattung, die
 * niemand mehr erreichte.
 */
class PaymentSafetyNetTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const SECRET = 'whsec_test';

    /**
     * @param  array<string, mixed>  $setup
     */
    private function connectStripe(array $setup): void
    {
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => null,
            'provider' => 'stripe',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'secret_key' => 'sk_test',
                'webhook_secret' => self::SECRET,
            ])),
        ]);
        $setup['tenant']->update(['feature_overrides' => ['deposits_enabled' => true]]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function awaitingPayment(array $setup, string $paymentStatus = 'required'): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 4,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::PaymentPending,
            'source' => 'online',
            'guest_name_snapshot' => 'Frau Kessler',
            'guest_email_snapshot' => 'kessler@example.test',
            'payment_status' => $paymentStatus,
            'payment_amount_minor' => 4000,
            'currency' => 'EUR',
            'payment_due_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * @param  array<string, mixed>  $nutzlast
     * @return array{0: string, 1: string}
     */
    private function signedWebhook(array $nutzlast): array
    {
        $body = json_encode($nutzlast);
        $t = now()->timestamp;
        $signature = 't='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, self::SECRET);

        return [$body, $signature];
    }

    /**
     * @param  array<string, mixed>  $nutzlast
     */
    private function postWebhook(array $nutzlast): void
    {
        [$body, $signature] = $this->signedWebhook($nutzlast);

        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
    }

    // ── Verzoegerte Zahlarten ─────────────────────────────────────────────

    /**
     * `checkout.session.completed` heisst nicht bezahlt. Bei Lastschrift und
     * Sofortueberweisung meldet Stripe die Sitzung sofort als abgeschlossen,
     * mit payment_status 'unpaid'. Wer hier bucht, haelt einen Tisch fuer eine
     * Zahlung, die nie ankommt - und kann sie nicht einmal erstatten, weil sie
     * nie als offen gilt.
     */
    public function test_an_unpaid_completed_session_does_not_confirm_anything(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_async',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->postWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_async',
                'payment_status' => 'unpaid',
                'metadata' => ['payment_intent_id' => (string) $intent->id],
            ]],
        ]);

        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.awaiting_settlement']);
    }

    public function test_the_later_success_of_a_delayed_payment_confirms_the_booking(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_async',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->postWebhook([
            'type' => 'checkout.session.async_payment_succeeded',
            'data' => ['object' => [
                'id' => 'cs_async',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_async',
                'metadata' => ['payment_intent_id' => (string) $intent->id],
            ]],
        ]);

        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_a_failed_delayed_payment_reopens_the_booking(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup, 'pending');
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_async',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->postWebhook([
            'type' => 'checkout.session.async_payment_failed',
            'data' => ['object' => [
                'id' => 'cs_async',
                'metadata' => ['payment_intent_id' => (string) $intent->id],
            ]],
        ]);

        $this->assertSame('failed', $intent->fresh()->status);
        $this->assertSame('required', $reservation->fresh()->payment_status);
    }

    // ── Der geschlossene Tab ──────────────────────────────────────────────

    /**
     * Der Gast klickt "Jetzt bezahlen", landet bei Stripe und schliesst den
     * Tab. Vorher blieb `payment_status` fuer immer auf 'pending': Stornieren
     * wies ihn dauerhaft ab, der Fristablauf griff bei der Vorgabe nicht, und
     * der Tisch blieb bis zum Termin gesperrt.
     */
    public function test_an_expired_checkout_makes_the_booking_cancellable_again(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup, 'pending');
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_weg',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->postWebhook([
            'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_weg', 'metadata' => ['payment_intent_id' => (string) $intent->id]]],
        ]);

        $this->assertSame('required', $reservation->fresh()->payment_status);
    }

    /**
     * Und selbst ohne den Webhook: Ein Bezahlvorgang, den seit einer
     * Viertelstunde niemand angefasst hat, ist kein laufender Vorgang mehr.
     */
    public function test_a_stale_checkout_no_longer_blocks_cancellation(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup, 'pending');
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_alt',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $intent->forceFill(['updated_at' => now()->subHour()])->saveQuietly();
        $this->clearTenantContext();

        $this->post(route('booking.cancel', ['code' => $reservation->code, 'token' => $reservation->manage_token]))
            ->assertOk();

        $this->assertSame(ReservationStatus::CancelledByGuest, $reservation->fresh()->status);
    }

    public function test_a_running_checkout_still_blocks_cancellation(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup, 'pending');
        PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_laeuft',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->post(route('booking.cancel', ['code' => $reservation->code, 'token' => $reservation->manage_token]))
            ->assertSessionHasErrors('status');

        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
    }

    // ── Fristablauf gegen Zahlungseingang ─────────────────────────────────

    /**
     * Der Fristablauf las den Status ohne Sperre. Ging die Zahlung zwischen
     * Lesen und Schreiben ein, stand danach "verfallen" auf einer bezahlten,
     * bestaetigten Buchung - Geld beim Betrieb, kein Tisch beim Gast.
     */
    public function test_the_deadline_run_leaves_a_paid_booking_alone(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->awaitingPayment($setup);
        $reservation->forceFill(['payment_due_at' => now()->subMinute()])->save();

        // Die Zahlung ist eingegangen, waehrend der Lauf schon lief.
        app(ReservationLifecycleService::class)->transition(
            $reservation, ReservationStatus::Confirmed, null, 'system', 'payment_received'
        );
        Reservation::withoutGlobalScopes()->whereKey($reservation->id)->update(['payment_status' => 'paid']);

        (new ExpireUnpaidReservations)->handle(app(ReservationLifecycleService::class));

        $frisch = $reservation->fresh();
        $this->assertSame(ReservationStatus::Confirmed, $frisch->status);
        $this->assertSame('paid', $frisch->payment_status);
    }

    /**
     * Der Zustand, an dem der gemeinsame Sperrgriff wirklich haengt: Status
     * noch payment_pending, Zahlung aber schon verbucht. Genau dort steht die
     * Buchung zwischen den beiden Schritten von handlePaid - und der
     * Fristablauf las unter der Sperre nur den Status, nie den Zahlungsstand.
     */
    public function test_the_deadline_run_leaves_a_booking_whose_payment_already_landed(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->awaitingPayment($setup);
        $reservation->forceFill([
            'payment_due_at' => now()->subMinute(),
            'payment_status' => 'paid',
        ])->save();

        (new ExpireUnpaidReservations)->handle(app(ReservationLifecycleService::class));

        $frisch = $reservation->fresh();
        $this->assertSame(ReservationStatus::PaymentPending, $frisch->status);
        $this->assertSame('paid', $frisch->payment_status);
    }

    /**
     * Und der Weg als Ganzes, von aussen: Der Gast kommt vom Anbieter zurueck,
     * die Buchung wird bestaetigt, und der Fristablauf direkt danach laesst sie
     * stehen. Der Fall trennt nichts, was die beiden Tests darueber nicht schon
     * trennen - er haelt nur den ganzen Weg zusammen.
     */
    public function test_a_paid_booking_is_confirmed_and_survives_the_deadline_run(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_spalt', 'payment_status' => 'paid', 'payment_intent' => 'pi_1',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $reservation->forceFill(['payment_due_at' => now()->subMinute()])->save();

        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_spalt',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id='.'cs_spalt')
            ->assertRedirect();

        // Der Fristablauf laeuft direkt danach - die Zahlung ist da.
        (new ExpireUnpaidReservations)->handle(app(ReservationLifecycleService::class));

        $frisch = $reservation->fresh();
        $this->assertSame(ReservationStatus::Confirmed, $frisch->status);
        $this->assertSame('paid', $frisch->payment_status);
    }

    /**
     * Eine abgelaufene ALTE Sitzung darf den Vorgang nicht mitreissen: Der Gast
     * hat den Bezahllink zweimal geoeffnet, die erste Sitzung verfaellt nach
     * einer Stunde, waehrend er in der zweiten gerade bezahlt.
     */
    public function test_an_expired_stale_session_leaves_the_payment_alone(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup, 'pending');
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_neu',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
            'metadata' => ['sessions' => ['cs_alt', 'cs_neu']],
        ]);
        $this->clearTenantContext();

        $this->postWebhook([
            'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_alt', 'metadata' => ['payment_intent_id' => (string) $intent->id]]],
        ]);

        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame('pending', $reservation->fresh()->payment_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.stale_session_expired']);

        // Die AKTUELLE Sitzung schliesst den Vorgang dagegen sehr wohl.
        $this->postWebhook([
            'type' => 'checkout.session.expired',
            'data' => ['object' => ['id' => 'cs_neu', 'metadata' => ['payment_intent_id' => (string) $intent->id]]],
        ]);

        $this->assertSame('expired', $intent->fresh()->status);
        $this->assertSame('required', $reservation->fresh()->payment_status);
    }

    /**
     * Zahlung auf eine abgesagte Eventbuchung: Sie darf nicht stillschweigend
     * als bezahlt gelten. Das Geld ist da, die Buchung nicht - also zurueck
     * damit, und der Betrieb erfaehrt davon.
     */
    public function test_a_payment_on_a_cancelled_event_booking_is_refunded(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['owner_notification_email' => 'betrieb@example.test']);
        $this->connectStripe($setup);

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(7)->setTime(19, 0);
        $event = Event::withoutGlobalScope('tenant')->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'title' => 'Weinprobe', 'slug' => 'weinprobe',
            'starts_at' => $start->utc(), 'ends_at' => $start->addHours(4)->utc(),
            'capacity' => 10, 'price_minor' => 4000, 'currency' => 'EUR',
            'is_public' => true, 'status' => 'published',
        ]);
        $booking = EventBooking::create([
            'tenant_id' => $setup['tenant']->id, 'event_id' => $event->id,
            'ticket_count' => 1, 'guest_name' => 'Eva Event', 'guest_email' => 'eva@example.test',
            'status' => 'cancelled', 'payment_status' => 'pending', 'amount_minor' => 4000,
        ]);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'event_booking_id' => $booking->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_event',
            'type' => 'prepayment', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->postWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_event', 'payment_status' => 'paid', 'payment_intent' => 'pi_event',
                'amount_total' => 4000, 'currency' => 'eur',
                'metadata' => ['payment_intent_id' => (string) $intent->id],
            ]],
        ]);

        $refund = Refund::withoutGlobalScopes()->sole();
        $this->assertSame($booking->id, $refund->event_booking_id);
        $this->assertSame(4000, $refund->amount_minor);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.late_on_inactive_reservation']);
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('betrieb@example.test'));
    }

    public function test_the_deadline_run_still_expires_an_unpaid_booking(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->awaitingPayment($setup);
        $reservation->forceFill(['payment_due_at' => now()->subMinute()])->save();

        (new ExpireUnpaidReservations)->handle(app(ReservationLifecycleService::class));

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    // ── Haengende Erstattung ──────────────────────────────────────────────

    /**
     * Stirbt der Prozess zwischen dem Beanspruchen und dem Anbieteraufruf,
     * blieb die Zeile fuer immer auf 'processing': processDue nimmt nur
     * 'approved', der Wiederholen-Knopf nur 'failed'. Der Gast bekam sein Geld
     * nie, und im Betrieb sah es aus, als liefe es noch.
     */
    public function test_a_stalled_refund_can_be_picked_up_again(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_x', 'type' => 'deposit',
            'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'paid',
            'metadata' => ['refund_ref' => 'pi_x'],
        ]);
        $refund = Refund::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id,
            'payment_intent_id' => $intent->id, 'provider' => 'stripe',
            'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'processing',
            'source' => 'staff', 'reason' => 'cancellation',
        ]);
        $refund->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $this->assertTrue($refund->fresh()->isStalled());
        $this->assertTrue(app(RefundService::class)->reopen($refund->fresh()));
        $this->assertTrue(app(RefundService::class)->process($refund->fresh()));
        $this->assertSame('completed', $refund->fresh()->status);

        // Genau hier haengt der Schutz gegen die doppelte Auszahlung: Der
        // Schluessel haengt an DIESER Zeile, also liefert ein zweiter Anlauf
        // beim Anbieter das vorhandene Ergebnis statt einer zweiten Zahlung.
        Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'swayy-refund-'.$refund->id));
    }

    /**
     * Ein Fehlversuch von vorgestern darf der Knopf nicht mehr anfassen.
     *
     * Der Wiederholungsschluessel beim Anbieter haelt 24 Stunden. Danach ist
     * er vergessen, und derselbe Aufruf loest eine ZWEITE echte Erstattung aus -
     * ausgerechnet in dem Fall, der die Zeile ueberhaupt auf 'failed' gesetzt
     * hat: Die Erstattung lief, nur die Antwort kam nicht an.
     */
    public function test_an_old_failed_refund_is_not_retried(): void
    {
        $setup = $this->createTenantSetup();
        $refund = Refund::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'provider' => 'stripe',
            'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'failed',
            'source' => 'staff', 'reason' => 'cancellation',
        ]);

        $refund->forceFill(['updated_at' => now()->subHours(2)])->saveQuietly();
        $this->assertTrue(app(RefundService::class)->reopen($refund->fresh()));

        // Ueber den Abfragebauer, nicht ueber das Modell: Das haelt in seinen
        // Attributen noch 'failed' und schriebe darum gar nichts.
        Refund::withoutGlobalScopes()->whereKey($refund->id)
            ->update(['status' => 'failed', 'updated_at' => now()->subHours(30)]);

        $this->assertFalse(app(RefundService::class)->reopen($refund->fresh()));
        $this->assertSame('failed', $refund->fresh()->status);
    }

    /**
     * Eine Erstattung, die gerade wirklich laeuft, darf niemand freigeben -
     * das ist der Weg in die zweite echte Auszahlung.
     */
    public function test_a_running_refund_is_not_reopened(): void
    {
        $setup = $this->createTenantSetup();
        $refund = Refund::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'provider' => 'stripe',
            'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'processing',
            'source' => 'staff', 'reason' => 'cancellation',
        ]);

        $this->assertFalse($refund->isStalled());
        $this->assertFalse(app(RefundService::class)->reopen($refund));
        $this->assertSame('processing', $refund->fresh()->status);
    }

    // ── Zwei Sitzungen, ein Vorgang ───────────────────────────────────────

    /**
     * Der Gast oeffnet den Bezahllink zweimal. Beim Anbieter entstehen zwei
     * bezahlbare Sitzungen; bezahlt er in der aelteren, endete der Rueckweg
     * vorher mit 403 - und ohne eingerichteten Webhook war das Geld kassiert
     * und im System nie angekommen.
     */
    public function test_a_return_from_the_earlier_session_is_still_accepted(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::sequence()
                ->push(['id' => 'cs_erste', 'url' => 'https://stripe.test/1'], 200)
                ->push(['id' => 'cs_zweite', 'url' => 'https://stripe.test/2'], 200),
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_erste', 'payment_status' => 'paid', 'payment_intent' => 'pi_1',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $this->clearTenantContext();

        $bezahllink = route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]);
        $this->get($bezahllink)->assertRedirect('https://stripe.test/1');
        $this->get($bezahllink)->assertRedirect('https://stripe.test/2');

        $intent = PaymentIntent::withoutGlobalScopes()->where('reservation_id', $reservation->id)->sole();
        $this->assertSame('cs_zweite', $intent->provider_intent_id);

        // Der Gast bezahlt in der ERSTEN, noch offenen Sitzung.
        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_erste')
            ->assertRedirect();

        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    // ── Zahlung auf eine tote Buchung ─────────────────────────────────────

    /**
     * Die Vorgabe ist refund_mode='off'. Vorher hiess das: Der Betrieb behaelt
     * das Geld, der Gast hat keinen Tisch, und ausser einem Auditeintrag
     * passiert nichts. Die Erstattungsregeln beschreiben aber die Kulanz bei
     * einer STORNIERUNG - hier gibt es gar keine Buchung mehr.
     */
    public function test_a_payment_on_a_dead_booking_lands_in_the_refund_list(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $setup['location']->settings()->update(['refund_mode' => 'off', 'refund_percent' => 50]);

        $reservation = $this->awaitingPayment($setup);
        $reservation->forceFill(['status' => ReservationStatus::Expired])->save();

        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_spaet', 'type' => 'deposit',
            'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->postWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => [
                'id' => 'cs_spaet', 'payment_status' => 'paid', 'payment_intent' => 'pi_spaet',
                'metadata' => ['payment_intent_id' => (string) $intent->id],
            ]],
        ]);

        $refund = Refund::withoutGlobalScopes()->where('reservation_id', $reservation->id)->first();
        $this->assertNotNull($refund, 'Es wurde keine Erstattung angelegt.');
        // Voller Betrag, nicht der Stornoprozentsatz: Der Gast bekaeme sonst
        // die Haelfte fuer eine Buchung, die es nie gab.
        $this->assertSame(4000, $refund->amount_minor);
        $this->assertSame('pending', $refund->status);
    }

    /**
     * Zwei Erstattungen zu je der Haelfte sind zusammen voll erstattet - und
     * eine dritte darf nicht mehr durchgehen.
     */
    public function test_refunds_never_exceed_what_was_paid(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_x', 'type' => 'deposit',
            'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'paid',
            'metadata' => ['refund_ref' => 'pi_x'],
        ]);

        $machen = fn (int $betrag) => Refund::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => null,
            'payment_intent_id' => $intent->id, 'provider' => 'stripe',
            'amount_minor' => $betrag, 'currency' => 'EUR', 'status' => 'approved',
            'source' => 'staff', 'reason' => 'cancellation',
        ]);

        $service = app(RefundService::class);

        $this->assertTrue($service->process($machen(2000)));
        $zweite = $machen(2000);
        $this->assertTrue($service->process($zweite));

        // Erst nach der zweiten Haelfte gilt der Vorgang als voll erstattet.
        $this->assertSame('refunded', $intent->fresh()->status);

        // Die dritte geht nicht mehr raus.
        $dritte = $machen(2000);
        $this->assertFalse($service->process($dritte));
        $this->assertSame('failed', $dritte->fresh()->status);
        Http::assertSentCount(2);
    }

    /**
     * Der verbuchte Betrag muss der kassierte sein.
     *
     * Zwei Aenderungen greifen ineinander: Ein wiederverwendeter Vorgang zieht
     * beim Umbuchen den neuen Betrag nach, und der Rueckweg nimmt jede
     * Sitzung an, die dieser Vorgang je gesehen hat. Wer in der aelteren, noch
     * offenen Sitzung ueber 10 Euro zahlt, haette damit eine Anzahlung ueber
     * 60 Euro abgeschlossen.
     */
    public function test_a_payment_over_the_wrong_amount_does_not_settle_the_booking(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_alt',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_1',
                // Der Gast hat in der alten Sitzung ueber 10 Euro gezahlt.
                'amount_total' => 1000,
                'currency' => 'eur',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_neu',
            'type' => 'deposit',
            // Am Vorgang stehen inzwischen 60 Euro.
            'amount_minor' => 6000, 'currency' => 'EUR', 'status' => 'pending',
            'metadata' => ['sessions' => ['cs_alt', 'cs_neu']],
        ]);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt')
            ->assertRedirect()
            ->assertSessionHas('payment_amount_mismatch');

        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.amount_mismatch']);
    }

    /**
     * Und das Geld bleibt nicht liegen.
     *
     * Der Abgleich verhinderte die falsche Verbuchung - mehr nicht. Kassiert
     * war der Betrag trotzdem: keine Erstattung, keine Meldung an den Betrieb,
     * nur eine Zeile im Auditlog, in die niemand sieht. Die Buchung verfiel
     * danach still an ihrer Frist, und der Gast hatte weder Tisch noch Geld.
     */
    public function test_a_payment_over_the_wrong_amount_is_refunded_and_reported(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_alt', 'payment_status' => 'paid', 'payment_intent' => 'pi_alt',
                'amount_total' => 1000, 'currency' => 'eur',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['owner_notification_email' => 'betrieb@example.test']);
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_neu',
            'type' => 'deposit',
            'amount_minor' => 6000, 'currency' => 'EUR', 'status' => 'pending',
            'metadata' => ['sessions' => ['cs_alt', 'cs_neu']],
        ]);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');

        $refund = Refund::withoutGlobalScopes()->sole();
        $this->assertSame(RefundService::MISMATCH, $refund->source);
        // Zurueck geht, was kassiert wurde - nicht, was am Vorgang steht.
        $this->assertSame(1000, $refund->amount_minor);
        $this->assertSame($reservation->id, $refund->reservation_id);
        // Und die Referenz auf die Belastung, ohne die der Anbieter nichts
        // zurueckzahlen kann.
        $this->assertSame('pi_alt', $intent->fresh()->metadata['refund_ref']);

        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('betrieb@example.test'));

        // Ein zweiter Anlauf - der Webhook kommt hinter dem Rueckweg an - legt
        // keine zweite Erstattung an.
        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');
        $this->assertSame(1, Refund::withoutGlobalScopes()->count());
    }

    /**
     * Der Hinweis muss auch ankommen.
     *
     * Er wurde in die Sitzung geschrieben, aber von keiner Ansicht ausgegeben -
     * der Gast sah eine unveraenderte Seite mit dem Knopf "Jetzt Anzahlung
     * bezahlen" und keinen Grund, warum seine Zahlung nicht gezaehlt hat.
     */
    public function test_the_mismatch_warning_is_shown_on_the_manage_page(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_alt', 'payment_status' => 'paid', 'payment_intent' => 'pi_alt',
                'amount_total' => 1000, 'currency' => 'eur',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_neu',
            'type' => 'deposit',
            'amount_minor' => 6000, 'currency' => 'EUR', 'status' => 'pending',
            'metadata' => ['sessions' => ['cs_alt', 'cs_neu']],
        ]);
        $this->clearTenantContext();

        $this->followingRedirects()
            ->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt')
            ->assertOk()
            ->assertSee('Der gezahlte Betrag passt nicht', false);
    }

    public function test_a_payment_over_the_right_amount_settles_normally(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_neu', 'payment_status' => 'paid', 'payment_intent' => 'pi_1',
                'amount_total' => 4000, 'currency' => 'eur',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_neu',
            'type' => 'deposit', 'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_neu')
            ->assertRedirect();

        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    /**
     * Eine fremde Kennung bleibt abgewiesen - aber nicht mehr stumm.
     */
    public function test_an_unknown_session_is_rejected_and_recorded(): void
    {
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_echt', 'type' => 'deposit',
            'amount_minor' => 4000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_fremd')
            ->assertForbidden();

        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.unknown_return_session']);
    }
}
