<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingProfile;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BillingPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_an_active_direct_debit_is_named_once_and_can_be_cancelled(): void
    {
        config(['services.gocardless.access_token' => 'sandbox_token']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['price_monthly_minor' => 3900, 'currency' => 'EUR'])->id]);
        BillingProfile::create(['tenant_id' => $setup['tenant']->id, 'billing_email' => 'kunde@beispiel.test']);
        BillingProfile::where('tenant_id', $setup['tenant']->id)->update([
            'gocardless_status' => 'active', 'gocardless_subscription_id' => 'SB123', 'gocardless_mandate_id' => 'MD123',
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/billing')
            ->assertOk()
            ->assertSee('title="Aktiv"', false)
            ->assertDontSee('<span class="sr-only">Aktiv</span>', false)
            ->assertSee('Lastschrift kündigen')
            ->assertDontSee('Per Lastschrift einrichten');
    }
}
