<?php

namespace App\Services;

use App\Jobs\DeliverWebhook;
use App\Models\Tenant;
use App\Models\WebhookDelivery;

class WebhookDispatchService
{
    /**
     * Queue delivery of an event to all subscribed endpoints of the tenant.
     */
    public function dispatch(Tenant $tenant, string $event, array $payload): void
    {
        if (! $tenant->hasFeature('webhooks_enabled')) {
            return;
        }

        $endpoints = $tenant->webhookEndpoints()
            ->where('is_active', true)
            ->get()
            ->filter(fn ($endpoint) => $endpoint->subscribesTo($event));

        foreach ($endpoints as $endpoint) {
            $delivery = WebhookDelivery::create([
                'tenant_id' => $tenant->id,
                'webhook_endpoint_id' => $endpoint->id,
                'event' => $event,
                'payload' => [
                    'event' => $event,
                    'version' => '1',
                    'tenant' => $tenant->slug,
                    'created_at' => now()->toIso8601String(),
                    'data' => $payload,
                ],
                'status' => 'pending',
            ]);

            DeliverWebhook::dispatch($delivery->id);
        }
    }
}
