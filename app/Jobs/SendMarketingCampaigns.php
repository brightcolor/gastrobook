<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MarketingCampaign;
use App\Services\MarketingCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs the active guest campaigns once a day. Everything that decides who gets
 * a mail lives in the service; this only walks the campaigns and keeps one
 * broken tenant from stopping the rest.
 */
class SendMarketingCampaigns implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(MarketingCampaignService $campaigns): void
    {
        MarketingCampaign::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereHas('tenant', fn ($q) => $q->where('status', 'active'))
            ->each(function (MarketingCampaign $campaign) use ($campaigns): void {
                try {
                    $sent = $campaigns->run($campaign);
                    if ($sent > 0) {
                        Log::info('Marketing campaign sent', [
                            'campaign' => $campaign->id,
                            'tenant' => $campaign->tenant_id,
                            'type' => $campaign->type,
                            'count' => $sent,
                        ]);
                    }
                } catch (\Throwable $e) {
                    // One tenant's broken configuration must not silence
                    // everybody else's campaigns.
                    Log::warning('Marketing campaign failed', [
                        'campaign' => $campaign->id,
                        'tenant' => $campaign->tenant_id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
