<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\RefundService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ProcessScheduledRefunds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(RefundService $refunds): void
    {
        $refunds->processDue();
    }
}
