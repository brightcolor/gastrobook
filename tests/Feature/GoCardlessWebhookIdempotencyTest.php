<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingProfile;
use App\Models\Plan;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GoCardlessWebhookIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.gocardless.webhook_secret' => 'test-secret']);
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, 'test-secret');
    }

    public function test_replayed_event_is_processed_only_once(): void
    {
        Mail::fake();

        $tenant = Tenant::factory()->create(['plan_id' => Plan::factory()]);
        $profile = BillingProfile::create([
            'tenant_id' => $tenant->id,
            'gocardless_mandate_id' => 'MD123',
            'gocardless_status' => 'active',
        ]);

        $payload = json_encode([
            'events' => [[
                'id' => 'EV123',
                'resource_type' => 'mandates',
                'action' => 'cancelled',
                'links' => ['mandate' => 'MD123'],
            ]],
        ]);
        $signature = $this->sign($payload);

        // First delivery: processed, mandate marked cancelled.
        $this->call('POST', '/webhooks/gocardless', [], [], [], [
            'HTTP_Webhook-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $profile = $profile->fresh();
        $this->assertSame('cancelled', $profile->gocardless_status);
        $this->assertSame(1, DB::table('gocardless_webhook_events')->count());

        // Simulate the mandate being reactivated in the meantime.
        $profile->update(['gocardless_status' => 'active']);

        // Replayed delivery of the *same* event must not flip it back to cancelled.
        $this->call('POST', '/webhooks/gocardless', [], [], [], [
            'HTTP_Webhook-Signature' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertOk();

        $this->assertSame(1, DB::table('gocardless_webhook_events')->count());
        $this->assertSame('active', $profile->fresh()->gocardless_status);
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payload = json_encode(['events' => []]);

        $this->call('POST', '/webhooks/gocardless', [], [], [], [
            'HTTP_Webhook-Signature' => 'wrong',
            'CONTENT_TYPE' => 'application/json',
        ], $payload)->assertStatus(401);
    }
}
