<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Die Rechte eines API-Schlüssels. Die Kennungen sind Teil der Schnittstelle
 * (resources/api/openapi.yaml); Bezeichnung und Satz sind für Menschen. Die
 * Seite „API-Schlüssel“ (ApiTokenController) und das Protokoll (AuditLog)
 * lesen dieselbe Liste. Salons lesen „Termine“ und „Kunden“.
 */
final class ApiScopes
{
    /**
     * @return array<string, array{label: string, help: string}>
     */
    public static function all(bool $salon): array
    {
        $bookings = $salon ? 'Termine' : 'Reservierungen';
        $guests = $salon ? 'Kunden' : 'Gäste';

        return [
            'reservations:read' => ['label' => $bookings.' lesen', 'help' => "Das Programm sieht {$bookings} mit Zeit, Status und Kontaktdaten."],
            'reservations:write' => ['label' => $bookings.' anlegen und ändern', 'help' => "Das Programm legt neue {$bookings} an und ändert bestehende, etwa durch eine Stornierung."],
            'guests:read' => ['label' => $guests.' lesen', 'help' => "Das Programm sieht die Profile deiner {$guests}."],
            'guests:write' => ['label' => $guests.' anlegen und ändern', 'help' => "Das Programm legt {$guests} an und ändert ihre Profile."],
            'availability:read' => ['label' => 'Freie Zeiten abfragen', 'help' => 'Das Programm fragt ab, wann noch etwas frei ist, etwa für eine Buchungsseite auf deiner Website.'],
            'waitlist:write' => ['label' => 'Warteliste befüllen', 'help' => "Das Programm trägt {$guests} in die Warteliste ein."],
            'events:read' => ['label' => 'Events lesen', 'help' => 'Das Programm sieht deine Events und ihre Buchungen.'],
            'events:write' => ['label' => 'Events anlegen und ändern', 'help' => 'Das Programm legt Events an und ändert sie.'],
            'webhooks:manage' => ['label' => 'Webhooks verwalten', 'help' => 'Das Programm richtet Webhooks ein und löscht sie wieder.'],
            'reports:read' => ['label' => 'Berichte lesen', 'help' => 'Das Programm sieht die Zahlen deiner Berichte.'],
        ];
    }

    /**
     * Nur die Bezeichnungen: Kennung => Bezeichnung.
     *
     * @return array<string, string>
     */
    public static function labels(bool $salon): array
    {
        return array_map(fn (array $scope): string => $scope['label'], self::all($salon));
    }
}
