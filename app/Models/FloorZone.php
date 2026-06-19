<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FloorZone extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'location_id', 'room_id',
        'name', 'color', 'opacity', 'points', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'array',
            'opacity' => 'integer',
        ];
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
