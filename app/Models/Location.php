<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

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

    public function seasonPeriods(): HasMany
    {
        return $this->hasMany(SeasonPeriod::class)
            ->orderBy('sort_order')
            ->orderBy('start_month')
            ->orderBy('start_day');
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

    /**
     * Sperrt die Zeile dieses Standorts bis zum Ende der laufenden Transaktion.
     * Zwei gleichzeitige Speicherungen seiner Zeiten (Öffnungszeiten, Ausnahmen)
     * laufen so nacheinander: Die zweite sieht danach die Zeilen der ersten.
     * Die Sperre auf den Zeilen der Gruppe allein reichte nicht, denn sie läuft
     * ins Leere, sobald die erste Speicherung diese Zeilen gelöscht hat.
     */
    public function lockRow(): void
    {
        static::query()->whereKey($this->getKey())->lock(self::rowLock(DB::getDriverName()))->first();
    }

    /**
     * Die Art der Sperre auf der Zeile eines Standorts je Datenbank. Unter
     * PostgreSQL "for no key update": Sie schließt eine zweite Speicherung aus,
     * hält aber die Fremdschlüsselprüfung anderer Zeilen des Standorts
     * (Reservierungen) nicht auf; "for update" hielte jede neue Reservierung an,
     * bis die Speicherung beendet ist. Sonst die übliche Zeilensperre.
     */
    public static function rowLock(string $driver): string|bool
    {
        return $driver === 'pgsql' ? 'for no key update' : true;
    }
}
