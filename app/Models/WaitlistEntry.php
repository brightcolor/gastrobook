<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property Carbon $desired_date
 * @property Carbon|null $desired_start_at
 * @property Carbon|null $expires_at
 */
class WaitlistEntry extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'location_id', 'guest_id', 'manage_token',
        'guest_name', 'guest_email', 'guest_phone',
        'party_size', 'desired_date', 'desired_start_at', 'flex_minutes',
        'status', 'source', 'priority', 'note', 'expires_at', 'reservation_id',
    ];

    protected function casts(): array
    {
        return [
            'desired_date' => 'date',
            'desired_start_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (WaitlistEntry $entry) {
            $entry->manage_token = $entry->manage_token ?: Str::random(48);
        });
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(WaitlistOffer::class);
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
