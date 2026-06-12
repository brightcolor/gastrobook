<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\OpeningHour;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\SpecialOpeningHour;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;

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

        return view('admin.settings.index', [
            'location' => $location,
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
        ]);

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($validated));

        $settings->update($validated + [
            'auto_confirm' => $request->boolean('auto_confirm'),
            'request_only' => $request->boolean('request_only'),
            'waitlist_enabled' => $request->boolean('waitlist_enabled'),
            'walkins_enabled' => $request->boolean('walkins_enabled'),
        ]);

        $this->audit->log('location.settings_updated', $settings, $old, $validated);

        return back()->with('success', __('Buchungsregeln gespeichert.'));
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

        return back()->with('success', __('Raum angelegt.'));
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
            'pos_x' => 50, 'pos_y' => 50,
        ]);

        $this->audit->log('table.created', $table, null, $validated);

        return back()->with('success', __('Tisch angelegt.'));
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

        return back()->with('success', __('Öffnungszeiten gespeichert.'));
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

        return back()->with('success', __('Sonderöffnungszeit gespeichert.'));
    }
}
