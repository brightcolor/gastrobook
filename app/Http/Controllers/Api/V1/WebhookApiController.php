<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Admin\WebhookController as AdminWebhookController;
use App\Http\Controllers\Controller;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use App\Support\OutboundUrlGuard;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class WebhookApiController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $this->authorizeWebhooks($request);

        return response()->json(['data' => WebhookEndpoint::all()->map(fn ($e) => [
            'id' => $e->id, 'url' => $e->url, 'events' => $e->events,
            'is_active' => $e->is_active, 'failure_count' => $e->failure_count,
        ])]);
    }

    public function store(Request $request)
    {
        $this->authorizeWebhooks($request);

        $validated = $request->validate([
            'url' => [
                'required', 'url:https',
                // SSRF guard: reject URLs resolving to private/loopback/reserved IPs.
                function (string $attr, mixed $value, callable $fail) {
                    if (! is_string($value) || ! OutboundUrlGuard::isAllowed($value)) {
                        $fail(__('Die URL muss öffentlich erreichbar sein (keine internen/privaten Adressen).'));
                    }
                },
            ],
            'events' => ['required', 'array', 'min:1'],
            // Dieselbe Liste wie im Admin und in der OpenAPI-Beschreibung. Ohne
            // sie legt ein Tippfehler ("reservation.create") einen Endpunkt an,
            // der gesund aussieht und nie irgendetwas zustellt.
            'events.*' => ['string', Rule::in(array_merge(['*'], AdminWebhookController::EVENTS))],
        ]);

        $secret = Str::random(40);
        $endpoint = WebhookEndpoint::create([
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => $validated['events'],
        ]);

        $this->audit->log('webhook.created', $endpoint, null, ['url' => $endpoint->url]);

        return response()->json(['data' => [
            'id' => $endpoint->id,
            'url' => $endpoint->url,
            'events' => $endpoint->events,
            'secret' => $secret, // shown exactly once
        ]], 201);
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint)
    {
        $this->authorizeWebhooks($request);

        // Siehe GuestApiController::show – das Binding passiert vor
        // ResolveApiTenant, der globale Scope greift hier also nicht.
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $this->audit->log('webhook.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();

        return response()->json([], 204);
    }

    /**
     * Umfang UND Tarifmerkmal.
     *
     * Der gleichwertige Weg ueber die Verwaltung prueft webhooks_enabled seit
     * jeher; hier fehlte es. Ein Betrieb, dem das Merkmal abgeschaltet wurde,
     * konnte ueber die Schnittstelle weiter Endpunkte anlegen - ausgeliefert
     * wurde danach nichts, die Oberflaeche zeigte aber Endpunkte, die nie
     * zustellen.
     */
    private function authorizeWebhooks(Request $request): void
    {
        abort_unless($request->user()->tokenCan('webhooks:manage'), 403);
        abort_unless($this->context->tenant()?->hasFeature('webhooks_enabled'), 403, 'Webhooks sind in diesem Tarif nicht enthalten.');
    }
}
