<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentIntent extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'reservation_id', 'event_booking_id', 'provider',
        'provider_intent_id', 'type', 'amount_minor', 'currency',
        'status', 'metadata', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }
}
