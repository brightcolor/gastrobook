<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index()
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_unless($this->context->tenant()->isSalon(), 403);

        return view('admin.services.index', [
            'location' => $location,
            'services' => Service::where('location_id', $location->id)
                ->with('staff')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'staff' => StaffMember::where('location_id', $location->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_unless($this->context->tenant()->isSalon(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:100000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'exists:staff_members,id'],
        ]);

        $service = Service::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_minutes' => (int) $validated['duration_minutes'],
            'price_minor' => (int) $validated['price_minor'],
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        if (! empty($validated['staff_ids'])) {
            $ids = StaffMember::where('location_id', $location->id)
                ->whereIn('id', $validated['staff_ids'])->pluck('id');
            $service->staff()->sync($ids);
        }

        $this->audit->log('service.created', $service, null, $validated);

        return back()->with('success', __('Leistung angelegt.'));
    }

    public function update(Request $request, Service $service)
    {
        abort_if($service->location_id !== $this->context->locationId(), 404);
        abort_unless($this->context->tenant()->isSalon(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:480'],
            'price_minor' => ['required', 'integer', 'min:0', 'max:100000'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer', 'exists:staff_members,id'],
        ]);

        $old = $service->only(['name', 'duration_minutes', 'price_minor', 'is_active']);

        $service->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_minutes' => (int) $validated['duration_minutes'],
            'price_minor' => (int) $validated['price_minor'],
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $ids = StaffMember::where('location_id', $service->location_id)
            ->whereIn('id', $validated['staff_ids'] ?? [])->pluck('id');
        $service->staff()->sync($ids);

        $this->audit->log('service.updated', $service, $old, $validated);

        return back()->with('success', __('Leistung aktualisiert.'));
    }

    public function destroy(Service $service)
    {
        abort_if($service->location_id !== $this->context->locationId(), 404);
        $this->audit->log('service.deleted', $service, ['name' => $service->name]);
        $service->delete();

        return back()->with('success', __('Leistung gelöscht.'));
    }
}
