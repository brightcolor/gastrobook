<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Models\GuestAuthToken;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Services\GuestAuthService;
use App\Services\Mail\MailDeliveryLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Erinnert Gäste an einen Bestätigungslink, den sie noch nicht angeklickt
 * haben, kurz bevor er abläuft (Vorlauf `swayy.guest_auth.verify_reminder_minutes`,
 * Vorgabe 6 Stunden). Nur der Gast bekommt sie; der Betrieb hört von der
 * Buchung erst nach dem Klick.
 *
 * Genau eine Erinnerung je Buchung: Unter Sperre der Reservierung wird
 * geprüft, ob schon ein Eintrag `verify_reminder` im Versandprotokoll steht.
 * Der Eintrag läuft über MailDeliveryLog, damit der Admin den Zustellstatus
 * der Erinnerung sieht wie bei jeder anderen Mail.
 */
class RemindUnconfirmedReservations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const TEMPLATE_KEY = 'verify_reminder';

    /** Notbremse pro Lauf, wie beim Aufräumen der unbestätigten Buchungen. */
    private const MAX_PER_RUN = 500;

    /** Die Zustände, in denen eine Buchung auf den Klick warten kann. */
    private const WAITING = [
        ReservationStatus::PendingConfirmation,
        ReservationStatus::Requested,
        ReservationStatus::PaymentPending,
    ];

    public function handle(GuestAuthService $auth, MailDeliveryLog $delivery): void
    {
        $lead = (int) config('swayy.guest_auth.verify_reminder_minutes', 360);
        if ($lead <= 0) {
            return;
        }

        $tokens = GuestAuthToken::withoutGlobalScopes()
            ->where('purpose', 'verify')
            ->whereNotNull('reservation_id')
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addMinutes($lead))
            ->whereNotIn('reservation_id', NotificationLog::withoutGlobalScopes()
                ->where('template_key', self::TEMPLATE_KEY)
                ->whereNotNull('reservation_id')
                ->select('reservation_id'))
            ->orderBy('expires_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        foreach ($tokens as $token) {
            // Ein Link, der kürzer gilt als der Vorlauf, ginge sonst direkt
            // nach der Buchung schon als Erinnerung hinterher.
            if ($token->created_at !== null
                && $token->created_at->diffInMinutes($token->expires_at) <= $lead) {
                continue;
            }

            $pending = DB::transaction(function () use ($token, $auth, $delivery) {
                $gesperrt = Reservation::withoutGlobalScopes()->lockForUpdate()->find($token->reservation_id);

                if ($gesperrt === null
                    || ! $gesperrt->guest_email_snapshot
                    || ! in_array($gesperrt->status, self::WAITING, true)
                    || in_array($gesperrt->payment_status, Reservation::SETTLED_PAYMENT_STATUSES, true)
                    || NotificationLog::withoutGlobalScopes()
                        ->where('reservation_id', $gesperrt->id)
                        ->where('template_key', self::TEMPLATE_KEY)
                        ->exists()) {
                    return null;
                }

                // Der Eintrag im Versandprotokoll entsteht unter der Sperre
                // und ist zugleich die Marke für „genau einmal“.
                $mail = $auth->verificationReminder($token, $gesperrt);
                $log = $delivery->open($mail, [
                    'tenant_id' => $gesperrt->tenant_id,
                    'location_id' => $gesperrt->location_id,
                    'reservation_id' => $gesperrt->id,
                    'template_key' => self::TEMPLATE_KEY,
                    'recipient' => $gesperrt->guest_email_snapshot,
                    'subject' => $mail->subjectLine,
                ]);

                return [$gesperrt, $mail, $log];
            });

            if ($pending === null) {
                continue;
            }

            [$reservation, $mail, $log] = $pending;
            try {
                Mail::to($reservation->guest_email_snapshot, $reservation->guest_name_snapshot)->queue($mail);
            } catch (Throwable $e) {
                report($e);
                $delivery->markFailed($log->id, $e);
            }
        }
    }
}
