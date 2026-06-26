<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $confirmed_at
 * @property Carbon|null $owner_notified_at
 */
class BillingRequest extends Model
{
    protected $fillable = [
        'tenant_id',
        'contact_name',
        'contact_email',
        'company_name',
        'address_line1',
        'address_line2',
        'postal_code',
        'city',
        'country',
        'vat_id',
        'phone',
        'plan_key',
        'notes',
        'token',
        'confirmed_at',
        'owner_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'confirmed_at' => 'datetime',
            'owner_notified_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }
}
