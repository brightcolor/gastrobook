<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\OutboundUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    /** Exponential backoff in seconds. */
    public array $backoff = [60, 300, 1800, 7200];

    private const DISABLE_AFTER_FAILURES = 20;

    public function __construct(public readonly int $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        $endpoint = $delivery->endpoint()->withoutGlobalScopes()->first();
        if ($endpoint === null || ! $endpoint->is_active) {
            $delivery->update(['status' => 'failed', 'response_body' => 'endpoint inactive']);

            return;
        }

        // SSRF guard: re-check at delivery time (defeats DNS-rebinding) that the
        // target resolves to a public address. Disable the endpoint so we don't
        // keep retrying a forbidden target.
        if (! OutboundUrlGuard::isAllowed($endpoint->url)) {
            $delivery->update(['status' => 'failed', 'response_body' => 'blocked: non-public URL']);
            $endpoint->update(['is_active' => false, 'disabled_at' => now()]);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        try {
            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Gastrobook-Event' => $delivery->event,
                    'X-Gastrobook-Signature' => 'sha256='.$signature,
                    'X-Gastrobook-Delivery' => (string) $delivery->id,
                ])
                ->withBody($body, 'application/json')
                ->post($endpoint->url);

            $delivery->update([
                'attempt' => $this->attempts(),
                'status' => $response->successful() ? 'success' : 'failed',
                'response_code' => $response->status(),
                'response_body' => substr($response->body(), 0, 2000),
                'delivered_at' => $response->successful() ? now() : null,
            ]);

            if ($response->successful()) {
                $endpoint->update(['failure_count' => 0]);

                return;
            }

            $this->registerFailure($endpoint);
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        } catch (\Throwable $e) {
            $delivery->update([
                'attempt' => $this->attempts(),
                'status' => 'failed',
                'response_body' => substr($e->getMessage(), 0, 2000),
            ]);
            $this->registerFailure($endpoint);

            if ($this->attempts() >= $this->tries) {
                return;
            }
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }

    private function registerFailure($endpoint): void
    {
        $endpoint->increment('failure_count');
        if ($endpoint->failure_count >= self::DISABLE_AFTER_FAILURES) {
            $endpoint->update(['is_active' => false, 'disabled_at' => now()]);
        }
    }
}
