<?php

declare(strict_types=1);

namespace App\Support;

use Collator;
use Illuminate\Support\Collection;

/**
 * Die Reihenfolge von Namen in den Listen der Einstellungen (Räume, Tische,
 * Kombinationen, Leistungen, Mitarbeiter): erst die eingestellte Reihenfolge
 * (sort_order), dann der Name wie in einem Verzeichnis. Umlaute stehen bei
 * ihrem Grundbuchstaben („Älteste“ zwischen „Abend“ und „Bart“), die
 * Groß- und Kleinschreibung zählt erst, wenn die Buchstaben sonst gleich sind,
 * und Zahlen zählen als Zahl („T2“ vor „T10“).
 *
 * Die Sprachregeln kommen aus der Sprache der Anwendung (app.locale, die
 * Erweiterung intl liefert sie).
 */
final class NameOrder
{
    private static ?Collator $collator = null;

    private static string $collatorLocale = '';

    /**
     * -1, 0 oder 1 wie der Operator <=> für zwei Namen.
     */
    public static function compare(string $a, string $b): int
    {
        $result = self::collator()->compare($a, $b);

        // false meldet einen Fehler der Bibliothek; dann entscheidet die Byte-Reihenfolge.
        return $result === false ? strcmp($a, $b) <=> 0 : $result;
    }

    /**
     * Die Einträge nach sort_order, dann nach Namen; die Schlüssel laufen neu von 0.
     *
     * @template TKey of array-key
     * @template TItem of object
     *
     * @param  Collection<TKey, TItem>  $items  Objekte mit name und sort_order
     * @return Collection<int, TItem>
     */
    public static function inOrder(Collection $items): Collection
    {
        return $items
            ->sort(fn ($a, $b) => (($a->sort_order ?? 0) <=> ($b->sort_order ?? 0)) ?: self::compare((string) $a->name, (string) $b->name))
            ->values();
    }

    private static function collator(): Collator
    {
        $locale = (string) config('app.locale');
        if (self::$collator === null || self::$collatorLocale !== $locale) {
            $collator = new Collator($locale);
            $collator->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
            self::$collator = $collator;
            self::$collatorLocale = $locale;
        }

        return self::$collator;
    }
}
