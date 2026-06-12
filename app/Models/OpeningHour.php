<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OpeningHour extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'location_id', 'weekday', 'opens_at', 'closes_at', 'service_name',
    ];

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}
