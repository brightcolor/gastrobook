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

        // SSRF guard: re-check at delivery time that the target resolves to a
        // public address. Disable the endpoint so we don't keep retrying a
        // forbidden target.
        $ips = OutboundUrlGuard::publicIpsFor($endpoint->url);
        if ($ips === null) {
            $delivery->update(['status' => 'failed', 'response_body' => 'blocked: non-public URL']);
            $endpoint->update(['is_active' => false, 'disabled_at' => now()]);

            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $body, $endpoint->secret);

        try {
            // Die Anfrage auf die eben geprueften Adressen festnageln. Ohne das
            // loest curl den Namen selbst noch einmal auf - und eine Domain mit
            // kurzer Lebensdauer kann zwischen Pruefung und Aufruf auf eine
            // interne Adresse umschwenken. Die Pruefung darueber traefe dann
            // eine andere Adresse als die Anfrage.
            $pin = OutboundUrlGuard::resolveOption($endpoint->url, $ips);

            $response = Http::timeout(10)
                ->withoutRedirecting()
                ->when($pin !== [], fn ($client) => $client->withOptions(['curl' => [CURLOPT_RESOLVE => $pin]]))
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
                'response_body' => self::safeText($response->body()),
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
                'response_body' => self::safeText($e->getMessage()),
            ]);
            $this->registerFailure($endpoint);

            if ($this->attempts() >= $this->tries) {
                return;
            }
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }

    /**
     * Fremdtext fuer die Datenbank zurechtschneiden. substr() schneidet nach
     * BYTES: faellt der Schnitt mitten in ein Mehrbyte-Zeichen, lehnt PostgreSQL
     * den ganzen Datensatz ab ("invalid byte sequence"). Der Job faellt dann
     * ausgerechnet im Erfolgsfall um, stellt erneut zu und schaltet am Ende einen
     * gesunden Endpunkt ab. SQLite in den Tests schluckt es klaglos - deshalb
     * faellt es dort nie auf.
     */
    private static function safeText(string $text): string
    {
        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return mb_substr($clean, 0, 2000, 'UTF-8');
    }

    /**
     * Gezaehlt werden gescheiterte EREIGNISSE, nicht Versuche.
     *
     * Vorher zaehlte jeder einzelne Versuch mit. Bei fuenf Versuchen je
     * Ereignis war die Abschaltschwelle von 20 damit nach vier Ereignissen
     * erreicht - ein Endpunkt, der einen halben Abend nicht erreichbar ist,
     * wurde stillschweigend abgeschaltet, und nichts schaltete ihn wieder ein.
     */
    private function registerFailure($endpoint): void
    {
        if ($this->attempts() < $this->tries) {
            return;
        }

        $endpoint->increment('failure_count');
        if ($endpoint->failure_count >= self::DISABLE_AFTER_FAILURES) {
            $endpoint->update(['is_active' => false, 'disabled_at' => now()]);
        }
    }
}
