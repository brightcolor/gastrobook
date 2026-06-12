<?php

namespace Tests\Concerns;

use App\Models\Location;
use App\Models\OpeningHour;
use App\Models\Plan;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\TenantContext;

trait CreatesTenants
{
    /**
     * Full tenant with one location, one room, opening hours every day
     * 12:00-23:00 and the given tables.
     *
     * @param  array<int, array{min: int, max: int}>  $tables
     * @return array{tenant: Tenant, location: Location, room: Room, tables: array<RestaurantTable>}
     */
    protected function createTenantSetup(array $tables = [['min' => 1, 'max' => 2], ['min' => 2, 'max' => 4], ['min' => 4, 'max' => 8]]): array
    {
        $tenant = Tenant::factory()->create(['plan_id' => Plan::factory()]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $location->settings()->create(['tenant_id' => $tenant->id, 'min_lead_minutes' => 0]);

        foreach (range(0, 6) as $weekday) {
            OpeningHour::create([
                'tenant_id' => $tenant->id,
                'location_id' => $location->id,
                'weekday' => $weekday,
                'opens_at' => '12:00',
                'closes_at' => '23:00',
            ]);
        }

        $room = Room::factory()->create(['location_id' => $location->id, 'tenant_id' => $tenant->id]);

        $created = [];
        foreach ($tables as $i => $spec) {
            $created[] = RestaurantTable::factory()->create([
                'tenant_id' => $tenant->id,
                'location_id' => $location->id,
                'room_id' => $room->id,
                'name' => 'T'.($i + 1),
                'min_capacity' => $spec['min'],
                'max_capacity' => $spec['max'],
            ]);
        }

        return ['tenant' => $tenant, 'location' => $location, 'room' => $room, 'tables' => $created];
    }

    protected function createMember(Tenant $tenant, string $role = 'tenant_admin', bool $allLocations = true): User
    {
        $user = User::factory()->create(['current_tenant_id' => $tenant->id]);
        TenantUser::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role,
            'all_locations' => $allLocations,
        ]);

        return $user;
    }

    protected function actAsTenant(Tenant $tenant, ?Location $location = null): void
    {
        $context = app(TenantContext::class);
        $context->setTenant($tenant);
        $context->setLocation($location);
    }

    protected function clearTenantContext(): void
    {
        app(TenantContext::class)->clear();
    }
}
