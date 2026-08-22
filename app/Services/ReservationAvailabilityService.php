<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Models\Location;
use Carbon\CarbonImmutable;

class ReservationAvailabilityService
{
    /**
     * Wie viele GEOEFFNETE Tage die Vorwaertssuche hoechstens durchrechnet.
     * Ohne diese Grenze rechnet eine einzige oeffentliche Anfrage bei
     * ausgebuchtem Zeitraum den kompletten Buchungshorizont durch (Standard 90
     * Tage) - je Tag mit Abfragen pro Zeitfenster.
     */
    private const FORWARD_SCAN_BUDGET = 7;

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
        // Die aufrufende Stelle darf eine abweichende Dauer vorgeben (interne
        // Maske, Salon-Kombileistungen). Wird sie hier ignoriert, prueft die
        // Verfuegbarkeit ein kuerzeres Fenster als spaeter gespeichert wird.
        $duration = (int) ($options['duration'] ?? $settings->durationFor($partySize));
        $startUtc = $startLocal->utc();
        $endUtc = $startUtc->addMinutes($duration);
        $nowLocal = CarbonImmutable::now($location->timezone);

        if (! $this->startIsBookable($location, $startLocal, $duration, (bool) ($options['ad_hoc'] ?? false))) {
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
        $budget = self::FORWARD_SCAN_BUDGET;
        for ($i = 1; $i <= $maxAdvance && count($otherDays) < $maxOtherDays && $budget > 0; $i++) {
            $day = $cursor->addDays($i);
            $alle = $this->slotsFor($location, $day, $partySize);
            // Geschlossene Tage kosten kein Budget - Ruhetage und Betriebsferien
            // sollen die Suche nicht aufbrauchen.
            if ($alle === []) {
                continue;
            }
            $budget--;
            if (collect($alle)->contains(fn ($s) => $s['available'])) {
                $otherDays[] = $day->toDateString();
            }
        }

        return ['same_day' => $sameDay, 'other_days' => $otherDays];
    }

