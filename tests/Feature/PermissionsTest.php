<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_staff_can_view_reservations_but_not_manage_users(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/reservations')->assertOk();
        $this->actingAs($staff)->get('/admin/users')->assertForbidden();
        $this->actingAs($staff)->get('/admin/settings')->assertForbidden();
        $this->actingAs($staff)->get('/admin/audit')->assertForbidden();
    }

    public function test_readonly_cannot_create_reservations(): void
    {
        $setup = $this->createTenantSetup();
        $readonly = $this->createMember($setup['tenant'], 'readonly');
        $this->clearTenantContext();

        $this->actingAs($readonly)->get('/admin/reservations')->assertOk();
        $this->actingAs($readonly)->post('/admin/reservations', [])->assertForbidden();
    }

    public function test_tenant_admin_can_access_settings_and_users(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/settings')->assertOk();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->actingAs($admin)->get('/admin/audit')->assertOk();
    }

    public function test_saas_area_is_blocked_for_normal_users(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/saas/tenants')->assertForbidden();
    }

    public function test_super_admin_can_access_saas_area_and_impersonation_is_audited(): void
    {
        $setup = $this->createTenantSetup();
        $superAdmin = User::factory()->create(['saas_role' => 'super_admin']);
        $this->clearTenantContext();

        $this->actingAs($superAdmin)->get('/saas/tenants')->assertOk();

        $this->actingAs($superAdmin)
            ->post('/saas/tenants/'.$setup['tenant']->id.'/impersonate', ['reason' => 'Support-Ticket #42'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'support.impersonation_started',
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $superAdmin->id,
        ]);
    }

    public function test_location_restricted_user_cannot_access_other_location(): void
    {
        $setup = $this->createTenantSetup();
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);

        $user = $this->createMember($setup['tenant'], 'location_manager', allLocations: false);
        $user->allowedLocations()->attach($setup['location']->id, ['tenant_id' => $setup['tenant']->id]);
        $this->clearTenantContext();

        $this->assertTrue($user->canAccessLocation($setup['tenant'], $setup['location']));
        $this->assertFalse($user->canAccessLocation($setup['tenant'], $otherLocation));

        $this->actingAs($user)
            ->post('/admin/switch-location', ['location_id' => $otherLocation->id])
            ->assertForbidden();
    }
}
