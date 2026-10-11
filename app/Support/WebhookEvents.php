<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Die Ereignisse, die Webhooks melden, und ihre Bezeichnungen. Die Seite
 * „Webhooks“ (WebhookController), die REST-API und das Protokoll (AuditLog)
 * lesen dieselbe Liste.
 */
final class WebhookEvents
{
    /** Events the application actually emits (see WebhookDispatchService callers). */
    public const EVENTS = [
        'reservation.created',
        'reservation.confirmed',
        'reservation.updated',
        'reservation.cancelled',
        'reservation.seated',
        'reservation.completed',
        'reservation.no_show',
        'waitlist.created',
        'waitlist.offered',
        'waitlist.accepted',
        'event.booking_created',
        'payment.succeeded',
        'feedback.received',
    ];

    /** Bezeichnungen der Ereignisse für Restaurants; die Seite zeigt den technischen Namen als Code daneben. */
    public const LABELS = [
        'reservation.created' => 'Neue Reservierung',
        'reservation.confirmed' => 'Reservierung bestätigt',
        'reservation.updated' => 'Reservierung geändert',
        'reservation.cancelled' => 'Reservierung storniert',
        'reservation.seated' => 'Gast am Tisch',
        'reservation.completed' => 'Besuch abgeschlossen',
        'reservation.no_show' => 'Gast nicht erschienen',
        'waitlist.created' => 'Gast auf der Warteliste',
        'waitlist.offered' => 'Platz aus der Warteliste angeboten',
        'waitlist.accepted' => 'Wartelistenplatz angenommen',
        'event.booking_created' => 'Neue Eventbuchung',
        'payment.succeeded' => 'Zahlung eingegangen',
        'feedback.received' => 'Feedback erhalten',
    ];

    /** Wortlaut für Salons, wo er vom Restaurant abweicht; alle anderen Bezeichnungen gelten für beide. */
    public const SALON_LABELS = [
        'reservation.created' => 'Neuer Termin',
        'reservation.confirmed' => 'Termin bestätigt',
        'reservation.updated' => 'Termin geändert',
        'reservation.cancelled' => 'Termin storniert',
        'reservation.seated' => 'Kunde angekommen',
        'reservation.completed' => 'Termin abgeschlossen',
        'reservation.no_show' => 'Kunde nicht erschienen',
        'waitlist.created' => 'Kunde auf der Warteliste',
        'waitlist.offered' => 'Termin aus der Warteliste angeboten',
        'waitlist.accepted' => 'Wartelistentermin angenommen',
    ];

    /** Wort für den Eintrag „*“ (alle Ereignisse). */
    public const ALL_LABEL = 'Alle Ereignisse';

    /**
     * Bezeichnungen der Ereignisse; Salons lesen „Termin“ und „Kunde“.
     *
     * @return array<string, string>
     */
    public static function labels(bool $salon): array
    {
        return $salon ? array_merge(self::LABELS, self::SALON_LABELS) : self::LABELS;
    }
}
