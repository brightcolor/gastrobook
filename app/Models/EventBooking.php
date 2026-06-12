<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class EventBooking extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'event_id', 'reservation_id', 'guest_id', 'code', 'manage_token',
        'ticket_count', 'guest_name', 'guest_email', 'guest_phone', 'note',
        'status', 'payment_status', 'amount_minor', 'checked_in_at', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'checked_in_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (EventBooking $booking) {
            $booking->code = $booking->code ?: 'E-'.strtoupper(Str::random(6));
            $booking->manage_token = $booking->manage_token ?: Str::random(48);
        });
    }

    /** @return BelongsTo<Event, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }
}
