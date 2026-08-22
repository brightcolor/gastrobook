<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FeedbackRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Unbeantwortete Feedback-Anfragen entfernen.
 *
 * Eigener Job aus demselben Grund wie ExpireStaleWaitlistOffers: Der Aufruf
 * löscht ohne Obergrenze und darf den Scheduler nicht aufhalten.
 */
class PruneUnansweredFeedback implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        FeedbackRequest::pruneUnanswered();
    }
}
