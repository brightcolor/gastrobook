<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe Checkout via REST API (form-encoded), no SDK dependency.
 * Only provider references are persisted — never card data.
 */
class StripeProvider implements PaymentProvider
{
    private const API_BASE = 'https://api.stripe.com/v1';

    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    public function __construct(
        private readonly string $secretKey,
        private readonly string $webhookSecret,
    ) {}

    public function createCheckout(PaymentIntent $intent, array $options): array
    {
        $response = Http::asForm()
            ->withToken($this->secretKey)
            ->timeout(15)
            ->post(self::API_BASE.'/checkout/sessions', array_filter([
                'mode' => 'payment',
                'line_items[0][price_data][currency]' => strtolower($intent->currency),
                'line_items[0][price_data][product_data][name]' => $options['description'],
                'line_items[0][price_data][unit_amount]' => $intent->amount_minor,
                'line_items[0][quantity]' => 1,
                'customer_email' => $options['customer_email'] ?? null,
                'success_url' => $options['success_url'],
                'cancel_url' => $options['cancel_url'],
                'metadata[payment_intent_id]' => (string) $intent->id,
                'expires_at' => now()->addMinutes(60)->timestamp,
            ]));

        if (! $response->successful()) {
            throw new RuntimeException('Stripe checkout creation failed: '.substr($response->body(), 0, 500));
        }

        return [
            'id' => (string) $response->json('id'),
            'url' => (string) $response->json('url'),
        ];
    }

    /**
     * Stripe-Signature: t=<timestamp>,v1=<hmac sha256 of "t.payload">
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $signatureHeader) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') {
                $timestamp = (int) $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(now()->timestamp - $timestamp) > self::SIGNATURE_TOLERANCE_SECONDS) {
            return false; // replay protection
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $this->webhookSecret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    public function refund(string $reference, int $amountMinor, string $currency): array
    {
        $response = Http::asForm()
            ->withToken($this->secretKey)
            ->timeout(15)
            ->post(self::API_BASE.'/refunds', [
                'payment_intent' => $reference,
                'amount' => $amountMinor,
            ]);

        if (! $response->successful()) {
            return ['ok' => false, 'id' => null];
        }

        $status = (string) $response->json('status');

        return [
            'ok' => in_array($status, ['succeeded', 'pending'], true),
            'id' => (string) $response->json('id'),
        ];
    }
}
