<?php

declare(strict_types=1);

namespace App\Enums;

enum TenantType: string
{
    case Restaurant = 'restaurant';
    case Salon = 'salon';

    public function label(): string
    {
        return match ($this) {
            self::Restaurant => 'Restaurant / Gastronomie',
            self::Salon => 'Friseursalon / Dienstleister',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Restaurant => '🍽️',
            self::Salon => '✂️',
        };
    }

    public function bookingLabel(): string
    {
        return match ($this) {
            self::Restaurant => 'Tisch reservieren',
            self::Salon => 'Termin buchen',
        };
    }

    public function summary(): string
    {
        return match ($this) {
            self::Restaurant => 'Räume, Tische und Reservierungen',
            self::Salon => 'Termine je Mitarbeiter und Leistung',
        };
    }
}
