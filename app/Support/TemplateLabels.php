<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Die Mailvorlagen, die ein Betrieb ändern kann, und ihre Bezeichnungen. Die
 * Seite „Vorlagen“ (NotificationTemplateController) und das Protokoll
 * (AuditLog) lesen dieselben Wörter. Salons lesen „Termin“.
 */
final class TemplateLabels
{
    /** Bezeichnungen der Vorlagen, die ein Betrieb ändern kann. */
    public const LABELS = [
        'reservation_confirmed' => 'Reservierung bestätigt',
        'reservation_requested' => 'Anfrage eingegangen',
        'reservation_cancelled' => 'Reservierung storniert',
        'reservation_rejected' => 'Anfrage abgelehnt',
        'reservation_reminder' => 'Erinnerung',
        'feedback_request' => 'Feedback-Anfrage',
        'waitlist_offer' => 'Wartelisten-Angebot',
        'payment_pending' => 'Anzahlung angefordert',
        'payment_reminder' => 'Anzahlung: Erinnerung',
        'payment_expired' => 'Anzahlung: Frist abgelaufen',
    ];

    /** Bezeichnungen, die in einem Salon anders lauten. */
    public const SALON_LABELS = [
        'reservation_confirmed' => 'Termin bestätigt',
        'reservation_cancelled' => 'Termin storniert',
    ];

    /** Bezeichnung einer Vorlage; eine unbekannte Kennung bleibt, wie sie ist. */
    public static function label(string $key, bool $salon): string
    {
        return ($salon ? (self::SALON_LABELS[$key] ?? null) : null) ?? self::LABELS[$key] ?? $key;
    }
}
