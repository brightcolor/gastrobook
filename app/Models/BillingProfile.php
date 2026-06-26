<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingProfile extends Model
{
    protected $fillable = [
        'tenant_id', 'company_name', 'address_line1', 'address_line2',
        'postal_code', 'city', 'country', 'vat_id', 'billing_email',
        'stripe_customer_id', 'payment_status',
        'gocardless_customer_id', 'gocardless_mandate_id',
        'gocardless_subscription_id', 'gocardless_status',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function hasActiveDirectDebit(): bool
    {
        return $this->gocardless_status === 'active'
            && ! empty($this->gocardless_subscription_id);
    }
}
