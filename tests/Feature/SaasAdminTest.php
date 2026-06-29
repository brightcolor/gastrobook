<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SaasAdminTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function superAdmin(): User
    {
        return User::factory()->create(['saas_role' => 'super_admin']);
    }

    public function test_dashboard_loads_for_saas_admin(): void
    {
        $this->actingAs($this->superAdmin())
            ->get('/saas')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_non_saas_user_is_forbidden(): void
    {
        $setup = $this->createTenantSetup();
        $member = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($member)->get('/saas')->assertForbidden();
        $this->actingAs($member)->get('/saas/users')->assertForbidden();
    }

    public function test_super_admin_can_create_platform_user(): void
    {
        $this->actingAs($this->superAdmin())->post('/saas/users', [
            'name' => 'Neue Kraft',
            'email' => 'neu@swayy.test',
            'password' => 'supersecret123',
            'saas_role' => 'support_admin',
        ])->assertRedirect();

        $user = User::where('email', 'neu@swayy.test')->first();
        $this->assertNotNull($user);
        $this->assertSame('support_admin', $user->saas_role);
        $this->assertTrue($user->is_active);
    }

    public function test_role_can_be_changed_and_last_super_admin_protected(): void
    {
        $admin = $this->superAdmin();

        // Only super admin → cannot be demoted.
        $this->actingAs($admin)->put("/saas/users/{$admin->id}/role", ['saas_role' => 'support_admin'])
            ->assertSessionHasErrors('saas_role');
        $this->assertSame('super_admin', $admin->fresh()->saas_role);

        // With a second super admin present, demotion is allowed.
        $second = $this->superAdmin();
        $this->actingAs($admin)->put("/saas/users/{$second->id}/role", ['saas_role' => null])
            ->assertRedirect();
        $this->assertNull($second->fresh()->saas_role);
    }

    public function test_cannot_delete_self_or_last_super_admin(): void
    {
        $admin = $this->superAdmin();
        $this->actingAs($admin)->delete("/saas/users/{$admin->id}")->assertStatus(422);
        $this->assertNotNull(User::find($admin->id));
    }

    public function test_readonly_admin_cannot_create_users(): void
    {
        $ro = User::factory()->create(['saas_role' => 'readonly_admin']);
        $this->actingAs($ro)->get('/saas/users')->assertOk(); // may view
        $this->actingAs($ro)->post('/saas/users', [
            'name' => 'X', 'email' => 'x@swayy.test', 'password' => 'supersecret123',
        ])->assertForbidden(); // but not create
    }
}
