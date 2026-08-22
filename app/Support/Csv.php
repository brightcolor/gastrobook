<?php

declare(strict_types=1);

namespace App\Support;

/**
 * CSV-Zeilen schreiben, ohne dass Tabellenkalkulationen sie ausführen.
 *
 * Excel und LibreOffice behandeln eine Zelle, die mit `=`, `+`, `-` oder `@`
 * beginnt, als Formel. In einem Export stehen dort Gasteingaben: Name, Notiz,
 * Telefonnummer. Ein Feld wie `=HYPERLINK("http://…";"Klick")` läuft beim
 * Öffnen los - auf dem Rechner desjenigen, der den Export ausgewertet hat.
 *
 * Ein vorangestelltes Apostroph macht daraus wieder Text. Es ist in der
 * Ansicht unsichtbar und geht beim erneuten Einlesen als CSV verloren.
 */
final class Csv
{
    /** Zeichen, mit denen eine Formel beginnen kann - inklusive Tabulator. */
    private const TRIGGER = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * @param  resource  $handle
     * @param  array<int, mixed>  $row
     */
    public static function write($handle, array $row, string $separator = ';'): void
    {
        fputcsv($handle, array_map(self::escape(...), $row), $separator);
    }

    public static function escape(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        return in_array($value[0], self::TRIGGER, true) ? "'".$value : $value;
    }
}
