<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per guest, campaign and occasion – the record that stops a daily job
 * from greeting the same person every morning.
 */
class MarketingSend extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'marketing_campaign_id', 'guest_id', 'reference', 'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(MarketingCampaign::class, 'marketing_campaign_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
