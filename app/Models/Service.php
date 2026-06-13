<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Service extends Model
{
    use BelongsToTenant, HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'location_id', 'name', 'description',
        'duration_minutes', 'price_minor', 'currency',
        'color', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'duration_minutes' => 'integer',
            'price_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsToMany<StaffMember, $this> */
    public function staff(): BelongsToMany
    {
        return $this->belongsToMany(StaffMember::class, 'staff_member_service');
    }

    public function priceFormatted(): string
    {
        if ($this->price_minor === 0) {
            return 'auf Anfrage';
        }

        return number_format($this->price_minor / 100, 2, ',', '.').' €';
    }

    public function durationFormatted(): string
    {
        $h = intdiv($this->duration_minutes, 60);
        $m = $this->duration_minutes % 60;

        if ($h === 0) {
            return "{$m} Min.";
        }

        if ($m === 0) {
            return "{$h} Std.";
        }

        return "{$h} Std. {$m} Min.";
    }
}
