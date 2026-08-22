<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\PaymentIntent;
use Illuminate\Support\Str;

/**
 * Kennungen, die mit jeder Zahlung an den Anbieter gehen.
 *
 * Zweck: Ein PayPal- oder Stripe-Konto wird selten nur für Swayy genutzt. Ohne
 * eine wiedererkennbare Kennung lässt sich im Nachhinein nicht sagen, welche
 * Zahlung aus dem Buchungssystem stammt und zu welcher Reservierung sie gehört
 * – die Zuordnung ginge nur von Swayy aus, nie von der Kontoseite her.
 *
 * Alle Anbieter bauen ihre Kennung hier, damit sie nicht auseinanderlaufen.
 */
final class PaymentReference
{
    /** Steht am Anfang jeder Kennung und ist das Filterkriterium. */
    public const PREFIX = 'swayy';

    /**
     * Frei durchsuchbare Kennung: `swayy:{mandant}:res:{code}`.
     *
     * Landet bei PayPal in `custom_id`, bei Stripe in den Metadaten. Für den
     * Gast unsichtbar, taucht aber in Transaktionsberichten und in der API auf.
     */
    public static function forBooking(string $tenantSlug, string $type, string $code): string
    {
        // PayPal erlaubt 127 Zeichen in custom_id, Stripe-Metadatenwerte 500 –
        // die kleinere Grenze gilt für beide. Gekürzt wird notfalls der
        // Mandantenname, nie der Buchungscode: Der ist der Teil, über den man
        // die Zahlung später wiederfindet.
        $schwanz = ':'.$type.':'.$code;
        $kopf = self::PREFIX.':'.$tenantSlug;
        $platz = 127 - mb_strlen($schwanz);

        return mb_substr($kopf, 0, max($platz, 0)).$schwanz;
    }

    /**
     * Rechnungsnummer: `SWAYY-42`.
     *
     * PayPal erzwingt die Eindeutigkeit und lehnt eine zweite Zahlung mit
     * derselben Nummer ab. Das ist kein Hindernis, sondern der Zweck: Eine
     * Anzahlung soll genau einmal fliessen. Meldet PayPal
     * `DUPLICATE_INVOICE_ID`, wirft PayPalProvider eine
     * PaymentAlreadySettledException – ein zweiter Anlauf ohne Rechnungsnummer
     * wuerde den Gast doppelt zahlen lassen.
     */
    public static function invoice(PaymentIntent $intent): string
    {
        return 'SWAYY-'.$intent->id;
    }

    /**
     * Was auf dem Kontoauszug des Gastes erscheinen soll.
     *
     * Höchstens 22 Zeichen, und die Anbieter akzeptieren nur einen engen
     * Zeichenvorrat. Ein Gast, der den Namen des Betriebs auf der Abrechnung
     * liest, erkennt die Buchung wieder und meldet sie nicht als unbekannt.
     */
    public static function statementName(string $name): string
    {
        $sauber = preg_replace('/[^A-Za-z0-9 .-]/', ' ', Str::ascii($name)) ?? '';
        $sauber = trim((string) preg_replace('/\s+/', ' ', $sauber));

        return rtrim(mb_substr($sauber, 0, 22)) ?: 'Reservierung';
    }
}
