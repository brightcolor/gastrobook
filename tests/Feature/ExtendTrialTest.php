<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtendTrialTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_extend_trial_and_reactivate(): void
    {
        $tenant = Tenant::factory()->create([
            'status' => 'trial_expired',
            'trial_ends_at' => now()->subDays(3),
            'plan_id' => Plan::factory(),
        ]);
        $admin = User::factory()->create(['saas_role' => 'super_admin']);

        $this->actingAs($admin)
            ->put("/saas/tenants/{$tenant->id}/trial", ['days' => 14])
            ->assertRedirect()
            ->assertSessionHas('success');

        $tenant->refresh();
        $this->assertSame('active', $tenant->status);
        $this->assertTrue($tenant->trial_ends_at->isFuture());
        $this->assertDatabaseHas('audit_logs', ['action' => 'tenant.trial_extended']);
    }

    public function test_tenant_admin_cannot_extend_trial(): void
    {
        $tenant = Tenant::factory()->create(['plan_id' => Plan::factory()]);
        $user = User::factory()->create(['saas_role' => null]);

        $this->actingAs($user)
            ->put("/saas/tenants/{$tenant->id}/trial", ['days' => 14])
            ->assertForbidden();
    }
}
