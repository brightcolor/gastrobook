<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\EventBooking;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentProviderManager;
use App\Services\ReservationLifecycleService;
use App\Services\WebhookDispatchService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentProviderManager $payments,
        private readonly ReservationLifecycleService $lifecycle,
        private readonly WebhookDispatchService $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start a Stripe Checkout for a paid event booking.
     */
    public function checkoutEventBooking(string $code, string $token)
    {
        $booking = EventBooking::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        abort_unless(hash_equals($booking->manage_token, $token), 404);
        abort_unless(in_array($booking->payment_status, ['required', 'pending'], true), 410);
        abort_if($booking->status === 'cancelled', 410);

        $event = $booking->event()->withoutGlobalScopes()->firstOrFail();
        $tenant = Tenant::findOrFail($booking->tenant_id);
        $provider = $this->payments->providerFor($tenant);
        abort_if($provider === null, 404, 'Keine Online-Zahlung konfiguriert.');

        $intent = PaymentIntent::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $booking->tenant_id,
                'event_booking_id' => $booking->id,
                'type' => 'prepayment',
                'status' => 'pending',
            ],
            [
                'provider' => 'stripe',
                'amount_minor' => $booking->amount_minor,
                'currency' => $event->currency,
                'expires_at' => now()->addHour(),
            ]
        );

        $manageUrl = route('events.manage', ['code' => $booking->code, 'token' => $booking->manage_token]);

        $session = $provider->createCheckout($intent, [
            'description' => $event->title.' – '.$booking->ticket_count.' Ticket(s)',
            'customer_email' => $booking->guest_email,
            'success_url' => $manageUrl.'?paid=1',
            'cancel_url' => $manageUrl,
        ]);

        $intent->update(['provider_intent_id' => $session['id']]);
        $booking->update(['payment_status' => 'pending']);

        $this->audit->log('payment.checkout_started', $intent, null, [
            'event_booking' => $booking->code, 'amount_minor' => $intent->amount_minor,
        ], null, null, $booking->tenant_id);

        return redirect()->away($session['url']);
    }

    /**
     * Start a Stripe Checkout for a reservation deposit/prepayment.
     */
    public function checkoutReservation(string $code, string $token)
    {
        $reservation = Reservation::withoutGlobalScope('tenant')->where('code', $code)->firstOrFail();
        abort_unless(hash_equals($reservation->manage_token, $token), 404);
        abort_unless(in_array($reservation->payment_status, ['required', 'pending'], true), 410);
        abort_unless($reservation->payment_amount_minor > 0, 410);

        $tenant = Tenant::findOrFail($reservation->tenant_id);
        $provider = $this->payments->providerFor($tenant);
        abort_if($provider === null, 404, 'Keine Online-Zahlung konfiguriert.');

        $intent = PaymentIntent::withoutGlobalScopes()->firstOrCreate(
            [
                'tenant_id' => $reservation->tenant_id,
                'reservation_id' => $reservation->id,
                'type' => 'deposit',
                'status' => 'pending',
            ],
            [
                'provider' => 'stripe',
                'amount_minor' => $reservation->payment_amount_minor,
                'currency' => $reservation->currency ?? 'EUR',
                'expires_at' => $reservation->payment_due_at ?? now()->addHour(),
            ]
        );

        $manageUrl = route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]);
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();

        $session = $provider->createCheckout($intent, [
            'description' => __('Anzahlung Reservierung :code – :location', [
                'code' => $reservation->code,
                'location' => $location?->name ?? '',
            ]),
            'customer_email' => $reservation->guest_email_snapshot,
            'success_url' => $manageUrl.'?paid=1',
            'cancel_url' => $manageUrl,
        ]);

        $intent->update(['provider_intent_id' => $session['id']]);
        $reservation->update(['payment_status' => 'pending']);

        $this->audit->log('payment.checkout_started', $intent, null, [
            'reservation' => $reservation->code, 'amount_minor' => $intent->amount_minor,
        ], null, null, $reservation->tenant_id);

        return redirect()->away($session['url']);
    }

    /**
     * Stripe webhook endpoint (CSRF-exempt). Tenant resolution happens via
     * the payment_intent_id in the session metadata; the signature is then
     * verified against THAT tenant's webhook secret before processing.
     */
    public function stripeWebhook(Request $request)
    {
        $payload = $request->getContent();
        $event = json_decode($payload, true);

        if (! is_array($event) || empty($event['type'])) {
            return response()->json(['error' => 'invalid payload'], 400);
        }

        $intentId = (int) ($event['data']['object']['metadata']['payment_intent_id'] ?? 0);
        if ($intentId === 0) {
            return response()->json(['received' => true]); // not ours
        }

        $intent = PaymentIntent::withoutGlobalScopes()->find($intentId);
        if ($intent === null) {
            return response()->json(['received' => true]);
        }

        $tenant = Tenant::find($intent->tenant_id);
        $provider = $tenant ? $this->payments->providerFor($tenant) : null;

        if ($provider === null
            || ! $provider->verifyWebhookSignature($payload, (string) $request->header('Stripe-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        match ($event['type']) {
            'checkout.session.completed' => $this->handlePaid($intent, $tenant, (string) ($event['data']['object']['id'] ?? '')),
            'checkout.session.expired' => $this->handleExpired($intent),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function handlePaid(PaymentIntent $intent, Tenant $tenant, string $sessionId): void
    {
        if ($intent->status === 'paid') {
            return; // idempotent
        }

        $intent->update(['status' => 'paid', 'provider_intent_id' => $sessionId ?: $intent->provider_intent_id]);

        if ($intent->event_booking_id) {
            EventBooking::withoutGlobalScopes()
                ->where('id', $intent->event_booking_id)
                ->update(['payment_status' => 'paid']);
        }

        if ($intent->reservation_id) {
            $reservation = Reservation::withoutGlobalScope('tenant')->find($intent->reservation_id);
            if ($reservation !== null) {
                $reservation->update(['payment_status' => 'paid']);
                if ($reservation->status === ReservationStatus::PaymentPending) {
                    $this->lifecycle->transition($reservation, ReservationStatus::Confirmed, null, 'system', 'payment_received');
                }
            }
        }

        $this->audit->log('payment.succeeded', $intent, null, [
            'amount_minor' => $intent->amount_minor,
        ], null, null, $intent->tenant_id);

        $this->webhooks->dispatch($tenant, 'payment.succeeded', [
            'payment_intent_id' => $intent->id,
            'amount_minor' => $intent->amount_minor,
            'currency' => $intent->currency,
            'type' => $intent->type,
        ]);
    }

    private function handleExpired(PaymentIntent $intent): void
    {
        if ($intent->status === 'pending') {
            $intent->update(['status' => 'expired']);
        }
    }
}
