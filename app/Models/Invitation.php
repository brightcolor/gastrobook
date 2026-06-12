<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Invitation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'email', 'role', 'all_locations', 'location_ids',
        'token', 'invited_by', 'accepted_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'all_locations' => 'boolean',
            'location_ids' => 'array',
            'accepted_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Invitation $invitation) {
            $invitation->token = $invitation->token ?: Str::random(48);
            $invitation->expires_at = $invitation->expires_at ?: now()->addDays(7);
        });
    }
}
