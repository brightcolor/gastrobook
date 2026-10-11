<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Felder, die nach einer abgelehnten Eingabe nie in der Sitzung landen
 * (_old_input). Die Zugangsdaten der Verbindungsseiten bleiben immer draußen;
 * swayy.security.dont_flash ergänzt weitere Felder. So schaltet ein leerer
 * oder vertippter Wert in SWAYY_DONT_FLASH den Schutz der Zugangsdaten nie ab.
 */
final class SessionSecrets
{
    /** Feldnamen der Zugangsdaten auf SMS-Versand, Zahlungsanbieter und Newsletter. */
    public const ALWAYS = ['api_key', 'secret_key', 'webhook_secret', 'client_id', 'secret'];

    /** @return list<string> */
    public static function fields(): array
    {
        $configured = app()->bound('config') ? (array) config('swayy.security.dont_flash', []) : [];

        return array_values(array_unique(array_merge(
            self::ALWAYS,
            array_filter($configured, 'is_string'),
        )));
    }
}
