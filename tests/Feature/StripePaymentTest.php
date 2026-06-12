<?php

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StripePaymentTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const WEBHOOK_SECRET = 'whsec_testsecret';

    private function connectStripe(int $tenantId): void
    {
        IntegrationConnection::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'provider' => 'stripe',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'secret_key' => 'sk_test_123',
                'webhook_secret' => self::WEBHOOK_SECRET,
            ])),
        ]);
    }

    private function makePaidEventBooking(array $setup): EventBooking
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDays(7)->setTime(19, 0);
        $event = Event::withoutGlobalScope('tenant')->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'title' => 'Weinprobe',
            'slug' => 'weinprobe',
            'starts_at' => $start->utc(),
            'ends_at' => $start->addHours(4)->utc(),
            'capacity' => 20,
            'price_minor' => 5000,
            'currency' => 'EUR',
            'is_public' => true,
            'status' => 'published',
        ]);

        return EventBooking::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'event_id' => $event->id,
            'ticket_count' => 2,
            'guest_name' => 'Paula Pay',
            'guest_email' => 'paula@example.test',
            'status' => 'confirmed',
            'payment_status' => 'required',
            'amount_minor' => 10000,
        ]);
    }

    private function signedWebhook(array $payload): array
    {
        $body = json_encode($payload);
        $timestamp = now()->timestamp;
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, self::WEBHOOK_SECRET);

        return [$body, 't='.$timestamp.',v1='.$signature];
    }

    public function test_event_checkout_redirects_to_stripe_and_creates_intent(): void
    {
        Http::fake([
            'api.stripe.com/*' => Http::response([
                'id' => 'cs_test_abc',
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_abc',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup['tenant']->id);
        $booking = $this->makePaidEventBooking($setup);
        $this->clearTenantContext();

        $response = $this->get('/pay/event/'.$booking->code.'/'.$booking->manage_token);

        $response->assertRedirect('https://checkout.stripe.com/c/pay/cs_test_abc');

        $intent = PaymentIntent::withoutGlobalScopes()->first();
        $this->assertSame(10000, (int) $intent->amount_minor);
        $this->assertSame('cs_test_abc', $intent->provider_intent_id);
        $this->assertSame('pending', $booking->fresh()->payment_status);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'checkout/sessions')
            && $request['line_items[0][price_data][unit_amount]'] == 10000);
    }

    public function test_webhook_marks_event_booking_as_paid(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://stripe.test/x'], 200)]);
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup['tenant']->id);
        $booking = $this->makePaidEventBooking($setup);
        $this->clearTenantContext();

        $this->get('/pay/event/'.$booking->code.'/'.$booking->manage_token);
        $intent = PaymentIntent::withoutGlobalScopes()->first();

        [$body, $signature] = $this->signedWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_1', 'metadata' => ['payment_intent_id' => (string) $intent->id]]],
        ]);

        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame('paid', $booking->fresh()->payment_status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'payment.succeeded', 'tenant_id' => $setup['tenant']->id]);
    }

    public function test_webhook_with_invalid_signature_is_rejected(): void
    {
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_1', 'url' => 'https://stripe.test/x'], 200)]);
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup['tenant']->id);
        $booking = $this->makePaidEventBooking($setup);
        $this->clearTenantContext();

        $this->get('/pay/event/'.$booking->code.'/'.$booking->manage_token);
        $intent = PaymentIntent::withoutGlobalScopes()->first();

        $body = json_encode([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_1', 'metadata' => ['payment_intent_id' => (string) $intent->id]]],
        ]);

        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 't='.now()->timestamp.',v1=gefälscht',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertNotSame('paid', $booking->fresh()->payment_status);
    }

    public function test_paid_deposit_confirms_payment_pending_reservation(): void
    {
        Mail::fake();
        Http::fake(['api.stripe.com/*' => Http::response(['id' => 'cs_res', 'url' => 'https://stripe.test/y'], 200)]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup['tenant']->id);
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $start = CarbonImmutable::now('Europe/Berlin')->addDays(3)->setTime(19, 0);
        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'status' => ReservationStatus::PaymentPending,
            'payment_status' => 'required',
            'payment_amount_minor' => 6000,
            'currency' => 'EUR',
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->utc()->addMinutes(120),
            'guest_email_snapshot' => 'deposit@example.test',
        ]);
        $this->clearTenantContext();

        $this->get('/pay/reservation/'.$reservation->code.'/'.$reservation->manage_token)
            ->assertRedirect('https://stripe.test/y');

        $intent = PaymentIntent::withoutGlobalScopes()->first();

        [$body, $signature] = $this->signedWebhook([
            'type' => 'checkout.session.completed',
            'data' => ['object' => ['id' => 'cs_res', 'metadata' => ['payment_intent_id' => (string) $intent->id]]],
        ]);

        $this->call('POST', '/webhooks/stripe', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        $reservation->refresh();
        $this->assertSame('paid', $reservation->payment_status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);
    }

    public function test_checkout_without_stripe_integration_returns_404(): void
    {
        $setup = $this->createTenantSetup();
        $booking = $this->makePaidEventBooking($setup);
        $this->clearTenantContext();

        $this->get('/pay/event/'.$booking->code.'/'.$booking->manage_token)->assertNotFound();
    }

    public function test_checkout_with_wrong_token_returns_404(): void
    {
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup['tenant']->id);
        $booking = $this->makePaidEventBooking($setup);
        $this->clearTenantContext();

        $this->get('/pay/event/'.$booking->code.'/falscher-token')->assertNotFound();
    }
}
