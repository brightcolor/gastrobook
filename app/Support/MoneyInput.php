<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Euro-Beträge aus Formularen und JSON in Cent und zurück. Die Umrechnung
 * arbeitet mit Ziffern: „1.15“ ergibt 115, wo 1.15 × 100 als Fließkomma
 * 114.99999999999999 ergäbe.
 *
 * Die Eingabe ist eine Zahl mit Punkt und höchstens zwei Nachkommastellen
 * (PATTERN). Prüfregeln nutzen PATTERN mit `regex:`; toMinor rechnet geprüfte
 * Werte um und meldet alles andere mit einer Ausnahme.
 */
final class MoneyInput
{
    /** Zahl ohne Vorzeichen, mit Punkt und höchstens zwei Nachkommastellen. */
    public const PATTERN = '/^\d+(\.\d{1,2})?$/';

    /**
     * @throws InvalidArgumentException wenn der Wert kein Betrag nach PATTERN ist
     */
    public static function toMinor(string|int|float $euro): int
    {
        $text = match (true) {
            is_float($euro) => rtrim(rtrim(sprintf('%.2F', $euro), '0'), '.'),
            default => (string) $euro,
        };

        // Das D macht das Zeilenende streng: „12.5\n“ ist kein Betrag.
        if (preg_match(self::PATTERN.'D', $text) !== 1) {
            throw new InvalidArgumentException("„{$text}“ ist kein Euro-Betrag mit Punkt und höchstens zwei Nachkommastellen.");
        }

        [$whole, $fraction] = array_pad(explode('.', $text, 2), 2, '');

        return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
    }

    /**
     * Cent als Euro-Text für ein Eingabefeld: 1250 ergibt „12.50“.
     */
    public static function fromMinor(int $minor): string
    {
        return sprintf('%d.%02d', intdiv($minor, 100), $minor % 100);
    }

    /**
     * Cent für die Anzeige: 123456 ergibt „1.234,56 €“.
     */
    public static function format(int $minor): string
    {
        return number_format(intdiv($minor, 100), 0, ',', '.').','.sprintf('%02d', $minor % 100).' €';
    }
}
