<?php

namespace App\Jobs;

use App\Models\GuestAuthToken;
use App\Models\Tenant;
use App\Services\GuestPrivacyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class RunRetentionPolicies implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(GuestPrivacyService $privacy): void
    {
        Tenant::where('status', 'active')->each(function (Tenant $tenant) use ($privacy) {
            $privacy->runRetention($tenant);
        });

        $this->pruneExpiredTokens();
    }

    /**
     * Verbrauchte und abgelaufene Anmelde- und Bestätigungslinks entfernen.
     *
     * Seit die E-Mail-Bestätigung bei jeder Buchung greift, wächst
     * `guest_auth_tokens` um eine Zeile pro Onlinebuchung. Die Tabelle wird
     * stündlich vom Aufräumlauf für unbestätigte Buchungen abgefragt – sie
     * darf nicht unbegrenzt wachsen. Eine Woche Nachlauf, damit ein
     * abgelaufener Link noch nachvollziehbar bleibt, wenn ein Gast anruft.
     */
    private function pruneExpiredTokens(): void
    {
        GuestAuthToken::withoutGlobalScopes()
            ->where(fn ($q) => $q->whereNotNull('used_at')->orWhere('expires_at', '<', now()))
            ->where('created_at', '<', now()->subWeek())
            ->delete();
    }
}
