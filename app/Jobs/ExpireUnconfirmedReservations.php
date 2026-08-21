<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Models\GuestAuthToken;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Gibt Tische wieder frei, deren E-Mail-Bestätigung nie kam.
 *
 * Verlangt ein Standort die Bestätigung der Adresse, bleibt die Buchung bis
 * zum Klick auf „Anfrage". Ohne diesen Lauf bliebe sie das für immer – und
 * weil `Requested` als aktiv gilt, blockiert sie den Tisch. Wer auf Vorrat
 * bucht und keine einzige Mail öffnet, hätte dann trotzdem alle Termine.
 *
 * Abgeräumt wird ausschliesslich, was an einem **abgelaufenen, nie benutzten
 * Bestätigungslink** hängt. Anfragen, die aus anderen Gründen offen sind –
 * etwa weil der Betrieb jede Buchung selbst freigibt – bleiben unangetastet.
 */
class ExpireUnconfirmedReservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Notbremse pro Lauf, wie bei den offenen Anzahlungen. */
    private const MAX_PER_RUN = 500;

    public function handle(ReservationLifecycleService $lifecycle): void
    {
        $offen = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Requested->value)
            // Genau die Buchungen, deren Bestätigungslink abgelaufen ist und
            // nie angeklickt wurde.
            ->whereIn('id', GuestAuthToken::withoutGlobalScopes()
                ->where('purpose', 'verify')
                ->whereNotNull('reservation_id')
                ->whereNull('used_at')
                ->where('expires_at', '<=', now())
                ->select('reservation_id'))
            // Ein zweiter, gültiger Link darf die Buchung retten – etwa wenn
            // der Gast sich die Mail erneut schicken liess.
            ->whereNotIn('id', GuestAuthToken::withoutGlobalScopes()
                ->where('purpose', 'verify')
                ->whereNotNull('reservation_id')
                ->where(fn ($q) => $q->whereNotNull('used_at')->orWhere('expires_at', '>', now()))
                ->select('reservation_id'))
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($offen as $reservation) {
            // Zwischen Laden und Verarbeiten kann der Gast doch noch bestätigt
            // haben, oder der Betrieb hat von Hand zugesagt.
            $reservation->refresh();

            if ($reservation->status !== ReservationStatus::Requested) {
                continue;
            }

            $lifecycle->transition(
                $reservation,
                ReservationStatus::Expired,
                null,
                'system',
                'email_confirmation_missing',
            );
        }
    }
}
