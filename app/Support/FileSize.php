<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Dateigrößen für Meldungen und Hilfetexte: deutsche Schreibweise, eine
 * Nachkommastelle, wo nötig („1,5 MB“, „3 MB“). Abgerundet, damit „größer
 * als …“ immer stimmt: Eine Grenze von 1900 KB (1,855 MB) heißt „1,8 MB“.
 */
final class FileSize
{
    private const UNITS = ['KB', 'MB', 'GB'];

    public static function fromKilobytes(int $kilobytes): string
    {
        $value = (float) $kilobytes;
        $unit = 0;
        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            $unit++;
        }

        return Number::format(floor($value * 10) / 10, maxPrecision: 1, locale: 'de').' '.self::UNITS[$unit];
    }
}
