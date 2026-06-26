<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LocationController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index()
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $locations = $tenant->locations()
            ->withCount(['tables', 'rooms'])
            ->orderBy('name')
            ->get();

        $limit = $tenant->limit('max_locations');

        return view('admin.locations.index', [
            'tenant' => $tenant,
            'locations' => $locations,
            'limit' => $limit,
            'used' => $locations->count(),
            'canAdd' => $this->limits->canAdd($tenant, 'max_locations'),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        if (! $this->limits->canAdd($tenant, 'max_locations')) {
            return back()->withErrors([
                'name' => __('Standort-Limit Ihres Tarifs erreicht. Bitte upgraden, um weitere Standorte anzulegen.'),
            ]);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $location = Location::create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'slug' => $this->uniqueSlug($tenant->id, $validated['name']),
            'timezone' => $validated['timezone'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_line1' => $validated['address_line1'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
            'is_active' => true,
            'online_booking_enabled' => true,
        ]);
        $location->settings()->create(['tenant_id' => $tenant->id]);

        $this->audit->log('location.created', $location, null, ['name' => $location->name]);

        return redirect()->route('admin.locations.index')
            ->with('success', __('Standort ":name" angelegt. Lege jetzt Öffnungszeiten und Tische an.', ['name' => $location->name]));
    }

    public function update(Request $request, Location $location)
    {
        // Route-model binding is tenant-scoped via the global scope; this is
        // belt-and-suspenders against a missing context.
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);

        $old = ['name' => $location->name];

        // The slug stays fixed on rename so existing public booking links
        // (/book/{tenant}/{location}) keep working.
        $location->update([
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_line1' => $validated['address_line1'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
        ]);

        $this->audit->log('location.updated', $location, $old, ['name' => $location->name]);

        return back()->with('success', __('Standort gespeichert.'));
    }

    public function toggleActive(Request $request, Location $location)
    {
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $tenant = $this->context->tenant();

        // Never deactivate the last active location – the admin area resolves a
        // location from the active set and the public booking would break.
        if ($location->is_active
            && $tenant->locations()->where('is_active', true)->count() <= 1) {
            return back()->withErrors([
                'active' => __('Der letzte aktive Standort kann nicht deaktiviert werden.'),
            ]);
        }

        $location->update(['is_active' => ! $location->is_active]);

        $this->audit->log('location.'.($location->is_active ? 'activated' : 'deactivated'), $location);

        return back()->with('success', $location->is_active
            ? __('Standort aktiviert.')
            : __('Standort deaktiviert.'));
    }

    private function uniqueSlug(int $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'standort';
        $slug = $base;
        $i = 1;
        while (Location::withTrashed()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
