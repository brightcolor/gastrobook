<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;
use App\Services\TimeSlotService;
use Carbon\CarbonImmutable;
use Closure;

/**
 * Der Satz „So wirkt es“ der Ausnahmen (Einstellungen › Platz & Zeiten ›
 * Ausnahmen): in einem Satz, was Gäste erleben, wenn die Ausnahme gilt, samt
 * letzter buchbarer Uhrzeit. Die Uhrzeit rechnet mit Takt und Dauer des
 * Standorts wie die Buchungsseite (TimeSlotService::startsForWindows).
 *
 * Neben dem Satz liefert die Klasse die Bausteine, die auch die Einträge der
 * Liste nutzen: Zeitangabe, Aufzählung, Anführungszeichen, Gästezahl.
 */
final class ScheduleExceptionSentence
{
    public function __construct(
        private readonly TimeSlotService $slots,
        private readonly OpeningHoursPreview $preview,
    ) {}

    /**
     * Der Satz zu den Feldern einer Ausnahme (geprüfte Felder aus
     * ScheduleExceptionRules::rules).
     *
     * Mit eigenen Öffnungszeiten rechnet die letzte Uhrzeit mit deren
     * Fenstern. Bei Grenze und Sperre gilt der Tag des Beginns so, wie die
     * Buchungsseite ihn liest: Sondertage ersetzen die regulären Zeiten, sonst
     * gelten die Zeiten des Wochentags. $otherSpecialRows schränkt die
     * gelesenen Sondertage ein (Aufruf mit der Abfrage); beim Bearbeiten
     * lässt sie die Zeilen des bearbeiteten Eintrags aus. Ist der Tag durch
     * einen anderen Eintrag geschlossen, sagt der Satz das.
     *
     * @param  array<string, mixed>  $data
     * @param  ?Closure(mixed): mixed  $otherSpecialRows
     */
    public function describe(Location $location, array $data, ?Closure $otherSpecialRows = null): string
    {
        $effects = array_values(array_intersect(ScheduleExceptionRules::EFFECTS, (array) ($data['effects'] ?? [])));
        if ($effects === []) {
            return '';
        }

        $salon = (bool) $location->tenant?->isSalon();
        $from = (string) $data['from'];
        $until = (string) ($data['until'] ?? $from);
        $when = self::when($from, $until);

        if ($effects === ['closed']) {
            return __(':when kann niemand online buchen.', ['when' => $when]);
        }

        $day = CarbonImmutable::parse($from, $location->timezone);
        $closure = null;
        if (in_array('hours', $effects, true)) {
            $windows = $this->slots->windowsFromTimes($day, ScheduleExceptionRules::sortedWindows($data));
        } else {
            $windows = $this->slots->windowsForDate($location, $day, $otherSpecialRows);
            $closure = $windows === [] ? $this->closure($location, $day, $otherSpecialRows) : null;
        }
        $last = $this->lastStart($location, $windows, $salon);
        $guests = $salon ? __('Kunden') : __('Gäste');
        $noTime = $salon
            ? __('Kein Fenster ist lang genug für einen Termin.')
            : __('Kein Fenster ist lang genug für einen Besuch.');

        if ($effects === ['hours']) {
            $opening = __(':when buchen :guests zwischen :open und :close', [
                'when' => $when, 'guests' => $guests, 'open' => $this->firstOpening($windows), 'close' => $this->lastClosing($windows),
            ]);

            return $last === null
                ? $opening.', '.__('finden aber keine Uhrzeit: :reason', ['reason' => $noTime])
                : $opening.', '.__('letzte Uhrzeit :last.', ['last' => $last]);
        }

        $parts = [];
        if (in_array('hours', $effects, true)) {
            $parts[] = __('buchen :guests zwischen :open und :close', ['guests' => $guests, 'open' => $this->firstOpening($windows), 'close' => $this->lastClosing($windows)]);
        }
        if (in_array('limit', $effects, true)) {
            $parts[] = $this->limitPart($location, $data, $salon, first: $parts === []);
        }
        if (in_array('lock', $effects, true)) {
            $parts[] = $this->lockPart($location, $data, first: $parts === []);
        }
        $sentence = $when.' '.implode('; ', array_values(array_filter($parts)));

        // Eine reine Sperre ändert keine Zeiten: Die letzte Uhrzeit gehört zu den Wirkungen, die Zeiten oder Plätze ändern.
        // Schließt ein anderer Eintrag den Tag, sagt der Satz das weiterhin.
        if ($effects === ['lock'] && $closure === null) {
            return $sentence.'.';
        }
        if ($last !== null) {
            return $sentence.'; '.__('letzte Uhrzeit :last.', ['last' => $last]);
        }
        if ($closure !== null) {
            return $sentence.'; '.$this->closedText($from !== $until, $from, $closure['label']).'.';
        }

        return $windows === []
            ? $sentence.'.'
            : $sentence.'; '.__(':guests finden keine Uhrzeit: :reason', ['guests' => $guests, 'reason' => $noTime]);
    }

    /**
     * Zeitangabe: "Am 24.12.2026" für einen Tag, "Vom 24.12.2026 bis 26.12.2026" für einen Zeitraum.
     */
    public static function when(string $from, string $until): string
    {
        return $from === $until
            ? __('Am :date', ['date' => self::date($from)])
            : __('Vom :from bis :until', ['from' => self::date($from), 'until' => self::date($until)]);
    }

    /**
     * Aufzählung: "A", "A und B", "A, B und C".
     *
     * @param  list<string>  $items
     */
    public static function joinList(array $items): string
    {
        if (count($items) < 2) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return implode(', ', $items).' '.__('und').' '.$last;
    }

