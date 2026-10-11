<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * No BelongsToTenant trait on purpose: audit logs are written for both
 * tenant-scoped and SaaS-level actions; reads are explicitly filtered.
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id', 'location_id', 'user_id', 'impersonator_id',
        'action', 'entity_type', 'entity_id',
        'old_values', 'new_values', 'metadata',
        'ip_address', 'user_agent', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Plain-language field names so non-technical staff understand what
     * changed. Unknown keys fall back to a title-cased version of the key.
     */
    private const FIELD_LABELS = [
        'status' => 'Status',
        'party_size' => 'Personenzahl',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'name' => 'Name',
        'email' => 'E-Mail',
        'phone' => 'Telefon',
        'note' => 'Notiz',
        'guest_note' => 'Gastnotiz',
        'internal_note' => 'Interne Notiz',
        'allergies' => 'Allergien',
        'allergy_note' => 'Allergien',
        'accessibility_notes' => 'Barrierefreiheit',
        'preferences' => 'Vorlieben',
        'is_vip' => 'VIP',
        'birthday' => 'Geburtstag',
        'occasion' => 'Anlass',
        'reservation_date' => 'Datum',
        'start_at' => 'Beginn',
        'end_at' => 'Ende',
        'duration_minutes' => 'Dauer (Min.)',
        'table_ids' => 'Tische',
        'staff_ids' => 'Personen',
        'service_ids' => 'Leistungen',
        'hours' => 'Arbeitszeiten',
        'starts_at' => 'Beginn',
        'ends_at' => 'Ende',
        'bio' => 'Kurzvorstellung',
        'tables' => 'Tische',
        'color' => 'Farbe',
        'min_capacity' => 'Min. Personen',
        'max_capacity' => 'Max. Personen',
        'price_minor' => 'Preis',
        'amount_minor' => 'Betrag',
        'payment_status' => 'Zahlungsstatus',
        'title' => 'Titel',
        'reason' => 'Grund',
        'role' => 'Rolle',
        'saas_role' => 'Plattform-Rolle',
        'plan_id' => 'Tarif',
        'guest_address' => 'Anrede',
        'timezone' => 'Zeitzone',
        // Standorte (LocationController)
        'address_line1' => 'Straße und Hausnummer',
        'postal_code' => 'PLZ',
        'city' => 'Ort',
        'public_intro' => 'Begrüßung',
        // Team & Rollen (UserManagementController)
        'all_locations' => 'Alle Standorte',
        'location_ids' => 'Standorte',
        'user_id' => 'Benutzer-Nr.',
        // Allgemein (GeneralController)
        'imprint_url' => 'Impressum',
        'privacy_url' => 'Datenschutzhinweise',
        'terms_url' => 'AGB',
        'brand_primary_color' => 'Markenfarbe',
        // Buchungsseite & Widget (BookingPageController)
        'confetti_on_booking' => 'Konfetti nach dem Buchen',
        'public_floorplan_enabled' => 'Tischplan zeigen',
        // Benachrichtigungen (NotificationsController); 'guest_address' steht oben
        'reminder_enabled' => 'E-Mail-Erinnerung',
        'reminder_hours_before' => 'Erinnerung: Stunden vorher',
        'sms_reminder_enabled' => 'SMS-Erinnerung',
        'feedback_enabled' => 'Feedback-Anfrage',
        'feedback_hours_after' => 'Feedback: Stunden nach dem Besuch',
        'feedback_redirect_min_score' => 'Ab Sternen zum Portal',
        'feedback_external_url' => 'Link zum Bewertungsportal',
        'owner_notification_enabled' => 'Info an den Betrieb',
        'owner_notification_email' => 'Adresse für die Info',
        // Absender & Antworten (SenderController)
        'mail_from_name' => 'Absendername',
        'mail_reply_to' => 'Antwortadresse',
        'mail_relay_enabled' => 'Antworten über Swayy',
        // SMS-Versand (SmsController)
        'sender_id' => 'Absenderkennung',
        // Zahlungsanbieter (PaymentProvidersController)
        'mode' => 'Modus',
        // Anzahlungen (DepositsController); 'name' steht oben
        'min_party_size' => 'Ab Personenzahl',
        'amount_per_person' => 'Betrag pro Person (€)',
        'flat_amount' => 'Grundbetrag (€)',
        'from_time' => 'Nur ab Uhrzeit',
        'until_time' => 'Nur bis Uhrzeit',
        'weekdays' => 'Wochentage',
        'payment_deadline_minutes' => 'Zahlungsfrist (Min.)',
        'cancel_unpaid_automatically' => 'Unbezahlte Buchungen automatisch stornieren',
        'service_id' => 'Leistung',
        'room_id' => 'Raum',
        'refund_mode' => 'Erstattung: Modus',
        'refund_percent' => 'Erstattung in %',
        'refund_processing' => 'Erstattung: Ausführung',
        // Newsletter (NewsletterController)
        'api_url' => 'API-URL',
        'list_uid' => 'Listen-UID',
        // API-Schlüssel (ApiTokenController) und Webhooks (WebhookController); 'name' steht oben
        'scopes' => 'Rechte',
        'owner_id' => 'Besitzer-Nr.',
        'url' => 'Adresse',
        'events' => 'Ereignisse',
        // Vorlagen (NotificationTemplateController)
        'key' => 'Vorlage',
        // 'type' auch bei Marketing-Kampagnen, deshalb allgemein
        'type' => 'Art',
    ];

    /**
     * Aktionen mit eigener Formulierung.
     *
     * Ansonsten gilt: Endung (created/updated/…) gibt die Taetigkeit, Praefix
     * den Gegenstand.
     *
     * Alles, was hier nicht steht, wird aus SUBJECTS und VERBS
     * zusammengesetzt. Faellt beides aus, entsteht eine englische Kruecke aus
     * dem Aktionsnamen ("Zahlung Amount Mismatch") - AuditLabelTest laesst das
     * nicht mehr durch.
     *
     * @var array<string, string>
     */
    public const ACTION_LABELS = [
        'reservation.status_changed' => 'Reservierung: Status geändert',
        'reservation.party_updated' => 'Reservierung: Personenzahl geändert',
        'reservation.rescheduled' => 'Reservierung umgebucht',
        'reservation.tags_updated' => 'Reservierung: Tags geändert',
        'auth.login' => 'Angemeldet',
        'guest.anonymized' => 'Gast anonymisiert',
        'guest.data_export' => 'Gastdaten exportiert',
        'support.impersonation_started' => 'Supportzugriff gestartet',
        'support.impersonation_ended' => 'Supportzugriff beendet',
        'tenant.trial_extended' => 'Testphase verlängert',
        'tenant.plan_changed' => 'Tarif geändert',
        'tenant.status_changed' => 'Betriebsstatus geändert',
        'tenant.type_changed' => 'Betriebsart geändert',
        'payment.succeeded' => 'Zahlung eingegangen',
        'payment.checkout_started' => 'Bezahlvorgang gestartet',
        'payment.forfeited' => 'Anzahlung einbehalten (No-Show)',
        // Ohne diese Zeilen stand im Protokoll "Zahlung Amount Mismatch" -
        // der Rueckfall setzt aus dem Aktionsnamen zusammen, und der ist
        // englisch. Gerade die Geldwege liest jemand, der wissen will, was
        // schiefging.
        'payment.amount_mismatch' => 'Zahlung mit falschem Betrag',
        'payment.late_on_inactive_reservation' => 'Zahlung auf ungültige Buchung',
        'payment.confirm_failed' => 'Zahlung verbucht, Bestätigung fehlgeschlagen',
        'payment.awaiting_settlement' => 'Zahlung noch nicht gutgeschrieben',
        'payment.async_failed' => 'Verzögerte Zahlung gescheitert',
        'payment.async_failed_after_settlement' => 'Zahlung gescheitert, obwohl verbucht',
        'payment.already_settled_at_provider' => 'Beim Anbieter bereits bezahlt',
        'payment.return_not_yet_paid' => 'Rücksprung ohne Zahlungseingang',
        'payment.stale_session_expired' => 'Alte Bezahlsitzung abgelaufen',
        'payment.unknown_return_session' => 'Rücksprung mit unbekannter Sitzung',
        'payment.webhook_received' => 'Meldung des Zahlungsanbieters',
        'user.locations_changed' => 'Standortfreigabe geändert',
        'user.role_changed' => 'Rolle geändert',
        'user.self_deleted' => 'Konto selbst gelöscht',
        'account.exported' => 'Alle Daten exportiert',
        'account.password_changed' => 'Passwort geändert',
        'billing.directdebit.webhook' => 'Meldung des Lastschriftanbieters',
        'billing.request_created' => 'Abrechnungsdaten übermittelt',
        'event.attendees_exported' => 'Teilnehmerliste exportiert',
        'event.booking_cancelled' => 'Eventbuchung storniert',
        'event.booking_checked_in' => 'Eventgast eingecheckt',
        'event.booking_created' => 'Eventbuchung angelegt',
        'event.status_changed' => 'Event: Status geändert',
        'guest.marketing_unsubscribed' => 'Newsletter abbestellt',
        'guest.merged' => 'Gäste zusammengeführt',
        'integration.mailwizz_updated' => 'Newsletter-Anbindung geändert',
        'integration.paypal_updated' => 'PayPal-Anbindung geändert',
        'integration.sms_updated' => 'SMS-Anbindung geändert',
        'integration.stripe_updated' => 'Stripe-Anbindung geändert',
        'location.field_rules_updated' => 'Standort: Feldregeln geändert',
        'location.general_updated' => 'Standort: Stammdaten geändert',
        'tenant.general_updated' => 'Betrieb: Stammdaten geändert',
        'location.settings_updated' => 'Standort: Einstellungen geändert',
        'location.capacity_updated' => 'Standort: Kapazität und Dauer geändert',
        'location.booking_rules_updated' => 'Standort: Buchungsregeln geändert',
        'location.booking_page_updated' => 'Standort: Buchungsseite geändert',
        'location.notifications_updated' => 'Benachrichtigungen geändert',
        'location.refunds_updated' => 'Erstattung der Anzahlung geändert',
        'reservation.attachment_added' => 'Reservierung: Anhang hinzugefügt',
        'reservation.attachment_deleted' => 'Reservierung: Anhang gelöscht',
        'reservation.deposit_shortfall' => 'Reservierung: Anzahlung zu niedrig',
        'reservation.note_added' => 'Reservierung: Notiz hinzugefügt',
        'reservation.staff_assigned' => 'Reservierung: Person zugewiesen',
        'provisioning.create' => 'Kundenpanel: Betrieb unter Vertrag genommen',
        'provisioning.approve' => 'Kundenpanel: Betrieb freigegeben',
        'provisioning.suspend' => 'Kundenpanel: Betrieb gesperrt',
        'provisioning.unsuspend' => 'Kundenpanel: Sperre aufgehoben',
        'provisioning.cancel' => 'Kundenpanel: Vertrag gekündigt',
        'provisioning.uncancel' => 'Kundenpanel: Kündigung zurückgenommen',
        'provisioning.tariff' => 'Kundenpanel: Tarif gesetzt',
        'provisioning.delete' => 'Kundenpanel: Betrieb gelöscht',
        'saas.user_created' => 'Plattform-Benutzer angelegt',
        'saas.user_deleted' => 'Plattform-Benutzer gelöscht',
        'saas.user_role_changed' => 'Plattform-Rolle geändert',
        'staff_member.absence_created' => 'Abwesenheit eingetragen',
        'staff_member.absence_deleted' => 'Abwesenheit gelöscht',
        'staff_member.working_hours_updated' => 'Arbeitszeiten geändert',
        'tenant.branding_updated' => 'Erscheinungsbild geändert',
        'tenant.guest_mail_updated' => 'Absender für Gästemails geändert',
        'api_token.deleted' => 'API-Schlüssel widerrufen',
        'webhook.deactivated' => 'Webhook pausiert',
        'webhook.ping' => 'Webhook getestet',
        'webhook.secret_rotated' => 'Webhook-Geheimnis erneuert',
    ];

    /** @var array<string, string> Praefix der Aktion => Gegenstand */
    public const SUBJECTS = [
        'reservation' => 'Reservierung', 'guest' => 'Gast', 'guests' => 'Gäste',
        'table' => 'Tisch', 'table_combination' => 'Tischkombination', 'room' => 'Raum',
        'zone' => 'Zone', 'event' => 'Event', 'service' => 'Leistung',
        'staff_member' => 'Mitarbeiter', 'tag' => 'Tag', 'location' => 'Standort',
        'user' => 'Benutzer', 'refund' => 'Erstattung', 'deposit_rule' => 'Anzahlungsregel',
        'blackout' => 'Sperrzeit', 'special_hours' => 'Sonderöffnungszeit',
        'opening_hours' => 'Öffnungszeiten', 'template' => 'E-Mail-Vorlage',
        'api_token' => 'API-Schlüssel', 'webhook' => 'Webhook', 'tenant' => 'Betrieb',
        'floorplan' => 'Tischplan', 'billing' => 'Abrechnung', 'integration' => 'Integration',
        'waitlist' => 'Warteliste', 'payment' => 'Zahlung', 'saas' => 'Plattform-Benutzer',
        'marketing_campaign' => 'Marketing-Kampagne', 'table_block' => 'Tischsperre',
        'account' => 'Konto', 'season' => 'Saison',
    ];

    /** @var array<string, string> Endung der Aktion => Taetigkeit */
    public const VERBS = [
        'created' => 'angelegt', 'updated' => 'geändert', 'deleted' => 'gelöscht',
        'removed' => 'entfernt', 'added' => 'hinzugefügt', 'invited' => 'eingeladen',
        'exported' => 'exportiert', 'approved' => 'genehmigt', 'rejected' => 'abgelehnt',
        'completed' => 'abgeschlossen', 'requested' => 'angefragt', 'offered' => 'angeboten',
        'activated' => 'aktiviert', 'cancelled' => 'storniert', 'reset' => 'zurückgesetzt',
        'signed_up' => 'registriert', 'imported' => 'importiert',
    ];

    public function actionLabel(): string
    {
        return self::labelFor($this->action);
    }

    /**
     * Dieselbe Aufloesung ohne Datensatz - damit ein Test sie fuer jede
     * geloggte Aktion durchspielen kann.
     */
    public static function labelFor(string $action): string
    {
        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }

        [$prefix, $rest] = array_pad(explode('.', $action, 2), 2, '');
        $subject = self::SUBJECTS[$prefix] ?? Str::headline($prefix);
        $verbKey = $rest ? (string) Str::afterLast($rest, '.') : '';
        $verb = self::VERBS[$verbKey] ?? null;

        return $verb ? "{$subject} {$verb}" : trim($subject.' '.Str::headline(str_replace('.', ' ', (string) $rest)));
    }

    /**
     * Ergibt diese Aktion eine deutsche Bezeichnung - oder faellt sie auf die
     * englische Kruecke aus dem Aktionsnamen zurueck?
     */
    public static function hasGermanLabel(string $action): bool
    {
        if (isset(self::ACTION_LABELS[$action])) {
            return true;
        }

        [$prefix, $rest] = array_pad(explode('.', $action, 2), 2, '');

        return isset(self::SUBJECTS[$prefix])
            && isset(self::VERBS[$rest ? (string) Str::afterLast($rest, '.') : '']);
    }

    /**
     * Resolve old/new values into readable per-field diff rows with a plain
     * label and humanised values:
     * [['field' => 'status', 'label' => 'Status', 'from' => 'Bestätigt', 'to' => 'Am Tisch'], …].
     *
     * @return array<int, array{field: string, label: string, from: ?string, to: ?string}>
     */
    public function fieldChanges(): array
    {
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $rows = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $field = (string) $field;
            $from = array_key_exists($field, $old) ? self::formatValue($field, $old[$field]) : null;
            $to = array_key_exists($field, $new) ? self::formatValue($field, $new[$field]) : null;
            if ($from === $to) {
                continue;
            }
            $rows[] = [
                'field' => $field,
                'label' => self::FIELD_LABELS[$field] ?? Str::headline($field),
                'from' => $from,
                'to' => $to,
            ];
        }

        return $rows;
    }

    private static function formatValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }

        // Field-aware humanisation so staff see meaning, not raw codes.
        // The admin UI is German-only, so translate value codes explicitly in
        // German regardless of the configured APP_LOCALE.
        if ($field === 'status' && is_string($value) && trans()->has('reservations.status.'.$value, 'de')) {
            return __('reservations.status.'.$value, [], 'de');
        }
        if ($field === 'source' && is_string($value) && trans()->has('reservations.source.'.$value, 'de')) {
            return __('reservations.source.'.$value, [], 'de');
        }
        if (str_ends_with($field, '_minor') && is_numeric($value)) {
            return number_format(((float) $value) / 100, 2, ',', '.').' €';
        }
        if ($field === 'role' && is_string($value) && trans()->has('roles.'.$value.'.label', 'de')) {
            return __('roles.'.$value.'.label', [], 'de');
        }
        if ($field === 'guest_address' && is_string($value)) {
            return $value === 'du' ? 'Du' : 'Sie';
        }
        if (is_array($value)) {
            // Lists of ids/tables etc. — show a compact, comma-joined form.
            $flat = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $value);

            return Str::limit(implode(', ', $flat), 80) ?: '—';
        }

        // ISO timestamps → readable date/time.
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2})/', $value)) {
            try {
                return Carbon::parse($value)->format('d.m.Y H:i');
            } catch (\Throwable) {
                // fall through to plain string
            }
        }

        return Str::limit((string) $value, 80);
    }
}
