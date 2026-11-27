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
     * Die Meldung für Gäste, wenn der Annahmeschluss erreicht ist. Hat der
     * Standort einen eigenen Text, ersetzt er die ganze Meldung samt seinen
     * Absätzen. Der Standardtext lädt im Restaurant zum spontanen
     * Vorbeikommen ein (eingeschaltetes „Walk-ins erfassen“), nennt die
     * Telefonnummer des Standorts (falls gepflegt) und den Weg zu einem
     * anderen Tag. Anrede nach den Einstellungen des Standorts.
     */
    public function closedMessage(Location $location): string
    {
        $settings = $location->effectiveSettings();
        $own = trim((string) $settings->same_day_closed_text);
        if ($own !== '') {
            return $own;
        }

        $du = $settings->du();
        $phone = trim((string) $location->phone);
        $salon = (bool) $location->tenant?->isSalon();
        // Der Salon hat keinen Walk-in-Schalter; die Spalte steht dort auf ihrer Vorgabe.
        $walkIns = $settings->walkins_enabled && ! $salon;

        $first = [
            match (true) {
                $walkIns && $du => __('Wie schön, dass du heute vorbeikommen möchtest!'),
                $walkIns => __('Wie schön, dass Sie heute vorbeikommen möchten!'),
                $du => __('Wie schön, dass du zu uns kommen möchtest!'),
                default => __('Wie schön, dass Sie zu uns kommen möchten!'),
            },
            $salon
                ? __('Unsere Online-Buchung für heute ist bereits geschlossen.')
                : __('Unsere Online-Reservierungen für heute sind bereits geschlossen.'),
        ];
        if ($walkIns) {
            $first[] = $du
                ? __('Schau trotzdem gerne spontan bei uns vorbei – mit ein bisschen Glück haben wir noch ein gemütliches Plätzchen für dich frei.')
                : __('Schauen Sie trotzdem gerne spontan bei uns vorbei – mit ein bisschen Glück haben wir noch ein gemütliches Plätzchen für Sie frei.');
        }
        if ($phone !== '') {
            // Nach der Einladung zum Vorbeikommen ist der Anruf der zweite Weg.
            $first[] = match (true) {
                $walkIns && $du => __('Du erreichst uns auch unter :phone.', ['phone' => $phone]),
                $walkIns => __('Sie erreichen uns auch unter :phone.', ['phone' => $phone]),
                $du => __('Ruf uns gern an: :phone.', ['phone' => $phone]),
                default => __('Rufen Sie uns gern an: :phone.', ['phone' => $phone]),
            };
        }

        $otherDay = match (true) {
            $walkIns && $du => __('Wenn du dir einen Platz sichern möchtest, such dir gerne einen anderen Tag aus.'),
            $walkIns => __('Wenn Sie sich einen Platz sichern möchten, suchen Sie sich gerne einen anderen Tag aus.'),
            $du => __('Such dir gerne einen anderen Tag aus.'),
            default => __('Suchen Sie sich gerne einen anderen Tag aus.'),
        };

        return implode("\n\n", [implode(' ', $first), $otherDay, $du ? __('Wir freuen uns auf dich!') : __('Wir freuen uns auf Sie!')]);
    }
}
