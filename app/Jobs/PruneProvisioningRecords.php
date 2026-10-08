<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Provisioning\Idempotency;
use App\Provisioning\LoginLinks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Loescht Aufrufkennungen der Bereitstellung nach PROVISIONING_IDEMPOTENCY_DAYS
 * und abgelaufene Anmeldelinks. Laeuft taeglich um PROVISIONING_CLEANUP_TIME.
 */
class PruneProvisioningRecords implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(Idempotency $idempotency, LoginLinks $links): void
    {
        $kennungen = $idempotency->cleanup();
        $anmeldelinks = $links->cleanup();
        if ($kennungen + $anmeldelinks > 0) {
            Log::info('Bereitstellung aufgeräumt.', ['aufrufkennungen' => $kennungen, 'anmeldelinks' => $anmeldelinks]);
        }
    }
}
