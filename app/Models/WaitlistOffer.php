<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $offered_start_at
 * @property Carbon $offered_end_at
 * @property Carbon $offer_expires_at
 */
class WaitlistOffer extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'waitlist_entry_id', 'offered_start_at', 'offered_end_at',
        'table_ids', 'offer_expires_at', 'status', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'offered_start_at' => 'datetime',
            'offered_end_at' => 'datetime',
            'table_ids' => 'array',
            'offer_expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WaitlistEntry, $this> */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(WaitlistEntry::class, 'waitlist_entry_id');
    }
}
