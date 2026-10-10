<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\DB;

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

    /**
     * Legt etwas an und hält dabei die Grenze ein, auch wenn zwei Anfragen
     * gleichzeitig kommen. Die Prüfung "noch Platz?" und das Anlegen laufen in
     * einer Transaktion, die die Zeile des Betriebs sperrt: Die zweite Anfrage
     * wartet, zählt danach neu und sieht den Eintrag der ersten. Die Grenze
     * kommt dabei frisch aus der Datenbank.
     *
     * @template T
     *
     * @param  Closure(): T  $create
     * @return T|null null, wenn die Grenze erreicht ist und nichts angelegt wurde
     */
    public function createWithinLimit(Tenant $tenant, string $limitKey, Closure $create): mixed
    {
        return DB::transaction(function () use ($tenant, $limitKey, $create) {
            $locked = Tenant::query()->whereKey($tenant->getKey())->lockForUpdate()->first();
            if ($locked === null || ! $this->canAdd($locked, $limitKey)) {
                return null;
            }

            return $create();
        });
    }

    public function currentUsage(Tenant $tenant, string $limitKey): int
    {
        return match ($limitKey) {
            'max_locations' => $tenant->locations()->count(),
            'max_users' => $tenant->memberships()->count(),
            'max_tables' => RestaurantTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->count(),
            'max_seats' => (int) RestaurantTable::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->where('is_active', true)->sum('max_capacity'),
            'max_reservations_per_month' => Reservation::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->whereBetween('created_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'max_events' => Event::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)->count(),
            default => 0,
        };
    }
}
