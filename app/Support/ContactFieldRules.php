<?php

namespace App\Support;

/**
 * Längengrenzen für E-Mail-Adressen und Telefonnummern in Formularen.
 *
 * Eine Stelle für alle Felder, damit Buchung, Reservierungsbuch,
 * Schnittstelle und Anmeldung dieselben Grenzen ziehen. Die Werte kommen aus
 * swayy.contact_fields (SWAYY_CONTACT_EMAIL_MAX, SWAYY_CONTACT_PHONE_MAX).
 * Ohne Grenze lief eine überlange Adresse bis in die Datenbank, und
 * PostgreSQL lehnte sie dort mit einem Fehler 500 ab.
 */
final class ContactFieldRules
{
    public static function emailMaxLength(): int
    {
        return (int) config('swayy.contact_fields.email_max');
    }

    public static function phoneMaxLength(): int
    {
        return (int) config('swayy.contact_fields.phone_max');
    }

    /**
     * Die Regel „max:N“ für E-Mail-Felder.
     */
    public static function emailMax(): string
    {
        return 'max:'.self::emailMaxLength();
    }

    /**
     * Die Regel „max:N“ für Telefonfelder.
     */
    public static function phoneMax(): string
    {
        return 'max:'.self::phoneMaxLength();
    }
}
