<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Models\FeedbackRequest;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Tenant;
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
            // Standorte ohne Feedback gehoeren GAR NICHT in die Ergebnismenge.
            // In PHP herausgefiltert bekommen sie kein feedback_requested_at,
            // bleiben also im Rueckschaufenster stehen - und weil sie die
            // aeltesten sind, sortiert orderBy('id') sie nach vorne. Ein
            // einziger Betrieb mit abgeschalteter Funktion belegte damit das
            // ganze Fenster, und kein anderer bekam je eine Anfrage.
            ->whereIn('location_id', $this->activeLocationIds())
            ->with('location.settings')
            ->orderBy('id')
            ->limit(self::MAX_PER_RUN)
            ->get()
            ->filter(function (Reservation $r) {
                $settings = $r->location?->effectiveSettings();
                if (! $settings) {
                    return false;
                }
                $reference = $r->departed_at ?? $r->end_at;

                return now()->gte($reference->copy()->addHours($settings->feedback_hours_after));
            });

        foreach ($reservations as $reservation) {
            // Erst den Platz beanspruchen, dann verschicken. Andersherum
            // erzeugte ein Abbruch zwischen Versand und Ausbuchen beim
            // naechsten Zustellversuch eine zweite Anfrage mit eigenem Token
            // und eine zweite Bewertungsmail an denselben Gast.
            $beansprucht = Reservation::withoutGlobalScopes()
                ->whereKey($reservation->id)
                ->whereNull('feedback_requested_at')
                ->update(['feedback_requested_at' => now()]);

            if ($beansprucht === 0) {
                continue;
            }

            $request = FeedbackRequest::create([
                'tenant_id' => $reservation->tenant_id,
                'location_id' => $reservation->location_id,
                'reservation_id' => $reservation->id,
                'sent_at' => now(),
            ]);

            $lifecycle->sendGuestMail($reservation, 'feedback_request', [
                'feedback_link' => route('feedback.show', ['token' => $request->token]),
            ]);
        }
    }

    /**
     * Standorte, an denen Feedback ueberhaupt eingeschaltet ist - im Tarif und
     * in den Einstellungen.
     *
     * @return array<int, int>
     */
    private function activeLocationIds(): array
    {
        // Das Tarifmerkmal steckt in feature_overrides oder im Tarif - das
        // laesst sich nicht sinnvoll in SQL ausdruecken. Einmal laden und in
        // PHP filtern reicht: eine Abfrage, keine pro Standort.
        $tenantIds = Tenant::withoutGlobalScopes()
            ->with('plan')
            ->get()
            ->filter(fn (Tenant $tenant) => $tenant->hasFeature('feedback_enabled'))
            ->pluck('id');

        return Location::withoutGlobalScopes()
            ->whereIn('tenant_id', $tenantIds)
            // Ohne eigene Einstellungszeile gelten die Vorgaben, und dort ist
            // Feedback eingeschaltet.
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('location_settings')
                    ->whereColumn('location_settings.location_id', 'locations.id')
                    ->where('location_settings.feedback_enabled', false);
            })
            ->pluck('id')
            ->all();
    }
}
