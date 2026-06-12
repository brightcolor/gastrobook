<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $birthday
 * @property Carbon|null $last_visit_at
 * @property Carbon|null $marketing_consent_at
 * @property Carbon|null $anonymized_at
 */
class Guest extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'first_name', 'last_name', 'email', 'phone', 'phone_normalized',
        'address_line1', 'postal_code', 'city', 'country', 'birthday', 'locale',
        'preferred_location_id', 'preferred_room_id', 'preferred_table_id',
        'preferences', 'allergies', 'accessibility_notes', 'is_vip',
        'visit_count', 'no_show_count', 'cancellation_count',
        'last_visit_at', 'avg_party_size', 'source',
        'marketing_consent', 'marketing_consent_at',
        'anonymized', 'anonymized_at',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'is_vip' => 'boolean',
            'last_visit_at' => 'datetime',
            'marketing_consent' => 'boolean',
            'marketing_consent_at' => 'datetime',
            'anonymized' => 'boolean',
            'anonymized_at' => 'datetime',
            'avg_party_size' => 'float',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Guest $guest) {
            $guest->phone_normalized = $guest->phone
                ? preg_replace('/\D+/', '', $guest->phone)
                : null;
        });
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /** @return HasMany<GuestNote, $this> */
    public function notes(): HasMany
    {
        return $this->hasMany(GuestNote::class);
    }

    /** @return HasMany<GuestConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(GuestConsent::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.$this->last_name);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D+/', '', $term);

        return $query->where(function (Builder $q) use ($term, $digits) {
            $q->where('last_name', 'like', "%{$term}%")
                ->orWhere('first_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");
            if ($digits !== '') {
                $q->orWhere('phone_normalized', 'like', "%{$digits}%");
            }
        });
    }
}
