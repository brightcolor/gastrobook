<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Location;
use Carbon\CarbonImmutable;

/**
 * Die Zeitgrenzen der Online-Buchung an einer Stelle: Mindestvorlauf,
 * Annahmeschluss für den laufenden Tag und Vorausbuchung.
 *
 * Vorher prüften Buchungsseite, Tischplan, Umbuchung durch den Gast und
 * Salonkalender Vorlauf und Vorausbuchung je für sich. Das Team bucht ohne
 * diese Grenzen; die Aufrufer fragen hier nur für Gäste.
 *
 * Der Annahmeschluss (location_settings.same_day_cutoff, „HH:MM“, leer = aus)
 * gilt für den Geschäftstag: Eine Uhrzeit nach Mitternacht, die noch im
 * Öffnungsfenster des Vortags liegt, gehört zum Vortag. Ab dem Annahmeschluss
 * eines Tages ist dieser Tag samt seiner Nacht online geschlossen.
 */
class OnlineBookingWindow
{
    public function __construct(private readonly TimeSlotService $timeSlots) {}

    /**
     * Warum ein Gast diesen Zeitpunkt online nicht buchen kann, oder null.
     *
     * @return 'lead_time'|'same_day_closed'|'too_far_ahead'|null
     */
    public function blockReason(Location $location, CarbonImmutable $startLocal, ?CarbonImmutable $nowLocal = null): ?string
    {
        $settings = $location->effectiveSettings();
        $now = $nowLocal ?? CarbonImmutable::now($location->timezone);

        if ($startLocal->lt($now->addMinutes((int) $settings->min_lead_minutes))) {
            return 'lead_time';
        }
        if ($this->closedForTheDay($location, $this->serviceDate($location, $startLocal), $now)) {
            return 'same_day_closed';
        }
        if ($startLocal->gt($now->addDays((int) $settings->max_advance_days))) {
            return 'too_far_ahead';
        }

        return null;
    }

    /**
     * Ist der Geschäftstag $serviceDate online schon geschlossen, weil sein
     * Annahmeschluss erreicht ist?
     */
    public function closedForTheDay(Location $location, CarbonImmutable $serviceDate, ?CarbonImmutable $nowLocal = null): bool
    {
        $cutoff = $this->cutoff($location);
        if ($cutoff === null) {
            return false;
        }

        [$hour, $minute] = array_map('intval', explode(':', $cutoff));
        $now = $nowLocal ?? CarbonImmutable::now($location->timezone);

        return $now->gte($serviceDate->setTimezone($location->timezone)->startOfDay()->setTime($hour, $minute));
    }

    /**
     * Der Annahmeschluss als „HH:MM“, null ohne Annahmeschluss.
     */
    public function cutoff(Location $location): ?string
    {
        $value = $location->effectiveSettings()->same_day_cutoff;

        return is_string($value) && preg_match('/^\d{2}:\d{2}$/', $value) === 1 ? $value : null;
    }

    /**
     * Der Geschäftstag eines Zeitpunkts: der Vortag, wenn der Zeitpunkt in
     * einem Öffnungsfenster des Vortags über Mitternacht liegt, sonst sein
     * Kalendertag.
     */
    public function serviceDate(Location $location, CarbonImmutable $startLocal): CarbonImmutable
    {
        $previous = $startLocal->startOfDay()->subDay();

        foreach ($this->timeSlots->windowsForDate($location, $previous) as $window) {
            if ($startLocal->gte($window['opens']) && $startLocal->lt($window['closes'])) {
                return $previous;
            }
        }

        return $startLocal->startOfDay();
    }

    /**
     * Die Meldung für Gäste, wenn der Annahmeschluss erreicht ist: mit der
     * Uhrzeit, der Einladung zum spontanen Vorbeikommen (Restaurant mit
     * eingeschaltetem „Walk-ins erfassen“), der Telefonnummer des Standorts
     * (falls gepflegt) und dem Weg zu einem anderen Tag. Anrede nach den
     * Einstellungen des Standorts.
     */
    public function closedMessage(Location $location): string
    {
        $settings = $location->effectiveSettings();
        $du = $settings->du();
        $phone = trim((string) $location->phone);
        // Der Salon hat keinen Walk-in-Schalter; die Spalte steht dort auf ihrer Vorgabe.
        $walkIns = $settings->walkins_enabled && ! $location->tenant?->isSalon();

        $sentences = [
            $du ? __('Schön, dass du heute kommen möchtest!') : __('Schön, dass Sie heute kommen möchten!'),
            __('Online buchen geht für heute nur bis :time Uhr.', ['time' => $this->cutoff($location) ?? '']),
        ];
        if ($walkIns) {
            $sentences[] = $du
                ? __('Komm aber gern einfach vorbei – vielleicht findet sich noch ein Plätzchen für dich, versprechen können wir es leider nicht.')
                : __('Kommen Sie aber gern einfach vorbei – vielleicht findet sich noch ein Plätzchen für Sie, versprechen können wir es leider nicht.');
        }
        if ($phone !== '') {
            // Nach der Einladung zum Vorbeikommen ist der Anruf der zweite Weg.
            $sentences[] = match (true) {
                $walkIns && $du => __('Du kannst uns auch anrufen: :phone.', ['phone' => $phone]),
                $walkIns => __('Sie können uns auch anrufen: :phone.', ['phone' => $phone]),
                $du => __('Ruf uns gern an: :phone.', ['phone' => $phone]),
                default => __('Rufen Sie uns gern an: :phone.', ['phone' => $phone]),
            };
        }
        $sentences[] = $du ? __('Oder such dir einen anderen Tag aus.') : __('Oder suchen Sie sich einen anderen Tag aus.');
        $sentences[] = $du ? __('Wir freuen uns auf dich!') : __('Wir freuen uns auf Sie!');

        return implode(' ', $sentences);
    }
}
