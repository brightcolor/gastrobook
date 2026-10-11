<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\BlackoutPeriod;
use App\Models\DepositRule;
use App\Models\IntegrationConnection;
use App\Models\Location;
use App\Models\OpeningHour;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\SeasonPeriod;
use App\Models\Service;
use App\Models\SpecialOpeningHour;
use App\Models\TableBlock;
use App\Rules\AllowedOutboundUrl;
use App\Services\AuditLogger;
use App\Services\Mail\GuestReplyAddress;
use App\Services\Newsletter\MailwizzProvider;
use App\Services\Sms\SevenIoProvider;
use App\Support\BookingSettingRules;
use App\Support\NotificationSettingRules;
use App\Support\OutboundUrlBlocked;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $mailwizz = IntegrationConnection::whereNull('location_id')
            ->where('provider', 'mailwizz')
            ->first();
        $mailwizzCredentials = [];
        if ($mailwizz?->credentials_encrypted) {
            $mailwizzCredentials = json_decode(Crypt::decryptString($mailwizz->credentials_encrypted), true) ?: [];
        }

        $stripe = IntegrationConnection::whereNull('location_id')
            ->where('provider', 'stripe')
            ->first();

        $sms = IntegrationConnection::whereNull('location_id')
            ->where('provider', 'sevenio')
            ->first();
        $smsCredentials = [];
        if ($sms?->credentials_encrypted) {
            $smsCredentials = json_decode(Crypt::decryptString($sms->credentials_encrypted), true) ?: [];
        }

        $paypal = IntegrationConnection::whereNull('location_id')
            ->where('provider', 'paypal')
            ->first();
        $paypalCredentials = [];
        if ($paypal?->credentials_encrypted) {
            $paypalCredentials = json_decode(Crypt::decryptString($paypal->credentials_encrypted), true) ?: [];
        }

        return view('admin.settings.index', [
            'location' => $location,
            'mailwizz' => $mailwizz,
            'mailwizzCredentials' => $mailwizzCredentials,
            'stripe' => $stripe,
            // Wann sich der Stripe-Webhook zuletzt gemeldet hat. Ob der
            // Endpunkt drüben wirklich eingerichtet ist, lässt sich von hier
            // aus nicht abfragen – aber ob je einer angekommen ist, schon.
            'stripeWebhookSeen' => AuditLog::where('tenant_id', $this->context->tenant()->id)
                ->where('action', 'payment.webhook_received')
                ->latest('id')
                ->value('created_at'),
            'paypal' => $paypal,
            'paypalCredentials' => $paypalCredentials,
            'sms' => $sms,
            'smsCredentials' => $smsCredentials,
            'depositRules' => DepositRule::where('location_id', $location->id)->with('service')->orderBy('name')->get(),
            // Aktive Leistungen PLUS die, an denen eine Regel bereits haengt:
            // Fehlt eine inaktive Leistung in der Auswahl, stuende das Feld beim
            // Bearbeiten auf "Alle Leistungen" und die Bindung waere beim naechsten
            // Speichern still verschwunden.
            'salonServices' => $this->context->tenant()->isSalon()
                ? Service::where('location_id', $location->id)
                    ->where(fn ($q) => $q->where('is_active', true)
                        ->orWhereIn('id', DepositRule::where('location_id', $location->id)->whereNotNull('service_id')->select('service_id')))
                    ->orderBy('name')
                    ->get()
                : collect(),
            'settings' => $location->effectiveSettings(),
            // „Weniger Gäste" bei Sperrzeiten wirkt nur im Platz-Modus – das
            // Formular blendet die Option sonst aus.
            'coversMode' => in_array($location->effectiveSettings()->capacity_mode, ['person', 'hybrid'], true),
            'rooms' => $location->rooms()->withCount('tables')->orderBy('sort_order')->get(),
            'tables' => $location->tables()->with('room')->orderBy('sort_order')->get(),
            'openingHours' => $location->openingHours()->orderBy('weekday')->orderBy('opens_at')->get(),
            'specialHours' => $location->specialOpeningHours()->where('date', '>=', now()->subDay())->orderBy('date')->get(),
            'combinations' => $location->tableCombinations()->with('tables')->get(),
            'blackouts' => $location->blackoutPeriods()->with('room')->where('ends_at', '>=', now())->orderBy('starts_at')->get(),
            'seasons' => $location->seasonPeriods()->get(),
            'tableBlocks' => TableBlock::where('location_id', $location->id)
                ->with('table.room')
                ->where('ends_at', '>=', now())
                ->orderBy('starts_at')
                ->get(),
        ]);
    }

    public function updateBookingRules(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(array_merge(
            BookingSettingRules::capacity($location->effectiveSettings(), salon: false),
            BookingSettingRules::booking(),
            // Erinnerung, Feedback, Anrede und Info an den Betrieb prüfen wie auf der Seite „Benachrichtigungen“,
            // mit denselben Grenzen aus config/swayy.php. Die alte Sammelseite sendet nur angehakte Kästchen;
            // ein fehlendes Kästchen gilt als „aus“.
            NotificationSettingRules::rules(switchesRequired: false),
            [
                // Auch hier sendet nur ein angehaktes Kästchen etwas.
                'waitlist_enabled' => ['nullable', 'boolean'],
                'walkins_enabled' => ['nullable', 'boolean'],
                'require_email_confirmation' => ['nullable', 'boolean'],
                'gap_optimization_enabled' => ['nullable', 'boolean'],
                'public_floorplan_enabled' => ['nullable', 'boolean'],
                'refund_mode' => ['required', 'in:off,manual,auto'],
                'refund_percent' => ['required', 'integer', 'min:0', 'max:100'],
                'refund_processing' => ['required', 'in:immediate,scheduled'],
                'confetti_on_booking' => ['nullable', 'boolean'],
            ],
        ), array_merge(BookingSettingRules::messages(), NotificationSettingRules::messages()));

        $validated['duration_rules'] = BookingSettingRules::durationRules((array) $request->input('duration_rules', []), atField: false);

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($validated));

        $mode = $request->input('booking_confirmation_mode');
        $settings->update($validated + [
            'auto_confirm' => $mode === 'auto',
            'request_only' => false,
            'waitlist_enabled' => $request->boolean('waitlist_enabled'),
            'walkins_enabled' => $request->boolean('walkins_enabled'),
            'reminder_enabled' => $request->boolean('reminder_enabled'),
            'sms_reminder_enabled' => $request->boolean('sms_reminder_enabled'),
            'gap_optimization_enabled' => $request->boolean('gap_optimization_enabled'),
            'public_floorplan_enabled' => $request->boolean('public_floorplan_enabled'),
            'require_email_confirmation' => $request->boolean('require_email_confirmation'),
            'confetti_on_booking' => $request->boolean('confetti_on_booking'),
            'feedback_enabled' => $request->boolean('feedback_enabled'),
            'owner_notification_enabled' => $request->boolean('owner_notification_enabled'),
        ]);

        $this->audit->log('location.settings_updated', $settings, $old, $validated);

        return $this->saved($request, __('Buchungsregeln gespeichert.'));
    }

    /**
     * MailWizz newsletter integration (tenant-wide). Credentials are stored
     * encrypted; the API key is never displayed again after saving.
     */
    public function updateMailwizz(Request $request)
    {
        $tenant = $this->context->tenant();

        $validated = $request->validate([
            // Der Server ruft diese Adresse selbst auf - also dieselbe
            // Zielpruefung wie bei Webhook-Endpunkten. Jede Anfrage prueft
            // spaeter noch einmal (MailwizzProvider).
            'api_url' => ['required', 'string', 'bail', 'url', new AllowedOutboundUrl],
            'api_key' => ['nullable', 'string', 'max:255'],
            'list_uid' => ['required', 'string', 'max:64'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $connection = IntegrationConnection::firstOrNew([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => 'mailwizz',
        ]);

        $credentials = [];
        if ($connection->credentials_encrypted) {
            $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true) ?: [];
        }
        $credentials['api_url'] = $validated['api_url'];
        $credentials['list_uid'] = $validated['list_uid'];
        if (! empty($validated['api_key'])) {
            $credentials['api_key'] = $validated['api_key'];
        }

        if (empty($credentials['api_key'])) {
            return $this->failed($request, 'api_key', __('Der API-Key fehlt. Bitte den API-Key aus MailWizz eintragen (in MailWizz unter Account → API).'));
        }

        $connection->credentials_encrypted = Crypt::encryptString(json_encode($credentials));
        $connection->status = $request->boolean('enabled', true) ? 'connected' : 'disconnected';
        // Ein alter Fehlergrund gehoert zur alten Eingabe.
        $settings = Arr::except($connection->settings ?? [], 'last_error');
        $connection->settings = $settings === [] ? null : $settings;
        $connection->save();

        $this->audit->log('integration.mailwizz_updated', $connection, null, [
            'api_url' => $validated['api_url'],
            'list_uid' => $validated['list_uid'],
            'status' => $connection->status,
        ]);

        if ($connection->status !== 'connected') {
            return $this->saved($request, __('MailWizz-Integration gespeichert. Die Synchronisierung ist ausgeschaltet.'));
        }

        // Verify the connection right away so misconfiguration is visible immediately
        $provider = new MailwizzProvider(
            $credentials['api_url'], $credentials['api_key'], $credentials['list_uid']
        );
        try {
            $response = $provider->testConnection();
        } catch (OutboundUrlBlocked $e) {
            // Zwischen Pruefung beim Speichern und Verbindungstest kann der
            // Name auf eine andere Adresse zeigen - der Test prueft selbst.
            return $this->mailwizzFailed($request, $connection, $e->getMessage());
        } catch (\Throwable) {
            return $this->mailwizzFailed($request, $connection, __('MailWizz ist unter dieser Adresse nicht erreichbar. Bitte die API-URL prüfen oder es später erneut versuchen.'));
        }

        if (! $response->successful()) {
            return $this->mailwizzFailed($request, $connection, $this->mailwizzTestFailure($response->status()));
        }

        return $this->saved($request, __('MailWizz-Integration gespeichert und Verbindung getestet.'));
    }

    /**
     * Anbindung auf "Fehler" setzen und den Grund behalten: Die Meldung zum
     * Speichern verschwindet, der Grund steht danach weiter an der Anbindung.
     */
    private function mailwizzFailed(Request $request, IntegrationConnection $connection, string $message): mixed
    {
        $connection->update([
            'status' => 'error',
            'settings' => array_merge($connection->settings ?? [], ['last_error' => $message]),
        ]);

        return $this->failed($request, 'api_url', $message);
    }

    /**
     * Was eine Antwort ausserhalb von 2xx beim Verbindungstest bedeutet.
     * Weiterleitungen folgt der Test bewusst nicht: Ihr Ziel hat niemand
     * geprueft.
     */
    private function mailwizzTestFailure(int $status): string
    {
        return match (true) {
            $status >= 300 && $status < 400 => __('MailWizz leitet die Anfrage an eine andere Adresse weiter (Status :status). Bitte die Adresse eintragen, auf die MailWizz weiterleitet.', ['status' => $status]),
            $status === 401, $status === 403 => __('MailWizz lehnt den API-Key ab (Status :status). Bitte den API-Key in MailWizz prüfen und neu eintragen.', ['status' => $status]),
            $status === 404 => __('MailWizz findet die Liste unter dieser Adresse nicht (Status 404). Bitte API-URL und Listen-UID prüfen.'),
            default => __('Verbindungstest fehlgeschlagen (Status :status). Bitte API-URL, API-Key und Listen-UID prüfen.', ['status' => $status]),
        };
    }

    /**
     * Stripe payment integration (tenant-wide). Only API references are
     * stored (encrypted) — card data never touches this system.
     */
    public function updateStripe(Request $request)
    {
        $tenant = $this->context->tenant();

        $validated = $request->validate([
            'secret_key' => ['nullable', 'string', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $connection = IntegrationConnection::firstOrNew([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => 'stripe',
        ]);

        $credentials = [];
        if ($connection->credentials_encrypted) {
            $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true) ?: [];
        }
        if (! empty($validated['secret_key'])) {
            $credentials['secret_key'] = $validated['secret_key'];
        }
        if (! empty($validated['webhook_secret'])) {
            $credentials['webhook_secret'] = $validated['webhook_secret'];
        }

        $missing = array_filter([
            'secret_key' => empty($credentials['secret_key'])
                ? __('Der Secret Key fehlt. Bitte ihn aus dem Stripe-Dashboard unter Developers → API keys eintragen.')
                : null,
            'webhook_secret' => empty($credentials['webhook_secret'])
                ? __('Das Webhook-Signing-Secret fehlt. Bitte es im Stripe-Dashboard beim Webhook-Endpunkt abrufen und eintragen.')
                : null,
        ]);
        if ($missing !== []) {
            return $this->rejected($request, $missing);
        }

        $connection->credentials_encrypted = Crypt::encryptString(json_encode($credentials));
        $connection->status = $request->boolean('enabled', true) ? 'connected' : 'disconnected';
        $connection->save();

        $this->audit->log('integration.stripe_updated', $connection, null, ['status' => $connection->status]);

        return $this->saved($request, __('Stripe-Integration gespeichert. Webhook-URL: :url', [
            'url' => route('webhooks.stripe'),
        ]));
    }

    /**
     * PayPal payment integration (tenant-wide). Only API credentials are
     * stored (encrypted); funds go to the operator's own PayPal account.
     */
    public function updatePaypal(Request $request)
    {
        $tenant = $this->context->tenant();

        $validated = $request->validate([
            'client_id' => ['nullable', 'string', 'max:255'],
            'secret' => ['nullable', 'string', 'max:255'],
            'mode' => ['required', 'in:sandbox,live'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $connection = IntegrationConnection::firstOrNew([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => 'paypal',
        ]);

        $credentials = [];
        if ($connection->credentials_encrypted) {
            $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true) ?: [];
        }
        if (! empty($validated['client_id'])) {
            $credentials['client_id'] = $validated['client_id'];
        }
        if (! empty($validated['secret'])) {
            $credentials['secret'] = $validated['secret'];
        }
        $credentials['mode'] = $validated['mode'];

        $missing = array_filter([
            'client_id' => empty($credentials['client_id'])
                ? __('Die Client-ID fehlt. Bitte sie aus dem PayPal Developer Dashboard unter Apps & Credentials eintragen.')
                : null,
            'secret' => empty($credentials['secret'])
                ? __('Das Secret fehlt. Bitte es aus dem PayPal Developer Dashboard unter Apps & Credentials eintragen.')
                : null,
        ]);
        if ($missing !== []) {
            return $this->rejected($request, $missing);
        }

        $connection->credentials_encrypted = Crypt::encryptString(json_encode($credentials));
        $connection->status = $request->boolean('enabled', true) ? 'connected' : 'disconnected';
        $connection->save();

        $this->audit->log('integration.paypal_updated', $connection, null, [
            'mode' => $credentials['mode'],
            'status' => $connection->status,
        ]);

        return $this->saved($request, __('PayPal-Integration gespeichert.'));
    }

    /**
     * SMS integration via seven.io (tenant-wide). API key stored encrypted;
     * never displayed again after saving. Verified on save via balance lookup.
     */
    public function updateSms(Request $request)
    {
        $tenant = $this->context->tenant();

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:255'],
            'sender_id' => ['nullable', 'string', 'max:16'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $connection = IntegrationConnection::firstOrNew([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => 'sevenio',
        ]);

        $credentials = [];
        if ($connection->credentials_encrypted) {
            $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true) ?: [];
        }
        if (! empty($validated['api_key'])) {
            $credentials['api_key'] = $validated['api_key'];
        }
        $credentials['sender_id'] = $validated['sender_id'] ?? ($credentials['sender_id'] ?? '');

        if (empty($credentials['api_key'])) {
            return $this->failed($request, 'api_key', __('Der API-Key fehlt. Bitte den API-Key aus seven.io eintragen (app.seven.io → Einstellungen → API).'));
        }

        $connection->credentials_encrypted = Crypt::encryptString(json_encode($credentials));
        $connection->status = $request->boolean('enabled', true) ? 'connected' : 'disconnected';
        $connection->save();

        $this->audit->log('integration.sms_updated', $connection, null, [
            'sender_id' => $credentials['sender_id'],
            'status' => $connection->status,
        ]);

        if ($connection->status === 'connected') {
            $provider = new SevenIoProvider($credentials['api_key'], $credentials['sender_id']);
            try {
                if (! $provider->testConnection()) {
                    $connection->update(['status' => 'error']);

                    return $this->failed($request, 'api_key', __('Gespeichert, aber seven.io hat den Verbindungstest abgelehnt. Bitte den API-Key unter app.seven.io → Einstellungen → API prüfen und neu eintragen.'));
                }
            } catch (\Throwable) {
                $connection->update(['status' => 'error']);

                return $this->failed($request, 'api_key', __('Gespeichert, aber seven.io war beim Verbindungstest nicht erreichbar. Bitte später noch einmal auf „Speichern“ klicken, um die Verbindung zu testen.'));
            }
        }

        return $this->saved($request, __('SMS-Integration (seven.io) gespeichert und Verbindung getestet.'));
    }

    /**
     * Deposit rule for online reservations (no-show protection).
     */
    public function storeDepositRule(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'min_party_size' => ['nullable', 'integer', 'min:1', 'max:200'],
            'amount_per_person' => ['required', 'numeric', 'min:0', 'max:10000'],
            'flat_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'until_time' => ['nullable', 'date_format:H:i'],
            'payment_deadline_minutes' => ['nullable', 'integer', 'min:10', 'max:10080'],
            'cancel_unpaid_automatically' => ['nullable', 'boolean'],
            'service_id' => ['nullable', 'integer'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'min:0', 'max:6'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $rule = DepositRule::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'type' => 'deposit',
            'service_id' => $this->serviceIdForRule($location, $validated['service_id'] ?? null),
            'room_id' => $this->roomIdForRule($location, $validated['room_id'] ?? null),
            // Leere Auswahl muss null werden, nicht [] - null ist der Wert, den
            // der Rest des Codes als "alle Tage" erwartet.
            'weekdays' => ! empty($validated['weekdays'])
                ? array_values(array_unique(array_map('intval', $validated['weekdays'])))
                : null,
            'min_party_size' => $validated['min_party_size'] ?? null,
            'from_time' => $validated['from_time'] ?? null,
            'until_time' => $validated['until_time'] ?? null,
            'amount_per_person_minor' => (int) round($validated['amount_per_person'] * 100),
            'flat_amount_minor' => (int) round(($validated['flat_amount'] ?? 0) * 100),
            'currency' => $location->currency,
            'payment_deadline_minutes' => (int) ($validated['payment_deadline_minutes'] ?? 60),
            'cancel_unpaid_automatically' => $request->boolean('cancel_unpaid_automatically'),
        ]);

        $this->audit->log('deposit_rule.created', $rule, null, $validated);

        return $this->saved($request, __('Anzahlungsregel angelegt.'), true);
    }

    public function updateDepositRule(Request $request, DepositRule $rule)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_if($rule->location_id !== $location->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'min_party_size' => ['nullable', 'integer', 'min:1', 'max:200'],
            'amount_per_person' => ['required', 'numeric', 'min:0', 'max:10000'],
            'flat_amount' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'from_time' => ['nullable', 'date_format:H:i'],
            'until_time' => ['nullable', 'date_format:H:i'],
            'payment_deadline_minutes' => ['nullable', 'integer', 'min:10', 'max:10080'],
            'cancel_unpaid_automatically' => ['nullable', 'boolean'],
            'service_id' => ['nullable', 'integer'],
            'weekdays' => ['nullable', 'array'],
            'weekdays.*' => ['integer', 'min:0', 'max:6'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $rule->update([
            'name' => $validated['name'],
            // Nur ueberschreiben, wenn das Feld ueberhaupt mitgeschickt wurde –
            // im Restaurantbetrieb (oder ohne Leistungen) gibt es das Auswahlfeld
            // nicht, und die bestehende Bindung darf davon nicht verschwinden.
            'service_id' => $request->has('service_id')
                ? $this->serviceIdForRule($location, $validated['service_id'] ?? null)
                : $rule->service_id,
            'room_id' => $request->has('room_id')
                ? $this->roomIdForRule($location, $validated['room_id'] ?? null)
                : $rule->room_id,
            'weekdays' => $request->has('weekdays')
                ? (! empty($validated['weekdays'])
                    ? array_values(array_unique(array_map('intval', $validated['weekdays'])))
                    : null)
                : $rule->weekdays,
            'min_party_size' => $validated['min_party_size'] ?? null,
            'from_time' => $validated['from_time'] ?? null,
            'until_time' => $validated['until_time'] ?? null,
            'amount_per_person_minor' => (int) round($validated['amount_per_person'] * 100),
            'flat_amount_minor' => (int) round(($validated['flat_amount'] ?? 0) * 100),
            'payment_deadline_minutes' => (int) ($validated['payment_deadline_minutes'] ?? 60),
            'cancel_unpaid_automatically' => $request->boolean('cancel_unpaid_automatically'),
        ]);

        $this->audit->log('deposit_rule.updated', $rule, null, $validated);

        return $this->saved($request, __('Anzahlungsregel aktualisiert.'), true);
    }

    /**
     * A deposit rule may point at one service of this location – or at none,
     * then it applies to every booking. Anything else is dropped rather than
     * silently attached to a foreign service.
     */
    private function serviceIdForRule(Location $location, mixed $serviceId): ?int
    {
        if (empty($serviceId)) {
            return null;
        }

        $id = (int) $serviceId;

        if (! Service::where('location_id', $location->id)->where('id', $id)->exists()) {
            return null;
        }

        return $id;
    }

    /**
     * Wie serviceIdForRule, aber fuer den Raum: Eine fremde ID wird verworfen
     * statt still uebernommen.
     */
    private function roomIdForRule(Location $location, mixed $roomId): ?int
    {
        if (empty($roomId)) {
            return null;
        }

        $id = (int) $roomId;

        if (! Room::where('location_id', $location->id)->where('id', $id)->exists()) {
            return null;
        }

        return $id;
    }

    public function deleteDepositRule(DepositRule $rule)
    {
        abort_if($rule->location_id !== $this->context->locationId(), 404);
        $this->audit->log('deposit_rule.deleted', $rule, ['name' => $rule->name]);
        $rule->delete();

        return back()->with('success', __('Anzahlungsregel gelöscht.'));
    }

    public function uploadLogo(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        // No SVG: an SVG served from our own origin can carry executable
        // JavaScript (stored XSS). Raster formats only (config/swayy.php, uploads.*).
        $request->validate([
            'logo' => ['required', 'image', 'mimes:'.implode(',', (array) config('swayy.uploads.logo_types')), 'max:'.(int) config('swayy.uploads.logo_max_kb')],
        ]);

        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
        }

        $path = $request->file('logo')->store(config('swayy.uploads.logo_directory').'/'.$location->id, 'public');
        $location->update(['brand_logo_path' => $path]);
        $this->audit->log('location.logo.updated', $location);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('Logo aktualisiert.'),
                'logo_url' => route('brand.location.logo', [$location->tenant->slug, $location->slug]),
            ]);
        }

        return back()->with('success', __('Logo aktualisiert.'));
    }

    public function deleteLogo()
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
            $location->update(['brand_logo_path' => null]);
        }
        $this->audit->log('location.logo.deleted', $location);

        return back()->with('success', __('Logo entfernt.'));
    }

    public function updateOpeningHours(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'hours' => ['required', 'array'],
            'hours.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.opens_at' => ['required', 'date_format:H:i'],
            'hours.*.closes_at' => ['required', 'date_format:H:i'],
            'hours.*.service_name' => ['nullable', 'string', 'max:60'],
        ]);

        $location->openingHours()->delete();
        foreach ($validated['hours'] as $hour) {
            OpeningHour::create([
                'tenant_id' => $location->tenant_id,
                'location_id' => $location->id,
                'weekday' => (int) $hour['weekday'],
                'opens_at' => $hour['opens_at'],
                'closes_at' => $hour['closes_at'],
                'service_name' => $hour['service_name'] ?? null,
            ]);
        }

        $this->audit->log('opening_hours.updated', null, null, ['count' => count($validated['hours'])]);

        return $this->saved($request, __('Öffnungszeiten gespeichert.'));
    }

    /**
     * Absendername und Antwortadresse fuer Mails an Gaeste. Leer heisst:
     * Ersatz aus config/swayy.php (guest_mail), siehe GuestMailSender.
     */
    public function updateGuestMail(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate([
            'mail_from_name' => ['nullable', 'string', 'max:100', 'not_regex:/[<>@\r\n"]/'],
            'mail_reply_to' => ['nullable', 'email:rfc', 'max:200'],
            'mail_relay_enabled' => ['sometimes', 'boolean'],
        ], [
            'mail_from_name.max' => __('Der Absendername darf höchstens 100 Zeichen haben. Bitte kürzen.'),
            'mail_from_name.not_regex' => __('Der Absendername darf keine Zeichen wie <, >, @ oder Anführungszeichen enthalten. Bitte nur den Namen des Betriebs eintragen.'),
            'mail_reply_to.email' => __('Die Antwortadresse ist keine gültige E-Mail-Adresse. Bitte so eintragen: info@dein-betrieb.de'),
            'mail_reply_to.max' => __('Die Antwortadresse darf höchstens 200 Zeichen haben.'),
        ]);

        // Die Swayy-Antwortadresse lässt sich nur aktivieren, wenn die Domain
        // eingerichtet ist – sonst zeigte sie ins Leere.
        $relayAn = $request->boolean('mail_relay_enabled');
        if ($relayAn && ! GuestReplyAddress::isConfigured()) {
            return back()->withErrors([
                'mail_relay_enabled' => __('Die Antwortadresse über Swayy ist noch nicht eingerichtet (es fehlt die Domain). Bitte wende dich an den Betreiber, bevor du sie aktivierst.'),
            ]);
        }

        $neu = [
            'mail_from_name' => trim((string) ($validated['mail_from_name'] ?? '')) ?: null,
            'mail_reply_to' => trim((string) ($validated['mail_reply_to'] ?? '')) ?: null,
            'mail_relay_enabled' => $relayAn,
        ];
        $old = ['mail_from_name' => $tenant->mail_from_name, 'mail_reply_to' => $tenant->mail_reply_to, 'mail_relay_enabled' => $tenant->mail_relay_enabled];
        $tenant->update($neu);

        $this->audit->log('tenant.guest_mail_updated', $tenant, $old, $neu);

        return $this->saved($request, __('E-Mail-Einstellungen gespeichert.'), reload: true);
    }

    public function storeSpecialHours(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'closed' => ['nullable', 'boolean'],
            'opens_at' => ['nullable', 'required_unless:closed,1', 'date_format:H:i'],
            'closes_at' => ['nullable', 'required_unless:closed,1', 'date_format:H:i'],
            'label' => ['nullable', 'string', 'max:120'],
            'staff_note' => ['nullable', 'string', 'max:500'],
        ]);

        $special = SpecialOpeningHour::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'date' => $validated['date'],
            'closed' => $request->boolean('closed'),
            'opens_at' => $request->boolean('closed') ? null : $validated['opens_at'],
            'closes_at' => $request->boolean('closed') ? null : $validated['closes_at'],
            'label' => $validated['label'] ?? null,
            'staff_note' => $validated['staff_note'] ?? null,
        ]);

        $this->audit->log('special_hours.created', $special, null, $validated);

        return $this->saved($request, __('Sonderöffnungszeit gespeichert.'), true);
    }

    public function deleteSpecialHours(SpecialOpeningHour $special, Request $request)
    {
        abort_if($special->location_id !== $this->context->locationId(), 404);
        $this->audit->log('special_hours.deleted', $special, [
            'date' => $special->getRawOriginal('date'),
            'label' => $special->label,
        ]);
        $special->delete();

        return $this->saved($request, __('Sonderöffnungszeit gelöscht.'), true);
    }

    public function storeBlackout(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'room_id' => ['nullable', 'integer'],
            'reduce_covers_to' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'reason' => ['nullable', 'string', 'max:200'],
            'staff_note' => ['nullable', 'string', 'max:500'],
        ]);

        // A room filter, if given, must belong to this location.
        $roomId = null;
        if (! empty($validated['room_id'])) {
            if (! $location->rooms()->where('id', $validated['room_id'])->exists()) {
                return $this->failed($request, 'room_id', __('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.'));
            }
            $roomId = (int) $validated['room_id'];
        }

        // "Max. Gäste" (reduce_covers_to) wirkt in jedem Kapazitätsmodus, für
        // den ganzen Betrieb und für einen einzelnen Raum.

        // Interpret the wall-clock input in the location timezone, store UTC
        // (the availability service compares against UTC start/end).
        $tz = $location->timezone;
        $blackout = $location->blackoutPeriods()->create([
            'tenant_id' => $location->tenant_id,
            'room_id' => $roomId,
            'starts_at' => Carbon::parse($validated['starts_at'], $tz)->utc(),
            'ends_at' => Carbon::parse($validated['ends_at'], $tz)->utc(),
            'reduce_covers_to' => $validated['reduce_covers_to'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'staff_note' => $validated['staff_note'] ?? null,
        ]);

        $this->audit->log('blackout.created', $blackout, null, $validated);

        return $this->saved($request, __('Sperrzeit gespeichert.'), true);
    }

    public function deleteBlackout(BlackoutPeriod $blackout, Request $request)
    {
        abort_if($blackout->location_id !== $this->context->locationId(), 404);
        $this->audit->log('blackout.deleted', $blackout, ['reason' => $blackout->reason]);
        $blackout->delete();

        return $this->saved($request, __('Sperrzeit gelöscht.'), true);
    }

    public function storeSeason(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'start_day' => ['required', 'integer', 'min:1', 'max:31'],
            'end_month' => ['required', 'integer', 'min:1', 'max:12'],
            'end_day' => ['required', 'integer', 'min:1', 'max:31'],
        ]);

        // Den Tag gegen den Monat prüfen; 2024 ist ein Schaltjahr, damit der
        // 29. Februar erlaubt bleibt.
        if (! checkdate((int) $validated['start_month'], (int) $validated['start_day'], 2024)) {
            return $this->failed($request, 'start_day', __('Diesen Tag gibt es in dem Monat nicht. Bitte einen gültigen Tag wählen.'));
        }
        if (! checkdate((int) $validated['end_month'], (int) $validated['end_day'], 2024)) {
            return $this->failed($request, 'end_day', __('Diesen Tag gibt es in dem Monat nicht. Bitte einen gültigen Tag wählen.'));
        }

        $season = $location->seasonPeriods()->create([
            'tenant_id' => $location->tenant_id,
            'label' => $validated['label'] ?? null,
            'start_month' => (int) $validated['start_month'],
            'start_day' => (int) $validated['start_day'],
            'end_month' => (int) $validated['end_month'],
            'end_day' => (int) $validated['end_day'],
        ]);

        $this->audit->log('season.created', $season, null, $validated);

        return $this->saved($request, __('Saison gespeichert.'), true);
    }

    public function deleteSeason(SeasonPeriod $season, Request $request)
    {
        abort_if($season->location_id !== $this->context->locationId(), 404);
        $this->audit->log('season.deleted', $season, ['label' => $season->label]);
        $season->delete();

        return $this->saved($request, __('Saison gelöscht.'), true);
    }

    /**
     * Block a single table for a period (defect, reserved for staff, repairs …).
     * A blackout closes the whole location or a room – this is the per-table
     * counterpart, honoured by the auto-assignment, the manual table choice,
     * the floor plan and the live board.
     */
    public function storeTableBlock(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'restaurant_table_id' => ['required', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:200'],
        ]);

        $tableId = (int) $validated['restaurant_table_id'];
        if (! RestaurantTable::where('location_id', $location->id)->where('id', $tableId)->exists()) {
            return $this->failed($request, 'restaurant_table_id', __('Dieser Tisch gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Tisch aus der Liste wählen.'));
        }

        // Wall-clock input is in location time; the availability logic compares UTC.
        $tz = $location->timezone;
        $startUtc = Carbon::parse($validated['starts_at'], $tz)->utc();
        $endUtc = Carbon::parse($validated['ends_at'], $tz)->utc();

        // Refuse if the window collides with existing bookings on that table.
        // Blocking does not cancel anything, so a silent overlap would leave the
        // table looking blocked while guests still show up for it.
        $conflict = Reservation::where('location_id', $location->id)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where('start_at', '<', $endUtc)
            ->where('end_at', '>', $startUtc)
            ->whereHas('tables', fn ($q) => $q->where('restaurant_tables.id', $tableId))
            ->exists();
        if ($conflict) {
            return $this->failed($request, 'restaurant_table_id', __('In diesem Zeitraum liegen noch Reservierungen auf diesem Tisch. Bitte zuerst umbelegen oder stornieren.'));
        }

        $block = TableBlock::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'restaurant_table_id' => $tableId,
            'starts_at' => $startUtc,
            'ends_at' => $endUtc,
            'reason' => $validated['reason'] ?? null,
            'created_by' => $request->user()?->id,
        ]);

        $this->audit->log('table_block.created', $block, null, $validated);

        return $this->saved($request, __('Tisch gesperrt.'), true);
    }

    public function deleteTableBlock(TableBlock $block, Request $request)
    {
        abort_if($block->location_id !== $this->context->locationId(), 404);
        $this->audit->log('table_block.deleted', $block, ['reason' => $block->reason]);
        $block->delete();

        return $this->saved($request, __('Tischsperre aufgehoben.'), true);
    }
}
