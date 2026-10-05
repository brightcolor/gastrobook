<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Support\OutboundUrlBlocked;
use App\Support\OutboundUrlGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Versuche je Ereignis (swayy.webhooks.tries). */
    public int $tries;

    /**
     * Wartezeiten zwischen den Versuchen in Sekunden (swayy.webhooks.backoff).
     *
     * @var list<int>
     */
    public array $backoff;

    public function __construct(public readonly int $deliveryId)
    {
        $this->tries = (int) config('swayy.webhooks.tries');
        $this->backoff = array_values((array) config('swayy.webhooks.backoff'));
    }

    public function handle(): void
    {
        $delivery = WebhookDelivery::withoutGlobalScopes()->find($this->deliveryId);
        if ($delivery === null) {
            return;
        }

        $endpoint = $delivery->endpoint()->withoutGlobalScopes()->first();
        if ($endpoint === null || ! $endpoint->is_active) {
            $delivery->update([
                'status' => 'failed',
                'response_body' => __('Nicht gesendet: Der Endpunkt ist pausiert. Unter „Webhooks“ lässt er sich wieder aktivieren.'),
            ]);

            return;
        }

        // SSRF guard: re-check at delivery time that the target resolves to
        // allowed addresses.
        try {
            $ips = OutboundUrlGuard::check($endpoint->url);
        } catch (OutboundUrlBlocked $e) {
            $this->refused($delivery, $endpoint, $e);

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

            $response = Http::timeout((int) config('swayy.webhooks.timeout'))
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
            $this->retryLater();
        } catch (ConnectionException $e) {
            $this->failedAttempt($delivery, $endpoint, __('Nicht zugestellt: Die Gegenstelle war nicht erreichbar oder hat nicht rechtzeitig geantwortet. Technische Angabe: :detail', [
                'detail' => $e->getMessage(),
            ]));
        } catch (\Throwable $e) {
            $this->failedAttempt($delivery, $endpoint, __('Nicht zugestellt: Bei der Zustellung ist ein Fehler aufgetreten. Technische Angabe: :detail', [
                'detail' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Die Zielpruefung hat die Adresse abgelehnt, bevor eine Verbindung
     * entstand.
     *
     * Laesst sich der Name gerade nicht aufloesen, ist das ein Fehlversuch wie
     * jeder andere: spaeter erneut, der Endpunkt bleibt an. Ist die Adresse
     * selbst nicht erlaubt, traefe jeder weitere Versuch dieselbe Pruefung;
     * dann schaltet sich der Endpunkt ab, und der Grund steht im Protokoll.
     */
    private function refused(WebhookDelivery $delivery, WebhookEndpoint $endpoint, OutboundUrlBlocked $e): void
    {
        if ($e->isTemporary()) {
            $this->failedAttempt($delivery, $endpoint, __('Nicht gesendet: :reason', ['reason' => $e->getMessage()]));

            return;
        }

        $delivery->update([
            'attempt' => $this->attempts(),
            'status' => 'failed',
            'response_body' => __('Nicht gesendet, Endpunkt abgeschaltet: :reason', ['reason' => $e->getMessage()]),
        ]);
        $endpoint->update(['is_active' => false, 'disabled_at' => now()]);
    }

    /**
     * Versuch ohne Antwort der Gegenstelle: Grund festhalten, zaehlen,
     * spaeter erneut.
     */
    private function failedAttempt(WebhookDelivery $delivery, WebhookEndpoint $endpoint, string $reason): void
    {
        $delivery->update([
            'attempt' => $this->attempts(),
            'status' => 'failed',
            'response_body' => mb_convert_encoding($reason, 'UTF-8', 'UTF-8'),
        ]);

        $this->registerFailure($endpoint);
        $this->retryLater();
    }

    /**
     * Zurueck in die Warteschlange, solange Versuche uebrig sind. Der letzte
     * Wert in backoff gilt fuer alle weiteren Versuche. Nach dem letzten
     * Versuch endet der Job; das Ereignis steht als gescheitert im Protokoll.
     */
    private function retryLater(): void
    {
        if ($this->attempts() >= $this->tries) {
            return;
        }

        $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 0);
    }

    /**
     * Fremdtext fuer die Datenbank zurechtschneiden. substr() schneidet nach
     * BYTES: faellt der Schnitt mitten in ein Mehrbyte-Zeichen, lehnt PostgreSQL
     * den ganzen Datensatz ab ("invalid byte sequence"). Der Job faellt dann
     * ausgerechnet im Erfolgsfall um, stellt erneut zu und schaltet am Ende einen
     * gesunden Endpunkt ab. SQLite in den Tests schluckt es klaglos - deshalb
     * faellt es dort nie auf.
     *
     * Wie viel der Antwort bleibt, legt swayy.webhooks.response_limit fest.
     */
    private static function safeText(string $text): string
    {
        $clean = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

        return mb_substr($clean, 0, (int) config('swayy.webhooks.response_limit'), 'UTF-8');
    }

    /**
     * Gezaehlt werden gescheiterte EREIGNISSE, nicht Versuche.
     *
     * Vorher zaehlte jeder einzelne Versuch mit. Bei fuenf Versuchen je
     * Ereignis war die Abschaltschwelle von 20 damit nach vier Ereignissen
     * erreicht - ein Endpunkt, der einen halben Abend nicht erreichbar ist,
     * wurde stillschweigend abgeschaltet, und nichts schaltete ihn wieder ein.
     *
     * Die Schwelle steht in swayy.webhooks.disable_after.
     */
    private function registerFailure(WebhookEndpoint $endpoint): void
    {
        if ($this->attempts() < $this->tries) {
            return;
        }

        $endpoint->increment('failure_count');
        if ($endpoint->failure_count >= (int) config('swayy.webhooks.disable_after')) {
            $endpoint->update(['is_active' => false, 'disabled_at' => now()]);
        }
    }
}
