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
 * @property-read Guest|null $guest
 */
class Reservation extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    /**
     * Zahlungsstaende, bei denen Geld geflossen ist.
     *
     * Solche Buchungen faellt keine Frist mehr an - was danach mit dem Geld
     * passiert, entscheidet der Betrieb, nicht ein Aufraeumlauf. Die Liste
     * steht hier, weil beide Fristlaeufe sie brauchen; als zwei Kopien wich
     * frueher oder spaeter eine von der anderen ab.
     *
     * @var array<int, string>
     */
    public const SETTLED_PAYMENT_STATUSES = ['paid', 'refunded', 'partially_refunded', 'forfeited'];

    protected $fillable = [
        'tenant_id', 'location_id', 'guest_id', 'event_id', 'service_id', 'staff_member_id',
        'code', 'manage_token', 'party_size', 'reservation_date',
        'start_at', 'end_at', 'timezone', 'status', 'source', 'table_chosen_by_guest', 'occasion',
        'guest_name_snapshot', 'guest_email_snapshot', 'guest_phone_snapshot',
        'guest_note', 'allergy_note', 'internal_note',
        'payment_status', 'payment_amount_minor', 'currency', 'payment_due_at', 'deposit_rule_id',
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
            'table_chosen_by_guest' => 'boolean',
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

    /**
     * Die Anzahlungsregel, die bei der Buchung gegriffen hat. Kann null sein,
     * wenn die Regel spaeter geloescht wurde (nullOnDelete).
     *
     * @return BelongsTo<DepositRule, $this>
     */
    public function depositRule(): BelongsTo
    {
        return $this->belongsTo(DepositRule::class);
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

    /**
     * All services that make up this (salon) appointment, in order.
     *
     * @return BelongsToMany<Service, $this>
     */
    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class, 'reservation_service')
            ->withPivot(['sort_order', 'duration_minutes', 'price_minor'])
            ->orderByPivot('sort_order');
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

    /** @return HasMany<ReservationAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(ReservationAttachment::class);
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

    /**
     * Zeitpunkt der Buchung in der Ortszeit des Standorts.
     */
    public function localCreatedAt(): ?Carbon
    {
        return $this->created_at?->copy()->setTimezone($this->timezone ?: config('app.timezone'));
    }

    /**
     * Der Reservierungstag, wie ihn das Personal liest: „Heute", „Morgen",
     * sonst Wochentag und Datum. Das Jahr nur, wenn es nicht das laufende ist.
     */
    public function localDayLabel(): string
    {
        $tag = $this->localStart();
        $heute = Carbon::now($this->timezone ?: config('app.timezone'))->startOfDay();

        return match (true) {
            $tag->isSameDay($heute) => 'Heute',
            $tag->isSameDay($heute->copy()->addDay()) => 'Morgen',
            $tag->isSameDay($heute->copy()->subDay()) => 'Gestern',
            $tag->year !== $heute->year => $tag->translatedFormat('D, d.m.Y'),
            default => $tag->translatedFormat('D, d.m.'),
        };
    }

    public function isWalkIn(): bool
    {
        return $this->source === 'walk_in';
    }
}
