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

            // afterCommit hier, nicht beim Aufrufer: Die Queue steht in
            // Produktion auf Redis mit after_commit=false. Wird dieser Dienst
            // aus einer offenen Transaktion gerufen, liegt der Job schon in
            // Redis, waehrend die Zeile noch nicht committet ist - der Worker
            // laeuft in einem eigenen Container, findet sie nicht und kehrt
            // still zurueck. Kein Fehler, keine Wiederholung, der Webhook geht
            // nie raus. So ist die Stelle unabhaengig vom Aufrufer sicher.
            DeliverWebhook::dispatch($delivery->id)->afterCommit();
        }
    }
}
