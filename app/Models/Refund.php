<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $scheduled_for
 * @property Carbon|null $processed_at
 */
class Refund extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'reservation_id', 'event_booking_id', 'payment_intent_id',
        'provider', 'provider_refund_id', 'amount_minor', 'currency',
        'status', 'source', 'reason', 'requested_by', 'approved_by',
        'scheduled_for', 'processed_at', 'error',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'scheduled_for' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class);
    }

    public function paymentIntent(): BelongsTo
    {
        return $this->belongsTo(PaymentIntent::class);
    }

    public function amountFormatted(): string
    {
        return number_format($this->amount_minor / 100, 2, ',', '.').' '.$this->currency;
    }
}
