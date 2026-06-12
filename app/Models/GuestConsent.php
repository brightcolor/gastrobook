<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $recorded_at
 */
class GuestConsent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'guest_id', 'type', 'granted', 'channel', 'ip_hash', 'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'granted' => 'boolean',
            'recorded_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
