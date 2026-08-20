<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Models\DepositRule;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * Kümmert sich um Reservierungen, die auf eine Anzahlung warten.
 *
 * Ohne diesen Lauf sind `payment_due_at` und `cancel_unpaid_automatically`
 * tote Felder: Die Reservierung bleibt für immer auf `payment_pending`, und
 * weil dieser Status als aktiv gilt, blockiert ein Gast, der nie zahlt, den
 * Tisch dauerhaft. Das ist das Gegenteil dessen, wofür eine Anzahlung da ist.
 *
 * Zwei Aufgaben:
 *  1. Erinnerung, wenn die Hälfte der Zahlungsfrist verstrichen ist.
 *  2. Nach Fristablauf den Tisch freigeben. Nur eine Regel, die das
 *     Auto-Storno ausdrücklich abschaltet, hält die Buchung offen - dann
 *     entscheidet der Betrieb selbst.
 */
class ExpireUnpaidReservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Notbremse pro Lauf. Der Job läuft alle fünf Minuten; wer mehr offene
     * Zahlungen hat, hat ein anderes Problem als diese Obergrenze.
     */
    private const MAX_PER_RUN = 500;

    /**
     * Wie lange ein begonnener Bezahlvorgang die Buchung über die Frist hinaus
     * schützt. Ein Checkout bei Stripe oder PayPal dauert selten länger als
     * ein paar Minuten.
     */
    private const CHECKOUT_GRACE_MINUTES = 15;

    /**
     * Mindestabstand zwischen Zahlungsaufforderung und Erinnerung.
     */
    private const MIN_REMINDER_GAP_MINUTES = 10;

    public function handle(ReservationLifecycleService $lifecycle): void
    {
        $this->remind($lifecycle);
        $this->expire($lifecycle);
    }

    /**
     * Erinnerung zur Halbzeit der Frist. Bei den üblichen 60 Minuten ist das
     * nach 30 - früh genug, dass der Gast noch reagieren kann, spät genug,
     * dass es keine Dopplung zur Buchungsmail ist.
     */
    private function remind(ReservationLifecycleService $lifecycle): void
    {
        $offen = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::PaymentPending->value)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '>', now())
            ->whereNotNull('guest_email_snapshot')
            // Schon erinnerte Buchungen gehören nicht mehr in die Auswahl –
            // sonst belegen sie das Fenster bis zum Fristende und spätere
            // Buchungen bekämen nie eine Erinnerung.
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('notification_logs')
                    ->whereColumn('notification_logs.reservation_id', 'reservations.id')
                    ->where('notification_logs.template_key', 'payment_reminder');
            })
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($offen as $reservation) {
            // Die Frist DIESER Buchung, nicht die der Regel von heute: Wer die
            // Regel zwischenzeitlich von 60 auf 15 Minuten stellt, würde sonst
            // rückwirkend die Halbzeit aller offenen Buchungen verschieben.
            $frist = (int) $reservation->created_at->diffInMinutes($reservation->payment_due_at);
            $halbzeit = $reservation->payment_due_at->copy()->subMinutes(intdiv(max($frist, 2), 2));

            if ($halbzeit->isFuture()) {
                continue;
            }

            // Bei sehr kurzen Fristen läge die Halbzeit fast auf der
            // Buchungsmail. Eine Erinnerung, die der Aufforderung auf dem Fuß
            // folgt, liest sich wie ein Fehler.
            if ($reservation->created_at->addMinutes(self::MIN_REMINDER_GAP_MINUTES)->isFuture()) {
                continue;
            }

            $lifecycle->sendGuestMail($reservation, 'payment_reminder');
        }
    }

    /**
     * Frist abgelaufen: Tisch freigeben, sofern die Regel das vorsieht.
     */
    private function expire(ReservationLifecycleService $lifecycle): void
    {
        $abgelaufen = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::PaymentPending->value)
            ->whereNotNull('payment_due_at')
            ->where('payment_due_at', '<=', now())
            // Wichtig: Buchungen, die hier nie stornierbar sind, gehören GAR
            // NICHT in die Ergebnismenge. Würde man sie laden und in PHP
            // überspringen, stünden sie beim nächsten Lauf wieder da – und weil
            // sie die ältesten sind, sortiert orderBy('id') sie nach vorne.
            // Ab MAX_PER_RUN solcher Dauergäste käme keine stornierbare Buchung
            // je wieder an die Reihe.
            ->where(function ($q) {
                $q->whereNull('deposit_rule_id')
                    ->orWhereIn('deposit_rule_id', DepositRule::withoutGlobalScopes()
                        ->where('cancel_unpaid_automatically', true)
                        ->select('id'));
            })
            // Dasselbe für die Kulanz: Ein laufender Bezahlvorgang ist ein
            // vorübergehender Zustand, aber er darf das Fenster nicht belegen.
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('payment_intents')
                    ->whereColumn('payment_intents.reservation_id', 'reservations.id')
                    ->where('payment_intents.status', 'pending')
                    ->where('payment_intents.updated_at', '>', now()->subMinutes(self::CHECKOUT_GRACE_MINUTES));
            })
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($abgelaufen as $reservation) {
            // Zwischen dem Laden und hier kann die Zahlung eingegangen sein –
            // der Webhook setzt dann payment_status auf 'paid' und den Status
            // auf Confirmed. Ohne diese Prüfung würde der Lauf eine bezahlte,
            // bestätigte Reservierung wieder auf 'expired' schreiben.
            $reservation->refresh();

            if ($reservation->status !== ReservationStatus::PaymentPending) {
                continue;
            }

            DB::transaction(function () use ($reservation, $lifecycle) {
                $reservation->update(['payment_status' => 'expired']);

                $lifecycle->transition(
                    $reservation,
                    ReservationStatus::Expired,
                    null,
                    'system',
                    'payment_deadline_exceeded',
                );
            });

            $frisch = $reservation->refresh();

            // Nach dem Termin wäre die Nachricht nur noch Rauschen.
            if ($frisch->guest_email_snapshot && $frisch->start_at->isFuture()) {
                $lifecycle->sendGuestMail($frisch, 'payment_expired');
            }
        }
    }
}
