<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class StaffMemberController extends Controller
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

        return view('admin.staff.index', [
            'location' => $location,
            'members' => StaffMember::where('location_id', $location->id)
                ->with('services')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'services' => Service::where('location_id', $location->id)
                ->where('is_active', true)
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
            'bio' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $member = StaffMember::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'bio' => $validated['bio'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        if (! empty($validated['service_ids'])) {
            $ids = Service::where('location_id', $location->id)
                ->whereIn('id', $validated['service_ids'])->pluck('id');
            $member->services()->sync($ids);
        }

        $this->audit->log('staff_member.created', $member, null, $validated);

        return back()->with('success', __('Mitarbeiter:in angelegt.'));
    }

    public function update(Request $request, StaffMember $member)
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        abort_unless($this->context->tenant()->isSalon(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer', 'exists:services,id'],
        ]);

        $old = $member->only(['name', 'is_active']);

        $member->update([
            'name' => $validated['name'],
            'bio' => $validated['bio'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);

        $ids = Service::where('location_id', $member->location_id)
            ->whereIn('id', $validated['service_ids'] ?? [])->pluck('id');
        $member->services()->sync($ids);

        $this->audit->log('staff_member.updated', $member, $old, $validated);

        return back()->with('success', __('Mitarbeiter:in aktualisiert.'));
    }

    public function destroy(StaffMember $member)
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->audit->log('staff_member.deleted', $member, ['name' => $member->name]);
        $member->delete();

        return back()->with('success', __('Mitarbeiter:in gelöscht.'));
    }
}
