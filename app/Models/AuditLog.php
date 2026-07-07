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
    ];

    /**
     * Turn the technical action key into a plain German sentence.
     * Suffix (created/updated/…) drives the verb, prefix the subject.
     */
    public function actionLabel(): string
    {
        $overrides = [
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
        ];
        if (isset($overrides[$this->action])) {
            return $overrides[$this->action];
        }

        $subjects = [
            'reservation' => 'Reservierung', 'guest' => 'Gast', 'guests' => 'Gäste',
            'table' => 'Tisch', 'table_combination' => 'Tischkombination', 'room' => 'Raum',
            'zone' => 'Zone', 'event' => 'Event', 'service' => 'Leistung',
            'staff_member' => 'Mitarbeiter', 'tag' => 'Tag', 'location' => 'Standort',
            'user' => 'Benutzer', 'refund' => 'Erstattung', 'deposit_rule' => 'Anzahlungsregel',
            'blackout' => 'Sperrzeit', 'special_hours' => 'Sonderöffnungszeit',
            'opening_hours' => 'Öffnungszeiten', 'template' => 'E-Mail-Vorlage',
            'api_token' => 'API-Token', 'webhook' => 'Webhook', 'tenant' => 'Betrieb',
            'floorplan' => 'Tischplan', 'billing' => 'Abrechnung', 'integration' => 'Integration',
            'waitlist' => 'Warteliste', 'payment' => 'Zahlung', 'saas' => 'Plattform-Benutzer',
        ];
        $verbs = [
            'created' => 'angelegt', 'updated' => 'geändert', 'deleted' => 'gelöscht',
            'removed' => 'entfernt', 'added' => 'hinzugefügt', 'invited' => 'eingeladen',
            'exported' => 'exportiert', 'approved' => 'genehmigt', 'rejected' => 'abgelehnt',
            'completed' => 'abgeschlossen', 'requested' => 'angefragt', 'offered' => 'angeboten',
            'activated' => 'aktiviert', 'cancelled' => 'storniert', 'reset' => 'zurückgesetzt',
            'signed_up' => 'registriert',
        ];

        [$prefix, $rest] = array_pad(explode('.', $this->action, 2), 2, '');
        $subject = $subjects[$prefix] ?? Str::headline($prefix);
        $verbKey = $rest ? (string) Str::afterLast($rest, '.') : '';
        $verb = $verbs[$verbKey] ?? null;

        return $verb ? "{$subject} {$verb}" : trim($subject.' '.Str::headline(str_replace('.', ' ', (string) $rest)));
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
