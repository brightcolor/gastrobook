<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use App\Models\OpeningHour;
use App\Models\Plan;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OnboardingBannerTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_banner_auto_clears_when_setup_is_actually_complete(): void
    {
        // createTenantSetup seeds opening hours + tables → setup is complete,
        // even though onboarding_completed_at starts null.
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['onboarding_completed_at' => null]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin')
            ->assertOk()
            ->assertDontSee('Einrichtung nicht abgeschlossen');

        // …and the flag is now persisted so it stays gone.
        $this->assertNotNull($setup['tenant']->fresh()->onboarding_completed_at);
    }

    public function test_banner_shows_when_hours_and_tables_missing(): void
    {
        $tenant = Tenant::factory()->create([
            'plan_id' => Plan::factory(),
            'onboarding_completed_at' => null,
        ]);
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $location->settings()->create(['tenant_id' => $tenant->id]);
        $owner = User::factory()->create(['current_tenant_id' => $tenant->id]);
        TenantUser::create(['tenant_id' => $tenant->id, 'user_id' => $owner->id, 'role' => 'tenant_owner', 'all_locations' => true]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin')
            ->assertOk()
            ->assertSee('Einrichtung nicht abgeschlossen');

        $this->assertNull($tenant->fresh()->onboarding_completed_at);

        // Add the essentials → banner disappears on the next load.
        $room = Room::factory()->create(['location_id' => $location->id, 'tenant_id' => $tenant->id]);
        RestaurantTable::factory()->create(['location_id' => $location->id, 'tenant_id' => $tenant->id, 'room_id' => $room->id]);
        OpeningHour::create(['tenant_id' => $tenant->id, 'location_id' => $location->id, 'weekday' => 1, 'opens_at' => '11:00', 'closes_at' => '23:00']);

        $this->actingAs($owner)->get('/admin')->assertDontSee('Einrichtung nicht abgeschlossen');
        $this->assertNotNull($tenant->fresh()->onboarding_completed_at);
    }
}
