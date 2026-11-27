<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Die Namen der Wochentage an einer Stelle.
 *
 * Die Woche beginnt am Montag mit 0, wie die Spalten opening_hours.weekday,
 * staff_working_hours.weekday und deposit_rules.weekdays. Vorher standen die
 * Namen in sechs Dateien, eine davon mit Sonntag als Tag 0. WeekdaysTest
 * meldet jede neue eigene Liste.
 */
final class Weekdays
{
    /** @var list<string> */
    public const NAMES = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

    /** @var list<string> */
    public const SHORT = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    /** Name zum Tag der Woche (0 = Montag). */
    public static function name(int $weekday): string
    {
        return self::NAMES[$weekday] ?? '';
    }

    /** Kurzform zum Tag der Woche (0 = Montag). */
    public static function short(int $weekday): string
    {
        return self::SHORT[$weekday] ?? '';
    }

    /** Name des Wochentags eines Datums. */
    public static function of(CarbonInterface $date): string
    {
        return self::name($date->dayOfWeekIso - 1);
    }
}
