<?php

namespace App\Jobs;

use App\Models\Guest;
use App\Models\NotificationLog;
use App\Services\Newsletter\NewsletterManager;
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

    public int $tries = 3;

    public array $backoff = [60, 600];

    public function __construct(
        public readonly int $guestId,
        public readonly bool $subscribe = true,
    ) {}

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

        $ok = $this->subscribe
            ? $provider->subscribe($guest)
            : $provider->unsubscribe($guest);

        NotificationLog::withoutGlobalScopes()->create([
            'tenant_id' => $guest->tenant_id,
            'channel' => 'newsletter',
            'template_key' => $this->subscribe ? 'newsletter_subscribe' : 'newsletter_unsubscribe',
            'recipient' => $guest->email,
            'status' => $ok ? 'sent' : 'failed',
            'error' => $ok ? null : 'Provider-Anfrage fehlgeschlagen',
        ]);

        if (! $ok) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }
}
