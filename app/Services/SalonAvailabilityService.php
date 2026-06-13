<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Service;
use App\Models\StaffMember;
use Carbon\CarbonImmutable;

class SalonAvailabilityService
{
    public function __construct(
        private readonly TimeSlotService $timeSlots,
    ) {}

    /**
     * Available time slots for a specific staff member and service on a date.
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
        $nowLocal = CarbonImmutable::now($location->timezone);
        $results = [];

        foreach ($this->timeSlots->slotStarts($location, $localDate, $service->duration_minutes) as $startLocal) {
            if ($startLocal->lt($nowLocal->addMinutes($settings->min_lead_minutes))) {
                continue;
            }

            $startUtc = $startLocal->utc();
            $endUtc = $startUtc->addMinutes($service->duration_minutes);

            $results[] = [
                'time' => $startLocal->format('H:i'),
                'start_utc' => $startUtc->toIso8601String(),
                'available' => ! $this->hasConflict($staff, $startUtc, $endUtc),
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
     * Find the first active staff member who is free at the given UTC slot.
     * Returns null if no one is available.
     */
    public function firstAvailableStaff(Service $service, CarbonImmutable $startUtc): ?StaffMember
    {
        $endUtc = $startUtc->addMinutes($service->duration_minutes);

        return $service->staff()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->first(fn (StaffMember $m) => ! $this->hasConflict($m, $startUtc, $endUtc));
    }

    private function hasConflict(StaffMember $staff, CarbonImmutable $startUtc, CarbonImmutable $endUtc): bool
    {
        return $staff->reservations()
            ->withoutGlobalScope('tenant')
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where('start_at', '<', $endUtc)
            ->where('end_at', '>', $startUtc)
            ->exists();
    }
}
