<?php

declare(strict_types=1);

namespace App\Services\Payments;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin GoCardless (Bacs/SEPA) REST client for SaaS subscription billing.
 *
 * Uses the platform-level access token (config/services.gocardless) – the SaaS
 * operator's single GoCardless account collects from all tenants. Flow:
 *  1. createRedirectFlow() → hosted page where the customer authorises a mandate
 *  2. completeRedirectFlow() → on return, yields the mandate + customer id
 *  3. createSubscription() → recurring monthly charge against the mandate
 *  4. cancelSubscription()/cancelMandate() → stop billing
 */
class GoCardlessService
{
    private const VERSION = '2015-07-06';

    public function configured(): bool
    {
        return ! empty(config('services.gocardless.access_token'));
    }

    private function baseUrl(): string
    {
        return config('services.gocardless.environment') === 'live'
            ? 'https://api.gocardless.com'
            : 'https://api-sandbox.gocardless.com';
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl())
            ->timeout(20)
            ->withHeaders([
                'Authorization' => 'Bearer '.config('services.gocardless.access_token'),
                'GoCardless-Version' => self::VERSION,
                'Accept' => 'application/json',
            ]);
    }

    /**
     * Create a redirect flow. Returns the hosted authorisation URL + flow id.
     *
     * @param  array<string,string|null>  $prefill
     * @return array{id: string, redirect_url: string}
     */
    public function createRedirectFlow(string $sessionToken, string $successUrl, string $description, array $prefill = []): array
    {
        $customer = array_filter([
            'email' => $prefill['email'] ?? null,
            'given_name' => $prefill['given_name'] ?? null,
            'family_name' => $prefill['family_name'] ?? null,
            'company_name' => $prefill['company_name'] ?? null,
            'address_line1' => $prefill['address_line1'] ?? null,
            'city' => $prefill['city'] ?? null,
            'postal_code' => $prefill['postal_code'] ?? null,
            'country_code' => $prefill['country_code'] ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->client()->post('/redirect_flows', [
            'redirect_flows' => array_filter([
                'description' => $description,
                'session_token' => $sessionToken,
                'success_redirect_url' => $successUrl,
                'prefilled_customer' => $customer !== [] ? $customer : null,
            ], fn ($v) => $v !== null),
        ])->throw()->json('redirect_flows');

        return [
            'id' => (string) $response['id'],
            'redirect_url' => (string) $response['redirect_url'],
        ];
    }

    /**
     * Complete the flow after the customer returns. Yields mandate + customer ids.
     *
     * @return array{mandate_id: string, customer_id: ?string}
     */
    public function completeRedirectFlow(string $redirectFlowId, string $sessionToken): array
    {
        $flow = $this->client()
            ->post("/redirect_flows/{$redirectFlowId}/actions/complete", [
                'data' => ['session_token' => $sessionToken],
            ])
            ->throw()
            ->json('redirect_flows');

        return [
            'mandate_id' => (string) ($flow['links']['mandate'] ?? ''),
            'customer_id' => isset($flow['links']['customer']) ? (string) $flow['links']['customer'] : null,
        ];
    }

    /**
     * Create a recurring monthly subscription against a mandate. Returns its id.
     */
    /**
     * Ein laufendes Abo zu diesem Mandat, falls es eines gibt.
     *
     * Ein Abo ist ein monatlicher Einzug – zwei davon heissen doppelt
     * abgebucht, und das zweite taucht in der Anwendung nirgends auf, ist also
     * auch nicht kuendbar. Vor dem Anlegen wird darum nachgesehen.
     */
    public function activeSubscriptionFor(string $mandateId): ?string
    {
        $subscriptions = $this->client()
            ->get('/subscriptions', ['mandate' => $mandateId, 'limit' => 50])
            ->throw()
            ->json('subscriptions') ?? [];

        foreach ($subscriptions as $subscription) {
            if (! is_array($subscription) || empty($subscription['id'])) {
                continue;
            }
            if (in_array($subscription['status'] ?? '', ['customer_approval_denied', 'cancelled', 'finished'], true)) {
                continue;
            }

            return (string) $subscription['id'];
        }

        return null;
    }

    /**
     * @param  string|null  $idempotencyKey  verhindert, dass ein Wiederholungs-
     *                                       versuch ein zweites Abo anlegt
     */
    public function createSubscription(string $mandateId, int $amountMinor, string $currency, string $name, ?string $idempotencyKey = null): string
    {
        $antwort = $this->client()
            ->when($idempotencyKey !== null, fn (PendingRequest $r) => $r->withHeaders(['Idempotency-Key' => $idempotencyKey]))
            ->post('/subscriptions', [
                'subscriptions' => [
                    'amount' => $amountMinor,
                    'currency' => strtoupper($currency),
                    'interval_unit' => 'monthly',
                    'day_of_month' => 1,
                    'name' => mb_substr($name, 0, 100),
                    'links' => ['mandate' => $mandateId],
                ],
            ]);

        // GoCardless beantwortet einen wiederholten Aufruf mit demselben
        // Schluessel nicht mit einem zweiten Abo, sondern mit 409 und der
        // Kennung des vorhandenen. Genau das wollen wir hier.
        $konflikt = $antwort->status() === 409
            ? ($antwort->json('error.errors.0.links.conflicting_resource_id') ?? null)
            : null;
        if ($konflikt !== null) {
            return (string) $konflikt;
        }

        return (string) $antwort->throw()->json('subscriptions')['id'];
    }

    public function cancelSubscription(string $subscriptionId): void
    {
        $this->client()
            ->post("/subscriptions/{$subscriptionId}/actions/cancel")
            ->throw();
    }

    public function cancelMandate(string $mandateId): void
    {
        $this->client()
            ->post("/mandates/{$mandateId}/actions/cancel")
            ->throw();
    }

    /**
     * Verify a GoCardless webhook signature (HMAC-SHA256 of the raw body).
     */
    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $secret = (string) config('services.gocardless.webhook_secret');
        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $payload, $secret), $signature);
    }
}
