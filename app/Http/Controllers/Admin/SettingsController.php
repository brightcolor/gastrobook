<?php

namespace App\Http\Controllers\Admin;

use App\Enums\TenantType;
use App\Http\Controllers\Controller;
use App\Models\DepositRule;
use App\Models\IntegrationConnection;
use App\Models\OpeningHour;
use App\Models\RestaurantTable;
use App\Models\SpecialOpeningHour;
use App\Models\TableCombination;
use App\Services\AuditLogger;
use App\Services\Newsletter\MailwizzProvider;
use App\Services\PlanLimitService;
use App\Services\Sms\SevenIoProvider;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

class SettingsController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
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
            'paypal' => $paypal,
            'paypalCredentials' => $paypalCredentials,
            'sms' => $sms,
            'smsCredentials' => $smsCredentials,
            'depositRules' => DepositRule::where('location_id', $location->id)->orderBy('name')->get(),
            'settings' => $location->effectiveSettings(),
            'rooms' => $location->rooms()->withCount('tables')->orderBy('sort_order')->get(),
            'tables' => $location->tables()->with('room')->orderBy('sort_order')->get(),
            'openingHours' => $location->openingHours()->orderBy('weekday')->orderBy('opens_at')->get(),
            'specialHours' => $location->specialOpeningHours()->where('date', '>=', now()->subDay())->orderBy('date')->get(),
            'combinations' => $location->tableCombinations()->with('tables')->get(),
        ]);
    }

    public function updateBookingRules(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'slot_interval_minutes' => ['required', 'integer', 'in:15,30,60'],
            'default_duration_minutes' => ['required', 'integer', 'min:30', 'max:480'],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:120'],
            'min_lead_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:730'],
            'min_party_online' => ['required', 'integer', 'min:1', 'max:50'],
            'max_party_online' => ['required', 'integer', 'min:1', 'max:100', 'gte:min_party_online'],
            'auto_confirm' => ['nullable', 'boolean'],
            'request_only' => ['nullable', 'boolean'],
            'capacity_mode' => ['required', 'in:table,person,hybrid'],
            'max_covers_per_slot' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'waitlist_enabled' => ['nullable', 'boolean'],
            'walkins_enabled' => ['nullable', 'boolean'],
            'cancellation_deadline_minutes' => ['required', 'integer', 'min:0', 'max:20160'],
            'reminder_enabled' => ['nullable', 'boolean'],
            'reminder_hours_before' => ['required', 'integer', 'min:1', 'max:168'],
            'sms_reminder_enabled' => ['nullable', 'boolean'],
            'gap_optimization_enabled' => ['nullable', 'boolean'],
            'public_floorplan_enabled' => ['nullable', 'boolean'],
            'refund_mode' => ['required', 'in:off,manual,auto'],
            'refund_percent' => ['required', 'integer', 'min:0', 'max:100'],
            'refund_processing' => ['required', 'in:immediate,scheduled'],
            'require_email_confirmation' => ['nullable', 'boolean'],
            'confetti_on_booking' => ['nullable', 'boolean'],
            'guest_address' => ['required', 'in:du,Sie'],
        ]);

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($validated));

        $settings->update($validated + [
            'auto_confirm' => $request->boolean('auto_confirm'),
            'request_only' => $request->boolean('request_only'),
            'waitlist_enabled' => $request->boolean('waitlist_enabled'),
            'walkins_enabled' => $request->boolean('walkins_enabled'),
            'reminder_enabled' => $request->boolean('reminder_enabled'),
            'sms_reminder_enabled' => $request->boolean('sms_reminder_enabled'),
            'gap_optimization_enabled' => $request->boolean('gap_optimization_enabled'),
            'public_floorplan_enabled' => $request->boolean('public_floorplan_enabled'),
            'require_email_confirmation' => $request->boolean('require_email_confirmation'),
            'confetti_on_booking' => $request->boolean('confetti_on_booking'),
        ]);

        $this->audit->log('location.settings_updated', $settings, $old, $validated);

        return $this->saved($request, __('Buchungsregeln gespeichert.'));
    }

    /**
     * Booking widget field visibility: hidden | optional | required per field.
     */
    public function updateFieldRules(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $fields = ['email', 'phone', 'occasion', 'note', 'allergies'];
        $validated = $request->validate(
            collect($fields)->mapWithKeys(fn ($f) => ["fields.{$f}" => ['required', 'in:hidden,optional,required']])->all()
        );

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->field_rules;
        $settings->update(['field_rules' => $validated['fields']]);

        $this->audit->log('location.field_rules_updated', $settings, ['field_rules' => $old], $validated['fields']);

        return $this->saved($request, __('Formularfelder gespeichert.'));
    }

    /**
     * MailWizz newsletter integration (tenant-wide). Credentials are stored
     * encrypted; the API key is never displayed again after saving.
     */
    public function updateMailwizz(Request $request)
    {
        $tenant = $this->context->tenant();

        $validated = $request->validate([
            'api_url' => ['required', 'url'],
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
            return $this->failed($request, 'api_key', __('API-Key erforderlich.'));
        }

        $connection->credentials_encrypted = Crypt::encryptString(json_encode($credentials));
        $connection->status = $request->boolean('enabled', true) ? 'connected' : 'disconnected';
        $connection->save();

        $this->audit->log('integration.mailwizz_updated', $connection, null, [
            'api_url' => $validated['api_url'],
            'list_uid' => $validated['list_uid'],
            'status' => $connection->status,
        ]);

        // Verify the connection right away so misconfiguration is visible immediately
        if ($connection->status === 'connected') {
            $provider = new MailwizzProvider(
                $credentials['api_url'], $credentials['api_key'], $credentials['list_uid']
            );
            try {
                if (! $provider->testConnection()) {
                    $connection->update(['status' => 'error']);

                    return $this->failed($request, 'api_url', __('Verbindungstest fehlgeschlagen – bitte URL, API-Key und Listen-UID prüfen.'));
                }
            } catch (\Throwable) {
                $connection->update(['status' => 'error']);

                return $this->failed($request, 'api_url', __('MailWizz nicht erreichbar.'));
            }
        }

        return $this->saved($request, __('MailWizz-Integration gespeichert und Verbindung getestet.'));
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

        if (empty($credentials['secret_key']) || empty($credentials['webhook_secret'])) {
            return back()->withErrors(['secret_key' => __('Secret Key und Webhook-Secret sind erforderlich.')]);
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

        if (empty($credentials['client_id']) || empty($credentials['secret'])) {
            return back()->withErrors(['client_id' => __('Client-ID und Secret sind erforderlich.')]);
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
            return $this->failed($request, 'api_key', __('API-Key erforderlich.'));
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

                    return $this->failed($request, 'api_key', __('Verbindungstest fehlgeschlagen – API-Key prüfen.'));
                }
            } catch (\Throwable) {
                $connection->update(['status' => 'error']);

                return $this->failed($request, 'api_key', __('seven.io nicht erreichbar.'));
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
            'from_time' => ['nullable', 'date_format:H:i'],
            'until_time' => ['nullable', 'date_format:H:i'],
            'payment_deadline_minutes' => ['nullable', 'integer', 'min:10', 'max:10080'],
        ]);

        $rule = DepositRule::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'type' => 'deposit',
            'min_party_size' => $validated['min_party_size'] ?? null,
            'from_time' => $validated['from_time'] ?? null,
            'until_time' => $validated['until_time'] ?? null,
            'amount_per_person_minor' => (int) round($validated['amount_per_person'] * 100),
            'currency' => $location->currency,
            'payment_deadline_minutes' => (int) ($validated['payment_deadline_minutes'] ?? 60),
        ]);

        $this->audit->log('deposit_rule.created', $rule, null, $validated);

        return $this->saved($request, __('Anzahlungsregel angelegt.'), true);
    }

    public function deleteDepositRule(DepositRule $rule)
    {
        abort_if($rule->location_id !== $this->context->locationId(), 404);
        $this->audit->log('deposit_rule.deleted', $rule, ['name' => $rule->name]);
        $rule->delete();

        return back()->with('success', __('Anzahlungsregel gelöscht.'));
    }

    public function storeRoom(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_outdoor' => ['nullable', 'boolean'],
        ]);

        $room = $location->rooms()->create([
            'tenant_id' => $location->tenant_id,
            'name' => $validated['name'],
            'is_outdoor' => $request->boolean('is_outdoor'),
        ]);

        $this->audit->log('room.created', $room, null, $validated);

        return $this->saved($request, __('Raum angelegt.'));
    }

    public function storeTable(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        if (! $this->limits->canAdd($location->tenant, 'max_tables')) {
            return back()->withErrors(['name' => __('Tisch-Limit Ihres Tarifs erreicht.')]);
        }

        $validated = $request->validate([
            'room_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:40'],
            'min_capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:50', 'gte:min_capacity'],
            'outdoor' => ['nullable', 'boolean'],
            'accessible' => ['nullable', 'boolean'],
            'joinable' => ['nullable', 'boolean'],
            'online_bookable' => ['nullable', 'boolean'],
        ]);

        abort_unless($location->rooms()->where('id', $validated['room_id'])->exists(), 422);

        [$width, $height] = RestaurantTable::sizeForCapacity('rect', (int) $validated['max_capacity']);

        $table = $location->tables()->create([
            'tenant_id' => $location->tenant_id,
            'room_id' => (int) $validated['room_id'],
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => (int) $validated['max_capacity'],
            'outdoor' => $request->boolean('outdoor'),
            'accessible' => $request->boolean('accessible'),
            'joinable' => $request->boolean('joinable', true),
            'online_bookable' => $request->boolean('online_bookable', true),
            'width' => $width, 'height' => $height,
            'pos_x' => 50, 'pos_y' => 50,
        ]);

        $this->audit->log('table.created', $table, null, $validated);

        return back()->with('success', __('Tisch angelegt.'));
    }

    public function uploadLogo(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        // No SVG: an SVG served from our own origin can carry executable
        // JavaScript (stored XSS). Raster formats only.
        $request->validate([
            'logo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
        ]);

        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
        }

        $path = $request->file('logo')->store('logos/'.$location->id, 'public');
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

    public function deleteTable(RestaurantTable $table)
    {
        abort_if($table->location_id !== $this->context->locationId(), 404);
        $table->delete();
        $this->audit->log('table.deleted', $table);

        return back()->with('success', __('Tisch gelöscht.'));
    }

    public function storeCombination(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'table_ids' => ['required', 'array', 'min:2'],
            'table_ids.*' => ['integer'],
            'min_capacity' => ['required', 'integer', 'min:1'],
            'max_capacity' => ['required', 'integer', 'min:1', 'gte:min_capacity'],
        ]);

        $tableIds = collect($validated['table_ids'])
            ->filter(fn ($id) => $location->tables()->where('id', $id)->where('joinable', true)->exists())
            ->values();
        abort_if($tableIds->count() < 2, 422, 'Mindestens zwei kombinierbare Tische erforderlich.');

        $combo = $location->tableCombinations()->create([
            'tenant_id' => $location->tenant_id,
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => (int) $validated['max_capacity'],
        ]);
        $combo->tables()->sync($tableIds);

        $this->audit->log('table_combination.created', $combo, null, $validated);

        if ($request->wantsJson()) {
            $combo->load('tables:id,name');

            return response()->json([
                'message' => __('Tischkombination angelegt.'),
                'combination' => [
                    'id' => $combo->id,
                    'name' => $combo->name,
                    'min_capacity' => $combo->min_capacity,
                    'max_capacity' => $combo->max_capacity,
                    'tables' => $combo->tables->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
                ],
            ]);
        }

        return back()->with('success', __('Tischkombination angelegt.'));
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

    public function updateTenantType(Request $request)
    {
        $tenant = $this->context->tenant();

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:restaurant,salon'],
        ]);

        $old = $tenant->getRawOriginal('type');
        $tenant->update(['type' => TenantType::from($validated['type'])]);

        $this->audit->log('tenant.type_changed', $tenant, ['type' => $old], ['type' => $validated['type']]);

        // reload: true — der Typwechsel ändert Navigation, Labels und die
        // öffentliche Buchungsseite. Ohne Reload schickt das AJAX-Settings-JS
        // das Formular ab und speichert zwar, aber die UI spiegelt den neuen
        // Typ nicht wider ("umschalten geht nicht").
        return $this->saved($request, __('Betriebstyp geändert auf: :type', [
            'type' => TenantType::from($validated['type'])->label(),
        ]), reload: true);
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

    public function deleteCombination(TableCombination $combination, Request $request)
    {
        abort_if($combination->location_id !== $this->context->locationId(), 404);
        $this->audit->log('table_combination.deleted', $combination, ['name' => $combination->name]);
        $combination->delete();

        return $this->saved($request, __('Tischkombination gelöscht.'));
    }

    private function saved(Request $request, string $message, bool $reload = false): mixed
    {
        if ($request->wantsJson()) {
            $data = ['message' => $message];
            if ($reload) {
                $data['reload'] = true;
            }

            return response()->json($data);
        }

        return back()->with('success', $message);
    }

    private function failed(Request $request, string $field, string $message): mixed
    {
        if ($request->wantsJson()) {
            return response()->json(['errors' => [$field => [$message]]], 422);
        }

        return back()->withErrors([$field => $message]);
    }
}
