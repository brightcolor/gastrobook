<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Gültigkeitsdauer in Worten für Mails und Hinweise.
 */
final class Duration
{
    public static function minutes(int $minutes): string
    {
        if ($minutes >= 2880 && $minutes % 1440 === 0) {
            return ($minutes / 1440).' Tage';
        }
        if ($minutes >= 60 && $minutes % 60 === 0) {
            $hours = intdiv($minutes, 60);

            return $hours === 1 ? '1 Stunde' : $hours.' Stunden';
        }

        return $minutes === 1 ? '1 Minute' : $minutes.' Minuten';
    }
}
