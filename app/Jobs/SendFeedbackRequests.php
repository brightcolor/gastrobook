<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Models\FeedbackRequest;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendFeedbackRequests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Nur Besuche der letzten drei Wochen kommen ueberhaupt in Frage. */
    private const LOOKBACK_DAYS = 21;

    /** Notbremse je Lauf, damit ein Stau nicht in einem Schwung rausgeht. */
    private const MAX_PER_RUN = 1000;

    public function handle(ReservationLifecycleService $lifecycle): void
    {
        $reservations = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Completed->value)
            ->whereNull('feedback_requested_at')
            ->whereNotNull('guest_email_snapshot')
            // Nur der frische Nachlauf. Ohne diese Grenze verschickt das erste
            // Wiedereinschalten der Funktion den kompletten Altbestand an echte
            // Gaeste - und die stuendliche Abfrage laedt bis dahin jedes Mal
            // alles. 21 Tage: feedback_hours_after darf bis 336 Stunden gehen,
            // dazu eine Woche Luft fuer Ausfaelle von Scheduler oder Queue.
            ->where(fn ($q) => $q->where('departed_at', '>=', now()->subDays(self::LOOKBACK_DAYS))
                ->orWhere('end_at', '>=', now()->subDays(self::LOOKBACK_DAYS)))
            ->with('location.settings', 'location.tenant')
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get()
            ->filter(function (Reservation $r) {
                $settings = $r->location?->effectiveSettings();
                if (! $settings || ! $settings->feedback_enabled || ! $r->location->tenant->hasFeature('feedback_enabled')) {
                    return false;
                }
                $reference = $r->departed_at ?? $r->end_at;

                return now()->gte($reference->copy()->addHours($settings->feedback_hours_after));
            });

        foreach ($reservations as $reservation) {
            $request = FeedbackRequest::create([
                'tenant_id' => $reservation->tenant_id,
                'location_id' => $reservation->location_id,
                'reservation_id' => $reservation->id,
                'sent_at' => now(),
            ]);

            $lifecycle->sendGuestMail($reservation, 'feedback_request', [
                'feedback_link' => route('feedback.show', ['token' => $request->token]),
            ]);

            $reservation->update(['feedback_requested_at' => now()]);
        }
    }
}
