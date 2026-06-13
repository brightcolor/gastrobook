<?php

declare(strict_types=1);

namespace App\Services\Payments;

use App\Models\PaymentIntent;
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
        $value = number_format($intent->amount_minor / 100, 2, '.', '');

        $response = Http::withToken($this->accessToken())
            ->timeout(15)
            ->post($this->baseUrl().'/v2/checkout/orders', [
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'custom_id' => (string) $intent->id,
                    'description' => mb_substr($options['description'], 0, 127),
                    'amount' => [
                        'currency_code' => strtoupper($intent->currency),
                        'value' => $value,
                    ],
                ]],
                'application_context' => [
                    'user_action' => 'PAY_NOW',
                    'shipping_preference' => 'NO_SHIPPING',
                    'return_url' => $options['success_url'],
                    'cancel_url' => $options['cancel_url'],
                ],
            ]);

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
     * Capture an approved order. Returns true when the payment completed.
     */
    public function captureOrder(string $orderId): bool
    {
        $response = Http::withToken($this->accessToken())
            ->withHeaders(['Content-Type' => 'application/json'])
            ->timeout(15)
            ->post($this->baseUrl()."/v2/checkout/orders/{$orderId}/capture");

        if (! $response->successful()) {
            return false;
        }

        return $response->json('status') === 'COMPLETED';
    }

    /**
     * PayPal confirmation uses capture-on-return, not webhooks; this is not
     * wired to a webhook route. Implemented for interface completeness.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        return false;
    }
}
