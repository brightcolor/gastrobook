<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Mail\TrialExpiryWarningMail;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

/**
 * Sends a 5-day trial expiry warning to all owner users of tenants
 * whose trial ends in the next 5–6 days. Runs once per day.
 * Uses trial_warning_sent_at to avoid duplicate sends.
 */
class SendTrialExpiryWarnings implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        $ownerEmail = config('swayy.owner_email');

        Tenant::query()
            ->where('status', 'active')
            ->whereNotNull('trial_ends_at')
            ->whereBetween('trial_ends_at', [now()->addDays(4), now()->addDays(6)])
            ->whereNull('trial_warning_sent_at')
            ->with(['users' => fn ($q) => $q->wherePivot('role', 'tenant_owner')])
            ->each(function (Tenant $tenant) use ($ownerEmail): void {
                // Send to each owner of this tenant
                foreach ($tenant->users as $owner) {
                    Mail::to($owner->email, $owner->name)
                        ->queue(new TrialExpiryWarningMail($tenant, $owner->name));
                }

                // Also notify the platform owner
                if ($ownerEmail) {
                    Mail::to($ownerEmail)
                        ->queue(new TrialExpiryWarningMail($tenant, 'Admin'));
                }

                $tenant->update(['trial_warning_sent_at' => now()]);
            });
    }
}
