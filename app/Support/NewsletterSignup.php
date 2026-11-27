<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;

/**
 * Die Angaben zur Newsletter-Anmeldung eines Betriebs an einer Stelle: der
 * Text neben dem Häkchen (Buchungsseite, Eventseite und Nachweis lesen
 * denselben) und die Felder, die eine neue Anmeldung im Newsletter-System
 * bekommt. Vorgaben stehen in config/swayy.php (newsletter).
 */
final class NewsletterSignup
{
    public const FORM_RESTAURANT = 'restaurant';

    public const FORM_SALON = 'salon';

    public const FORM_EVENT = 'event';

    /** Einstellbare Feldkürzel und Werte (tenants.newsletter_fields). */
    public const FIELD_KEYS = ['source_tag', 'source_value', 'event_tag', 'event_value'];

    /** Kürzel, die die Anmeldung selbst belegt. */
    public const RESERVED_TAGS = ['EMAIL', 'FNAME', 'LNAME'];

    public static function consentText(Tenant $tenant, string $form): string
    {
        $own = trim((string) $tenant->newsletter_consent_text);

        return $own !== '' ? $own : (string) config('swayy.newsletter.consent_text_defaults.'.$form);
    }

    /**
     * Die vier Werte des Betriebs, mit Vorgabe, wo er nichts gespeichert hat.
     * Ein gespeicherter leerer Wert bleibt leer: Ein leeres Kürzel schaltet
     * das Feld ab.
     *
     * @return array{source_tag: string, source_value: string, event_tag: string, event_value: string}
     */
    public static function fieldSettings(Tenant $tenant): array
    {
        $stored = (array) ($tenant->newsletter_fields ?? []);
        $values = [];
        foreach (self::FIELD_KEYS as $key) {
            $values[$key] = array_key_exists($key, $stored)
                ? (string) $stored[$key]
                : (string) config('swayy.newsletter.fields.'.$key);
        }

        return $values;
    }

    /**
     * Zusätzliche Felder einer neuen Anmeldung. Ohne eigenen Wert nennt das
     * Veranstaltungsfeld den Namen des Betriebs.
     *
     * @return array<string, string>
     */
    public static function extraFields(Tenant $tenant): array
    {
        $settings = self::fieldSettings($tenant);
        $fields = [];

        if ($settings['source_tag'] !== '' && $settings['source_value'] !== '') {
            $fields[$settings['source_tag']] = $settings['source_value'];
        }
        if ($settings['event_tag'] !== '') {
            $fields[$settings['event_tag']] = $settings['event_value'] !== '' ? $settings['event_value'] : (string) $tenant->name;
        }

        return $fields;
    }
}
