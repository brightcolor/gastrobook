<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\BillingProfile;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DirectDebitTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.gocardless.access_token' => 'sandbox_token',
            'services.gocardless.environment' => 'sandbox',
            'services.gocardless.webhook_secret' => 'whsec',
            'swayy.owner_email' => 'owner@swayy.test',
        ]);
    }

    private function paidTenant(): array
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update([
            'plan_id' => Plan::factory()->create(['price_monthly_minor' => 3900, 'currency' => 'EUR', 'name' => 'Professional'])->id,
        ]);
        BillingProfile::create(['tenant_id' => $setup['tenant']->id, 'billing_email' => 'kunde@test.example']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');

        return [$setup, $owner];
    }

    private function fakeGoCardless(): void
    {
        Http::fake([
            'api-sandbox.gocardless.com/redirect_flows/*/actions/complete' => Http::response([
                'redirect_flows' => ['links' => ['mandate' => 'MD123', 'customer' => 'CU123']],
            ], 200),
            'api-sandbox.gocardless.com/redirect_flows' => Http::response([
                'redirect_flows' => ['id' => 'RE123', 'redirect_url' => 'https://pay-sandbox.gocardless.com/flow/RE123'],
            ], 200),
            'api-sandbox.gocardless.com/subscriptions/*/actions/cancel' => Http::response(['subscriptions' => ['id' => 'SB123', 'status' => 'cancelled']], 200),
            'api-sandbox.gocardless.com/subscriptions' => Http::response(['subscriptions' => ['id' => 'SB123']], 200),
            'api-sandbox.gocardless.com/mandates/*/actions/cancel' => Http::response([], 200),
        ]);
    }

    public function test_setup_redirects_to_gocardless(): void
    {
        $this->fakeGoCardless();
        [$setup, $owner] = $this->paidTenant();
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/billing/direct-debit/setup')
            ->assertRedirect('https://pay-sandbox.gocardless.com/flow/RE123');
    }

    public function test_complete_creates_subscription_and_notifies_both(): void
    {
        Mail::fake();
        $this->fakeGoCardless();
        [$setup, $owner] = $this->paidTenant();
        $this->clearTenantContext();

        // setup first so the session carries the flow token
        $this->actingAs($owner)->get('/admin/billing/direct-debit/setup');
        $this->actingAs($owner)->get('/admin/billing/direct-debit/complete?redirect_flow_id=RE123')
            ->assertRedirect(route('admin.billing.show'));

        $profile = BillingProfile::where('tenant_id', $setup['tenant']->id)->first();
        $this->assertSame('active', $profile->gocardless_status);
        $this->assertSame('SB123', $profile->gocardless_subscription_id);
        $this->assertSame('MD123', $profile->gocardless_mandate_id);
        $this->assertSame('active', $setup['tenant']->fresh()->status);

        // Mail to both the customer and the platform owner.
        Mail::assertQueued(TemplatedMail::class, 2);
    }

    public function test_complete_is_idempotent_no_second_subscription(): void
    {
        $this->fakeGoCardless();
        [$setup, $owner] = $this->paidTenant();
        BillingProfile::where('tenant_id', $setup['tenant']->id)->update([
            'gocardless_status' => 'active', 'gocardless_subscription_id' => 'SBOLD', 'gocardless_mandate_id' => 'MDOLD',
        ]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/billing/direct-debit/setup');
        $this->actingAs($owner)->get('/admin/billing/direct-debit/complete?redirect_flow_id=RE123')
            ->assertRedirect(route('admin.billing.show'));

        // No /subscriptions POST should have happened.
        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/subscriptions'));
        $this->assertSame('SBOLD', BillingProfile::where('tenant_id', $setup['tenant']->id)->first()->gocardless_subscription_id);
    }

    public function test_cancel_stops_subscription_and_notifies_both(): void
    {
        Mail::fake();
        $this->fakeGoCardless();
        [$setup, $owner] = $this->paidTenant();
        BillingProfile::where('tenant_id', $setup['tenant']->id)->update([
            'gocardless_status' => 'active', 'gocardless_subscription_id' => 'SB123', 'gocardless_mandate_id' => 'MD123',
        ]);
        $this->clearTenantContext();

        $this->actingAs($owner)->post('/admin/billing/direct-debit/cancel')->assertRedirect();

        $profile = BillingProfile::where('tenant_id', $setup['tenant']->id)->first();
        $this->assertSame('cancelled', $profile->gocardless_status);
        $this->assertNull($profile->gocardless_subscription_id);
        Mail::assertQueued(TemplatedMail::class, 2);
    }

    public function test_staff_cannot_access_billing(): void
    {
        [$setup] = $this->paidTenant();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/billing')->assertForbidden();
        $this->actingAs($staff)->post('/admin/billing/direct-debit/cancel')->assertForbidden();
    }

    public function test_webhook_marks_mandate_cancelled_and_rejects_bad_signature(): void
    {
        Mail::fake();
        [$setup] = $this->paidTenant();
        BillingProfile::where('tenant_id', $setup['tenant']->id)->update([
            'gocardless_status' => 'active', 'gocardless_subscription_id' => 'SB123', 'gocardless_mandate_id' => 'MD123',
        ]);

        $payload = json_encode([
            'events' => [[
                'resource_type' => 'mandates', 'action' => 'cancelled',
                'links' => ['mandate' => 'MD123'],
            ]],
        ]);
        $sig = hash_hmac('sha256', $payload, 'whsec');

        // Bad signature → rejected.
        $this->call('POST', '/webhooks/gocardless', [], [], [], [
            'HTTP_WEBHOOK_SIGNATURE' => 'deadbeef', 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(401);

        // Valid signature → processed.
        $this->call('POST', '/webhooks/gocardless', [], [], [], [
            'HTTP_WEBHOOK_SIGNATURE' => $sig, 'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertSame('cancelled', BillingProfile::where('tenant_id', $setup['tenant']->id)->first()->gocardless_status);
        Mail::assertQueued(TemplatedMail::class);
    }
}
