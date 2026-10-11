<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Die eine Regel für jedes neue Passwort: Mindestlänge aus
 * swayy.auth.password_min_length, dazu Buchstaben und Ziffern. Registrierung,
 * Einladung, Zurücksetzen, „Mein Konto“, Plattform-Benutzer und der Befehl
 * swayy:create-admin lesen sie hier; ihre Seiten nennen dieselbe Länge.
 */
final class PasswordPolicy
{
    public static function minLength(): int
    {
        return (int) config('swayy.auth.password_min_length');
    }

    public static function rule(): Password
    {
        return Password::min(self::minLength())->letters()->numbers();
    }

    /** Hilfesatz unter einem Passwortfeld. */
    public static function hint(): string
    {
        return __('Mindestens :min Zeichen, mit Buchstaben und Ziffern.', ['min' => self::minLength()]);
    }
}
