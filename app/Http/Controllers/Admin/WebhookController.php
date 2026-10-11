<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Jobs\DeliverWebhook;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Rules\AllowedOutboundUrl;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Einstellungen › Verbindungen › Webhooks: Endpunkte in der Verwaltung. Sie
 * existierten von Anfang an, ließen sich aber nur über die REST-API anlegen,
 * die ein API-Token und damit das Tarifmerkmal der Schnittstelle braucht.
 * Dieselben Daten, dieselbe Signatur, jetzt erreichbar. Das Geheimnis eines
 * Endpunkts steht genau einmal in der Seite, nach dem Neuladen aus der
 * Sitzung.
 */
class WebhookController extends Controller
{
    use RespondsToSettingsForms;

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

    /** Bezeichnungen der Ereignisse für Restaurants; die Seite zeigt den technischen Namen als Code daneben. */
    public const EVENT_LABELS = [
        'reservation.created' => 'Neue Reservierung',
        'reservation.confirmed' => 'Reservierung bestätigt',
        'reservation.updated' => 'Reservierung geändert',
        'reservation.cancelled' => 'Reservierung storniert',
        'reservation.seated' => 'Gast am Tisch',
        'reservation.completed' => 'Besuch abgeschlossen',
        'reservation.no_show' => 'Gast nicht erschienen',
        'waitlist.created' => 'Gast auf der Warteliste',
        'waitlist.offered' => 'Platz aus der Warteliste angeboten',
        'waitlist.accepted' => 'Wartelistenplatz angenommen',
        'event.booking_created' => 'Neue Eventbuchung',
        'payment.succeeded' => 'Zahlung eingegangen',
        'feedback.received' => 'Feedback erhalten',
    ];

    /** Wortlaut für Salons, wo er vom Restaurant abweicht; alle anderen Bezeichnungen gelten für beide. */
    public const EVENT_LABELS_SALON = [
        'reservation.created' => 'Neuer Termin',
        'reservation.confirmed' => 'Termin bestätigt',
        'reservation.updated' => 'Termin geändert',
        'reservation.cancelled' => 'Termin storniert',
        'reservation.seated' => 'Kunde angekommen',
        'reservation.completed' => 'Termin abgeschlossen',
        'reservation.no_show' => 'Kunde nicht erschienen',
        'waitlist.created' => 'Kunde auf der Warteliste',
        'waitlist.offered' => 'Termin aus der Warteliste angeboten',
        'waitlist.accepted' => 'Wartelistentermin angenommen',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $tenant = $this->context->tenant();
        $logEntries = (int) config('swayy.webhooks.log_entries');
        $disableAfter = (int) config('swayy.webhooks.disable_after');
        $endpoints = WebhookEndpoint::withCount('deliveries')->orderBy('id')->get();

        return view('admin.webhooks.index', [
            'endpoints' => $endpoints,
            'states' => $endpoints->mapWithKeys(fn (WebhookEndpoint $endpoint) => [$endpoint->id => $this->state($endpoint, $disableAfter)]),
            'deliveries' => WebhookDelivery::with('endpoint')->latest('id')->limit($logEntries)->get(),
            'logEntries' => $logEntries,
            'events' => self::EVENTS,
            'salon' => $tenant->isSalon(),
            'eventLabels' => self::eventLabels($tenant->isSalon()),
            'webhooksEnabled' => $tenant->hasFeature('webhooks_enabled'),
            // Das Geheimnis eines neuen oder erneuerten Endpunkts steht nach dem Neuladen einmal in der Seite (Flash der Sitzung).
            'newSecret' => session('new_secret'),
            'newSecretUrl' => session('new_secret_url'),
            'urlMax' => (int) config('swayy.webhooks.url_max'),
        ]);
    }

    public function store(Request $request)
    {
        $this->requireFeature();

        $urlMax = (int) config('swayy.webhooks.url_max');
        $validated = $request->validate([
            // SSRF guard: only https targets resolving to allowed addresses.
            // Delivery checks again (DeliverWebhook).
            'url' => ['bail', 'required', 'string', 'max:'.$urlMax, 'url', new AllowedOutboundUrl],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::in(array_merge(['*'], self::EVENTS))],
        ], [
            'url.required' => __('Bitte die Adresse eintragen, an die Swayy die Ereignisse schickt, etwa https://example.com/swayy-webhook.'),
            'url.string' => __('Die Adresse muss ein Text sein. Bitte eine vollständige Adresse mit https:// eintragen.'),
            'url.max' => __('Die Adresse ist zu lang (höchstens :max Zeichen). Bitte eine kürzere Adresse eintragen.', ['max' => $urlMax]),
            'url.url' => __('Bitte eine vollständige Adresse mit https:// eintragen.'),
            'events.required' => __('Bitte mindestens ein Ereignis anhaken oder „Alle Ereignisse“ wählen.'),
            'events.array' => __('Bitte mindestens ein Ereignis anhaken oder „Alle Ereignisse“ wählen.'),
            'events.min' => __('Bitte mindestens ein Ereignis anhaken oder „Alle Ereignisse“ wählen.'),
            'events.*.string' => __('Dieses Ereignis gibt es nicht. Bitte die Seite neu laden.'),
            'events.*.in' => __('Dieses Ereignis gibt es nicht. Bitte die Seite neu laden.'),
        ]);

