<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SalonAvailabilityService
{
    public function __construct(
        private readonly TimeSlotService $timeSlots,
    ) {}

    // ---------------------------------------------------------------------
    // Single-service convenience API (kept for callers/tests)
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array{time: string, start_utc: string, available: bool}>
     */
    public function slotsFor(Location $location, StaffMember $staff, Service $service, CarbonImmutable $localDate): array
    {
        return $this->staffSlots($location, $staff, $service->duration_minutes, $localDate);
    }

    public function isStaffAvailable(StaffMember $staff, Service $service, CarbonImmutable $startUtc, Location $location): bool
    {
        return $this->staffAvailableAt($staff, $service->duration_minutes, $startUtc, $location);
    }

    /**
     * @return array<int, array<int, array{time: string, start_utc: string, available: bool}>>
     */
    public function slotsByStaff(Location $location, Service $service, CarbonImmutable $localDate): array
    {
        return $this->slotsByStaffForServices($location, collect([$service]), $localDate);
    }

    public function firstAvailableStaff(Service $service, CarbonImmutable $startUtc, Location $location): ?StaffMember
    {
        return $this->firstAvailableStaffForServices(collect([$service]), $startUtc, $location);
    }

    // ---------------------------------------------------------------------
    // Multi-service API (combined appointments)
    // ---------------------------------------------------------------------

    /**
     * @param  Collection<int, Service>  $services
     */
    public function combinedDuration(Collection $services): int
    {
        return (int) $services->sum('duration_minutes');
    }

    /**
     * Active staff members who offer *all* of the given services (intersection),
     * ordered for stable "any" assignment.
     *
     * @param  Collection<int, Service>  $services
     * @return Collection<int, StaffMember>
     */
    public function eligibleStaff(Collection $services): Collection
    {
        if ($services->isEmpty()) {
            return collect();
        }

        $sets = $services->map(fn (Service $s) => $s->staff->where('is_active', true)->keyBy('id'));

        /** @var Collection<int, StaffMember> $intersection */
        $intersection = $sets->first();
        foreach ($sets->slice(1) as $set) {
            $intersection = $intersection->filter(fn ($member, $id) => $set->has($id));
        }

        return $intersection
            ->sortBy(fn (StaffMember $m) => sprintf('%05d-%s', $m->sort_order, $m->name))
            ->values();
    }

    /**
     * @param  Collection<int, Service>  $services
     * @return array<int, array<int, array{time: string, start_utc: string, available: bool}>>
     */
    public function slotsByStaffForServices(Location $location, Collection $services, CarbonImmutable $localDate): array
    {
        $duration = $this->combinedDuration($services);
        $eligible = $this->eligibleStaff($services);

        $results = [];
        foreach ($eligible as $member) {
            $results[$member->id] = $this->staffSlots($location, $member, $duration, $localDate);
        }

        // Key 0 = "any": a slot is available if at least one eligible member is free
        $allTimes = collect($results)
            ->flatMap(fn ($slots) => collect($slots)->pluck('time'))
            ->unique()
            ->sort()
            ->values();

        $anySlots = $allTimes->map(function (string $time) use ($results) {
            foreach ($results as $staffSlots) {
                foreach ($staffSlots as $slot) {
                    if ($slot['time'] === $time && $slot['available']) {
                        return ['time' => $time, 'start_utc' => $slot['start_utc'], 'available' => true];
                    }
                }
            }

            $first = collect($results)->flatMap(fn ($s) => $s)->firstWhere('time', $time);

            return ['time' => $time, 'start_utc' => $first['start_utc'] ?? '', 'available' => false];
        })->values()->all();

        return [0 => $anySlots] + $results;
    }

    public function isStaffAvailableForServices(StaffMember $staff, Collection $services, CarbonImmutable $startUtc, Location $location): bool
    {
        // Staff must offer every service and be free for the combined duration
        if (! $this->eligibleStaff($services)->contains('id', $staff->id)) {
            return false;
        }

        return $this->staffAvailableAt($staff, $this->combinedDuration($services), $startUtc, $location);
    }

    /**
     * Pick the staff member to fulfil an "any" booking. With gap optimization
     * enabled, choose the eligible+free member whose schedule the appointment
     * fits most snugly (minimises unusable gaps); otherwise the first in order.
     *
     * @param  Collection<int, Service>  $services
     */
    public function firstAvailableStaffForServices(Collection $services, CarbonImmutable $startUtc, Location $location): ?StaffMember
    {
        $duration = $this->combinedDuration($services);

        $free = $this->eligibleStaff($services)
            ->filter(fn (StaffMember $m) => $this->staffAvailableAt($m, $duration, $startUtc, $location))
            ->values();

        if ($free->isEmpty()) {
            return null;
        }

        if (! $location->effectiveSettings()->gap_optimization_enabled) {
            return $free->first();
        }

        $endUtc = $startUtc->addMinutes($duration);
        $threshold = $this->minFillableMinutes($location);

        // sortBy is stable: ties keep the eligible order (sort_order)
        return $free->sortBy(fn (StaffMember $m) => $this->fragmentationScore($m, $startUtc, $endUtc, $threshold))->first();
    }

    // ---------------------------------------------------------------------
    // Core (duration-based)
    // ---------------------------------------------------------------------

    /**
     * @return array<int, array{time: string, start_utc: string, available: bool}>
     */
    private function staffSlots(Location $location, StaffMember $staff, int $duration, CarbonImmutable $localDate): array
    {
        $settings = $location->effectiveSettings();
        $buffer = (int) $settings->buffer_minutes;
        $nowLocal = CarbonImmutable::now($location->timezone);

        $windows = $this->staffWindowsForDate($staff, $localDate);
        $absences = $this->absencesForDate($staff, $localDate);

        $results = [];
        foreach ($this->timeSlots->slotStarts($location, $localDate, $duration) as $startLocal) {
            $endLocal = $startLocal->addMinutes($duration);
            $startUtc = $startLocal->utc();
            $endUtc = $startUtc->addMinutes($duration);

            $available = true;

            if ($startLocal->lt($nowLocal->addMinutes($settings->min_lead_minutes))) {
                $available = false;
            }
            if ($available && $windows !== null && ! $this->fitsAnyWindow($startLocal, $endLocal, $windows)) {
                $available = false;
            }
            if ($available && $this->overlapsAbsence($startUtc, $endUtc, $absences)) {
                $available = false;
            }
            if ($available && $this->hasConflict($staff, $startUtc, $endUtc, $buffer)) {
                $available = false;
            }

            $results[] = [
                'time' => $startLocal->format('H:i'),
                'start_utc' => $startUtc->toIso8601String(),
                'available' => $available,
            ];
        }

        return $results;
    }

    private function staffAvailableAt(StaffMember $staff, int $duration, CarbonImmutable $startUtc, Location $location): bool
    {
        $buffer = (int) $location->effectiveSettings()->buffer_minutes;
        $endUtc = $startUtc->addMinutes($duration);
        $startLocal = $startUtc->setTimezone($location->timezone);
        $endLocal = $endUtc->setTimezone($location->timezone);
        $localDate = $startLocal->startOfDay();

        $windows = $this->staffWindowsForDate($staff, $localDate);
        if ($windows !== null && ! $this->fitsAnyWindow($startLocal, $endLocal, $windows)) {
            return false;
        }
        if ($this->overlapsAbsence($startUtc, $endUtc, $this->absencesForDate($staff, $localDate))) {
            return false;
        }

        return ! $this->hasConflict($staff, $startUtc, $endUtc, $buffer);
    }

    /**
     * Wasted minutes a candidate slot would create next to adjacent bookings.
     * A gap is "wasted" when it is too small to be filled by the shortest
     * bookable service (incl. buffer). Lower score = tighter fit; 0 = ideal.
     */
    private function fragmentationScore(StaffMember $staff, CarbonImmutable $startUtc, CarbonImmutable $endUtc, int $threshold): int
    {
        $bookings = $staff->reservations()
            ->withoutGlobalScope('tenant')
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where('start_at', '<', $endUtc->addDay())
            ->where('end_at', '>', $startUtc->subDay())
            ->get(['start_at', 'end_at']);

        $score = 0;
        $hasNeighbour = false;

        $prevEnd = $bookings
            ->filter(fn ($b) => CarbonImmutable::parse($b->end_at)->lte($startUtc))
            ->max(fn ($b) => CarbonImmutable::parse($b->end_at)->timestamp);
        if ($prevEnd !== null) {
            $hasNeighbour = true;
            $gap = (int) round(($startUtc->timestamp - $prevEnd) / 60);
            if ($gap > 0 && $gap < $threshold) {
                $score += $gap; // small unusable gap before
            }
        }

        $nextStart = $bookings
            ->filter(fn ($b) => CarbonImmutable::parse($b->start_at)->gte($endUtc))
            ->min(fn ($b) => CarbonImmutable::parse($b->start_at)->timestamp);
        if ($nextStart !== null) {
            $hasNeighbour = true;
            $gap = (int) round(($nextStart - $endUtc->timestamp) / 60);
            if ($gap > 0 && $gap < $threshold) {
                $score += $gap; // small unusable gap after
            }
        }

        // Prefer docking onto a staff member who already has work that day over
        // opening up an otherwise empty schedule (keeps colleagues free).
        if (! $hasNeighbour) {
            $score += 1;
        }

        return $score;
    }

    private function minFillableMinutes(Location $location): int
    {
        $min = (int) Service::where('location_id', $location->id)
            ->where('is_active', true)
            ->min('duration_minutes');

        return max(1, $min) + (int) $location->effectiveSettings()->buffer_minutes;
    }

    /**
     * @return array<int, array{opens: CarbonImmutable, closes: CarbonImmutable}>|null
     */
    private function staffWindowsForDate(StaffMember $staff, CarbonImmutable $localDate): ?array
    {
        if ($staff->workingHours()->count() === 0) {
            return null;
        }

        $weekday = $localDate->dayOfWeekIso - 1; // 0 = Monday
        $tz = $localDate->getTimezone();

        return $staff->workingHours()
            ->where('weekday', $weekday)
            ->get()
            ->map(function ($h) use ($localDate, $tz) {
                $opens = CarbonImmutable::parse($localDate->toDateString().' '.$h->starts_at, $tz);
                $closes = CarbonImmutable::parse($localDate->toDateString().' '.$h->ends_at, $tz);
                if ($closes->lte($opens)) {
                    $closes = $closes->addDay();
                }

                return ['opens' => $opens, 'closes' => $closes];
            })
            ->all();
    }

    /**
     * @return Collection<int, StaffAbsence>
     */
    private function absencesForDate(StaffMember $staff, CarbonImmutable $localDate): Collection
    {
        $dayStart = $localDate->startOfDay()->utc();
        $dayEnd = $localDate->endOfDay()->utc();

        return $staff->absences()
            ->where('starts_at', '<', $dayEnd)
            ->where('ends_at', '>', $dayStart)
            ->get();
    }

    /**
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable}>  $windows
     */
    private function fitsAnyWindow(CarbonImmutable $startLocal, CarbonImmutable $endLocal, array $windows): bool
    {
        foreach ($windows as $window) {
            if ($startLocal->gte($window['opens']) && $endLocal->lte($window['closes'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, StaffAbsence>  $absences
     */
    private function overlapsAbsence(CarbonImmutable $startUtc, CarbonImmutable $endUtc, Collection $absences): bool
    {
        foreach ($absences as $absence) {
            if ($absence->starts_at->lt($endUtc) && $absence->ends_at->gt($startUtc)) {
                return true;
            }
        }

        return false;
    }

    private function hasConflict(StaffMember $staff, CarbonImmutable $startUtc, CarbonImmutable $endUtc, int $buffer): bool
    {
        $from = $startUtc->subMinutes($buffer);
        $to = $endUtc->addMinutes($buffer);

        return $staff->reservations()
            ->withoutGlobalScope('tenant')
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->exists();
    }
}
