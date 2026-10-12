<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;

/**
 * Die Zählweisen eines Standorts (Einstellungen › Platz & Zeiten › Kapazität &
 * Dauer, Spalte location_settings.capacity_mode) mit den Bezeichnungen, die
 * Seiten und Meldungen gemeinsam nutzen.
 */
final class CapacityModes
{
    public const TABLE = 'table';

    public const PERSON = 'person';

    public const HYBRID = 'hybrid';

    /** @var array<string, string> */
    public const LABELS = [
        self::TABLE => 'Nach Tischen',
        self::PERSON => 'Nach Personen',
        self::HYBRID => 'Beides',
    ];

    /** Zählweisen, in denen Swayy Tische vergibt: Nur dort wirken Sperren von Räumen und Tischen auf Buchungen. */
    public const ASSIGNING = [self::TABLE, self::HYBRID];

    /** Die Bezeichnung einer Zählweise; eine unbekannte Kennung bleibt, wie sie ist. */
    public static function label(string $mode): string
    {
        return self::LABELS[$mode] ?? $mode;
    }

    /** Die Zählweise des Standorts (ohne gespeicherte Einstellungen die Vorgabe der Spalte). */
    public static function of(Location $location): string
    {
        return (string) $location->effectiveSettings()->capacity_mode;
    }

    /** Vergibt Swayy an diesem Standort Tische? Dann wirken Sperren von Räumen und Tischen. */
    public static function assignsTables(Location $location): bool
    {
        return in_array(self::of($location), self::ASSIGNING, true);
    }

    /** Die Bezeichnungen der Zählweisen mit Tischvergabe als Aufzählung: „Nach Tischen“ und „Beides“. */
    public static function assigningList(): string
    {
        return ScheduleExceptionSentence::joinList(array_map(fn (string $mode) => ScheduleExceptionSentence::quoted(self::label($mode)), self::ASSIGNING));
    }
}
