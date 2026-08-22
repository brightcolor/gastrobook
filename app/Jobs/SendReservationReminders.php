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
use Illuminate\Support\Facades\Log;

class SendReservationReminders implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Obergrenze aus SettingsController: reminder_hours_before => max:168. */
    private const MAX_LEAD_HOURS = 168;

    /**
     * Mindestabstand zwischen Buchung und Erinnerung.
     */
    private const MIN_GAP_MINUTES = 60;

    public function handle(ReservationLifecycleService $lifecycle, SmsManager $sms): void
    {
        $reservations = Reservation::withoutGlobalScopes()
            ->where('status', ReservationStatus::Confirmed->value)
            ->whereNull('reminder_sent_at')
            ->where('start_at', '>', now())
            // Weiter als die groesste erlaubte Vorwarnzeit kann nie erinnert
            // werden - solche Datensaetze alle 15 Minuten mitzuladen, kostet auf
            // einem Host ohne Swap echten Speicher.
            ->where('start_at', '<=', now()->addHours(self::MAX_LEAD_HOURS))
            ->with('location.settings', 'location.tenant')
            ->lazyById(200)
            ->filter(function (Reservation $r) {
                $settings = $r->location?->effectiveSettings();

                if (! $settings || ! $settings->reminder_enabled) {
                    return false;
                }

                // Mindestabstand zur Buchungsbestaetigung. Wer kurzfristig
                // bucht, liegt sofort innerhalb der Vorwarnzeit - die
                // Erinnerung ging dann fuenfzehn Minuten nach der Bestaetigung
                // raus und liest sich wie ein Fehler.
                if ($r->created_at->copy()->addMinutes(self::MIN_GAP_MINUTES)->isFuture()) {
                    return false;
                }

                return now()->gte($r->start_at->copy()->subHours($settings->reminder_hours_before));
            });

        // Cache one SMS provider per tenant to avoid repeated credential decryption
        $smsProviders = [];

        foreach ($reservations as $reservation) {
            // Erst beanspruchen, dann versenden. Stirbt der Lauf zwischen SMS
            // und Ausbuchen - der Worker schiesst nach 60 Sekunden ab -, bekam
            // der Gast beim naechsten Zustellversuch Mail UND SMS erneut, und
            // die SMS kostet den Betrieb bei jedem Versand echtes Geld.
            $beansprucht = Reservation::withoutGlobalScopes()
                ->whereKey($reservation->id)
                ->whereNull('reminder_sent_at')
                ->update(['reminder_sent_at' => now()]);

            if ($beansprucht === 0) {
                continue;
            }

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
        $du = $location->effectiveSettings()->du();
        $what = $isSalon
            ? ($du ? 'Dein Termin' : 'Ihr Termin')
            : ($du ? 'Deine Reservierung' : 'Ihre Reservierung');

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
                Log::warning('SMS reminder failed to send', [
                    'reservation_id' => $reservation->id,
                    'provider' => $provider::class,
                ]);
            }
        } catch (\Throwable $e) {
            $status = 'failed';
            Log::warning('SMS reminder threw an exception', [
                'reservation_id' => $reservation->id,
                'provider' => $provider::class,
                'error' => $e->getMessage(),
            ]);
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
