<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Number;

/**
 * Dateigrößen für Meldungen und Hilfetexte: deutsche Schreibweise, eine
 * Nachkommastelle, wo nötig („1,5 MB“, „3 MB“). Ganze Einheiten allein
 * würden eine Grenze von 1500 KB als „1 MB“ nennen und dann Dateien unter
 * 1,5 MB mit „größer als 1 MB“ ablehnen.
 */
final class FileSize
{
    public static function fromKilobytes(int $kilobytes): string
    {
        return Number::withLocale('de', fn () => (string) Number::fileSize($kilobytes * 1024, maxPrecision: 1));
    }
}
