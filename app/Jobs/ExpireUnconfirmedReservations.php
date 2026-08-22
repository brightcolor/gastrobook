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
use Illuminate\Support\Facades\DB;

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
            // Beide Zustände, in denen eine Buchung auf die Bestätigung der
            // Adresse warten kann: als reine Anfrage, oder – wenn zusätzlich
            // eine Anzahlung fällig ist – als PaymentPending. Im zweiten Fall
            // läuft noch keine Zahlungsfrist (die startet erst mit dem Klick),
            // ExpireUnpaidReservations greift dort also nicht.
            ->whereIn('status', [
                ReservationStatus::Requested->value,
                ReservationStatus::PaymentPending->value,
            ])
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
            // haben, oder der Betrieb hat von Hand zugesagt. Unter Sperre
            // gelesen, sonst committet die Bestätigung zwischen Lesen und
            // Schreiben und der Lauf setzt sie danach auf "verfallen".
            DB::transaction(function () use ($reservation, $lifecycle) {
                $gesperrt = Reservation::withoutGlobalScopes()->lockForUpdate()->find($reservation->id);

                if ($gesperrt === null || ! in_array($gesperrt->status, [
                    ReservationStatus::Requested,
                    ReservationStatus::PaymentPending,
                ], true)) {
                    return;
                }

                $lifecycle->transition(
                    $gesperrt,
                    ReservationStatus::Expired,
                    null,
                    'system',
                    'email_confirmation_missing',
                );
            });
        }
    }
}
