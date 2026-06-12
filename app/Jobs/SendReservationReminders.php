<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendReservationReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(ReservationLifecycleService $lifecycle): void
    {
        $reservations = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Confirmed->value)
            ->whereNull('reminder_sent_at')
            ->whereNotNull('guest_email_snapshot')
            ->where('start_at', '>', now())
            ->with('location.settings', 'location.tenant')
            ->get()
            ->filter(function (Reservation $r) {
                $settings = $r->location?->effectiveSettings();

                return $settings
                    && $settings->reminder_enabled
                    && now()->gte($r->start_at->copy()->subHours($settings->reminder_hours_before));
            });

        foreach ($reservations as $reservation) {
            $lifecycle->sendGuestMail($reservation, 'reservation_reminder');
            $reservation->update(['reminder_sent_at' => now()]);
        }
    }
}
