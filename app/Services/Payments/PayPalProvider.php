<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use App\Support\PaymentReference;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * PayPal Orders v2 via REST, no SDK dependency. Card/PayPal data never touches
 * this system — the buyer approves on PayPal, we capture on return.
 *
 * Flow: createCheckout() creates an order (intent=CAPTURE) and returns the
 * approval URL. After the buyer approves and returns, captureOrder() finalises
 * the payment.
 */
class PayPalProvider implements PaymentProvider
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $secret,
        private readonly string $mode = 'live', // 'live' | 'sandbox'
    ) {}

    private function baseUrl(): string
    {
        return $this->mode === 'sandbox'
            ? 'https://api-m.sandbox.paypal.com'
            : 'https://api-m.paypal.com';
    }

    private function accessToken(): string
    {
        $response = Http::asForm()
            ->withBasicAuth($this->clientId, $this->secret)
            ->timeout(15)
            ->post($this->baseUrl().'/v1/oauth2/token', ['grant_type' => 'client_credentials']);

        if (! $response->successful()) {
            throw new RuntimeException('PayPal auth failed: '.substr($response->body(), 0, 300));
        }

        return (string) $response->json('access_token');
    }

    public function createCheckout(PaymentIntent $intent, array $options): array
    {
        $response = $this->postOrder($intent, $options, PaymentReference::invoice($intent));

        // PayPal meldet DUPLICATE_INVOICE_ID nur, wenn zu dieser Nummer bereits
        // kassiert wurde. Ein zweiter Anlauf ohne Rechnungsnummer – der
        // naheliegende Reflex – liesse den Gast ein zweites Mal zahlen und
        // höbe genau den Schutz auf, für den die Nummer da ist. Also
        // durchreichen statt umgehen.
        if (! $response->successful() && $this->isDuplicateInvoice($response->body())) {
            throw new PaymentAlreadySettledException(
                'PayPal kennt zu Vorgang '.$intent->id.' bereits eine abgeschlossene Zahlung.'
            );
        }

        if (! $response->successful()) {
            throw new RuntimeException('PayPal order creation failed: '.substr($response->body(), 0, 300));
        }

        $approve = collect($response->json('links', []))->firstWhere('rel', 'approve');

        return [
            'id' => (string) $response->json('id'),
            'url' => (string) ($approve['href'] ?? ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function postOrder(PaymentIntent $intent, array $options, ?string $invoiceId): Response
    {
        $einheit = array_filter([
            // Für den Käufer unsichtbar, steht aber im Transaktionsbericht und
            // in der API – das Filterkriterium für „stammt aus Swayy".
            'custom_id' => $options['reference'] ?? null,
            'invoice_id' => $invoiceId,
            'description' => mb_substr($options['description'], 0, 127),
            'amount' => [
                'currency_code' => strtoupper($intent->currency),
                'value' => number_format($intent->amount_minor / 100, 2, '.', ''),
            ],
            // Was auf dem Kontoauszug des Gastes erscheint. Ohne das steht dort
            // der Kontoname des Betreibers, was zu Rückfragen und im
            // schlimmsten Fall zu Rückbuchungen führt.
            'soft_descriptor' => $options['statement_name'] ?? null,
        ], fn ($wert) => $wert !== null && $wert !== '');

        return Http::withToken($this->accessToken())
            ->timeout(15)
            ->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [$einheit],
                'application_context' => [
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => $options['success_url'],
                    'cancel_url' => $options['cancel_url'],
                ],
            ]);
    }

    private function isDuplicateInvoice(string $body): bool
    {
        return str_contains($body, 'DUPLICATE_INVOICE_ID');
    }

    /**
     * Capture an approved order. Returns the capture id (needed for refunds)
     * when completed, or null on failure.
     */
    public function captureOrder(string $orderId): ?string
    {
        $response = Http::withToken($this->accessToken())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(15)
            ->post($this->baseUrl()."/v2/checkout/orders/{$orderId}/capture");

        if (! $response->successful() || $response->json('status') !== 'COMPLETED') {
            return null;
        }

        return $response->json('purchase_units.0.payments.captures.0.id');
    }

    /**
     * PayPal confirmation uses capture-on-return, not webhooks; this is not
     * wired to a webhook route. Implemented for interface completeness.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        return false;
    }

    public function refund(string $reference, int $amountMinor, string $currency): array
    {
        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->post($this->baseUrl()."/v2/payments/captures/{$reference}/refund", [
                'amount' => [
                    'value' => number_format($amountMinor / 100, 2, '.', ''),
                    'currency_code' => strtoupper($currency),
                ],
            ]);

        if (! $response->successful()) {
            return ['ok' => false, 'id' => null];
        }

        $status = (string) $response->json('status');

        return [
            'ok' => in_array($status, ['COMPLETED', 'PENDING'], true),
            'id' => (string) $response->json('id'),
        ];
    }
}
