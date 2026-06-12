<?php

namespace App\Services\Payments;

use App\Models\PaymentIntent;

/**
 * Payment provider abstraction (Stripe, Mollie, …).
 * Card data NEVER touches our system — providers host the checkout,
 * we only store provider references and status.
 */
interface PaymentProvider
{
    /**
     * Create a hosted checkout session.
     *
     * @param array{
     *     description: string, customer_email?: ?string,
     *     success_url: string, cancel_url: string, quantity?: int,
     * } $options
     * @return array{id: string, url: string}
     */
    public function createCheckout(PaymentIntent $intent, array $options): array;

    /**
     * Verify a webhook payload signature.
     */
    public function verifyWebhookSignature(string $payload, string $signatureHeader): bool;
}
