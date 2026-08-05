<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\AuditLogger;
use App\Support\OutboundUrlGuard;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Webhook endpoints in the admin UI. They existed from the start, but could
 * only ever be created through the REST API – which needs an API token, which
 * needs the API feature. Same data, same signing, just reachable.
 */
class WebhookController extends Controller
{
    /** Events the application actually emits (see WebhookDispatchService callers). */
    public const EVENTS = [
        'reservation.created',
        'reservation.confirmed',
        'reservation.updated',
        'reservation.cancelled',
        'reservation.seated',
        'reservation.completed',
        'reservation.no_show',
        'waitlist.created',
        'waitlist.offered',
        'waitlist.accepted',
        'event.booking_created',
        'payment.succeeded',
        'feedback.received',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        return view('admin.webhooks.index', [
            'endpoints' => WebhookEndpoint::withCount('deliveries')->orderBy('id')->get(),
            'deliveries' => WebhookDelivery::with('endpoint')->latest('id')->limit(25)->get(),
            'events' => self::EVENTS,
            'webhooksEnabled' => $this->context->tenant()->hasFeature('webhooks_enabled'),
        ]);
    }

    public function store(Request $request)
    {
        $this->requireFeature();

        $validated = $request->validate([
            'url' => [
                'required', 'url:https',
                // SSRF guard: reject URLs resolving to private/loopback/reserved IPs.
                function (string $attr, mixed $value, callable $fail) {
                    if (! is_string($value) || ! OutboundUrlGuard::isAllowed($value)) {
                        $fail(__('Die URL muss öffentlich per https erreichbar sein (keine internen oder privaten Adressen).'));
                    }
                },
            ],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', 'in:*,'.implode(',', self::EVENTS)],
        ]);

        $secret = Str::random(40);
        $endpoint = WebhookEndpoint::create([
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($validated['events'])),
        ]);

        $this->audit->log('webhook.created', $endpoint, null, ['url' => $endpoint->url, 'events' => $endpoint->events]);

        // The secret signs every payload and is never shown again.
        return back()->with('new_secret', $secret)->with('success', __('Webhook angelegt.'));
    }

    /**
     * Pause an endpoint, or switch it back on. Re-activating clears the failure
     * counter – otherwise an endpoint auto-disabled after 20 failures would fall
     * over again on the very next delivery.
     */
    public function toggle(WebhookEndpoint $endpoint)
    {
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $activate = ! $endpoint->is_active;
        $endpoint->update([
            'is_active' => $activate,
            'failure_count' => $activate ? 0 : $endpoint->failure_count,
            'disabled_at' => $activate ? null : now(),
        ]);

        $this->audit->log($activate ? 'webhook.activated' : 'webhook.deactivated', $endpoint, null, ['url' => $endpoint->url]);

        return back()->with('success', $activate ? __('Webhook aktiviert.') : __('Webhook pausiert.'));
    }

    public function rotateSecret(WebhookEndpoint $endpoint)
    {
        $this->requireFeature();
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $secret = Str::random(40);
        $endpoint->update(['secret' => $secret]);

        $this->audit->log('webhook.secret_rotated', $endpoint, null, ['url' => $endpoint->url]);

        return back()->with('new_secret', $secret)->with('success', __('Neues Secret erzeugt – der alte Schlüssel gilt ab sofort nicht mehr.'));
    }

    /**
     * Send a test event through the real delivery path (queue, signature,
     * retries, delivery log) so the receiving side can be verified.
     */
    public function ping(WebhookEndpoint $endpoint)
    {
        $this->requireFeature();
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $tenant = $this->context->tenant();
        $delivery = WebhookDelivery::create([
            'tenant_id' => $tenant->id,
            'webhook_endpoint_id' => $endpoint->id,
            'event' => 'ping',
            'payload' => [
                'event' => 'ping',
                'version' => '1',
                'tenant' => $tenant->slug,
                'created_at' => now()->toIso8601String(),
                'data' => ['message' => 'Testereignis aus der Swayy-Verwaltung.'],
            ],
            'status' => 'pending',
        ]);

        DeliverWebhook::dispatch($delivery->id);

        $this->audit->log('webhook.ping', $endpoint, null, ['url' => $endpoint->url]);

        return back()->with('success', __('Testereignis verschickt – das Ergebnis steht gleich im Zustellprotokoll.'));
    }

    public function destroy(WebhookEndpoint $endpoint)
    {
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $this->audit->log('webhook.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();

        return back()->with('success', __('Webhook gelöscht.'));
    }

    private function requireFeature(): void
    {
        abort_unless(
            $this->context->tenant()->hasFeature('webhooks_enabled'),
            403,
            'Webhooks sind in diesem Tarif nicht enthalten.'
        );
    }
}
