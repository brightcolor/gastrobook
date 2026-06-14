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
        $max = max(1, $maxCapacity);

        if ($shape === 'round') {
            $d = (int) min(190, 84 + $max * 9);

            return [$d, $d];
        }

        // Two long sides seat ~half each; the table gets longer with capacity.
        $width = (int) min(260, 96 + (int) ceil($max / 2) * 28);

        return [$width, 88];
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
