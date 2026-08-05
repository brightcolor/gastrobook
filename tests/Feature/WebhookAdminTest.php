<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Webhooks used to be reachable only through the REST API – which needs an API
 * token, which needs the API feature. These tests cover the admin UI on top of
 * the very same endpoints.
 *
 * URLs use a literal public IP so the SSRF guard resolves without DNS.
 */
class WebhookAdminTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const URL = 'https://93.184.216.34/swayy-hook';

    private function endpoint(int $tenantId, array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::create(array_merge([
            'tenant_id' => $tenantId,
            'url' => self::URL,
            'secret' => 'secret-one',
            'events' => ['reservation.created'],
            'is_active' => true,
        ], $attributes));
    }

    public function test_admin_can_create_a_webhook_and_sees_the_secret_once(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks', [
            'url' => self::URL,
            'events' => ['reservation.created', 'reservation.cancelled'],
        ])->assertRedirect()->assertSessionHas('new_secret');

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(self::URL, $endpoint->url);
        $this->assertSame(['reservation.created', 'reservation.cancelled'], $endpoint->events);
        $this->assertTrue($endpoint->is_active);
        $this->assertNotEmpty($endpoint->secret);
    }

    public function test_internal_addresses_are_refused(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks', [
            'url' => 'https://127.0.0.1/hook',
            'events' => ['*'],
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_unknown_events_are_refused(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks', [
            'url' => self::URL,
            'events' => ['reservation.exploded'],
        ])->assertSessionHasErrors('events.0');

        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_reactivating_clears_the_failure_counter(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        // Exactly the state the delivery job leaves behind after 20 failures.
        $endpoint = $this->endpoint($setup['tenant']->id, [
            'is_active' => false,
            'failure_count' => 20,
            'disabled_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks/'.$endpoint->id.'/toggle')->assertRedirect();

        $fresh = WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint->id);
        $this->assertTrue($fresh->is_active);
        $this->assertSame(0, $fresh->failure_count);
        $this->assertNull($fresh->disabled_at);
    }

    public function test_pausing_stops_an_endpoint(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks/'.$endpoint->id.'/toggle')->assertRedirect();

        $this->assertFalse(WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint->id)->is_active);
    }

    public function test_a_test_event_goes_through_the_normal_delivery_path(): void
    {
        Queue::fake();

        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks/'.$endpoint->id.'/ping')->assertRedirect();

        $delivery = WebhookDelivery::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('ping', $delivery->event);
        $this->assertSame($endpoint->id, $delivery->webhook_endpoint_id);
        Queue::assertPushed(DeliverWebhook::class);
    }

    public function test_the_secret_can_be_rotated(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks/'.$endpoint->id.'/rotate-secret')
            ->assertRedirect()->assertSessionHas('new_secret');

        $this->assertNotSame('secret-one', WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint->id)->secret);
    }

    public function test_a_webhook_can_be_deleted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($admin)->delete('/admin/webhooks/'.$endpoint->id)->assertRedirect();

        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_another_tenants_webhook_is_out_of_reach(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $foreign = $this->endpoint($other['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($admin)->delete('/admin/webhooks/'.$foreign->id)->assertNotFound();
        $this->assertSame(1, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_service_staff_cannot_manage_webhooks(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/webhooks')->assertForbidden();
    }

    public function test_a_plan_without_webhooks_cannot_create_one(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['webhooks_enabled' => false]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/webhooks', [
            'url' => self::URL,
            'events' => ['*'],
        ])->assertForbidden();
    }
}
