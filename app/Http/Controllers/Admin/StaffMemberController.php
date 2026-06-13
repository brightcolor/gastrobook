<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
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
                ->with(['services', 'workingHours', 'absences' => fn ($q) => $q->where('ends_at', '>=', now())->orderBy('starts_at')])
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

    /**
     * Replace the weekly working hours for a staff member. Empty = no hours
     * configured → location opening hours apply.
     */
    public function updateWorkingHours(Request $request, StaffMember $member)
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        abort_unless($this->context->tenant()->isSalon(), 403);

        $validated = $request->validate([
            'hours' => ['nullable', 'array'],
            'hours.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.starts_at' => ['required', 'date_format:H:i'],
            'hours.*.ends_at' => ['required', 'date_format:H:i', 'after:hours.*.starts_at'],
        ]);

        $member->workingHours()->delete();
        foreach ($validated['hours'] ?? [] as $hour) {
            StaffWorkingHour::create([
                'tenant_id' => $member->tenant_id,
                'staff_member_id' => $member->id,
                'weekday' => (int) $hour['weekday'],
                'starts_at' => $hour['starts_at'],
                'ends_at' => $hour['ends_at'],
            ]);
        }

        $this->audit->log('staff_member.working_hours_updated', $member, null, ['count' => count($validated['hours'] ?? [])]);

        return back()->with('success', __('Arbeitszeiten gespeichert.'));
    }

    public function storeAbsence(Request $request, StaffMember $member)
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        abort_unless($this->context->tenant()->isSalon(), 403);

        $location = $member->location;
        $tz = $location->timezone;

        $validated = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'starts_time' => ['nullable', 'date_format:H:i'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'ends_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        // Default to full days (00:00 → 24:00) in the location timezone, stored as UTC
        $startsLocal = CarbonImmutable::parse(
            $validated['starts_on'].' '.($validated['starts_time'] ?? '00:00'), $tz
        );
        $endsLocal = $validated['ends_time'] ?? null
            ? CarbonImmutable::parse($validated['ends_on'].' '.$validated['ends_time'], $tz)
            : CarbonImmutable::parse($validated['ends_on'], $tz)->endOfDay();

        $absence = StaffAbsence::create([
            'tenant_id' => $member->tenant_id,
            'staff_member_id' => $member->id,
            'starts_at' => $startsLocal->utc(),
            'ends_at' => $endsLocal->utc(),
            'reason' => $validated['reason'] ?? null,
        ]);

        $this->audit->log('staff_member.absence_created', $absence, null, $validated);

        return back()->with('success', __('Abwesenheit eingetragen.'));
    }

    public function deleteAbsence(StaffAbsence $absence)
    {
        abort_if($absence->staffMember->location_id !== $this->context->locationId(), 404);
        $this->audit->log('staff_member.absence_deleted', $absence, ['reason' => $absence->reason]);
        $absence->delete();

        return back()->with('success', __('Abwesenheit gelöscht.'));
    }
}
