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

    /**
     * Available time slots for a specific staff member and service on a date.
     * Honours: location opening hours, the staff member's weekly working hours,
     * one-off absences, existing bookings and the configured buffer between
     * appointments.
     *
     * @return array<int, array{time: string, start_utc: string, available: bool}>
     */
    public function slotsFor(
        Location $location,
        StaffMember $staff,
        Service $service,
        CarbonImmutable $localDate
    ): array {
        $settings = $location->effectiveSettings();
        $buffer = (int) $settings->buffer_minutes;
        $nowLocal = CarbonImmutable::now($location->timezone);

        $windows = $this->staffWindowsForDate($staff, $localDate);
        $absences = $this->absencesForDate($staff, $localDate);

        $results = [];
        foreach ($this->timeSlots->slotStarts($location, $localDate, $service->duration_minutes) as $startLocal) {
            $endLocal = $startLocal->addMinutes($service->duration_minutes);
            $startUtc = $startLocal->utc();
            $endUtc = $startUtc->addMinutes($service->duration_minutes);

            $available = true;

            // Past / lead time
            if ($startLocal->lt($nowLocal->addMinutes($settings->min_lead_minutes))) {
                $available = false;
            }

            // Staff working hours (null = no constraint, fall back to opening hours)
            if ($available && $windows !== null && ! $this->fitsAnyWindow($startLocal, $endLocal, $windows)) {
                $available = false;
            }

            // Absences
            if ($available && $this->overlapsAbsence($startUtc, $endUtc, $absences)) {
                $available = false;
            }

            // Existing bookings + buffer
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

    /**
     * Slots for every active staff member who offers the service, plus an
     * aggregated entry under key 0 ("any available staff").
     *
     * @return array<int, array<int, array{time: string, start_utc: string, available: bool}>>
     */
    public function slotsByStaff(Location $location, Service $service, CarbonImmutable $localDate): array
    {
        $staffMembers = $service->staff()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $results = [];

        foreach ($staffMembers as $member) {
            $results[$member->id] = $this->slotsFor($location, $member, $service, $localDate);
        }

        // Key 0 = "any": a slot is available if at least one staff member can do it
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

    /**
     * Find the first active staff member who is free at the given UTC slot,
     * respecting working hours, absences and buffer. Returns null if none.
     */
    public function firstAvailableStaff(Service $service, CarbonImmutable $startUtc, Location $location): ?StaffMember
    {
        return $service->staff()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->first(fn (StaffMember $m) => $this->isStaffAvailable($m, $service, $startUtc, $location));
    }

    /**
     * Full availability check for one staff member at a concrete UTC slot
     * (working hours + absences + bookings + buffer). Used to validate an
     * explicit staff choice on booking.
     */
    public function isStaffAvailable(StaffMember $staff, Service $service, CarbonImmutable $startUtc, Location $location): bool
    {
        $buffer = (int) $location->effectiveSettings()->buffer_minutes;
        $endUtc = $startUtc->addMinutes($service->duration_minutes);
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
     * Weekly working windows (local times) for the staff member on a date.
     * Returns null when the member has no working hours configured at all
     * (then location opening hours apply unchanged). An empty array means the
     * member is configured but does not work on this weekday.
     *
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
    private function absencesForDate(StaffMember $staff, CarbonImmutable $localDate)
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
    private function overlapsAbsence(CarbonImmutable $startUtc, CarbonImmutable $endUtc, $absences): bool
    {
        foreach ($absences as $absence) {
            if ($absence->starts_at->lt($endUtc) && $absence->ends_at->gt($startUtc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A slot conflicts with an existing booking when their windows overlap once
     * the new window is widened by the buffer on both sides (enforces a gap).
     */
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
