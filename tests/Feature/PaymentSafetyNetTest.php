<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\ExpireUnpaidReservations;
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
