<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Ein bezahlter Tarif darf nicht wegen einer alten Testfrist aussperren.
 *
 * Frueher liess ein Tarifwechsel `trial_ends_at` stehen; lief diese Frist ab,
 * kippte EnsureTrialActive den zahlenden Betrieb auf `trial_expired` und
 * sperrte den Login. Der Betreiber setzte den Tarif erneut - ohne Wirkung
 * ("Tarif wird nicht gespeichert"), weil die Frist blieb.
 */
class PaidPlanTrialGateTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function paidPlan(): Plan
    {
        return Plan::factory()->create(['key' => 'professional', 'trial_days' => 0]);
    }

    private function trialPlan(): Plan
    {
        return Plan::factory()->create(['key' => 'trial', 'trial_days' => 30]);
    }

    public function test_a_paid_tenant_with_an_elapsed_trial_date_can_still_work(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => $this->paidPlan()->id, 'status' => 'active', 'trial_ends_at' => now()->subDay()]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->assertSame('active', $setup['tenant']->fresh()->status);
    }

    public function test_a_paid_tenant_wrongly_marked_expired_is_reactivated(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => $this->paidPlan()->id, 'status' => 'trial_expired', 'trial_ends_at' => now()->subWeek()]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin')->assertOk();
        $this->assertSame('active', $setup['tenant']->fresh()->status);
    }

    public function test_a_trial_tenant_with_an_elapsed_trial_is_still_blocked(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => $this->trialPlan()->id, 'status' => 'active', 'trial_ends_at' => now()->subDay()]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin')->assertRedirect(route('admin.trial.expired'));
        $this->assertSame('trial_expired', $setup['tenant']->fresh()->status);
    }

    public function test_assigning_a_paid_plan_clears_the_trial_date_and_reactivates(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => $this->trialPlan()->id, 'status' => 'trial_expired', 'trial_ends_at' => now()->subDay()]);
        $paid = $this->paidPlan();
        $super = User::factory()->create(['saas_role' => 'super_admin']);
        $this->clearTenantContext();

        $this->actingAs($super)
            ->put("/saas/tenants/{$setup['tenant']->id}/plan", ['plan_id' => $paid->id])
            ->assertRedirect()->assertSessionHas('success');

        $t = $setup['tenant']->fresh();
        $this->assertSame($paid->id, $t->plan_id);
        $this->assertNull($t->trial_ends_at);
        $this->assertSame('active', $t->status);
    }

    public function test_assigning_the_trial_plan_sets_a_fresh_trial_window(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => $this->paidPlan()->id, 'trial_ends_at' => null]);
        $trial = $this->trialPlan();
        $super = User::factory()->create(['saas_role' => 'super_admin']);
        $this->clearTenantContext();

        $this->actingAs($super)
            ->put("/saas/tenants/{$setup['tenant']->id}/plan", ['plan_id' => $trial->id])
            ->assertRedirect();

        $t = $setup['tenant']->fresh();
        $this->assertNotNull($t->trial_ends_at);
        $this->assertTrue($t->trial_ends_at->isFuture());
    }
}