    public static function quoted(string $name): string
    {
        return '„'.$name.'“';
    }

    /** Gästezahl mit Einzahl und Mehrzahl: "1 Gast", "20 Gäste", im Salon "3 Kunden". */
    public static function guests(int $count, bool $salon): string
    {
        return trans_choice($salon ? '{1} :count Kunde|[0,*] :count Kunden' : '{1} :count Gast|[0,*] :count Gäste', $count);
    }

    /** Datum "d.m.Y" aus "Y-m-d". */
    public static function date(string $day): string
    {
        return CarbonImmutable::parse($day)->format('d.m.Y');
    }

    /**
     * Sondertage, die den Tag schließen: null, wenn es keine gibt, sonst die
     * Bezeichnung des ersten mit Namen (oder null ohne Namen).
     *
     * @param  ?Closure(mixed): mixed  $otherSpecialRows
     * @return ?array{label: ?string}
     */
    private function closure(Location $location, CarbonImmutable $day, ?Closure $otherSpecialRows): ?array
    {
        $rows = $location->specialOpeningHours()
            ->whereDate('date', $day->toDateString())
            ->when($otherSpecialRows !== null, $otherSpecialRows)
            ->orderBy('id')
            ->get();
        if ($rows->isEmpty()) {
            return null;
        }
        $label = $rows->pluck('label')->first(fn ($name) => is_string($name) && $name !== '');

        return ['label' => $label === null ? null : (string) $label];
    }

    private function closedText(bool $range, string $from, ?string $label): string
    {
        $date = self::date($from);

        return match (true) {
            $label !== null && ! $range => __('an diesem Tag ist wegen „:label“ geschlossen', ['label' => $label]),
            $label !== null => __('am :date ist wegen „:label“ geschlossen', ['date' => $date, 'label' => $label]),
            ! $range => __('an diesem Tag ist durch eine andere Ausnahme geschlossen'),
            default => __('am :date ist durch eine andere Ausnahme geschlossen', ['date' => $date]),
        };
    }

    /**
     * Die letzte Startzeit der Fenster ("HH:MM"), oder null, wenn keine Uhrzeit bleibt.
     *
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     */
    private function lastStart(Location $location, array $windows, bool $salon): ?string
    {
        $starts = $this->slots->startsForWindows($windows, $this->preview->duration($location, $salon), $this->slots->slotInterval($location));

        return $starts === [] ? null : $starts[array_key_last($starts)]->format('H:i');
    }

    /**
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     */
    private function firstOpening(array $windows): string
    {
        return collect($windows)->map(fn (array $window) => $window['opens'])->sortBy(fn (CarbonImmutable $time) => $time->getTimestamp())->first()?->format('H:i') ?? '';
    }

    /**
     * @param  array<int, array{opens: CarbonImmutable, closes: CarbonImmutable, label: ?string}>  $windows
     */
    private function lastClosing(array $windows): string
    {
        return collect($windows)->map(fn (array $window) => $window['closes'])->sortByDesc(fn (CarbonImmutable $time) => $time->getTimestamp())->first()?->format('H:i') ?? '';
    }

    /** @param array<string, mixed> $data */
    private function limitPart(Location $location, array $data, bool $salon, bool $first): string
    {
        $guests = self::guests((int) ($data['limit_covers'] ?? 0), $salon);
        $roomId = $data['limit_room_id'] ?? null;
        $room = $roomId === null ? null : $location->rooms()->whereKey((int) $roomId)->value('name');
        $where = $room === null ? __('im ganzen Betrieb') : __('im Raum „:room“', ['room' => $room]);
        $range = $this->range($data['limit_from'] ?? null, $data['limit_until'] ?? null);
        $verb = $first ? __('gelten') : __('es gelten');

        return $range === null
            ? __(':verb höchstens :guests gleichzeitig :where', ['verb' => $verb, 'guests' => $guests, 'where' => $where])
            : __(':verb :range höchstens :guests gleichzeitig :where', ['verb' => $verb, 'range' => $range, 'guests' => $guests, 'where' => $where]);
    }

    /** @param array<string, mixed> $data */
    private function lockPart(Location $location, array $data, bool $first): string
    {
        $items = [];
        $rooms = ScheduleExceptionRules::ids($data['lock_rooms'] ?? null);
        if ($rooms !== []) {
            foreach ($location->rooms()->whereIn('id', $rooms)->orderBy('name')->orderBy('id')->pluck('name') as $name) {
                $items[] = self::quoted((string) $name);
            }
        }
        $tables = ScheduleExceptionRules::ids($data['lock_tables'] ?? null);
        if ($tables !== []) {
            foreach ($location->tables()->whereIn('id', $tables)->orderBy('name')->orderBy('id')->pluck('name') as $name) {
                $items[] = __('Tisch :table', ['table' => $name]);
            }
        }
        if ($items === []) {
            return '';
        }

        $list = self::joinList($items);
        $one = count($items) === 1;
        $part = $first
            ? ($one ? __('ist :list gesperrt', ['list' => $list]) : __('sind :list gesperrt', ['list' => $list]))
            : ($one ? __(':list ist gesperrt', ['list' => $list]) : __(':list sind gesperrt', ['list' => $list]));
        $range = $this->range($data['lock_from'] ?? null, $data['lock_until'] ?? null);

        return $range === null ? $part : $part.' '.$range;
    }

    /** "von 18:00 bis 22:00", oder null ohne Uhrzeiten. */
    private function range(mixed $from, mixed $until): ?string
    {
        return is_string($from) && is_string($until) ? __('von :from bis :until', ['from' => $from, 'until' => $until]) : null;
    }
}