    /**
     * The soonest concrete free slots (date + time) that can seat the party,
     * scanning forward from the given day. Each entry is directly bookable.
     *
     * @return array<int, array{date: string, time: string}>
     */
    public function nextSlots(Location $location, CarbonImmutable $fromLocalDate, int $partySize, int $limit = 6, int $perDay = 2): array
    {
        $out = [];
        $start = $fromLocalDate->startOfDay();
        $maxAdvance = (int) $location->effectiveSettings()->max_advance_days;

        $budget = self::FORWARD_SCAN_BUDGET;
        for ($i = 0; $i <= $maxAdvance && count($out) < $limit && $budget > 0; $i++) {
            $day = $start->addDays($i);
            $alle = $this->slotsFor($location, $day, $partySize);
            if ($alle === []) {
                continue;
            }
            $budget--;
            $daySlots = collect($alle)
                ->filter(fn ($s) => $s['available'])
                ->take($perDay);

            foreach ($daySlots as $s) {
                $out[] = ['date' => $day->toDateString(), 'time' => $s['time']];
                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Time-window and blackout constraints for a slot, independent of table
     * assignment. Returns a block reason, or null if the slot is bookable.
     *
     * Used when tables are chosen manually (public floor plan / internal
     * picker): those bookings must still respect opening/special hours and
     * blackouts, which the plain busy-table check does not cover.
     *
     * @param  array<int>  $tableIds  chosen tables (for room-specific blackouts)
     * @param  bool  $adHoc  seating starting now, off the public slot grid
     */
    public function bookingBlockReason(
        Location $location,
        CarbonImmutable $startLocal,
        CarbonImmutable $startUtc,
        CarbonImmutable $endUtc,
        array $tableIds = [],
        bool $adHoc = false
    ): ?string {
        $duration = (int) $startUtc->diffInMinutes($endUtc);

        if (! $this->startIsBookable($location, $startLocal, $duration, $adHoc)) {
            return 'outside_opening_hours';
        }

        // Location-wide full blackout.
        $fullBlackout = $location->blackoutPeriods()
            ->whereNull('room_id')
            ->whereNull('reduce_covers_to')
            ->where('starts_at', '<', $endUtc)
            ->where('ends_at', '>', $startUtc)
            ->exists();
        if ($fullBlackout) {
            return 'blackout';
        }

        // Room-specific blackout covering any of the chosen tables.
        if ($tableIds !== []) {
            $blockedRooms = $location->blackoutPeriods()
                ->whereNotNull('room_id')
                ->whereNull('reduce_covers_to')
                ->where('starts_at', '<', $endUtc)
                ->where('ends_at', '>', $startUtc)
                ->pluck('room_id')
                ->all();
            if ($blockedRooms !== [] && $location->tables()->whereIn('id', $tableIds)->whereIn('room_id', $blockedRooms)->exists()) {
                return 'blackout';
            }
        }

        return null;
    }

    /**
     * Is this start time bookable at all, before any table or capacity check?
     *
     * Normally the answer is "only if it is one of the generated slot starts" –
     * that keeps public bookings on the grid the guest was shown. Fenster ueber
     * Mitternacht beginnen am Vortag, deshalb zaehlen beide Tage.
     *
     * Ad-hoc-Belegungen entstehen dagegen an der Uhr und nicht am Raster: Ein
     * Walk-in um 19:07:23 trifft keinen Rasterpunkt, und kurz vor Feierabend
     * gibt es gar keinen mehr, weil slotStarts bei `closes - duration` endet.
     * Fuer sie zaehlt nur, ob der Betrieb zu diesem Zeitpunkt geoeffnet hat.
     */
    private function startIsBookable(Location $location, CarbonImmutable $startLocal, int $duration, bool $adHoc): bool
    {
        if ($adHoc) {
            return $this->withinOpeningWindow($location, $startLocal);
        }

        $validStarts = array_merge(
            $this->timeSlots->slotStarts($location, $startLocal->startOfDay()->subDay(), $duration),
            $this->timeSlots->slotStarts($location, $startLocal->startOfDay(), $duration),
        );

        return collect($validStarts)->contains(fn ($s) => $s->equalTo($startLocal));
    }

    /**
     * Does the local time fall inside an opening window of that day?
     *
     * Anders als slotStarts wird die Dauer nicht verlangt: Wer um 22:45 noch
     * hereinkommt, bekommt einen Tisch, auch wenn um 23:00 geschlossen wird.
     */
    public function withinOpeningWindow(Location $location, CarbonImmutable $startLocal): bool
    {
        $windows = array_merge(
            $this->timeSlots->windowsForDate($location, $startLocal->startOfDay()->subDay()),
            $this->timeSlots->windowsForDate($location, $startLocal->startOfDay()),
        );

        foreach ($windows as $window) {
            if ($startLocal->gte($window['opens']) && $startLocal->lt($window['closes'])) {
                return true;
            }
        }

        return false;
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
        [$available, $reason] = $this->checkSlotDetailed($location, $startLocal, $startUtc, $endUtc, $partySize, $online, $nowLocal, []);

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

        // Vorlaufzeit und Buchungshorizont schuetzen die oeffentliche Buchung
        // vor sich selbst. Eine Belegung "ab jetzt" kommt vom Betrieb und steht
        // per Definition davor - der Gast steht bereits in der Tuer.
        $adHoc = (bool) ($options['ad_hoc'] ?? false);

        if ($online && ! $adHoc && $startLocal->lt($nowLocal->addMinutes($settings->min_lead_minutes))) {
            return [false, 'lead_time', []];
        }

        if ($online && ! $adHoc && $startLocal->gt($nowLocal->addDays($settings->max_advance_days))) {
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
                    // Beim Umbuchen darf sich die eigene Reservierung nicht
                    // selbst gegen das Platzlimit zaehlen.
                    ->when($options['exclude_reservation_id'] ?? null, fn ($q, $id) => $q->where('reservations.id', '!=', $id))
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
