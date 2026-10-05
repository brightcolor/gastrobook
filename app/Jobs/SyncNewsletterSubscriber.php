<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\NotificationLog;
use App\Services\Newsletter\NewsletterManager;
use App\Support\OutboundUrlBlocked;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pushes a guest with newsletter consent to the tenant's newsletter system
 * (currently MailWizz). Only runs for guests whose consent is recorded —
 * GDPR separation between reservation data and marketing stays intact.
 */
class SyncNewsletterSubscriber implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Versuche je Uebertragung (swayy.newsletter.tries). */
    public int $tries;

    /**
     * Wartezeiten zwischen den Versuchen in Sekunden (swayy.newsletter.backoff).
     *
     * @var list<int>
     */
    public array $backoff;

    public function __construct(
        public readonly int $guestId,
        public readonly bool $subscribe = true,
    ) {
        $this->tries = (int) config('swayy.newsletter.tries');
        $this->backoff = array_values((array) config('swayy.newsletter.backoff'));
    }

    public function handle(NewsletterManager $newsletters): void
    {
        $guest = Guest::withoutGlobalScopes()->find($this->guestId);
        if ($guest === null || ! $guest->email || $guest->anonymized) {
            return;
        }

        // Consent must still be valid at execution time
        if ($this->subscribe && ! $guest->marketing_consent) {
            return;
        }

        $tenant = $guest->tenant()->first();
        if ($tenant === null) {
            return;
        }

        $provider = $newsletters->providerFor($tenant);
        if ($provider === null) {
            return; // no newsletter integration configured
        }

        try {
            $ok = $this->subscribe
                ? $provider->subscribe($guest)
                : $provider->unsubscribe($guest);
            $error = $ok ? null : 'Das Newsletter-System hat die Anfrage abgelehnt. Bitte API-URL, API-Key und Listen-UID in den Einstellungen prüfen.';
        } catch (OutboundUrlBlocked $e) {
            $ok = false;
            $error = 'Anfrage nicht gesendet: '.$e->getMessage();

            // Die Adresse selbst ist nicht erlaubt. Ein weiterer Versuch traefe
            // dieselbe Pruefung - also die Anbindung anhalten, bis jemand die
            // Einstellungen korrigiert.
            if (! $e->isTemporary()) {
                $newsletters->suspend($tenant, $e->getMessage());
                $this->log($guest, $error);

                return;
            }
        }

        $this->log($guest, $error);

        if (! $ok) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)] ?? 0);
        }
    }

    private function log(Guest $guest, ?string $error): void
    {
        NotificationLog::withoutGlobalScopes()->create([
            'tenant_id' => $guest->tenant_id,
            'channel' => 'newsletter',
            'template_key' => $this->subscribe ? 'newsletter_subscribe' : 'newsletter_unsubscribe',
            'recipient' => $guest->email,
            'status' => $error === null ? 'sent' : 'failed',
            'error' => $error,
        ]);
    }
}
