<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LocationManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_can_add_a_second_location(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        // Plan with room for 5 locations.
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 5]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->post('/admin/locations', [
            'name' => 'Filiale Süd',
            'timezone' => 'Europe/Berlin',
        ])->assertRedirect(route('admin.locations.index'));

        $loc = Location::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)
            ->where('name', 'Filiale Süd')
            ->first();

        $this->assertNotNull($loc);
        $this->assertSame('filiale-sud', $loc->slug);
        $this->assertTrue($loc->is_active);
        // Settings row is created alongside the location.
        $this->assertNotNull($loc->settings()->withoutGlobalScopes()->first());
    }

    public function test_plan_limit_blocks_additional_locations(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Tenant already has its initial location → limit of 1 is reached.
        $this->actingAs($owner)->post('/admin/locations', [
            'name' => 'Zweite (verboten)',
            'timezone' => 'Europe/Berlin',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count());
    }

    public function test_owner_can_rename_location_without_changing_slug(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $location = $setup['location'];
        $originalSlug = $location->slug;
        $this->clearTenantContext();

        $this->actingAs($owner)->put("/admin/locations/{$location->id}", [
            'name' => 'Neuer Name',
            'timezone' => 'Europe/Vienna',
        ])->assertRedirect();

        $fresh = $location->fresh();
        $this->assertSame('Neuer Name', $fresh->name);
        $this->assertSame('Europe/Vienna', $fresh->timezone);
        $this->assertSame($originalSlug, $fresh->slug, 'Slug must stay stable so booking links keep working.');
    }

    public function test_last_active_location_cannot_be_deactivated(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->post("/admin/locations/{$setup['location']->id}/toggle-active")
            ->assertSessionHasErrors('active');

        $this->assertTrue($setup['location']->fresh()->is_active);
    }

    public function test_staff_cannot_manage_locations(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/locations')->assertForbidden();
        $this->actingAs($staff)->post('/admin/locations', [
            'name' => 'X', 'timezone' => 'Europe/Berlin',
        ])->assertForbidden();
    }

    public function test_cannot_edit_location_of_other_tenant(): void
    {
        $a = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $b = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $ownerA = $this->createMember($a['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($ownerA)->put("/admin/locations/{$b['location']->id}", [
            'name' => 'Hijack', 'timezone' => 'Europe/Berlin',
        ])->assertNotFound();

        $this->assertNotSame('Hijack', $b['location']->fresh()->name);
    }
}
