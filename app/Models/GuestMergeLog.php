<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Record of one merged duplicate. The merged guest row itself is removed, so
 * the snapshot here is the only remaining copy – it keeps the merge traceable
 * (and reversible by hand) without leaving a half-dead profile behind.
 */
class GuestMergeLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'kept_guest_id', 'merged_guest_id', 'merged_snapshot', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'merged_snapshot' => 'array',
        ];
    }

    public function keptGuest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'kept_guest_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
