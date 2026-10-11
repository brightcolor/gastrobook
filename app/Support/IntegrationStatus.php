<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Die Wörter für den Zustand einer Anbindung (SMS, Newsletter, Stripe,
 * PayPal). Die Seiten der Anbindungen und das Protokoll (AuditLog) lesen
 * dieselben Wörter.
 */
final class IntegrationStatus
{
    /** Zustand => Wort. Der Zustand steht in integration_connections.status. */
    public const LABELS = [
        'connected' => 'Verbunden',
        'disconnected' => 'Ausgeschaltet',
        'error' => 'Fehler beim letzten Test',
    ];

    /** Wort für eine Anbindung, die es noch nicht gibt. */
    public const NOT_SET_UP = 'Nicht eingerichtet';

    /** Wort für den Zustand; ohne bekannten Zustand gilt die Anbindung als nicht eingerichtet. */
    public static function label(?string $status): string
    {
        return self::LABELS[$status ?? ''] ?? self::NOT_SET_UP;
    }
}
