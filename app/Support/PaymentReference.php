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
        return mb_substr(
            self::PREFIX.':'.$tenantSlug.':'.$type.':'.$code,
            0,
            // PayPal erlaubt 127 Zeichen in custom_id; Stripe-Metadatenwerte
            // dürfen 500 lang sein. Die kleinere Grenze gilt für beide.
            127
        );
    }

    /**
     * Rechnungsnummer: `SWAYY-42`.
     *
     * PayPal erzwingt die Eindeutigkeit und lehnt eine zweite abgeschlossene
     * Zahlung mit derselben Nummer ab – ein Nebeneffekt, der doppelte
     * Anzahlungen verhindert. Falls PayPal deshalb einen laufenden Vorgang
     * blockiert, weicht PayPalProvider auf eine Order ohne Rechnungsnummer aus.
     */
    public static function invoice(PaymentIntent $intent): string
    {
        return 'SWAYY-'.$intent->id;
    }

    /**
     * Was auf dem Kontoauszug des Gastes erscheinen soll.
     *
     * Höchstens 22 Zeichen, und die Anbieter akzeptieren nur einen engen
     * Zeichenvorrat. Ein Gast, der „Sternenwald Wismar" auf der Abrechnung
     * liest, erkennt die Buchung wieder und meldet sie nicht als unbekannt.
     */
    public static function statementName(string $name): string
    {
        $sauber = preg_replace('/[^A-Za-z0-9 .-]/', ' ', Str::ascii($name)) ?? '';
        $sauber = trim((string) preg_replace('/\s+/', ' ', $sauber));

        return mb_substr($sauber, 0, 22) ?: 'Reservierung';
    }
}
