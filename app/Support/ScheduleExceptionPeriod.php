<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Der Zeitraum einer Ausnahme für die Liste (Einstellungen › Platz & Zeiten ›
 * Ausnahmen): der Text mit Jahr und die Bausteine des Kalenderblatts. Das
 * Blatt zeigt den Monat klein und den Tag groß; ein Zeitraum nennt Beginn und
 * Ende, über eine Monatsgrenze auch beide Monate.
 */
final class ScheduleExceptionPeriod
{
    /** Kurzformen der Monate, wie sie im Deutschen üblich sind (Duden). */
    private const MONTHS = [
        1 => 'Jan.', 2 => 'Feb.', 3 => 'März', 4 => 'Apr.', 5 => 'Mai', 6 => 'Juni',
        7 => 'Juli', 8 => 'Aug.', 9 => 'Sept.', 10 => 'Okt.', 11 => 'Nov.', 12 => 'Dez.',
    ];

    /**
     * Der Zeitraum mit Jahr: "24.12.2026" für einen Tag, "24.12.2026 bis
     * 26.12.2026" für mehrere.
     */
    public static function text(string $from, string $until): string
    {
        return $from === $until
            ? ScheduleExceptionSentence::date($from)
            : __(':from bis :until', ['from' => ScheduleExceptionSentence::date($from), 'until' => ScheduleExceptionSentence::date($until)]);
    }

    /**
     * Bausteine des Kalenderblatts:
     *
     * - ein Tag: Monat "Dez.", start "24", end null
     * - mehrere Tage im selben Monat: Monat "Dez.", start "24.", end "26."
     * - über eine Monatsgrenze: Monat "Dez. – Jan.", start "30.", end "2."
     *
     * @return array{month: string, start: string, end: ?string}
     */
    public static function sheet(string $from, string $until): array
    {
        $first = CarbonImmutable::parse($from);
        $last = CarbonImmutable::parse($until);

        if ($from === $until) {
            return ['month' => self::month($first), 'start' => (string) $first->day, 'end' => null];
        }

        $sameMonth = $first->format('Y-m') === $last->format('Y-m');

        return [
            'month' => $sameMonth ? self::month($first) : self::month($first).' – '.self::month($last),
            'start' => $first->day.'.',
            'end' => $last->day.'.',
        ];
    }

    private static function month(CarbonImmutable $day): string
    {
        return self::MONTHS[$day->month];
    }
}
