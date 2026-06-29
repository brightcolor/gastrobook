<?php

namespace App\Services;

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
        $template = $this->resolve($key, $reservation->tenant_id, $reservation->location_id, 'de');

        $placeholders = $this->placeholders($reservation, $extra);

        $subject = $template['subject'];
        $body = $template['body'];

        foreach ($placeholders as $name => $value) {
            $subject = str_replace('{'.$name.'}', (string) $value, $subject);
            $body = str_replace('{'.$name.'}', (string) $value, $body);
        }

        return ['subject' => $subject, 'body' => $body];
    }

    /**
     * @return array{subject: string, body: string}
     */
    public function resolve(string $key, int $tenantId, ?int $locationId, string $locale): array
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

        return $this->builtIn($key);
    }

    public function placeholders(Reservation $reservation, array $extra = []): array
    {
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $tenant = $location?->tenant;

        return array_merge([
            'guest_name' => $reservation->guest_name_snapshot,
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
        ];
    }

    /**
     * @return array{subject: string, body: string}
     */
    private function builtIn(string $key): array
    {
        $defaults = self::defaults();

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
    public static function defaults(): array
    {
        return [
            'reservation_confirmed' => [
                'subject' => 'Reservierungsbestätigung – {location_name} am {reservation_date}',
                'body' => "Hallo {guest_name},\n\nIhre Reservierung ist bestätigt:\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\nReservierungsnummer: {reservation_code}\n\nÄndern oder stornieren: {cancel_link}\n\nWir freuen uns auf Ihren Besuch!\n{location_name}",
            ],
            'reservation_requested' => [
                'subject' => 'Reservierungsanfrage erhalten – {location_name}',
                'body' => "Hallo {guest_name},\n\nwir haben Ihre Anfrage für den {reservation_date} um {reservation_time} Uhr ({party_size} Personen) erhalten und melden uns schnellstmöglich.\n\nReservierungsnummer: {reservation_code}\n\n{location_name}",
            ],
            'reservation_cancelled' => [
                'subject' => 'Reservierung storniert – {location_name}',
                'body' => "Hallo {guest_name},\n\nIhre Reservierung am {reservation_date} um {reservation_time} Uhr wurde storniert.\n\nReservierungsnummer: {reservation_code}\n\n{location_name}",
            ],
            'reservation_rejected' => [
                'subject' => 'Reservierungsanfrage – {location_name}',
                'body' => "Hallo {guest_name},\n\nleider können wir Ihre Anfrage für den {reservation_date} um {reservation_time} Uhr nicht bestätigen.\n\n{custom_message}\n\n{location_name}",
            ],
            'reservation_reminder' => [
                'subject' => 'Erinnerung: Ihre Reservierung morgen – {location_name}',
                'body' => "Hallo {guest_name},\n\nwir erinnern Sie an Ihre Reservierung:\n\nDatum: {reservation_date}\nUhrzeit: {reservation_time} Uhr\nPersonen: {party_size}\n\nÄndern oder stornieren: {cancel_link}\n\nBis bald!\n{location_name}",
            ],
            'feedback_request' => [
                'subject' => 'Wie war Ihr Besuch bei {location_name}?',
                'body' => "Hallo {guest_name},\n\nvielen Dank für Ihren Besuch! Wir würden uns über Ihr Feedback freuen:\n\n{feedback_link}\n\n{location_name}",
            ],
            'waitlist_offer' => [
                'subject' => 'Ein Tisch ist frei geworden – {location_name}',
                'body' => "Hallo {guest_name},\n\nfür Ihren Wartelisteneintrag ist ein Tisch frei geworden. Bitte bestätigen Sie zeitnah:\n\n{waitlist_link}\n\n{location_name}",
            ],
        ];
    }
}
