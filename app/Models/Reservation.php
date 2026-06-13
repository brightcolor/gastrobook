<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * @property \Illuminate\Support\Carbon $reservation_date
 * @property \Illuminate\Support\Carbon $start_at
 * @property \Illuminate\Support\Carbon $end_at
 * @property ReservationStatus $status
 * @property \Illuminate\Support\Carbon|null $payment_due_at
 * @property \Illuminate\Support\Carbon|null $confirmed_at
 * @property \Illuminate\Support\Carbon|null $seated_at
 * @property \Illuminate\Support\Carbon|null $departed_at
 * @property \Illuminate\Support\Carbon|null $cancelled_at
 * @property \Illuminate\Support\Carbon|null $reminder_sent_at
 * @property \Illuminate\Support\Carbon|null $feedback_requested_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class Reservation extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'location_id', 'guest_id', 'event_id', 'service_id', 'staff_member_id',
        'code', 'manage_token', 'party_size', 'reservation_date',
        'start_at', 'end_at', 'timezone', 'status', 'source', 'occasion',
        'guest_name_snapshot', 'guest_email_snapshot', 'guest_phone_snapshot',
        'guest_note', 'allergy_note', 'internal_note',
        'payment_status', 'payment_amount_minor', 'currency', 'payment_due_at',
        'confirmed_at', 'seated_at', 'departed_at', 'cancelled_at',
        'reminder_sent_at', 'feedback_requested_at', 'no_show_risk', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reservation_date' => 'date',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'status' => ReservationStatus::class,
            'payment_due_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'seated_at' => 'datetime',
            'departed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reminder_sent_at' => 'datetime',
            'feedback_requested_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Reservation $reservation) {
            $reservation->code = $reservation->code ?: static::generateCode();
            $reservation->manage_token = $reservation->manage_token ?: Str::random(48);
        });
    }

    public static function generateCode(): string
    {
        do {
            // unambiguous alphabet, e.g. "R-7KQ2M9"
            $code = 'R-'.strtoupper(Str::random(6));
        } while (static::withoutGlobalScope('tenant')->where('code', $code)->exists());

        return $code;
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

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(RestaurantTable::class, 'reservation_tables');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(ReservationNote::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ReservationStatus::activeStatuses());
    }

    public function scopeOverlapping(Builder $query, \DateTimeInterface $start, \DateTimeInterface $end): Builder
    {
        return $query->where('start_at', '<', $end)->where('end_at', '>', $start);
    }

    public function localStart(): Carbon
    {
        return $this->start_at->copy()->setTimezone($this->timezone);
    }

    public function localEnd(): Carbon
    {
        return $this->end_at->copy()->setTimezone($this->timezone);
    }

    public function isWalkIn(): bool
    {
        return $this->source === 'walk_in';
    }
}
