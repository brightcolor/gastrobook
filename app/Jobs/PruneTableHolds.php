<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\TableHolds;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Entfernt abgelaufene gehaltene Tische. Abgelaufene Zeilen zählen ohnehin nicht mehr; der Lauf hält nur die Tabelle
 * klein, auch für Standorte, an denen gerade niemand bucht.
 */
class PruneTableHolds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(TableHolds $holds): void
    {
        $holds->pruneExpired();
    }
}
