<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Location;
use Carbon\CarbonImmutable;

class ReservationAvailabilityService
{
    public function __construct(
        private readonly TimeSlotService $timeSlots,
        private readonly TableAssignmentService $tableAssignment,
    ) {}

    /**
     * All slots for a local date with availability flags.
     *
     * @param  array{online?: bool}  $options
     * @return array<int, array{time: string, start_utc: string, available: bool, reason: ?string}>
     */
    public function slotsFor(Location $location, CarbonImmutable $localDate, int $partySize, array $options = []): array
    {
        $online = $options['online'] ?? true;
        $settings = $location->effectiveSettings();
        $duration = $settings->durationFor($partySize);
        $nowLocal = CarbonImmutable::now($location->timezone);

        if ($online) {
            if ($partySize < $settings->min_party_online || $partySize > $settings->max_party_online) {
                return [];
            }
            if ($localDate->endOfDay()->lt($nowLocal)) {
                return [];
            }
            if ($localDate->startOfDay()->gt($nowLocal->addDays($settings->max_advance_days))) {
                return [];
            }
        }

        $results = [];
        foreach ($this->timeSlots->slotStarts($location, $localDate, $duration) as $startLocal) {
            $startUtc = $startLocal->utc();
            $endUtc = $startUtc->addMinutes($duration);

            [$available, $reason] = $this->checkSlot($location, $startLocal, $startUtc, $endUtc, $partySize, $online, $nowLocal);

            $results[] = [
                'time' => $startLocal->format('H:i'),
                'start_utc' => $startUtc->toIso8601String(),
                'available' => $available,
                'reason' => $reason,
            ];
        }

        return $results;
    }

    /**
     * Check a specific desired time; returns availability plus assignment.
     *
     * @return array{available: bool, reason: ?string, table_ids: array<int>, duration: int}
     */
    public function checkExact(Location $location, CarbonImmutable $startLocal, int $partySize, array $options = []): array
    {
        $online = $options['online'] ?? true;
        $settings = $location->effectiveSettings();
        $duration = $settings->durationFor($partySize);
        $startUtc = $startLocal->utc();
        $endUtc = $startUtc->addMinutes($duration);
        $nowLocal = CarbonImmutable::now($location->timezone);

        // Must be on a generated slot grid inside opening windows
        $validStarts = $this->timeSlots->slotStarts($location, $startLocal->startOfDay(), $duration);
        $onGrid = collect($validStarts)->contains(fn ($s) => $s->equalTo($startLocal));
        if (! $onGrid) {
            return ['available' => false, 'reason' => 'outside_opening_hours', 'table_ids' => [], 'duration' => $duration];
        }

        [$available, $reason, $tableIds] = $this->checkSlotDetailed($location, $startLocal, $startUtc, $endUtc, $partySize, $online, $nowLocal, $options);

        return ['available' => $available, 'reason' => $reason, 'table_ids' => $tableIds, 'duration' => $duration];
    }

    /**
     * Alternative suggestions around a desired time: nearby earlier/later slots
     * on the same day, then the next days with any availability.
     *
     * @return array{same_day: array<int, string>, other_days: array<int, string>}
     */
    public function alternatives(Location $location, CarbonImmutable $desiredLocal, int $partySize, int $maxSameDay = 4, int $maxOtherDays = 3): array
    {
        $slots = collect($this->slotsFor($location, $desiredLocal->startOfDay(), $partySize))
            ->filter(fn ($s) => $s['available']);

        $sameDay = $slots
            ->sortBy(fn ($s) => abs(
                CarbonImmutable::parse($desiredLocal->toDateString().' '.$s['time'], $location->timezone)->timestamp
                - $desiredLocal->timestamp
            ))
            ->take($maxSameDay)
            ->pluck('time')
            ->values()
            ->all();

        $otherDays = [];
        $cursor = $desiredLocal->startOfDay();
        $maxAdvance = $location->effectiveSettings()->max_advance_days;
        for ($i = 1; $i <= $maxAdvance && count($otherDays) < $maxOtherDays; $i++) {
            $day = $cursor->addDays($i);
            $daySlots = collect($this->slotsFor($location, $day, $partySize))->filter(fn ($s) => $s['available']);
            if ($daySlots->isNotEmpty()) {
                $otherDays[] = $day->toDateString();
            }
        }

        return ['same_day' => $sameDay, 'other_days' => $otherDays];
    }

    /**
     * @return array{0: bool, 1: ?string}
     */
    private function checkSlot(
        Location $location,
        CarbonImmutable $startLocal,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $partySize,
        bool $online,
        CarbonImmutable $nowLocal
    ): array {
        [$available, $reason] = [true, null];
        [$available, $reason, ] = $this->checkSlotDetailed($location, $startLocal, $startUtc, $endUtc, $partySize, $online, $nowLocal, []);

        return [$available, $reason];
    }

    /**
     * @return array{0: bool, 1: ?string, 2: array<int>}
     */
    private function checkSlotDetailed(
        Location $location,
        CarbonImmutable $startLocal,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        int $partySize,
        bool $online,
        CarbonImmutable $nowLocal,
        array $options
    ): array {
        $settings = $location->effectiveSettings();

        if ($online && $startLocal->lt($nowLocal->addMinutes($settings->min_lead_minutes))) {
            return [false, 'lead_time', []];
        }

        if ($online && $startLocal->gt($nowLocal->addDays($settings->max_advance_days))) {
            return [false, 'too_far_ahead', []];
        }

        // Location-wide blackout (room_id null, no reduced capacity = full block)
        $fullBlackout = $location->blackoutPeriods()
            ->whereNull('room_id')
            ->whereNull('reduce_covers_to')
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->exists();
        if ($fullBlackout) {
            return [false, 'blackout', []];
        }

        $mode = $settings->capacity_mode;

        if ($mode === 'person' || $mode === 'hybrid') {
            $maxCovers = $this->effectiveMaxCovers($location, $startUtc, $endUtc, $settings->max_covers_per_slot);
            if ($maxCovers !== null) {
                $currentCovers = (int) $location->reservations()
                    ->whereIn('status', ReservationStatus::activeStatuses())
                    ->where('start_at', '<', $endUtc)
                    ->where('end_at', '>', $startUtc)
                    ->sum('party_size');
                if ($currentCovers + $partySize > $maxCovers) {
                    return [false, 'covers_full', []];
                }
            }
            if ($mode === 'person') {
                return [true, null, []];
            }
        }

        // table / hybrid: need an actual table
        $assignment = $this->tableAssignment->findTables($location, $startUtc, $endUtc, $partySize, [
            'online' => $online,
            'accessible' => $options['accessible'] ?? false,
            'room_id' => $options['room_id'] ?? null,
            'exclude_reservation_id' => $options['exclude_reservation_id'] ?? null,
        ]);

        if ($assignment === null) {
            return [false, 'no_table', []];
        }

        return [true, null, $assignment['table_ids']];
    }

    private function effectiveMaxCovers(Location $location, CarbonImmutable $startUtc, CarbonImmutable $endUtc, ?int $configured): ?int
    {
        $reduced = $location->blackoutPeriods()
            ->whereNull('room_id')
            ->whereNotNull('reduce_covers_to')
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->min('reduce_covers_to');

        if ($reduced !== null) {
            return $configured === null ? (int) $reduced : min((int) $reduced, $configured);
        }

        return $configured;
    }
}
