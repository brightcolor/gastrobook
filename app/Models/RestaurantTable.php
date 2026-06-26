<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class RestaurantTable extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'location_id', 'room_id', 'name',
        'min_capacity', 'max_capacity', 'preferred_capacity', 'extra_capacity',
        'is_active', 'online_bookable', 'joinable', 'priority', 'sort_order',
        'outdoor', 'accessible', 'high_chair_possible', 'backup_table_id', 'note',
        'pos_x', 'pos_y', 'width', 'height', 'rotation', 'shape',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'online_bookable' => 'boolean',
            'joinable' => 'boolean',
            'outdoor' => 'boolean',
            'accessible' => 'boolean',
            'high_chair_possible' => 'boolean',
        ];
    }

    /**
     * Sensible floor-plan dimensions (logical units) for a table, derived from
     * its seat count and shape. Round tables grow as a circle; rectangular
     * tables grow lengthwise so the long sides can seat the guests realistically.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public static function sizeForCapacity(string $shape, int $maxCapacity): array
    {
        // 1 plan unit = 1 cm. Gastronomy standard: ~60 cm of table edge per
        // cover. Sizes grow strictly with capacity (no upper cap) so seats are
        // always spaced ≥ SEAT_SPAN apart and never overlap — this holds for any
        // capacity, from a 2-top up to a 100-seat banquet table.
        $n = max(1, $maxCapacity);

        $seatSpan = 60;   // linear space per cover (cm)

        if ($shape === 'round') {
            // Circumference must fit n covers: π·d ≥ n·seatSpan.
            $d = (int) max(90, (int) ceil($n * $seatSpan / M_PI));

            return [$d, $d];
        }

        // Rectangular: guests sit on the two long sides; for ≥7 covers the short
        // ends ("heads") are used too. Length grows with the busiest long side.
        $heads = $n >= 8 ? 2 : ($n % 2 === 1 ? 1 : 0);
        $perSide = (int) ceil(($n - $heads) / 2);

        $length = max(70, $perSide * $seatSpan);
        $depth = $n <= 2 ? 70 : ($n >= 8 ? 90 : 80);

        return [$length, $depth];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function backupTable(): BelongsTo
    {
        return $this->belongsTo(self::class, 'backup_table_id');
    }

    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_tables');
    }

    /** @return HasMany<TableBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(TableBlock::class);
    }

    public function fitsParty(int $partySize, bool $allowExtra = false): bool
    {
        $max = $this->max_capacity + ($allowExtra ? $this->extra_capacity : 0);

        return $partySize >= $this->min_capacity && $partySize <= $max;
    }
}
