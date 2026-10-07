<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Location;
use App\Models\SeasonPeriod;
use Carbon\CarbonImmutable;

/**
 * Buchbare Saisons: wiederkehrende Fenster je Standort. Ein Tag ist buchbar,
 * wenn er in mindestens ein Fenster fällt. Ohne Fenster gilt keine
 * Einschränkung (abwärtskompatibel). Verglichen wird über Monat*100+Tag, damit
 * Fenster über den Jahreswechsel natürlich funktionieren.
 */
class SeasonService
{
    public function isBookable(Location $location, CarbonImmutable $localDate): bool
    {
        $periods = $location->seasonPeriods;

        if ($periods->isEmpty()) {
            return true;
        }

        $md = $localDate->month * 100 + $localDate->day;

        foreach ($periods as $period) {
            if ($this->withinWindow($md, $period)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Der nächste lokale Tag ab $fromLocal, der wieder in Saison liegt – für den
     * freundlichen Hinweis auf der Buchungsseite. null, wenn kein Fenster
     * gesetzt ist (dann gibt es keine Saison-Sperre).
     */
    public function nextOpening(Location $location, CarbonImmutable $fromLocal): ?CarbonImmutable
    {
        if ($location->seasonPeriods->isEmpty()) {
            return null;
        }

        $day = $fromLocal->startOfDay();

        // Ein voller Jahreskreis genügt, um bei jedem Fenster den nächsten
        // Start zu treffen.
        for ($i = 0; $i <= 366; $i++) {
            if ($this->isBookable($location, $day)) {
                return $day;
            }
            $day = $day->addDay();
        }

        return null;
    }

    private function withinWindow(int $md, SeasonPeriod $period): bool
    {
        $start = $period->start_month * 100 + $period->start_day;
        $end = $period->end_month * 100 + $period->end_day;

        return $start <= $end
            ? ($md >= $start && $md <= $end)
            : ($md >= $start || $md <= $end);
    }
}
