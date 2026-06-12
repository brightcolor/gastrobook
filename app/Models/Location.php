<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'name', 'slug', 'type', 'timezone', 'currency', 'locale',
        'phone', 'email', 'address_line1', 'postal_code', 'city', 'country',
        'is_active', 'online_booking_enabled', 'public_intro', 'brand_logo_path',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'online_booking_enabled' => 'boolean',
        ];
    }

    /** @return HasOne<LocationSettings, $this> */
    public function settings(): HasOne
    {
        return $this->hasOne(LocationSettings::class);
    }

    /** @return HasMany<Room, $this> */
    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    /** @return HasMany<RestaurantTable, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(RestaurantTable::class);
    }

    /** @return HasMany<TableCombination, $this> */
    public function tableCombinations(): HasMany
    {
        return $this->hasMany(TableCombination::class);
    }

    /** @return HasMany<OpeningHour, $this> */
    public function openingHours(): HasMany
    {
        return $this->hasMany(OpeningHour::class);
    }

    /** @return HasMany<SpecialOpeningHour, $this> */
    public function specialOpeningHours(): HasMany
    {
        return $this->hasMany(SpecialOpeningHour::class);
    }

    /** @return HasMany<BlackoutPeriod, $this> */
    public function blackoutPeriods(): HasMany
    {
        return $this->hasMany(BlackoutPeriod::class);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function effectiveSettings(): LocationSettings
    {
        return $this->settings ?? new LocationSettings([
            'tenant_id' => $this->tenant_id,
            'location_id' => $this->id,
        ]);
    }
}
