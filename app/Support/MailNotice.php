<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Der Hinweis auf den Spam-Ordner. Jede Meldung, die eine Mail ankündigt,
 * nennt ihn – Bestätigungslinks, Anmelde- und Reset-Links, Eingangs- und
 * Reservierungsbestätigungen, Testmails (Vorgabe von Mathias vom 26.11.2026).
 * Der Text steht nur hier, damit alle Stellen gleich sprechen;
 * MailNoticeGuardTest prüft, dass jede ankündigende Meldung ihn nutzt.
 */
final class MailNotice
{
    public static function spamHint(bool $du): string
    {
        return $du
            ? 'Keine Mail da? Sieh bitte auch im Spam- oder Werbeordner nach.'
            : 'Keine Mail da? Bitte sehen Sie auch im Spam- oder Werbeordner nach.';
    }

    /**
     * Für Meldungen in einem Satz (Flash-Meldungen, Statuszeilen).
     */
    public static function withSpamHint(string $message, bool $du): string
    {
        return rtrim($message).' '.self::spamHint($du);
    }
}
