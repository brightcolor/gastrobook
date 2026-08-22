<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\WaitlistService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Abgelaufene Wartelisten-Angebote aufräumen.
 *
 * Steht als eigener Job in der Warteschlange, nicht als `Schedule::call`: Ein
 * call läuft im Scheduler-Prozess selbst und hält ihn so lange auf, wie er
 * dauert. Diese Aufgabe lädt alle offenen abgelaufenen Angebote ohne
 * Obergrenze - ihre Laufzeit ginge eins zu eins in die Taktung des Schedulers
 * ein.
 */
class ExpireStaleWaitlistOffers implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(WaitlistService $waitlist): void
    {
        $waitlist->expireStale();
    }
}
