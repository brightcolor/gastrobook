<?php

declare(strict_types=1);

namespace App\Mail\Layout;

/**
 * Werte, die ein Gast selbst eingibt (Name, Notiz, Anlass, Allergien), für
 * gegliederte Mails: eine Zeile, ohne Web-Adressen. So wird aus einer
 * Eingabe nie ein Knopf, eine Tabelle oder ein Link im Auftritt des Betriebs
 * oder von Swayy (Spec Mail-Design, Review Focus 1).
 */
final class GuestText
{
    public static function inline(?string $value): string
    {
        $line = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return (string) preg_replace('~https?://~i', '', $line);
    }
}
