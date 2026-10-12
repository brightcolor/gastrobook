<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;
use App\Models\Service;
use App\Services\TimeSlotService;
use Carbon\CarbonImmutable;

/**
 * Die Vorschau „So sehen Gäste es“ auf der Seite Öffnungszeiten: Welche
 * Uhrzeiten die Buchungsseite für einen Wochentag anbietet, wenn er die Zeiten
 * im Formular hätte. Sie rechnet mit demselben Dienst wie die Buchungsseite
 * (TimeSlotService::startsForWindows), also mit Takt und Dauer des Standorts.
 */
final class OpeningHoursPreview
{
    /** Wochentage in der Zählung der Tabelle opening_hours: 0 ist Montag. */
    public const WEEKDAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    public function __construct(private readonly TimeSlotService $slots) {}

    /**
     * Die Vorschau für einen Wochentag mit den Zeitfenstern aus dem Formular.
     *
     * @param  list<array{opens_at: string, closes_at: string, service_name?: ?string}>  $times
     * @return array{starts: list<string>, last: ?string, reason: string, groups: list<array{label: string, last: ?string}>, hints: list<array{index: int, text: string}>}
     */
    public function forDay(Location $location, int $weekday, array $times): array
    {
        $salon = (bool) $location->tenant?->isSalon();
        $interval = $this->slots->slotInterval($location);
        $duration = $this->duration($location, $salon);
        $windows = $this->slots->windowsFromTimes($this->nextDate($weekday, $location->timezone), array_values($times));

        $starts = $this->slots->startsForWindows($windows, $duration, $interval);
        $last = $starts === [] ? null : $starts[array_key_last($starts)];

        return [
            'starts' => array_map(fn (CarbonImmutable $start) => $start->format('H:i'), $starts),
            'last' => $last?->format('H:i'),
            'reason' => $this->reason($windows, $last, $duration, $salon),
            'groups' => $this->groups($location, $salon, $windows, $duration, $interval),
            'hints' => $this->hints($windows, $weekday, $duration, $salon),
        ];
    }

    /**
     * Die Dauer, an der die Vorschau misst: im Restaurant die Standarddauer,
     * im Salon die kürzeste Dauer der aktiven Leistungen (ohne Leistungen die
     * Standarddauer).
     */
    public function duration(Location $location, bool $salon): int
    {
        $default = (int) $location->effectiveSettings()->default_duration_minutes;
        if (! $salon) {
            return $default;
        }
        $shortest = Service::where('location_id', $location->id)->where('is_active', true)->min('duration_minutes');

        return $shortest === null ? $default : (int) $shortest;
    }

    /** Der nächste Tag mit diesem Wochentag, heute eingeschlossen, in der Zeit des Standorts. */
    private function nextDate(int $weekday, string $timezone): CarbonImmutable
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return $today->addDays((($weekday - ($today->dayOfWeekIso - 1)) % 7 + 7) % 7);
    }

    /**
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     */
    private function reason(array $windows, ?CarbonImmutable $last, int $duration, bool $salon): string
    {
        if ($last === null) {
            return $windows === []
                ? __('An diesem Tag ist geschlossen.')
                : ($salon
                    ? __('Kein Fenster ist lang genug für einen Termin von :duration Minuten.', ['duration' => $duration])
                    : __('Kein Fenster ist lang genug für einen Besuch von :duration Minuten.', ['duration' => $duration]));
        }

        $closing = $last;
        foreach ($windows as $window) {
            if ($last->gte($window['opens']) && $last->lte($window['closes']->subMinutes($duration))) {
                $closing = $window['closes'];
                break;
            }
        }
        $values = ['last' => $last->format('H:i'), 'duration' => $duration, 'closing' => $closing->format('H:i')];

        return $salon
            ? __('Letzte Uhrzeit :last: Ein Termin dauert mindestens :duration Minuten, um :closing wird geschlossen.', $values)
            : __('Letzte Uhrzeit :last: Ein Besuch dauert :duration Minuten, um :closing wird geschlossen.', $values);
    }

    /**
     * Zeilen für Dauern, die von der Grundlage abweichen, mit der letzten
     * Uhrzeit, die dafür noch angeboten wird (null: keine).
     *
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     * @return list<array{label: string, last: ?string}>
     */
    private function groups(Location $location, bool $salon, array $windows, int $duration, int $interval): array
    {
        $groups = [];
        foreach ($salon ? $this->serviceDurations($location) : $this->partyDurations($location) as [$label, $minutes]) {
            if ($minutes === $duration) {
                continue;
            }
            $starts = $this->slots->startsForWindows($windows, $minutes, $interval);
            $groups[] = ['label' => $label, 'last' => $starts === [] ? null : $starts[array_key_last($starts)]->format('H:i')];
        }

        return array_slice($groups, 0, (int) config('swayy.opening_hours.preview_groups_max'));
    }

    /**
     * Tischzeiten je Gruppengröße im Restaurant: Beschriftung und Dauer.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function partyDurations(Location $location): array
    {
        $durations = [];
        foreach ((array) ($location->effectiveSettings()->duration_rules ?? []) as $rule) {
            if (! is_array($rule) || ! isset($rule['duration'])) {
                continue;
            }
            $min = (int) ($rule['min_party'] ?? 1);
            $max = (int) ($rule['max_party'] ?? $min);
            $durations[] = [
                $min === $max
                    ? trans_choice('{1} :count Person|[2,*] :count Personen', $min, ['count' => $min])
                    : __(':min–:max Personen', ['min' => $min, 'max' => $max]),
                (int) $rule['duration'],
            ];
        }

        return $durations;
    }

    /**
     * Dauer jeder aktiven Leistung des Standorts im Salon: Name und Dauer.
     *
     * @return list<array{0: string, 1: int}>
     */
    private function serviceDurations(Location $location): array
    {
        return Service::where('location_id', $location->id)->where('is_active', true)
            ->orderBy('name')->orderBy('id')->get(['name', 'duration_minutes'])
            ->map(fn (Service $service) => [(string) $service->name, (int) $service->duration_minutes])->all();
    }

    /**
     * Hinweise je Fenster: zu kurz für einen Besuch, endet am Folgetag.
     *
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     * @return list<array{index: int, text: string}>
     */
    private function hints(array $windows, int $weekday, int $duration, bool $salon): array
    {
        $hints = [];
        foreach ($windows as $index => $window) {
            $minutes = intdiv($window['closes']->getTimestamp() - $window['opens']->getTimestamp(), 60);
            if ($minutes < $duration) {
                $hints[] = ['index' => $index, 'text' => $salon
                    ? __('Kürzer als ein Termin (:duration Minuten): Hier finden Kunden keine Uhrzeit.', ['duration' => $duration])
                    : __('Kürzer als ein Besuch (:duration Minuten): Hier finden Gäste keine Uhrzeit.', ['duration' => $duration])];
            }
            if ($window['closes']->format('H:i') === $window['opens']->format('H:i')) {
                // Gleiche Öffnungs- und Schließzeit: Das Fenster dauert 24 Stunden, so liest es auch die Buchungsseite.
                $hints[] = ['index' => $index, 'text' => __('Rund um die Uhr geöffnet: Das Fenster dauert 24 Stunden.')];
            } elseif ($window['closes']->toDateString() !== $window['opens']->toDateString()) {
                $hints[] = ['index' => $index, 'text' => __('Endet am Folgetag (:day) um :time.', [
                    'day' => self::WEEKDAYS[($weekday + 1) % 7],
                    'time' => $window['closes']->format('H:i'),
                ])];
            }
        }

        return $hints;
    }
}
