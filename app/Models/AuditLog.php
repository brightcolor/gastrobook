<?php

namespace App\Models;

use App\Support\ApiScopes;
use App\Support\IntegrationStatus;
use App\Support\TemplateLabels;
use App\Support\WebhookEvents;
use Illuminate\Database\Eloquent\Builder;
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
        // Allgemein (GeneralController, mit Standortdaten)
        'business_name' => 'Name des Betriebs',
        'location_name' => 'Name des Standorts',
        // Kapazität & Dauer (CapacityController)
        'slot_interval_minutes' => 'Takt der Uhrzeiten (Min.)',
        'default_duration_minutes' => 'Standarddauer (Min.)',
        'buffer_minutes' => 'Puffer nach jeder Buchung (Min.)',
        'capacity_mode' => 'Zählweise',
        'max_covers_per_slot' => 'Höchstens gleichzeitig (Gäste)',
        'gap_optimization_enabled' => 'Lückenoptimierung',
        'duration_rules' => 'Tischzeiten',
        // Buchungsregeln (BookingRulesController)
        'min_lead_minutes' => 'Mindestvorlauf (Min.)',
        'max_advance_days' => 'Vorausbuchung (Tage)',
        'min_party_online' => 'Kleinste Gruppe',
        'max_party_online' => 'Größte Gruppe',
        'large_group_email' => 'Adresse für größere Gruppen',
        'cancellation_deadline_minutes' => 'Stornofrist (Min.)',
        'modification_deadline_minutes' => 'Umbuchungsfrist (Min.)',
        'auto_confirm' => 'Online-Buchungen',
        'request_only' => 'Buchungen nur als Anfrage',
        'require_email_confirmation' => 'E-Mail-Adresse bestätigen lassen',
        'waitlist_enabled' => 'Warteliste anbieten',
        'walkins_enabled' => 'Walk-ins erfassen',
        // Räume & Tische (RoomsController); 'name', 'min_capacity', 'max_capacity', 'room_id' und 'table_ids' stehen oben
        'is_outdoor' => 'Draußen',
        'outdoor' => 'Draußen',
        'online_bookable' => 'Online buchbar',
        'joinable' => 'Kombinierbar',
        'accessible' => 'Barrierefrei',
        'high_chair_possible' => 'Kinderstuhl möglich',
        // Leistungen (ServiceController) und Mitarbeiter (StaffMemberController); weitere Felder stehen oben
        'description' => 'Beschreibung',
        'is_active' => 'Aktiv',
        // Öffnungszeiten und Saison (OpeningHoursController); 'count' heißt bei den Öffnungszeiten „Zeitfenster in der Woche“ (ACTION_FIELD_LABELS)
        'count' => 'Anzahl',
        'label' => 'Bezeichnung',
        'start_month' => 'Beginn (Monat)',
        'start_day' => 'Beginn (Tag)',
        'end_month' => 'Ende (Monat)',
        'end_day' => 'Ende (Tag)',
        // Ausnahmen (ExceptionsController); 'label' steht oben
        'from' => 'Von',
        'until' => 'Bis',
        'effects' => 'Wirkung',
    ];

    /**
     * Bezeichnungen, die nur für eine Aktion gelten und die allgemeine
     * Bezeichnung aus FIELD_LABELS ersetzen: Das Formular nennt das Feld dort
     * anders als der Spaltenname.
     *
     * @var array<string, array<string, string>>
     */
    public const ACTION_FIELD_LABELS = [
        'location.field_rules_updated' => ['note' => 'Anmerkung', 'occasion' => 'Anlass', 'allergies' => 'Allergien', 'email' => 'E-Mail', 'phone' => 'Telefon'],
        'tenant.type_changed' => ['type' => 'Betriebstyp'],
        'service.created' => ['is_active' => 'Online buchbar'],
        'service.updated' => ['is_active' => 'Online buchbar'],
        'staff_member.created' => ['is_active' => 'Buchbar', 'color' => 'Farbe im Terminplan'],
        'staff_member.updated' => ['is_active' => 'Buchbar', 'color' => 'Farbe im Terminplan'],
        'opening_hours.updated' => ['count' => 'Zeitfenster in der Woche'],
    ];

    /**
     * Auswahlwerte in den Worten der Einstellungsseiten: Feld => Wert => Wort.
     * Die Seiten nennen jede Option so, Protokoll und Seite sagen dasselbe.
     *
     * @var array<string, array<string, string>>
     */
    private const VALUE_LABELS = [
        'type' => ['restaurant' => 'Restaurant', 'salon' => 'Salon'],
        'refund_mode' => ['off' => 'Aus', 'manual' => 'Von Hand freigeben', 'auto' => 'Automatisch'],
        'refund_processing' => ['immediate' => 'Sofort', 'scheduled' => 'Gesammelt nach Zeitplan'],
        'capacity_mode' => ['table' => 'Nach Tischen', 'person' => 'Nach Personen', 'hybrid' => 'Beides'],
        'mode' => ['sandbox' => 'Test (Sandbox)', 'live' => 'Live'],
    ];

    /**
     * Auswahlwerte, die in einer Aktion für jedes Feld gelten: Aktion => Wert => Wort.
     *
     * @var array<string, array<string, string>>
     */
    private const ACTION_VALUE_LABELS = [
        'location.field_rules_updated' => ['hidden' => 'Ausgeblendet', 'optional' => 'Freiwillig', 'required' => 'Pflichtfeld'],
    ];

    /** Wörter der Wahl „Online-Buchungen“ (auto_confirm) auf der Seite Buchungsregeln. */
    private const CONFIRMATION_WORDS = ['auto' => 'Sofort bestätigen', 'manual' => 'Von Hand freigeben'];

    /**
     * Aktionen mit eigener Formulierung.
     *
     * Ansonsten gilt: Endung (created/updated/…) gibt die Taetigkeit, Praefix
     * den Gegenstand.
     *
     * Alles, was hier nicht steht, wird aus SUBJECTS und VERBS
     * zusammengesetzt. Faellt beides aus, zeigt die Zeile den Bereich und den
     * technischen Namen der Aktion ("Zahlung: Aktion payment.amount_mismatch").
     * Das liest sich fuer Betreiber holprig, deshalb laesst AuditLabelTest
     * keine geloggte Aktion ohne Bezeichnung durch.
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
        // Schrieb die frühere Sammelseite der Einstellungen; ihre Einträge stehen weiter im Protokoll.
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
        'exception.created' => 'Ausnahme angelegt',
        'exception.updated' => 'Ausnahme geändert',
        'exception.deleted' => 'Ausnahme gelöscht',
        'onboarding.rules_updated' => 'Einrichtung: Buchungsregeln gespeichert',
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
        'floorplan' => 'Tischplan', 'billing' => 'Abo & Rechnungen', 'integration' => 'Integration',
        'waitlist' => 'Warteliste', 'payment' => 'Zahlung', 'saas' => 'Plattform-Benutzer',
        'marketing_campaign' => 'Marketing-Kampagne', 'table_block' => 'Tischsperre',
        'account' => 'Konto', 'season' => 'Saison', 'exception' => 'Ausnahmen',
        'auth' => 'Anmeldung', 'support' => 'Support', 'provisioning' => 'Kundenpanel',
        'onboarding' => 'Einrichtung',
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

    /** Zeichen, mit dem die Suche %, _ und das Zeichen selbst im Suchtext als gewöhnliche Zeichen kennzeichnet (LIKE … ESCAPE). */
    private const LIKE_ESCAPE = '!';

    public function actionLabel(): string
    {
        return self::labelFor($this->action);
    }

    /**
     * Hat die Aktion dieses Eintrags eine eigene deutsche Bezeichnung? Sonst
     * zeigt die Ansicht Bereich und technischen Namen (area(), action).
     */
    public function hasActionLabel(): bool
    {
        return self::hasGermanLabel($this->action);
    }

    public function area(): ?string
    {
        return self::areaLabel($this->action);
    }

    /**
     * Der Bereich einer Aktion in Deutsch (Anfang des Namens, SUBJECTS), oder
     * null, wenn der Anfang in keinem Bereich steht.
     */
    public static function areaLabel(string $action): ?string
    {
        return self::SUBJECTS[Str::before($action, '.')] ?? null;
    }

    /**
     * Dieselbe Aufloesung ohne Datensatz - damit ein Test sie fuer jede
     * geloggte Aktion durchspielen kann.
     *
     * Ohne eigene Bezeichnung entsteht der Satz aus Bereich und Taetigkeit
     * ("Tisch gelöscht"). Fehlt auch die Taetigkeit, nennt die Zeile den
     * Bereich und den technischen Namen ("Standort: Aktion location.xyz"),
     * damit ein Betreiber die Aktion findet. Die Ansicht setzt den Namen in
     * <code>.
     */
    public static function labelFor(string $action): string
    {
        if (isset(self::ACTION_LABELS[$action])) {
            return self::ACTION_LABELS[$action];
        }

        $area = self::areaLabel($action);
        $verb = self::verbOf($action);

        if ($area !== null && $verb !== null) {
            return "{$area} {$verb}";
        }

        return $area !== null ? "{$area}: Aktion {$action}" : "Aktion {$action}";
    }

    /**
     * Die Taetigkeit aus der Endung des Aktionsnamens ("…deleted" => "gelöscht"), oder null.
     */
    private static function verbOf(string $action): ?string
    {
        $rest = array_pad(explode('.', $action, 2), 2, '')[1];

        return self::VERBS[$rest !== '' ? Str::afterLast($rest, '.') : ''] ?? null;
    }

    /**
     * Ergibt diese Aktion eine deutsche Bezeichnung - oder faellt sie auf die
     * Zeile mit Bereich und technischem Namen zurueck?
     */
    public static function hasGermanLabel(string $action): bool
    {
        return isset(self::ACTION_LABELS[$action])
            || (self::areaLabel($action) !== null && self::verbOf($action) !== null);
    }

    /**
     * Hat dieses Feld eine deutsche Bezeichnung, allgemein oder (mit $action)
     * für diese Aktion?
     */
    public static function hasFieldLabel(string $field, ?string $action = null): bool
    {
        return ($action !== null && isset(self::ACTION_FIELD_LABELS[$action][$field]))
            || isset(self::FIELD_LABELS[$field]);
    }

    /**
     * Resolve old/new values into readable per-field diff rows with a plain
     * label and humanised values:
     * [['field' => 'status', 'label' => 'Status', 'from' => 'Bestätigt', 'to' => 'Am Tisch'], …].
     * Bezeichnung und Werte hängen bei manchen Feldern von der Aktion ab
     * (ACTION_FIELD_LABELS, ACTION_VALUE_LABELS). Die Wörter für Vorlagen,
     * API-Rechte und Webhook-Ereignisse nennen die Seiten je nach Betriebstyp
     * (Reservierung oder Termin); $salon wählt den Wortlaut.
     *
     * @return array<int, array{field: string, label: string, from: ?string, to: ?string}>
     */
    public function fieldChanges(bool $salon = false): array
    {
        $action = (string) $this->action;
        $old = $this->old_values ?? [];
        $new = $this->new_values ?? [];
        $rows = [];

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $field) {
            $field = (string) $field;
            $from = array_key_exists($field, $old) ? self::formatValue($field, $old[$field], $action, $salon) : null;
            $to = array_key_exists($field, $new) ? self::formatValue($field, $new[$field], $action, $salon) : null;
            if ($from === $to) {
                continue;
            }
            $rows[] = [
                'field' => $field,
                'label' => self::ACTION_FIELD_LABELS[$action][$field] ?? self::FIELD_LABELS[$field] ?? Str::headline($field),
                'from' => $from,
                'to' => $to,
            ];
        }

        return $rows;
    }

    private static function formatValue(string $field, mixed $value, string $action, bool $salon): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        // Online-Buchungen: Die Seite „Buchungsregeln“ nennt die Wahl „Sofort bestätigen“ oder „Von Hand freigeben“.
        if ($field === 'auto_confirm' && is_bool($value)) {
            return self::CONFIRMATION_WORDS[$value ? 'auto' : 'manual'];
        }
        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }

        // Zustand einer Anbindung (SMS, Newsletter, Stripe, PayPal) in den Worten ihrer Seiten.
        if ($field === 'status' && is_string($value) && str_starts_with($action, 'integration.') && isset(IntegrationStatus::LABELS[$value])) {
            return IntegrationStatus::LABELS[$value];
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
        // Die Anrede nennt die Seite „Benachrichtigungen“ „du“ und „Sie“.
        if ($field === 'guest_address' && is_string($value)) {
            return strtolower($value) === 'du' ? 'du' : 'Sie';
        }
        // Auswahlwerte in den Worten der Einstellungsseite; was dort nicht vorkommt, bleibt wie gespeichert.
        if (is_string($value)) {
            $word = self::ACTION_VALUE_LABELS[$action][$value] ?? self::VALUE_LABELS[$field][$value] ?? null;
            if ($word !== null) {
                return $word;
            }
        }
        if ($field === 'duration_rules' && is_array($value)) {
            return self::formatDurationRules($value);
        }
        // Vorlagen, API-Rechte und Webhook-Ereignisse in den Worten ihrer Seiten.
        if ($field === 'key' && is_string($value) && str_starts_with($action, 'template.')) {
            return TemplateLabels::label($value, $salon);
        }
        if (is_array($value) && ($words = self::identifierWords($field, $action, $salon)) !== null) {
            return implode(', ', array_map(fn (mixed $code): string => is_scalar($code) ? ($words[(string) $code] ?? (string) $code) : '', $value)) ?: '—';
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

    /**
     * Die Wörter, die eine Seite für die Kennungen in einem Feld nennt: die
     * Rechte eines API-Schlüssels und die Ereignisse eines Webhooks. Andere
     * Felder haben keine solche Liste.
     *
     * @return array<string, string>|null
     */
    private static function identifierWords(string $field, string $action, bool $salon): ?array
    {
        if ($field === 'scopes' && str_starts_with($action, 'api_token.')) {
            return ApiScopes::labels($salon);
        }
        if ($field === 'events' && str_starts_with($action, 'webhook.')) {
            return WebhookEvents::labels($salon) + ['*' => WebhookEvents::ALL_LABEL];
        }

        return null;
    }

    /**
     * Tischzeiten je Gruppengröße wie auf der Seite „Kapazität & Dauer“:
     * "1–2 Personen: 75 Min.; 3–6 Personen: 120 Min.". Zeilen in anderer
     * Form erscheinen als Liste wie jeder andere Wert.
     *
     * @param  array<int|string, mixed>  $rules
     */
    private static function formatDurationRules(array $rules): string
    {
        $rows = [];
        foreach ($rules as $rule) {
            if (! is_array($rule) || ! isset($rule['min_party'], $rule['max_party'], $rule['duration'])) {
                $flat = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE), $rules);

                return Str::limit(implode(', ', $flat), 80) ?: '—';
            }
            $rows[] = "{$rule['min_party']}–{$rule['max_party']} Personen: {$rule['duration']} Min.";
        }

        return implode('; ', $rows) ?: '—';
    }

    /**
     * Schränkt die Abfrage auf Einträge ein, die den Text enthalten: im Namen
     * der Aktion, in der deutschen Bezeichnung der Aktion oder in den alten
     * und neuen Werten. Groß- und Kleinschreibung zählt nicht; %, _ und das
     * Fluchtzeichen im Text suchen sich selbst. Die Abfrage ist vorher auf den
     * Betrieb eingegrenzt: Die Bezeichnungen kommen aus den Aktionen genau
     * dieser Einträge.
     *
     * Die Werte stehen als JSON-Text in der Spalte; Umlaute erscheinen dort
     * als \uXXXX, Schrägstriche als \/ und Anführungszeichen als \".
     * Die Suche vergleicht deshalb mit der maskierten Form des Textes
     * (jsonNeedles). CAST(… AS TEXT) gilt unter SQLite (Text) und PostgreSQL
     * (Spalte vom Typ json) gleichermaßen.
     *
     * @param  Builder<AuditLog>  $query
     */
    public function scopeMatchingText(Builder $query, string $text): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }

        $labelled = $query->clone()->reorder()->distinct()->pluck('action')
            ->filter(fn (mixed $action): bool => mb_stripos(self::labelFor((string) $action), $text) !== false)
            ->values()
            ->all();

        $grammar = $query->getQuery()->getGrammar();
        $escape = self::LIKE_ESCAPE;
        $pattern = fn (string $needle): string => '%'.str_replace(
            [$escape, '%', '_'],
            [$escape.$escape, $escape.'%', $escape.'_'],
            $needle,
        ).'%';

        $query->where(function (Builder $match) use ($text, $labelled, $grammar, $escape, $pattern): void {
            $match->whereRaw('LOWER('.$grammar->wrap('action').") LIKE LOWER(?) ESCAPE '{$escape}'", [$pattern($text)]);
            foreach (['old_values', 'new_values'] as $column) {
                foreach (self::jsonNeedles($text) as $needle) {
                    $match->orWhereRaw('LOWER(CAST('.$grammar->wrap($column)." AS TEXT)) LIKE LOWER(?) ESCAPE '{$escape}'", [$pattern($needle)]);
                }
            }
            if ($labelled !== []) {
                $match->orWhereIn('action', $labelled);
            }
        });
    }

    /**
     * Der Suchtext in der Form, in der eine JSON-Spalte ihn hält: Umlaute als
     * \uXXXX, Schrägstriche als \/, Anführungszeichen als \". Der Vergleich
     * in der Datenbank setzt beide Seiten auf Kleinbuchstaben; das gelingt
     * dort für ASCII-Zeichen. Die Maskierung eines Umlauts hängt von seiner
     * Schreibweise ab (großes und kleines Ü haben verschiedene Kennungen).
     * Deshalb entstehen die Formen für den Text wie eingegeben, ganz in
     * Kleinbuchstaben und mit großen Umlauten.
     *
     * @return list<string>
     */
    private static function jsonNeedles(string $text): array
    {
        $upperUmlauts = (string) preg_replace_callback(
            '/[^\x00-\x7F]/u',
            fn (array $match): string => mb_convert_case($match[0], MB_CASE_UPPER_SIMPLE),
            $text,
        );

        $needles = [];
        foreach ([$text, mb_strtolower($text), $upperUmlauts] as $variant) {
            $encoded = json_encode($variant);
            if (is_string($encoded)) {
                // Gleiche Formen nach dem Kleinschreiben fallen zusammen; die Datenbank setzt sie ohnehin gleich.
                $needles[strtolower($encoded)] = substr($encoded, 1, -1);
            }
        }

        return array_values($needles);
    }
}
