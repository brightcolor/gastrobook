<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property \Illuminate\Support\Carbon $starts_at
 * @property \Illuminate\Support\Carbon $ends_at
 * @property \Illuminate\Support\Carbon|null $booking_deadline_at
 * @property \Illuminate\Support\Carbon|null $cancellation_deadline_at
 */
class Event extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'location_id', 'room_id', 'title', 'slug', 'description',
        'image_path', 'starts_at', 'ends_at', 'capacity',
        'price_minor', 'deposit_minor', 'currency',
        'booking_deadline_at', 'cancellation_deadline_at',
        'is_public', 'waitlist_enabled', 'field_rules', 'status',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'booking_deadline_at' => 'datetime',
            'cancellation_deadline_at' => 'datetime',
            'is_public' => 'boolean',
            'waitlist_enabled' => 'boolean',
            'field_rules' => 'array',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(EventBooking::class);
    }

    public function bookedTickets(): int
    {
        return (int) $this->bookings()
            ->whereIn('status', ['requested', 'confirmed', 'checked_in'])
            ->sum('ticket_count');
    }

    public function remainingCapacity(): int
    {
        return max(0, $this->capacity - $this->bookedTickets());
    }
}
