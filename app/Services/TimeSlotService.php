<?php

namespace App\Services;

use App\Models\Location;
use Carbon\CarbonImmutable;

class TimeSlotService
{
    /**
     * Opening windows for a local date, considering special opening hours
     * (which fully override regular hours for that date).
     *
     * @return array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>
     *         Times are in the location's timezone.
     */
    public function windowsForDate(Location $location, CarbonImmutable $localDate): array
    {
        $special = $location->specialOpeningHours()
            ->whereDate('date', $localDate->toDateString())
            ->get();

        if ($special->isNotEmpty()) {
            $windows = [];
            foreach ($special as $entry) {
                if ($entry->closed || ! $entry->opens_at || ! $entry->closes_at) {
                    continue;
                }
                $windows[] = $this->makeWindow($localDate, $entry->opens_at, $entry->closes_at, $entry->label);
            }

            return $windows;
        }

        // ISO weekday 1 (Mon) ... 7 (Sun) → stored 0..6
        $weekday = $localDate->dayOfWeekIso - 1;

        return $location->openingHours()
            ->where('weekday', $weekday)
            ->orderBy('opens_at')
            ->get()
            ->map(fn ($h) => $this->makeWindow($localDate, $h->opens_at, $h->closes_at, $h->service_name))
            ->all();
    }

    /**
     * Candidate slot start times (local) for a date given the slot interval.
     * A slot is only generated if the full duration fits into the window.
     *
     * @return array<int, CarbonImmutable>
     */
    public function slotStarts(Location $location, CarbonImmutable $localDate, int $durationMinutes): array
    {
        $settings = $location->effectiveSettings();
        $interval = max(5, (int) $settings->slot_interval_minutes);
        $starts = [];

        foreach ($this->windowsForDate($location, $localDate) as $window) {
            $cursor = $window['opens'];
            $lastStart = $window['closes']->subMinutes($durationMinutes);
            while ($cursor->lte($lastStart)) {
                $starts[] = $cursor;
                $cursor = $cursor->addMinutes($interval);
            }
        }

        $starts = array_unique($starts, SORT_REGULAR);
        usort($starts, fn ($a, $b) => $a->timestamp <=> $b->timestamp);

        return $starts;
    }

    /**
     * @param  string  $opens  'HH:MM:SS' or 'HH:MM'
     */
    private function makeWindow(CarbonImmutable $localDate, string $opens, string $closes, ?string $label): array
    {
        $tz = $localDate->getTimezone();
        $open = CarbonImmutable::parse($localDate->toDateString().' '.$opens, $tz);
        $close = CarbonImmutable::parse($localDate->toDateString().' '.$closes, $tz);

        // Past-midnight closing (e.g. bar open until 02:00)
        if ($close->lte($open)) {
            $close = $close->addDay();
        }

        return ['opens' => $open, 'closes' => $close, 'label' => $label];
    }
}
