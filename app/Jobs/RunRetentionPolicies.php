<?php

namespace App\Jobs;

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
    }
}
