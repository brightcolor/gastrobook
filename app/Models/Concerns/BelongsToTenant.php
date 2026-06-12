<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant isolation: adds a global scope restricting every query to the
 * active tenant and auto-fills tenant_id on create.
 *
 * Defense in depth: controllers/policies additionally verify ownership
 * explicitly — the scope alone is never the only line of defense.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);
            if ($context->tenantId() !== null) {
                $builder->where($builder->getModel()->getTable().'.tenant_id', $context->tenantId());
            }
        });

        static::creating(function (Model $model) {
            $context = app(TenantContext::class);
            if (empty($model->tenant_id) && $context->tenantId() !== null) {
                $model->tenant_id = $context->tenantId();
            }
        });
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeWithoutTenantScope(Builder $builder): Builder
    {
        return $builder->withoutGlobalScope('tenant');
    }
}
