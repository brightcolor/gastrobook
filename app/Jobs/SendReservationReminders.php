<?php

namespace App\Jobs;

use App\Enums\ReservationStatus;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use App\Services\Sms\SmsManager;
use App\Services\Sms\SmsProvider;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class SendReservationReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(ReservationLifecycleService $lifecycle, SmsManager $sms): void
    {
        $reservations = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Confirmed->value)
            ->whereNull('reminder_sent_at')
            ->where('start_at', '>', now())
            ->with('location.settings', 'location.tenant')
            ->get()
            ->filter(function (Reservation $r) {
                $settings = $r->location?->effectiveSettings();

                return $settings
                    && $settings->reminder_enabled
                    && now()->gte($r->start_at->copy()->subHours($settings->reminder_hours_before));
            });

        // Cache one SMS provider per tenant to avoid repeated credential decryption
        $smsProviders = [];

        foreach ($reservations as $reservation) {
            $settings = $reservation->location->effectiveSettings();

            // Mail reminder
            if ($reservation->guest_email_snapshot) {
                $lifecycle->sendGuestMail($reservation, 'reservation_reminder');
            }

            // SMS reminder (opt-in per location, requires configured provider + phone)
            if ($settings->sms_reminder_enabled && $reservation->guest_phone_snapshot) {
                $tenant = $reservation->location->tenant;
                if ($tenant !== null) {
                    if (! array_key_exists($tenant->id, $smsProviders)) {
                        $smsProviders[$tenant->id] = $sms->providerFor($tenant);
                    }
                    $provider = $smsProviders[$tenant->id];
                    if ($provider instanceof SmsProvider) {
                        $this->sendSms($sms, $provider, $reservation);
                    }
                }
            }

            $reservation->update(['reminder_sent_at' => now()]);
        }
    }

    private function sendSms(SmsManager $sms, SmsProvider $provider, Reservation $reservation): void
    {
        $to = $sms->normalizePhone($reservation->guest_phone_snapshot);
        if ($to === null) {
            return;
        }

        $location = $reservation->location;
        $localStart = $reservation->localStart();
        $isSalon = $location->tenant?->isSalon() ?? false;
        $what = $isSalon ? 'Ihr Termin' : 'Ihre Reservierung';

        $text = sprintf(
            'Erinnerung: %s bei %s am %s um %s Uhr. Bis bald!',
            $what,
            $location->name,
            $localStart->format('d.m.Y'),
            $localStart->format('H:i'),
        );

        $status = 'sent';
        try {
            if (! $provider->send($to, $text)) {
                $status = 'failed';
            }
        } catch (\Throwable) {
            $status = 'failed';
        }

        NotificationLog::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id,
            'location_id' => $reservation->location_id,
            'reservation_id' => $reservation->id,
            'channel' => 'sms',
            'template_key' => 'reservation_reminder',
            'recipient' => $to,
            'status' => $status,
        ]);
    }
}
