<?php

namespace App\Services;

use App\Models\Tenant;

class PlanLimitService
{
    /**
     * Whether the tenant may add one more of the given resource.
     */
    public function canAdd(Tenant $tenant, string $limitKey): bool
    {
        $limit = $tenant->limit($limitKey);
        if ($limit === null) {
            return true; // unlimited
        }

        return $this->currentUsage($tenant, $limitKey) < $limit;
    }

    public function currentUsage(Tenant $tenant, string $limitKey): int
    {
        return match ($limitKey) {
            'max_locations' => $tenant->locations()->count(),
            'max_users' => $tenant->memberships()->count(),
            'max_tables' => \App\Models\RestaurantTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->count(),
            'max_seats' => (int) \App\Models\RestaurantTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->where('is_active', true)->sum('max_capacity'),
            'max_reservations_per_month' => \App\Models\Reservation::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'max_events' => \App\Models\Event::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->count(),
            default => 0,
        };
    }
}
