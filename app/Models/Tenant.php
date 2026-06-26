<?php

namespace App\Models;

use App\Enums\TenantType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property TenantType $type
 * @property Carbon|null $trial_ends_at
 * @property Carbon|null $trial_warning_sent_at
 */
class Tenant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'type', 'plan_id', 'status', 'trial_ends_at', 'trial_warning_sent_at',
        'default_locale', 'default_currency',
        'brand_logo_path', 'brand_primary_color', 'brand_accent_color',
        'mail_from_name', 'mail_reply_to',
        'imprint_url', 'privacy_url', 'terms_url',
        'guest_retention_months', 'settings', 'feature_overrides',
    ];

    protected function casts(): array
    {
        return [
            'type' => TenantType::class,
            'trial_ends_at' => 'datetime',
            'trial_warning_sent_at' => 'datetime',
            'settings' => 'array',
            'feature_overrides' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tenant_users')
            ->withPivot(['role', 'all_locations'])
            ->withTimestamps();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    public function billingProfile(): HasOne
    {
        return $this->hasOne(BillingProfile::class);
    }

    public function billingRequests(): HasMany
    {
        return $this->hasMany(BillingRequest::class);
    }

    public function latestBillingRequest(): HasOne
    {
        return $this->hasOne(BillingRequest::class)->latestOfMany();
    }

    /** Trial is running and not yet expired. */
    public function isTrialing(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /** Trial window has passed and the tenant is not yet re-activated. */
    public function isTrialExpired(): bool
    {
        return $this->status === 'trial_expired';
    }

    /** Billing request submitted and email-confirmed; waiting for owner to activate. */
    public function isPendingBilling(): bool
    {
        return $this->status === 'pending_billing';
    }

    /** Any state that blocks access to the admin. */
    public function isLocked(): bool
    {
        return in_array($this->status, ['trial_expired', 'suspended', 'cancelled'], true);
    }

    /** @return HasMany<WebhookEndpoint, $this> */
    public function webhookEndpoints(): HasMany
    {
        return $this->hasMany(WebhookEndpoint::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isRestaurant(): bool
    {
        return ($this->type ?? TenantType::Restaurant) === TenantType::Restaurant;
    }

    public function isSalon(): bool
    {
        return $this->type === TenantType::Salon;
    }

    public function hasFeature(string $feature): bool
    {
        $overrides = $this->feature_overrides ?? [];
        if (array_key_exists($feature, $overrides)) {
            return (bool) $overrides[$feature];
        }

        return (bool) ($this->plan?->features[$feature] ?? false);
    }

    public function limit(string $key): ?int
    {
        $value = $this->plan?->limits[$key] ?? null;

        return $value === null ? null : (int) $value;
    }
}
