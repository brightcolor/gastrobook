<?php

namespace App\Services;

use App\Mail\Layout\GuestText;
use App\Models\NotificationTemplate;
use App\Models\Reservation;

class NotificationTemplateRenderer
{
    /**
     * Resolve a template (location override → tenant default → built-in)
     * and render subject/body with placeholders.
     *
     * @return array{subject: string, body: string}
     */
    public function render(string $key, Reservation $reservation, array $extra = []): array
    {
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $address = $location?->effectiveSettings()->guest_address ?? 'Sie';
        $template = $this->resolve($key, $reservation->tenant_id, $reservation->location_id, 'de', $address);

        $placeholders = $this->placeholders($reservation, $extra);

        $subject = $template['subject'];
        $body = self::dropEmptyLines($template['body'], $placeholders);

        foreach ($placeholders as $name => $value) {
            $subject = str_replace('{'.$name.'}', (string) $value, $subject);
            $body = str_replace('{'.$name.'}', (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * Zeilen „Bezeichnung: {platzhalter}“, deren Platzhalter leer bleibt,
     * entfallen ganz – etwa „Jetzt bezahlen: {payment_link}“ ohne offene
     * Zahlung. Gilt auch für Vorlagen, die ein Betrieb selbst geschrieben hat.
     *
     * @param  array<string, mixed>  $placeholders
     */
    public static function dropEmptyLines(string $body, array $placeholders): string
    {
        $lines = preg_split('/\R/u', $body) ?: [];

        $kept = array_filter($lines, static function (string $line) use ($placeholders): bool {
            if (preg_match('/^\s*[^{}:\n]+:\s*\{(\w+)\}\s*$/u', $line, $m) !== 1) {
                return true;
            }

            return trim((string) ($placeholders[$m[1]] ?? '')) !== '';
        });

        return implode("\n", $kept);
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function resolve(string $key, int $tenantId, ?int $locationId, string $locale, string $address = 'Sie'): array
    {
        $template = NotificationTemplate::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('key', $key)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where(fn ($q) => $q->where('location_id', $locationId)->orWhereNull('location_id'))
            ->orderByRaw('location_id is null') // location-specific first
            ->first();

        if ($template !== null) {
            return ['subject' => $template->subject, 'body' => $template->body];
        }

        return $this->builtIn($key, $address);
    }

    public function placeholders(Reservation $reservation, array $extra = []): array
    {
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $tenant = $location?->tenant;

        return array_merge([
            'guest_name' => GuestText::inline($reservation->guest_name_snapshot),
            'reservation_date' => $reservation->localStart()->format('d.m.Y'),
            'reservation_time' => $reservation->localStart()->format('H:i'),
            'party_size' => $reservation->party_size,
            'location_name' => $location?->name ?? '',
            'tenant_name' => $tenant?->name ?? '',
            'table_name' => $reservation->tables->pluck('name')->implode(', '),
            'room_name' => $reservation->tables->first()?->room?->name ?? '',
            'reservation_code' => $reservation->code,
            'cancel_link' => route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]),
            'modify_link' => route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]),
            // Leer, wenn nichts zu zahlen ist: Die Adresse antwortet ohne
            // offene Zahlung mit 410, ein Link darauf in einer gewöhnlichen
            // Bestätigungsmail führt den Gast also ins Leere.
            'payment_link' => $reservation->payment_amount_minor > 0
                ? route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token])
                : '',
            'payment_amount' => $reservation->payment_amount_minor !== null
                ? number_format($reservation->payment_amount_minor / 100, 2, ',', '.').' '.($reservation->currency ?: 'EUR')
                : '',
            'payment_deadline' => $reservation->payment_due_at
                ? $reservation->payment_due_at->setTimezone($reservation->timezone ?: config('app.timezone'))->format('d.m.Y H:i')
                : '',
            'event_name' => '',
            'custom_message' => '',
        ], $extra);
    }

    /**
     * Placeholder names available in every template (for the editor hint list).
     *
     * @return list<string>
     */
    public static function placeholderHints(): array
    {
        return [
            'guest_name', 'reservation_date', 'reservation_time', 'party_size',
            'location_name', 'tenant_name', 'table_name', 'room_name',
            'reservation_code', 'cancel_link', 'modify_link', 'feedback_link',
            'waitlist_link', 'custom_message',
            'payment_link', 'payment_amount', 'payment_deadline',
        ];
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function builtIn(string $key, string $address = 'Sie'): array
    {
        $defaults = self::defaults($address);

        if ($address === 'du') {
            return $defaults[$key] ?? [
                'subject' => 'Deine Reservierung – {location_name}',
                'body' => "Hallo {guest_name},\n\n{custom_message}\n\n{location_name}",
            ];
        }

        return $defaults[$key] ?? [
            'subject' => 'Ihre Reservierung – {location_name}',
            'body' => "Hallo {guest_name},\n\n{custom_message}\n\n{location_name}",
        ];
    }

    /**
     * Built-in default templates, keyed by template key. Public so the admin
     * editor can show defaults and offer a reset.
     *
     * @return array<string, array{subject: string, body: string}>
     */
    public static function defaults(string $address = 'Sie'): array
    {
        if ($address === 'du') {
            return [
                'reservation_confirmed' => [
                    'subject' => 'Reservierungsbestätigung – {location_name} am {reservation_date}',
                    'body' => "Hallo {guest_name},\n\nwir haben deinen Tisch reserviert und freuen uns auf deinen Besuch.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
                ],
                'reservation_requested' => [
                    'subject' => 'Deine Anfrage ist angekommen – {location_name}',
                    'body' => "Hallo {guest_name},\n\nschön, dass du zu uns möchtest. Wir schauen uns jede Anfrage persönlich an und schreiben dir, sobald alles feststeht. Mit unserer Zusage ist dein Platz fest für dich reserviert.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nAnfrage ansehen oder zurückziehen: {cancel_link}\n\nHerzliche Grüße\n{location_name}",
                ],
                'reservation_cancelled' => [
                    'subject' => 'Reservierung storniert – {location_name}',
                    'body' => "Hallo {guest_name},\n\ndiese Reservierung gilt nicht mehr:\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nReservierungsnummer: {reservation_code}\n\nWir freuen uns, wenn du ein andermal vorbeikommst.\n{location_name}",
                ],
                'reservation_rejected' => [
                    'subject' => 'Reservierungsanfrage – {location_name}',
                    'body' => "Hallo {guest_name},\n\nfür den {reservation_date} um {reservation_time} Uhr können wir dir leider keinen Tisch zusagen.\n\n{custom_message}\n\nWir freuen uns, wenn du es zu einem anderen Termin versuchst.\n{location_name}",
                ],
                'reservation_reminder' => [
                    'subject' => 'Erinnerung: deine Reservierung morgen – {location_name}',
                    'body' => "Hallo {guest_name},\n\nbald ist es so weit, wir freuen uns auf dich.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
                ],
                'feedback_request' => [
                    'subject' => 'Wie war dein Besuch bei {location_name}?',
                    'body' => "Hallo {guest_name},\n\ndanke für deinen Besuch. Erzähl uns, was dir gefallen hat und was wir besser machen können.\n\nFeedback geben: {feedback_link}\n\n{location_name}",
                ],
                'waitlist_offer' => [
                    'subject' => 'Ein Tisch ist frei geworden – {location_name}',
                    'body' => "Hallo {guest_name},\n\nfür deinen Wartelisteneintrag haben wir jetzt Platz. Bestätige bitte zeitnah, dann halten wir den Tisch für dich.\n\nTisch bestätigen: {waitlist_link}\n\n{location_name}",
                ],
                'payment_pending' => [
                    'subject' => 'Bitte Anzahlung leisten – {location_name} am {reservation_date}',
                    'body' => "Hallo {guest_name},\n\nwir haben deinen Tisch vorgemerkt. Verbindlich wird die Reservierung mit einer Anzahlung von {payment_amount}. Wir verrechnen sie bei deinem Besuch vollständig mit der Rechnung; bei Nichterscheinen behalten wir sie ein.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\nZahlen bis: {payment_deadline} Uhr\n\nJetzt bezahlen: {payment_link}\n\nNach der Frist geben wir den Tisch wieder frei.\n{location_name}",
                ],
                'payment_reminder' => [
                    'subject' => 'Erinnerung: Anzahlung offen – {location_name}',
                    'body' => "Hallo {guest_name},\n\nwir halten deinen Tisch noch bis {payment_deadline} Uhr für dich frei.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nAnzahlung: {payment_amount}\n\nJetzt bezahlen: {payment_link}\n\n{location_name}",
                ],
                'payment_expired' => [
                    'subject' => 'Reservierung verfallen – {location_name}',
                    'body' => "Hallo {guest_name},\n\ndie Anzahlung für den {reservation_date} um {reservation_time} Uhr ist nicht rechtzeitig eingegangen, deshalb haben wir den Tisch wieder freigegeben.\n\nBuch gern jederzeit neu, wir freuen uns auf dich.\n{location_name}",
                ],
            ];
        }

        return [
            'reservation_confirmed' => [
                'subject' => 'Reservierungsbestätigung – {location_name} am {reservation_date}',
                'body' => "Hallo {guest_name},\n\nwir haben Ihren Tisch reserviert und freuen uns auf Ihren Besuch.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
            ],
            'reservation_requested' => [
                'subject' => 'Ihre Anfrage ist angekommen – {location_name}',
                'body' => "Hallo {guest_name},\n\nschön, dass Sie zu uns möchten. Wir schauen uns jede Anfrage persönlich an und schreiben Ihnen, sobald alles feststeht. Mit unserer Zusage ist Ihr Platz fest für Sie reserviert.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nAnfrage ansehen oder zurückziehen: {cancel_link}\n\nHerzliche Grüße\n{location_name}",
            ],
            'reservation_cancelled' => [
                'subject' => 'Reservierung storniert – {location_name}',
                'body' => "Hallo {guest_name},\n\ndiese Reservierung gilt nicht mehr:\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nReservierungsnummer: {reservation_code}\n\nWir freuen uns, wenn Sie ein andermal vorbeikommen.\n{location_name}",
            ],
            'reservation_rejected' => [
                'subject' => 'Reservierungsanfrage – {location_name}',
                'body' => "Hallo {guest_name},\n\nfür den {reservation_date} um {reservation_time} Uhr können wir Ihnen leider keinen Tisch zusagen.\n\n{custom_message}\n\nWir freuen uns, wenn Sie es zu einem anderen Termin versuchen.\n{location_name}",
            ],
            'reservation_reminder' => [
                'subject' => 'Erinnerung: Ihre Reservierung morgen – {location_name}',
                'body' => "Hallo {guest_name},\n\nbald ist es so weit, wir freuen uns auf Sie.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\n\nReservierung ändern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
            ],
            'feedback_request' => [
                'subject' => 'Wie war Ihr Besuch bei {location_name}?',
                'body' => "Hallo {guest_name},\n\nvielen Dank für Ihren Besuch. Erzählen Sie uns, was Ihnen gefallen hat und was wir besser machen können.\n\nFeedback geben: {feedback_link}\n\n{location_name}",
            ],
            'waitlist_offer' => [
                'subject' => 'Ein Tisch ist frei geworden – {location_name}',
                'body' => "Hallo {guest_name},\n\nfür Ihren Wartelisteneintrag haben wir jetzt Platz. Bitte bestätigen Sie zeitnah, dann halten wir den Tisch für Sie.\n\nTisch bestätigen: {waitlist_link}\n\n{location_name}",
            ],
            'payment_pending' => [
                'subject' => 'Bitte Anzahlung leisten – {location_name} am {reservation_date}',
                'body' => "Hallo {guest_name},\n\nwir haben Ihren Tisch vorgemerkt. Verbindlich wird die Reservierung mit einer Anzahlung von {payment_amount}. Wir verrechnen sie bei Ihrem Besuch vollständig mit der Rechnung; bei Nichterscheinen behalten wir sie ein.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\nZahlen bis: {payment_deadline} Uhr\n\nJetzt bezahlen: {payment_link}\n\nNach der Frist geben wir den Tisch wieder frei.\n{location_name}",
            ],
            'payment_reminder' => [
                'subject' => 'Erinnerung: Anzahlung offen – {location_name}',
                'body' => "Hallo {guest_name},\n\nwir halten Ihren Tisch noch bis {payment_deadline} Uhr für Sie frei.\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nAnzahlung: {payment_amount}\n\nJetzt bezahlen: {payment_link}\n\n{location_name}",
            ],
            'payment_expired' => [
                'subject' => 'Reservierung verfallen – {location_name}',
                'body' => "Hallo {guest_name},\n\ndie Anzahlung für den {reservation_date} um {reservation_time} Uhr ist nicht rechtzeitig eingegangen, deshalb haben wir den Tisch wieder freigegeben.\n\nBuchen Sie gern jederzeit neu, wir freuen uns auf Sie.\n{location_name}",
            ],
        ];
    }
}