        $secret = Str::random(40);
        $endpoint = WebhookEndpoint::create([
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => array_values(array_unique($validated['events'])),
        ]);

        $this->audit->log('webhook.created', $endpoint, null, ['url' => $endpoint->url, 'events' => $endpoint->events]);

        // The secret signs every payload and is never shown again.
        $this->rememberSecret($request, $secret, $endpoint);

        return $this->saved($request, __('Webhook angelegt. Kopiere das Geheimnis jetzt; Swayy zeigt es nur einmal.'), reload: true);
    }

    /**
     * Pause an endpoint, or switch it back on. Re-activating clears the failure
     * counter – otherwise an endpoint auto-disabled after 20 failures would fall
     * over again on the very next delivery.
     */
    public function toggle(Request $request, WebhookEndpoint $endpoint)
    {
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $activate = ! $endpoint->is_active;
        $endpoint->update([
            'is_active' => $activate,
            'failure_count' => $activate ? 0 : $endpoint->failure_count,
            'disabled_at' => $activate ? null : now(),
        ]);

        if ($activate) {
            $this->audit->log('webhook.activated', $endpoint, null, ['url' => $endpoint->url]);
        } else {
            $this->audit->log('webhook.deactivated', $endpoint, null, ['url' => $endpoint->url]);
        }

        return $this->saved($request, $activate ? __('Webhook aktiviert.') : __('Webhook pausiert.'), reload: true);
    }

    public function rotateSecret(Request $request, WebhookEndpoint $endpoint)
    {
        $this->requireFeature();
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $secret = Str::random(40);
        $endpoint->update(['secret' => $secret]);

        $this->audit->log('webhook.secret_rotated', $endpoint, null, ['url' => $endpoint->url]);

        $this->rememberSecret($request, $secret, $endpoint);

        return $this->saved($request, __('Neues Geheimnis erzeugt. Das alte gilt ab sofort nicht mehr. Kopiere das neue jetzt; Swayy zeigt es nur einmal.'), reload: true);
    }

    /**
     * Send a test event through the real delivery path (queue, signature,
     * retries, delivery log) so the receiving side can be verified.
     */
    public function ping(Request $request, WebhookEndpoint $endpoint)
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

        return $this->saved($request, __('Testereignis verschickt. Das Ergebnis steht gleich im Zustellprotokoll.'), reload: true);
    }

    public function destroy(Request $request, WebhookEndpoint $endpoint)
    {
        abort_if($endpoint->tenant_id !== $this->context->tenantId(), 404);

        $this->audit->log('webhook.deleted', $endpoint, ['url' => $endpoint->url]);
        $endpoint->delete();

        return $this->saved($request, __('Webhook gelöscht.'), reload: true);
    }

    /**
     * Bezeichnungen der Ereignisse; Salons lesen „Termin“ und „Kunde“.
     *
     * @return array<string, string>
     */
    public static function eventLabels(bool $salon): array
    {
        return $salon ? array_merge(self::EVENT_LABELS, self::EVENT_LABELS_SALON) : self::EVENT_LABELS;
    }

    /**
     * Zustand für die Liste: Symbol und Wort. Ein Endpunkt, der die Schwelle gescheiterter Ereignisse erreicht hat,
     * schaltete sich selbst ab; jeder andere abgeschaltete wurde pausiert.
     *
     * @return array{state: string, label: string}
     */
    private function state(WebhookEndpoint $endpoint, int $disableAfter): array
    {
        return match (true) {
            $endpoint->is_active => ['state' => 'ok', 'label' => __('aktiv')],
            $endpoint->failure_count >= $disableAfter => ['state' => 'error', 'label' => __('abgeschaltet nach Fehlern')],
            default => ['state' => 'off', 'label' => __('pausiert')],
        };
    }

    /**
     * Das Geheimnis signiert jede Nachricht und erscheint einmal, nach dem Neuladen der Seite.
     */
    private function rememberSecret(Request $request, string $secret, WebhookEndpoint $endpoint): void
    {
        $request->session()->flash('new_secret', $secret);
        $request->session()->flash('new_secret_url', $endpoint->url);
    }

    private function requireFeature(): void
    {
        abort_unless(
            $this->context->tenant()->hasFeature('webhooks_enabled'),
            403,
            __('Webhooks sind in deinem Tarif nicht enthalten. Unter „Abo & Rechnungen“ kannst du den Tarif wechseln.')
        );
    }
}
