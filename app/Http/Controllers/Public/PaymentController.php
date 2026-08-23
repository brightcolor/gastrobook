<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\EventBooking;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\Payments\PaymentAlreadySettledException;
use App\Services\Payments\PaymentProvider;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\PayPalProvider;
use App\Services\Payments\StripeProvider;
use App\Services\RefundService;
use App\Services\ReservationLifecycleService;
use App\Services\WebhookDispatchService;
use App\Support\PaymentReference;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        $intent = $this->openIntent(
            ['tenant_id' => $booking->tenant_id, 'event_booking_id' => $booking->id, 'type' => 'prepayment', 'status' => 'pending'],
            ['provider' => $key, 'amount_minor' => $booking->amount_minor, 'currency' => $event->currency, 'expires_at' => now()->addHour()]
        );
        $intent->update(['provider' => $key]);

        try {
            $session = $available[$key]->createCheckout($intent, [
                'description' => $event->title.' – '.$booking->ticket_count.' Ticket(s)',
                'customer_email' => $booking->guest_email,
                'success_url' => $this->successUrl($key, $intent, $manageUrl),
                'cancel_url' => $manageUrl,
                'reference' => PaymentReference::forBooking($tenant->slug, 'event', $booking->code),
                'statement_name' => PaymentReference::statementName($tenant->name),
            ]);
        } catch (PaymentAlreadySettledException $e) {
            return $this->alreadySettled($intent, $manageUrl, $booking->tenant_id);
        }

        $this->rememberSession($intent, (string) $session['id']);
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

        $intent = $this->openIntent(
            ['tenant_id' => $reservation->tenant_id, 'reservation_id' => $reservation->id, 'type' => 'deposit', 'status' => 'pending'],
            ['provider' => $key, 'amount_minor' => $reservation->payment_amount_minor, 'currency' => $currency, 'expires_at' => $reservation->payment_due_at ?? now()->addHour()]
        );
        // Ein wiederverwendeter Vorgang traegt den Betrag von damals. Nach einer
        // Umbuchung stimmt der nicht mehr - der Gast zahlte sonst die Anzahlung
        // fuer zwei Personen am Dienstag, obwohl zwoelf am Samstag dastehen.
        $intent->update([
            'provider' => $key,
            'amount_minor' => $reservation->payment_amount_minor,
            'currency' => $currency,
            'expires_at' => $reservation->payment_due_at ?? now()->addHour(),
        ]);

        $location = $reservation->location()->withoutGlobalScope('tenant')->first();

        try {
            $session = $available[$key]->createCheckout($intent, [
                'description' => __('Anzahlung Reservierung :code – :location', ['code' => $reservation->code, 'location' => $location?->name ?? '']),
                'customer_email' => $reservation->guest_email_snapshot,
                'success_url' => $this->successUrl($key, $intent, $manageUrl),
                'cancel_url' => $manageUrl,
                'reference' => PaymentReference::forBooking($tenant->slug, 'res', $reservation->code),
                // Der Standortname steht dem Gast näher als der Firmenname des
                // Mandanten – er hat beim Restaurant gebucht, nicht bei der
                // Betreibergesellschaft.
                'statement_name' => PaymentReference::statementName($location?->name ?? $tenant->name),
            ]);
        } catch (PaymentAlreadySettledException $e) {
            return $this->alreadySettled($intent, $manageUrl, $reservation->tenant_id);
        }

        $this->rememberSession($intent, (string) $session['id']);
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

        $this->abortOnUnknownSession($paymentIntent, $orderId, 'paypal');

        $tenant = Tenant::findOrFail($paymentIntent->tenant_id);
        $provider = $this->payments->provider($tenant, 'paypal');
        $manageUrl = $this->manageUrlFor($paymentIntent);

        if ($paymentIntent->status !== 'paid' && $provider instanceof PayPalProvider) {
            $capture = $provider->captureOrderDetails($orderId);
            if ($capture !== null) {
                // Derselbe Abgleich wie bei Stripe: Ein wiederverwendeter
                // Vorgang traegt nach einer Umbuchung einen anderen Betrag als
                // die Bestellung, die der Gast noch offen hatte.
                if (! $this->amountMatches($paymentIntent, $capture['amount_minor'], 'paypal', $capture['id'], $capture['currency'])) {
                    return redirect()->to($manageUrl)->with(
                        'payment_amount_mismatch',
                        __('Der gezahlte Betrag passt nicht zur hinterlegten Anzahlung. Wir erstatten ihn zurück und melden uns – bitte zahle nicht sofort erneut.')
                    );
                }

                // Store the capture id so the payment can later be refunded
                $paymentIntent->update(['metadata' => array_merge($paymentIntent->metadata ?? [], ['refund_ref' => $capture['id']])]);
                $this->handlePaid($paymentIntent, $tenant, $orderId);

                return redirect()->to($manageUrl.'?paid=1');
            }
        }

        return redirect()->to($manageUrl);
    }

    /**
     * Rückweg von Stripe. Schlägt die Sitzung direkt bei Stripe nach und
     * schließt die Zahlung selbst ab.
     *
     * Vorher zeigte diese Stelle nur `?paid=1` an und überließ alles dem
     * Webhook. War der bei Stripe nicht eingerichtet – was die Anwendung nicht
     * prüfen kann –, hatte der Gast bezahlt, sah eine Bestätigung, und die
     * Reservierung verfiel trotzdem nach Fristablauf.
     */
    public function stripeReturn(Request $request, int $intent)
    {
        $paymentIntent = PaymentIntent::withoutGlobalScopes()->findOrFail($intent);
        $sessionId = (string) $request->query('session_id');

        // Wie bei PayPal: Ohne die Sitzungskennung aus dem Ruecksprung geht
        // hier nichts. Sie ist nur dem Gast bekannt, der wirklich bezahlt hat.
        $this->abortOnUnknownSession($paymentIntent, $sessionId, 'stripe');

        $tenant = Tenant::findOrFail($paymentIntent->tenant_id);
        $provider = $this->payments->provider($tenant, 'stripe');
        $manageUrl = $this->manageUrlFor($paymentIntent);

        if ($paymentIntent->status !== 'paid' && $provider instanceof StripeProvider) {
            $session = $provider->fetchSession($sessionId);

            if ($session !== null && $session['paid']) {
                if (! $this->amountMatches($paymentIntent, $session['amount_minor'] ?? null, 'stripe', $session['charge_reference'] ?? null, $session['currency'] ?? null)) {
                    return redirect()->to($manageUrl)->with(
                        'payment_amount_mismatch',
                        __('Der gezahlte Betrag passt nicht zur hinterlegten Anzahlung. Wir erstatten ihn zurück und melden uns – bitte zahle nicht sofort erneut.')
                    );
                }

                $this->handlePaid($paymentIntent, $tenant, $sessionId, $session['charge_reference']);

                return redirect()->to($manageUrl.'?paid=1');
            }

            // Kein Abbruch: Stripe kann bei Lastschrift oder Sofortüberweisung
            // ein paar Sekunden brauchen. Dann greift der Webhook, und die
            // Verwaltungsseite zeigt den Stand.
            $this->audit->log('payment.return_not_yet_paid', $paymentIntent, null, [
                'provider' => 'stripe',
                'session_reachable' => $session !== null,
            ], null, null, $paymentIntent->tenant_id);
        }

        return redirect()->to($manageUrl.($paymentIntent->status === 'paid' ? '?paid=1' : ''));
    }

    /**
     * Höchstens so viele Sitzungskennungen je Vorgang. Wer öfter auf „Jetzt
     * bezahlen" klickt, hat ein anderes Problem als diese Grenze.
     */
    private const MAX_SESSIONS = 20;

    /**
     * Den offenen Zahlungsvorgang holen oder anlegen - genau einen.
     *
     * firstOrCreate ist ein SELECT gefolgt von einem INSERT und damit nicht
     * atomar. Zwei zeitgleiche Aufrufe desselben Bezahllinks - Doppelklick,
     * zwei Tabs - fanden beide nichts und legten beide an. Ergebnis: zwei
     * bezahlbare Sitzungen ueber denselben Betrag, und die Erstattung findet
     * die Doppelzahlung nicht, weil sie nur EINEN bezahlten Vorgang sucht.
     *
     * Der eindeutige Index auf payment_intents faengt das jetzt ab. Der zweite
     * Aufrufer laeuft in die Verletzung und nimmt die Zeile des ersten.
     *
     * @param  array<string, mixed>  $schluessel
     * @param  array<string, mixed>  $vorgaben
     */
    private function openIntent(array $schluessel, array $vorgaben): PaymentIntent
    {
        try {
            return PaymentIntent::withoutGlobalScopes()->firstOrCreate($schluessel, $vorgaben);
        } catch (UniqueConstraintViolationException) {
            return PaymentIntent::withoutGlobalScopes()->where($schluessel)->firstOrFail();
        }
    }

    /**
     * Die Kennung einer neu erzeugten Anbietersitzung merken.
     *
     * Ein Vorgang hat nur EIN Feld provider_intent_id, aber der Gast kann den
     * Bezahllink mehrfach oeffnen - jedes Mal entsteht beim Anbieter eine neue,
     * bezahlbare Sitzung. Wurde die Kennung schlicht ueberschrieben, endete der
     * Rueckweg aus der aelteren Sitzung mit 403, obwohl bezahlt wurde: Ohne
     * eingerichteten Webhook war das Geld kassiert und im System nie
     * angekommen, und die Reservierung verfiel nach Fristablauf.
     */
    private function rememberSession(PaymentIntent $intent, string $sessionId): void
    {
        $metadata = $intent->metadata ?? [];
        $bekannt = array_values(array_filter(
            array_map('strval', $metadata['sessions'] ?? []),
            fn (string $id) => $id !== '' && $id !== $sessionId
        ));
        $bekannt[] = $sessionId;
        $metadata['sessions'] = array_slice($bekannt, -self::MAX_SESSIONS);

        $intent->update([
            'provider_intent_id' => $sessionId,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Den Rueckweg abweisen, wenn die Sitzungskennung nicht zu diesem Vorgang
     * gehoert - aber nicht stumm: Ohne Eintrag faellt ein solcher Fall
     * niemandem auf, und genau dahinter kann eine kassierte, nie verbuchte
     * Zahlung stecken.
     */
    private function abortOnUnknownSession(PaymentIntent $intent, string $sessionId, string $provider): void
    {
        $metadata = $intent->metadata ?? [];
        $bekannt = array_map('strval', $metadata['sessions'] ?? []);
        $bekannt[] = (string) $intent->provider_intent_id;

        foreach ($bekannt as $id) {
            if ($sessionId !== '' && $id !== '' && hash_equals($id, $sessionId)) {
                return;
            }
        }

        $this->audit->log('payment.unknown_return_session', $intent, null, [
            'provider' => $provider,
            'session' => mb_substr($sessionId, 0, 64),
        ], null, null, $intent->tenant_id);

        abort(403);
    }

    /**
     * Der Anbieter kennt zu diesem Vorgang bereits eine abgeschlossene
     * Zahlung. Den Gast nicht noch einmal zur Kasse schicken – stattdessen
     * zurück auf seine Verwaltungsseite und den Betrieb im Auditlog
     * benachrichtigen, damit jemand nachsieht, wo das Geld geblieben ist.
     */
    private function alreadySettled(PaymentIntent $intent, string $manageUrl, int $tenantId)
    {
        $this->audit->log('payment.already_settled_at_provider', $intent, null, [
            'provider' => $intent->provider,
            'amount_minor' => $intent->amount_minor,
        ], null, null, $tenantId);

        return redirect()->to($manageUrl)->with(
            'payment_already_settled',
            __('Zu dieser Buchung liegt beim Zahlungsanbieter bereits eine Zahlung vor. Bitte zahle nicht erneut – wir prüfen das und melden uns.')
        );
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

    /**
     * Wohin der Anbieter den Gast nach dem Bezahlen zurückschickt.
     *
     * Beide Wege laufen über eine eigene Route, die die Zahlung beim Anbieter
     * nachschlägt und selbst abschließt. Der Webhook bleibt die zweite Spur
     * für den Fall, dass der Gast den Tab vorher schließt – aber er ist nicht
     * mehr die einzige.
     *
     * `{CHECKOUT_SESSION_ID}` ist ein Platzhalter, den Stripe beim Zurückleiten
     * durch die echte Sitzungskennung ersetzt.
     */
    private function successUrl(string $providerKey, PaymentIntent $intent, string $manageUrl): string
    {
        return match ($providerKey) {
            'paypal' => route('pay.paypal.return', ['intent' => $intent->id]),
            'stripe' => route('pay.stripe.return', ['intent' => $intent->id]).'?session_id={CHECKOUT_SESSION_ID}',
            default => $manageUrl.'?paid=1',
        };
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

        // Eigener Eintrag, damit die Einstellungsseite zeigen kann, ob der
        // Endpunkt in Stripe wirklich eingerichtet ist. `payment.succeeded`
        // taugt dafür nicht – den schreibt auch der Rückweg des Gastes.
        $this->audit->log('payment.webhook_received', $intent, null, [
            'provider' => 'stripe',
            'event' => $event['type'],
        ], null, null, $intent->tenant_id);

        $objekt = is_array($event['data']['object'] ?? null) ? $event['data']['object'] : [];

        match ($event['type']) {
            // `completed` heisst NICHT bezahlt. Bei Lastschrift und
            // Sofortueberweisung meldet Stripe die Sitzung sofort als
            // abgeschlossen, mit payment_status 'unpaid' - das Geld kommt Tage
            // spaeter oder gar nicht. Wer hier ohne Pruefung bucht, bestaetigt
            // einen Tisch fuer eine Zahlung, die nie ankommt, und kann sie
            // spaeter nicht einmal erstatten, weil sie nie als offen gilt.
            'checkout.session.completed',
            'checkout.session.async_payment_succeeded' => $this->handleCheckoutSession($intent, $tenant, $objekt),
            'checkout.session.async_payment_failed' => $this->handleAsyncFailed($intent, $objekt),
            'checkout.session.expired' => $this->handleExpired($intent, $objekt),
            default => null,
        };

        return response()->json(['received' => true]);
    }

    /**
     * Eine Checkout-Sitzung aus dem Webhook, aber nur wenn sie auch bezahlt ist.
     *
     * @param  array<string, mixed>  $objekt
     */
    private function handleCheckoutSession(PaymentIntent $intent, Tenant $tenant, array $objekt): void
    {
        if (($objekt['payment_status'] ?? null) !== 'paid') {
            $this->audit->log('payment.awaiting_settlement', $intent, null, [
                'provider' => 'stripe',
                'payment_status' => $objekt['payment_status'] ?? null,
            ], null, null, $intent->tenant_id);

            return;
        }

        $betrag = $objekt['amount_total'] ?? null;
        $belastung = $objekt['payment_intent'] ?? null;
        if (! $this->amountMatches($intent, is_numeric($betrag) ? (int) $betrag : null, 'stripe', is_string($belastung) ? $belastung : null, is_string($objekt['currency'] ?? null) ? $objekt['currency'] : null)) {
            return;
        }

        $this->handlePaid(
            $intent,
            $tenant,
            (string) ($objekt['id'] ?? ''),
            $objekt['payment_intent'] ?? null,
        );
    }

    /**
     * Stimmt der kassierte Betrag mit dem ueberein, der am Vorgang steht?
     *
     * Ein Vorgang wird wiederverwendet und sein Betrag beim Umbuchen
     * nachgezogen, und der Rueckweg nimmt jede Sitzung an, die dieser Vorgang
     * je gesehen hat. Beides zusammen liess eine aeltere Sitzung ueber 10 Euro
     * eine Anzahlung ueber 60 Euro abschliessen - die Buchung galt als voll
     * bezahlt, und eine spaetere Erstattung haette den falschen Betrag
     * zurueckgezahlt.
     *
     * Meldet der Anbieter keinen Betrag, wird nicht blockiert: Dann ist die
     * Pruefung nicht moeglich, und ein stiller Abbruch waere schlimmer als die
     * fehlende Kontrolle.
     *
     * Abgewiesen heisst nicht folgenlos: Das Geld ist kassiert. Vorher endete
     * dieser Weg mit einer Zeile im Auditlog - keine Erstattung, keine Meldung
     * an den Betrieb, und die Buchung verfiel still an ihrer Zahlungsfrist.
     */
    private function amountMatches(PaymentIntent $intent, ?int $charged, string $provider, ?string $chargeRef = null, ?string $currency = null): bool
    {
        // Die Waehrung gehoert dazu: 60 ist nicht 60, wenn das eine Euro und
        // das andere Zloty sind. Der Vergleich lief bisher nur ueber die Zahl.
        $waehrungPasst = $currency === null
            || mb_strtoupper($currency) === mb_strtoupper((string) $intent->currency);

        if ($charged === null || ($charged === (int) $intent->amount_minor && $waehrungPasst)) {
            return true;
        }

        $this->audit->log('payment.amount_mismatch', $intent, null, [
            'provider' => $provider,
            'expected_minor' => (int) $intent->amount_minor,
            'charged_minor' => $charged,
            'expected_currency' => (string) $intent->currency,
            'charged_currency' => $currency,
        ], null, null, $intent->tenant_id);

        $this->refunds->requestForMismatch($intent, $charged, $chargeRef);

        return false;
    }

    /**
     * Die verzoegerte Zahlung ist gescheitert. Der Vorgang wird wieder
     * geoeffnet, damit der Gast einen zweiten Anlauf nehmen kann - und damit
     * der Fristablauf den Tisch wieder freigeben darf.
     *
     * @param  array<string, mixed>  $objekt
     */
    private function handleAsyncFailed(PaymentIntent $intent, array $objekt): void
    {
        if ($intent->status === 'paid') {
            // Verbucht ist verbucht. Das gehoert auf den Tisch des Betriebs,
            // nicht in einen automatischen Rueckbau.
            $this->audit->log('payment.async_failed_after_settlement', $intent, null, [
                'provider' => 'stripe',
                'session' => $objekt['id'] ?? null,
            ], null, null, $intent->tenant_id);

            return;
        }

        // Nur ein noch OFFENER Vorgang wird auf gescheitert gesetzt. "Alles
        // ausser bezahlt" traf auch 'refunded' und 'partially_refunded' - eine
        // erstattete Zahlung stuende danach als Fehlversuch da, und
        // reopenPayment stellte die Buchung wieder auf "Zahlung offen".
        $beansprucht = PaymentIntent::withoutGlobalScopes()
            ->whereKey($intent->id)
            ->where('status', 'pending')
            ->update(['status' => 'failed']);

        if ($beansprucht === 0) {
            return;
        }

        $this->reopenPayment($intent);

        $this->audit->log('payment.async_failed', $intent, null, [
            'provider' => 'stripe',
            'session' => $objekt['id'] ?? null,
        ], null, null, $intent->tenant_id);
    }

    private function handlePaid(PaymentIntent $intent, Tenant $tenant, string $sessionId, ?string $refundRef = null): void
    {
        $updates = ['status' => 'paid', 'provider_intent_id' => $sessionId ?: $intent->provider_intent_id];
        if ($refundRef) {
            // Stripe payment_intent id, needed to issue a refund later
            $updates['metadata'] = array_merge($intent->metadata ?? [], ['refund_ref' => $refundRef]);
        }

        // Genau einmal, und zwar wirklich: Seit der Rückweg des Gastes die
        // Zahlung selbst abschliesst, laufen hier ZWEI Schreiber gegeneinander –
        // der Browser des Gastes und der Webhook des Anbieters, im Normalfall
        // sekundengleich. Ein gelesener Status ist bis zum Schreiben längst
        // veraltet; das Fenster ist der HTTP-Roundtrip zum Anbieter breit.
        // Ohne diese Sperre entstünden zwei Statuswechsel, zwei ausgehende
        // Webhooks und zwei Bestätigungsmails an denselben Gast.
        $beansprucht = PaymentIntent::withoutGlobalScopes()
            ->whereKey($intent->id)
            ->where('status', '!=', 'paid')
            ->update($updates);

        if ($beansprucht === 0) {
            return;
        }

        $intent->refresh();

        if ($intent->event_booking_id) {
            // Unter Sperre lesen und schreiben, wie bei der Reservierung: Der
            // Rueckweg des Gastes und der Webhook des Anbieters laufen
            // sekundengleich, und eine Absage kann dazwischen liegen. Ohne
            // Sperre las dieser Zweig einen Stand von vorher - eine Absage
            // unmittelbar vor der Zahlung galt dann als gar nicht passiert,
            // und die Buchung stand bezahlt da, ohne Erstattung und ohne
            // Meldung.
            $booking = DB::transaction(function () use ($intent) {
                $booking = EventBooking::withoutGlobalScopes()->lockForUpdate()->find($intent->event_booking_id);

                $booking?->update(['payment_status' => 'paid']);

                return $booking;
            });

            if ($booking !== null) {
                // Dieselbe Behandlung wie bei der Reservierung: Eine Zahlung
                // auf eine abgesagte Buchung ist gegenstandslos. Vorher galt
                // sie stillschweigend als bezahlt - keine Erstattung, keine
                // Meldung, kein Eintrag.
                if ($booking->status === 'cancelled') {
                    $this->refunds->requestForEventBooking($booking, 'late_payment_auto_refund');
                    $this->audit->log('payment.late_on_inactive_reservation', $booking, null, [
                        'booking_status' => $booking->status,
                        'payment_intent_id' => $intent->id,
                        'amount_minor' => $intent->amount_minor,
                    ], null, null, $intent->tenant_id);
                }
            }
        }

        if ($intent->reservation_id) {
            // Unter Sperre lesen und schreiben: Der Fristablauf arbeitet auf
            // derselben Zeile. Ohne gemeinsame Sperre beanspruchten die beiden
            // Schreiber nie dasselbe Objekt - der spaetere gewann, und bei
            // ungluecklicher Reihenfolge stand "verfallen" auf einer bezahlten
            // Buchung.
            // Der Zahlungsstand zuerst, unter Sperre und fuer sich. Er ist die
            // Schutzmarke: Der Fristablauf liest ihn unter derselben Sperre und
            // laesst jede Buchung stehen, an der Geld haengt. Vorher stand die
            // Buchung dazwischen auf "wartet auf Zahlung" UND "bezahlt" und
            // wurde in genau diesem Spalt auf "verfallen" geschrieben.
            $reservation = DB::transaction(function () use ($intent) {
                $reservation = Reservation::withoutGlobalScopes()->lockForUpdate()->find($intent->reservation_id);

                if ($reservation === null) {
                    return null;
                }

                $reservation->update(['payment_status' => 'paid']);

                return $reservation;
            });

            if ($reservation !== null) {
                if ($reservation->status === ReservationStatus::PaymentPending) {
                    // Der Statuswechsel steht BEWUSST hinter dem Commit: Er
                    // verschickt Mails und Webhooks, und ein Fehler dabei darf
                    // den verbuchten Zahlungsstand nicht mitreissen. Innerhalb
                    // der Transaktion abgefangen brauchte es das gar nicht -
                    // ein Datenbankfehler bricht sie ohnehin ganz ab, und der
                    // Commit scheiterte danach trotzdem.
                    try {
                        $this->lifecycle->transition($reservation, ReservationStatus::Confirmed, null, 'system', 'payment_received');
                    } catch (\Throwable $e) {
                        report($e);
                        // Nur die Fehlerklasse, nicht die Meldung: Ein
                        // Datenbankfehler traegt die eingesetzten Werte im
                        // Text - im Zweifel Name, Mailadresse und Telefonnummer
                        // des Gastes. Die Einzelheiten stehen im Anwendungslog.
                        $this->audit->log('payment.confirm_failed', $reservation, null, [
                            'payment_intent_id' => $intent->id,
                            'error' => $e::class,
                        ], null, null, $intent->tenant_id);
                    }
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

    /**
     * @param  array<string, mixed>  $objekt
     */
    private function handleExpired(PaymentIntent $intent, array $objekt = []): void
    {
        // Nur die AKTUELLE Sitzung zaehlt. Ein Vorgang kennt mehrere - der Gast
        // hat den Bezahllink zweimal geoeffnet. Die erste laeuft nach einer
        // Stunde ab, waehrend die zweite noch offen und bezahlbar ist; ohne
        // diese Pruefung riss der Ablauf der alten den ganzen Vorgang und die
        // Buchung mit sich.
        $gemeldet = (string) ($objekt['id'] ?? '');
        if ($gemeldet !== '' && $gemeldet !== (string) $intent->provider_intent_id) {
            $this->audit->log('payment.stale_session_expired', $intent, null, [
                'provider' => 'stripe',
                'session' => mb_substr($gemeldet, 0, 64),
            ], null, null, $intent->tenant_id);

            return;
        }

        $beansprucht = PaymentIntent::withoutGlobalScopes()
            ->whereKey($intent->id)
            ->where('status', 'pending')
            ->update(['status' => 'expired']);

        if ($beansprucht === 1) {
            $this->reopenPayment($intent);
        }
    }

    /**
     * Die Zahlung wieder als offen kennzeichnen.
     *
     * Der Start des Bezahlvorgangs setzt `payment_status` auf 'pending'. Bis
     * hierher gab es keinen einzigen Weg zurueck: Schloss der Gast den Tab bei
     * Stripe, blieb der Wert fuer immer stehen. Stornieren wies ihn danach
     * dauerhaft mit "Deine Zahlung wird gerade verarbeitet" ab, und der
     * Fristablauf griff bei der Vorgabe ohne Auto-Storno auch nicht - der
     * Tisch blieb bis zum Termin gesperrt, und der Gast bekam am Ende noch die
     * Erinnerungsmail zu einer Buchung, die er loswerden wollte.
     */
    private function reopenPayment(PaymentIntent $intent): void
    {
        if ($intent->reservation_id) {
            Reservation::withoutGlobalScopes()
                ->whereKey($intent->reservation_id)
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'required']);
        }

        if ($intent->event_booking_id) {
            EventBooking::withoutGlobalScopes()
                ->whereKey($intent->event_booking_id)
                ->where('payment_status', 'pending')
                ->update(['payment_status' => 'required']);
        }
    }
}
