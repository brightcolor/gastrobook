<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Env;

/**
 * Liest Einstellungen aus der Umgebung und haelt sie in ihren Grenzen.
 *
 * Gedacht fuer die Konfigurationsdateien: Dort stehen Variable, Vorgabe und
 * Grenzen jeder Einstellung beieinander. Ein ungueltiger Wert legt die
 * Anwendung nicht lahm. Es gilt die Vorgabe oder die naechste Grenze, und eine
 * Warnung nennt Variable, Wert und erlaubten Bereich. Mit zwischengespeicherter
 * Konfiguration (config:cache beim Containerstart) steht sie einmal im
 * Startprotokoll des Containers, sonst bei jedem Laden der Konfiguration im
 * PHP-Fehlerprotokoll.
 */
final class EnvSetting
{
    /**
     * Ganze Zahl von $min bis $max.
     */
    public static function integer(string $variable, int $default, int $min, int $max): int
    {
        $raw = self::raw($variable);
        if ($raw === null) {
            return $default;
        }

        $value = filter_var($raw, FILTER_VALIDATE_INT);
        if ($value === false) {
            self::warn("{$variable}=„{$raw}“ ist keine ganze Zahl. Es gilt die Vorgabe {$default}. Bitte einen Wert von {$min} bis {$max} in der .env eintragen.");

            return $default;
        }

        if ($value < $min || $value > $max) {
            $limited = max($min, min($max, $value));
            self::warn("{$variable}={$value} liegt außerhalb des erlaubten Bereichs von {$min} bis {$max}. Es gilt {$limited}. Bitte den Wert in der .env anpassen.");

            return $limited;
        }

        return $value;
    }

    /**
     * Komma-Liste ganzer Zahlen, jede von $min bis $max. Ist ein Eintrag
     * ungueltig, gilt die ganze Vorgabe: Eine halbe Liste aendert die
     * Bedeutung der uebrigen Eintraege.
     *
     * @param  list<int>  $default
     * @return list<int>
     */
    public static function integerList(string $variable, array $default, int $min, int $max): array
    {
        $raw = self::raw($variable);
        if ($raw === null) {
            return $default;
        }

        $values = [];
        foreach (self::items($raw) as $item) {
            $value = filter_var($item, FILTER_VALIDATE_INT);
            if ($value === false || $value < $min || $value > $max) {
                self::warn("{$variable}: Der Eintrag „{$item}“ ist keine ganze Zahl von {$min} bis {$max}. Es gilt die Vorgabe ".implode(',', $default).'. Bitte den Wert in der .env anpassen.');

                return $default;
            }

            $values[] = $value;
        }

        return $values === [] ? $default : $values;
    }

    /**
     * Komma-Liste von Schluesseln aus $allowed, Reihenfolge wie angegeben.
     * Ein leerer Wert ("-") ergibt eine leere Liste. Ist ein Eintrag
     * unbekannt, gilt die ganze Vorgabe: Eine halbe Liste aendert die
     * Reihenfolge der uebrigen Eintraege.
     *
     * @param  list<string>  $default
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public static function choiceList(string $variable, array $default, array $allowed): array
    {
        $raw = self::raw($variable);
        if ($raw === null) {
            return $default;
        }
        if ($raw === '-') {
            return [];
        }

        $values = [];
        foreach (self::items($raw) as $item) {
            if (! in_array($item, $allowed, true)) {
                self::warn("{$variable}: Der Eintrag „{$item}“ ist unbekannt. Es gilt die Vorgabe ".implode(',', $default).'. Erlaubt sind: '.implode(', ', $allowed).'; „-“ für keinen.');

                return $default;
            }

            if (! in_array($item, $values, true)) {
                $values[] = $item;
            }
        }

        return $values === [] ? $default : $values;
    }

    /**
     * Komma-Liste aus IP-Adressen und Netzen in CIDR-Schreibweise. Ungueltige
     * Eintraege entfallen mit Warnung, die uebrigen gelten.
     *
     * @return list<string>
     */
    public static function networks(string $variable): array
    {
        $raw = self::raw($variable);
        if ($raw === null) {
            return [];
        }

        $networks = [];
        foreach (self::items($raw) as $item) {
            if (self::isNetwork($item)) {
                $networks[] = $item;

                continue;
            }

            self::warn("{$variable}: Der Eintrag „{$item}“ ist weder eine IP-Adresse noch ein Netz in CIDR-Schreibweise und bleibt unberücksichtigt. Bitte den Eintrag korrigieren, etwa 10.20.0.0/16.");
        }

        return $networks;
    }

    private static function raw(string $variable): ?string
    {
        $value = Env::get($variable);
        if ($value === null || is_array($value)) {
            return null;
        }

        $value = trim(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function items(string $raw): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $item) => $item !== ''
        ));
    }

    private static function isNetwork(string $item): bool
    {
        [$address, $prefix] = array_pad(explode('/', $item, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if ($prefix === null) {
            return true;
        }

        $max = str_contains((string) $address, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $max;
    }

    private static function warn(string $message): void
    {
        error_log('[swayy] Einstellung '.$message);
    }
}
