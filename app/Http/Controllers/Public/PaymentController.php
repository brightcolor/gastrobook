<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\EventBooking;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\PayPalProvider;
use App\Services\RefundService;
use App\Services\ReservationLifecycleService;
use App\Services\WebhookDispatchService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentProviderManager $payments,
        private readonly ReservationLifecycleService $lifecycle,
        private readonly RefundService $refunds,
        private readonly WebhookDispatchService $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Start checkout for a paid event booking. Shows a provider chooser when
     * more than one payment method is configured.
     */
    public function checkoutEventBooking(Request $request, string $code, string $token)
    {
        $booking = EventBooking::withoutGlobalScopes()->where('code', $code)->firstOrFail();
        abort_unless(hash_equals($booking->manage_token, $token), 404);
        abort_unless(in_array($booking->payment_status, ['required', 'pending'], true), 410);
        abort_if($booking->status === 'cancelled', 410);

        $event = $booking->event()->withoutGlobalScopes()->firstOrFail();
        $tenant = Tenant::findOrFail($booking->tenant_id);
        $manageUrl = route('events.manage', ['code' => $booking->code, 'token' => $booking->manage_token]);

        $available = $this->payments->available($tenant);
        abort_if($available === [], 404, 'Keine Online-Zahlung konfiguriert.');
        $key = $this->chosenProviderKey($available, $request);
        if ($key === null) {
            return $this->providerChooser($available, route('pay.event', ['code' => $code, 'token' => $token]), $manageUrl, $booking->amount_minor, $event->currency);
        }

        $intent = PaymentIntent::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $booking->tenant_id, 'event_booking_id' => $booking->id, 'type' => 'prepayment', 'status' => 'pending'],
            ['provider' => $key, 'amount_minor' => $booking->amount_minor, 'currency' => $event->currency, 'expires_at' => now()->addHour()]
        );
        $intent->update(['provider' => $key]);

        $session = $available[$key]->createCheckout($intent, [
            'description' => $event->title.' – '.$booking->ticket_count.' Ticket(s)',
            'customer_email' => $booking->guest_email,
            'success_url' => $this->successUrl($key, $intent, $manageUrl),
            'cancel_url' => $manageUrl,
        ]);

        $intent->update(['provider_intent_id' => $session['id']]);
        $booking->update(['payment_status' => 'pending']);

        $this->audit->log('payment.checkout_started', $intent, null, [
            'event_booking' => $booking->code, 'provider' => $key, 'amount_minor' => $intent->amount_minor,
        ], null, null, $booking->tenant_id);

        return redirect()->away($session['url']);
    }

    /**
     * Start checkout for a reservation deposit/prepayment. Shows a provider
     * chooser when more than one payment method is configured.
     */
    public function checkoutReservation(Request $request, string $code, string $token)
    {
        $reservation = Reservation::withoutGlobalScope('tenant')->where('code', $code)->firstOrFail();
        abort_unless(hash_equals($reservation->manage_token, $token), 404);
        abort_unless(in_array($reservation->payment_status, ['required', 'pending'], true), 410);
        abort_unless($reservation->payment_amount_minor > 0, 410);
        abort_unless($reservation->guest_email_snapshot !== null, 422);

        $tenant = Tenant::findOrFail($reservation->tenant_id);
        $currency = $reservation->currency ?? 'EUR';
        $manageUrl = route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]);

        $available = $this->payments->available($tenant);
        abort_if($available === [], 404, 'Keine Online-Zahlung konfiguriert.');
        $key = $this->chosenProviderKey($available, $request);
        if ($key === null) {
            return $this->providerChooser($available, route('pay.reservation', ['code' => $code, 'token' => $token]), $manageUrl, $reservation->payment_amount_minor, $currency);
        }

        $intent = PaymentIntent::withoutGlobalScopes()->firstOrCreate(
            ['tenant_id' => $reservation->tenant_id, 'reservation_id' => $reservation->id, 'type' => 'deposit', 'status' => 'pending'],
            ['provider' => $key, 'amount_minor' => $reservation->payment_amount_minor, 'currency' => $currency, 'expires_at' => $reservation->payment_due_at ?? now()->addHour()]
        );
        $intent->update(['provider' => $key]);

        $location = $reservation->location()->withoutGlobalScope('tenant')->first();

        $session = $available[$key]->createCheckout($intent, [
            'description' => __('Anzahlung Reservierung :code – :location', ['code' => $reservation->code, 'location' => $location?->name ?? '']),
            'customer_email' => $reservation->guest_email_snapshot,
            'success_url' => $this->successUrl($key, $intent, $manageUrl),
            'cancel_url' => $manageUrl,
        ]);

        $intent->update(['provider_intent_id' => $session['id']]);
        $reservation->update(['payment_status' => 'pending']);

        $this->audit->log('payment.checkout_started', $intent, null, [
            'reservation' => $reservation->code, 'provider' => $key, 'amount_minor' => $intent->amount_minor,
        ], null, null, $reservation->tenant_id);

        return redirect()->away($session['url']);
    }

    /**
     * PayPal return handler: captures the approved order, then finalises.
     */
    public function paypalReturn(Request $request, int $intent)
    {
        $paymentIntent = PaymentIntent::withoutGlobalScopes()->findOrFail($intent);
        $orderId = (string) $request->query('token'); // PayPal appends the order id

        abort_unless($orderId !== '' && hash_equals((string) $paymentIntent->provider_intent_id, $orderId), 403);

        $tenant = Tenant::findOrFail($paymentIntent->tenant_id);
        $provider = $this->payments->provider($tenant, 'paypal');
        $manageUrl = $this->manageUrlFor($paymentIntent);

        if ($paymentIntent->status !== 'paid' && $provider instanceof PayPalProvider) {
            $captureId = $provider->captureOrder($orderId);
            if ($captureId !== null) {
                // Store the capture id so the payment can later be refunded
                $paymentIntent->update(['metadata' => array_merge($paymentIntent->metadata ?? [], ['refund_ref' => $captureId])]);
                $this->handlePaid($paymentIntent, $tenant, $orderId);

                return redirect()->to($manageUrl.'?paid=1');
            }
        }

        return redirect()->to($manageUrl);
    }

    /**
     * @param  array<string, PaymentProvider>  $available
     */
    private function chosenProviderKey(array $available, Request $request): ?string
    {
        $key = $request->query('provider');
        if (is_string($key) && isset($available[$key])) {
            return $key;
        }

        return count($available) === 1 ? (string) array_key_first($available) : null;
    }

    /**
     * @param  array<string, PaymentProvider>  $available
     */
    private function providerChooser(array $available, string $payUrl, string $cancelUrl, int $amountMinor, string $currency)
    {
        $labels = ['stripe' => 'Kreditkarte (Stripe)', 'paypal' => 'PayPal'];
        $options = [];
        foreach (array_keys($available) as $providerKey) {
            $options[] = [
                'key' => $providerKey,
                'label' => $labels[$providerKey] ?? ucfirst($providerKey),
                'url' => $payUrl.'?provider='.$providerKey,
            ];
        }

        return view('public.pay-select', [
            'options' => $options,
            'cancel_url' => $cancelUrl,
            'amount' => number_format($amountMinor / 100, 2, ',', '.').' '.$currency,
        ]);
    }

    private function successUrl(string $providerKey, PaymentIntent $intent, string $manageUrl): string
    {
        return $providerKey === 'paypal'
            ? route('pay.paypal.return', ['intent' => $intent->id])
            : $manageUrl.'?paid=1';
    }

    private function manageUrlFor(PaymentIntent $intent): string
    {
        if ($intent->reservation_id) {
            $reservation = Reservation::withoutGlobalScope('tenant')->findOrFail($intent->reservation_id);

            return route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]);
        }

        $booking = EventBooking::withoutGlobalScopes()->findOrFail($intent->event_booking_id);

        return route('events.manage', ['code' => $booking->code, 'token' => $booking->manage_token]);
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
        $provider = $tenant ? $this->payments->provider($tenant, 'stripe') : null;

        if ($provider === null
            || ! $provider->verifyWebhookSignature($payload, (string) $request->header('Stripe-Signature'))) {
            return response()->json(['error' => 'invalid signature'], 400);
        }

        match ($event['type']) {
            'checkout.session.completed' => $this->handlePaid(
                $intent,
                $tenant,
                (string) ($event['data']['object']['id'] ?? ''),
                $event['data']['object']['payment_intent'] ?? null,
            ),
            'checkout.session.expired' => $this->handleExpired($intent),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    private function handlePaid(PaymentIntent $intent, Tenant $tenant, string $sessionId, ?string $refundRef = null): void
    {
        if ($intent->status === 'paid') {
            return; // idempotent
        }

        $updates = ['status' => 'paid', 'provider_intent_id' => $sessionId ?: $intent->provider_intent_id];
        if ($refundRef) {
            // Stripe payment_intent id, needed to issue a refund later
            $updates['metadata'] = array_merge($intent->metadata ?? [], ['refund_ref' => $refundRef]);
        }
        $intent->update($updates);

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
                } elseif (! $reservation->status->isActive()) {
                    // Late webhook: payment arrived after the reservation was cancelled,
                    // rejected, or expired. We must not resurrect a terminal reservation.
                    // Automatically queue a refund so the guest gets their money back
                    // without manual intervention. The soft-deleted / terminal reservation
                    // still exists in the DB and carries the PaymentIntent reference.
                    $this->refunds->requestForReservation($reservation, 'late_payment_auto_refund');
                    $this->audit->log('payment.late_on_inactive_reservation', $reservation, null, [
                        'reservation_status' => $reservation->status->value,
                        'payment_intent_id' => $intent->id,
                        'amount_minor' => $intent->amount_minor,
                    ], null, null, $intent->tenant_id);
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
