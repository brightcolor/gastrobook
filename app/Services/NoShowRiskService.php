<?php

namespace App\Services;

use App\Models\Guest;

class NoShowRiskService
{
    /**
     * Simple transparent heuristic (0-100). Documented and deactivatable;
     * no opaque profiling (GDPR Art. 22 friendly — staff hint only, never
     * an automated rejection).
     */
    public function score(?Guest $guest, int $partySize): int
    {
        $score = 0;

        if ($guest !== null) {
            $history = $guest->visit_count + $guest->no_show_count + $guest->cancellation_count;
            if ($history > 0) {
                $score += (int) round(60 * $guest->no_show_count / max(1, $history));
                $score += (int) round(20 * $guest->cancellation_count / max(1, $history));
            }
            if ($guest->visit_count >= 3 && $guest->no_show_count === 0) {
                $score = max(0, $score - 20);
            }
        } else {
            $score += 10; // unknown guest, slight uncertainty
        }

        if ($partySize >= 8) {
            $score += 15;
        } elseif ($partySize >= 6) {
            $score += 10;
        }

        return min(100, max(0, $score));
    }
}
