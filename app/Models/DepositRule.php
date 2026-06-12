<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DepositRule extends Model
{
    use BelongsToTenant, HasFactory;

    protected $fillable = [
        'tenant_id', 'location_id', 'name', 'type',
        'min_party_size', 'weekdays', 'from_time', 'until_time',
        'room_id', 'event_id', 'amount_per_person_minor', 'flat_amount_minor',
        'currency', 'payment_deadline_minutes', 'cancel_unpaid_automatically', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'cancel_unpaid_automatically' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function amountFor(int $partySize): int
    {
        return $this->flat_amount_minor + $this->amount_per_person_minor * $partySize;
    }
}
