<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Zuordnung alt → neu: Jedes Feld der früheren Sammelseite steht auf der Seite,
 * die ihre Karte übernommen hat. Die Zeilen nennen Karte, alten Feldnamen,
 * Route der neuen Seite, das Muster des neuen Feldnamens (regulärer Ausdruck
 * für den Inhalt von name="…"), den Wert, den das Feld tragen muss, und die
 * Betriebsart, für die die Zeile gilt.
 */
class SettingsMappingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Namen, die auf mehreren Seiten vorkommen und deshalb keiner Seite allein gehören („email“ auch im Einladungsformular von Team & Rollen). */
    private const SHARED_NAMES = ['name', 'label', 'enabled', 'api_key', 'secret', 'mode', 'reason', 'starts_at', 'ends_at', 'room_id', 'from', 'until', 'email'];

    /**
     * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: ?string, 5: string}>
     */
    public static function fields(): array
    {
        $rows = [];
        $add = function (string $card, string $old, string $route, ?string $new = null, ?string $value = null, string $type = 'restaurant') use (&$rows): void {
            $rows["{$card}: {$old} ({$type})"] = [$card, $old, $route, $new ?? preg_quote($old, '/'), $value, $type];
        };

        $general = 'admin.settings.general.show';
        foreach (['business_name', 'imprint_url', 'privacy_url', 'terms_url'] as $field) {
            $add('Stammdaten', $field, $general);
        }
        foreach (['type', 'brand_primary_color'] as $field) {
            $add('Betriebstyp, Markenfarbe', $field, $general);
        }

        $locations = 'admin.locations.index';
        $add('Stammdaten', 'location_name', $locations, 'name');
        foreach (['timezone', 'phone', 'email', 'address_line1', 'postal_code', 'city', 'public_intro'] as $field) {
            $add('Stammdaten', $field, $locations);
        }
        $add('Logo', 'logo', $locations);

        foreach (['mail_from_name', 'mail_reply_to', 'mail_relay_enabled'] as $field) {
            $add('E-Mails an Gäste', $field, 'admin.settings.sender.show');
        }

        $add('Formularfelder', 'fields[…]', 'admin.settings.booking-form.show', 'fields\[[a-z_]+\]');

        $capacity = 'admin.settings.capacity.show';
        foreach (['slot_interval_minutes', 'default_duration_minutes', 'buffer_minutes', 'capacity_mode', 'max_covers_per_slot'] as $field) {
            $add('Kapazität', $field, $capacity);
        }
        foreach (['min_party', 'max_party', 'duration'] as $part) {
            $add('Kapazität', "duration_rules[][{$part}]", $capacity, 'duration_rules\[[^\]]+\]\['.$part.'\]');
        }
        $add('Kapazität', 'gap_optimization_enabled', $capacity, null, null, 'salon');

        foreach ([
            'min_lead_minutes', 'max_advance_days', 'cancellation_deadline_minutes', 'modification_deadline_minutes',
            'min_party_online', 'max_party_online', 'large_group_email', 'waitlist_enabled', 'walkins_enabled',
            'booking_confirmation_mode', 'require_email_confirmation',
        ] as $field) {
            $add('Regeln', $field, 'admin.settings.rules.show');
        }

        foreach (['public_floorplan_enabled', 'confetti_on_booking'] as $field) {
            $add('Online-Buchung', $field, 'admin.settings.booking-page.show');
        }

        foreach ([
            'reminder_enabled', 'reminder_hours_before', 'sms_reminder_enabled', 'owner_notification_enabled', 'owner_notification_email',
            'feedback_enabled', 'feedback_hours_after', 'feedback_external_url', 'feedback_redirect_min_score', 'guest_address',
        ] as $field) {
            $add('Erinnerungen, Betrieb, Feedback, Anrede', $field, 'admin.settings.notifications.show');
        }

        $deposits = 'admin.settings.deposits.show';
        foreach (['refund_mode', 'refund_percent', 'refund_processing'] as $field) {
            $add('Rückerstattung', $field, $deposits);
        }

        $hours = 'admin.settings.opening-hours.show';
        foreach (['weekday', 'opens_at', 'closes_at', 'service_name'] as $part) {
            $add('Öffnungszeiten', "hours[][{$part}]", $hours, 'hours\[[^\]]+\]\['.$part.'\]');
        }
        $add('Öffnungszeiten', 'hours_sent', $hours);
        foreach (['label', 'start_day', 'start_month', 'end_day', 'end_month'] as $field) {
            $add('Saison', $field, $hours);
        }

        $exceptions = 'admin.settings.exceptions.show';
        $add('Sonderöffnungszeiten', 'date', $exceptions, 'from');
        $add('Sonderöffnungszeiten', 'label', $exceptions);
        $add('Sonderöffnungszeiten', 'opens_at', $exceptions, 'windows\[[^\]]+\]\[opens_at\]');
        $add('Sonderöffnungszeiten', 'closes_at', $exceptions, 'windows\[[^\]]+\]\[closes_at\]');
        $add('Sonderöffnungszeiten', 'closed', $exceptions, 'effects\[\]', 'closed');
        $add('Sperrzeiten', 'starts_at', $exceptions, 'from');
        $add('Sperrzeiten', 'ends_at', $exceptions, 'until');
        $add('Sperrzeiten', 'room_id', $exceptions, 'limit_room_id');
        $add('Sperrzeiten', 'reduce_covers_to', $exceptions, 'limit_covers');
        $add('Sperrzeiten', 'bo_type', $exceptions, 'effects\[\]', 'limit');
        $add('Sperrzeiten', 'reason', $exceptions, 'label');
        $add('Tisch sperren', 'restaurant_table_id', $exceptions, 'lock_tables\[\]');
        $add('Tisch sperren', 'starts_at', $exceptions, 'from');
        $add('Tisch sperren', 'ends_at', $exceptions, 'until');
        $add('Tisch sperren', 'reason', $exceptions, 'label');

        $rooms = 'admin.settings.rooms.index';
        foreach (['name', 'is_outdoor', 'min_capacity', 'max_capacity', 'online_bookable', 'joinable', 'accessible', 'high_chair_possible'] as $field) {
            $add('Räume, Tische, Kombinationen', $field, $rooms);
        }
        $add('Räume, Tische, Kombinationen', 'table_ids[]', $rooms, 'table_ids\[\]');

        foreach (['name', 'color'] as $field) {
            $add('Tags', $field, 'admin.settings.tags.show');
        }

        foreach (['secret_key', 'webhook_secret', 'client_id', 'secret', 'mode', 'enabled'] as $field) {
            $add('Stripe, PayPal', $field, 'admin.settings.payment-providers.show');
        }

        foreach ([
            'name', 'min_party_size', 'from_time', 'until_time', 'room_id', 'amount_per_person', 'flat_amount',
            'payment_deadline_minutes', 'cancel_unpaid_automatically',
        ] as $field) {
            $add('Anzahlungsregeln', $field, $deposits);
        }
        $add('Anzahlungsregeln', 'weekdays[]', $deposits, 'weekdays\[\]');
        $add('Anzahlungsregeln', 'service_id', $deposits, null, null, 'salon');

        foreach (['api_url', 'api_key', 'list_uid', 'enabled'] as $field) {
            $add('MailWizz', $field, 'admin.settings.newsletter.show');
        }

        foreach (['api_key', 'sender_id', 'enabled'] as $field) {
            $add('seven.io', $field, 'admin.settings.sms.show');
        }

        $add('Website-Widget', 'Einbindung (Text)', 'admin.settings.booking-page.show', 'widget_label');
        $add('Website-Widget', 'Einbindung (Farbe)', 'admin.settings.booking-page.show', 'widget_color');

        return $rows;
    }

    #[DataProvider('fields')]
    public function test_every_old_field_has_a_home_on_its_new_page(string $card, string $old, string $route, string $pattern, ?string $value, string $type): void
    {
        $owner = $this->ownerOf($type);
        $html = (string) $this->actingAs($owner)->get(route($route))->assertOk()->getContent();

        $this->assertNotEmpty(
            $this->tagsNamed($html, $pattern, $value),
            "{$card}: Das Feld „{$old}“ fehlt auf der Seite {$route} (gesucht: name=\"{$pattern}\"".($value !== null ? ", Wert {$value}" : '').').',
        );
    }

    public function test_each_unique_new_field_name_stands_on_exactly_one_settings_page(): void
    {
        foreach (['restaurant', 'salon'] as $type) {
            // Einfache Feldnamen der Zeilen dieser Betriebsart; Muster mit Klammern und die geteilten Namen entfallen.
            $names = [];
            foreach (self::fields() as [, , , $pattern, , $rowType]) {
                if ($rowType === $type && preg_match('/^[a-z_0-9]+$/', $pattern) === 1 && ! in_array($pattern, self::SHARED_NAMES, true)) {
                    $names[$pattern] = [];
                }
            }

            $owner = $this->ownerOf($type);
            foreach ((array) config('navigation.settings') as $group) {
                foreach ($group['items'] as $item) {
                    if (($item['only'] ?? null) !== null && $item['only'] !== $type) {
                        continue;
                    }
                    $html = (string) $this->actingAs($owner)->get(route($item['route']))->assertOk()->getContent();
                    foreach (array_keys($names) as $name) {
                        if ($this->tagsNamed($html, preg_quote($name, '/'), null) !== []) {
                            $names[$name][] = $item['key'];
                        }
                    }
                }
            }

            $wrong = [];
            foreach ($names as $name => $pages) {
                if (count($pages) !== 1) {
                    $wrong[] = "„{$name}“ steht auf: ".($pages === [] ? 'keiner Seite' : implode(', ', $pages));
                }
            }
            $this->assertSame([], $wrong, "Felder ({$type}), die nicht auf genau einer Seite stehen.");
        }
    }

    /** Inhaber eines frischen Betriebs der Betriebsart, angemeldet auf dem Standort des Betriebs. */
    private function ownerOf(string $type): User
    {
        $setup = $this->createTenantSetup();
        if ($type === 'salon') {
            $setup['tenant']->update(['type' => 'salon']);
            // Die Auswahl „Nur für Leistung“ der Anzahlungsregeln erscheint erst, wenn der Salon eine Leistung hat.
            Service::create([
                'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Haarschnitt',
                'duration_minutes' => 45, 'price_minor' => 3500, 'currency' => 'EUR', 'is_active' => true,
            ]);
        }
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        return $owner;
    }

    /**
     * Eingabefelder (input, select, textarea) mit passendem name; $value verlangt zusätzlich value="…" im selben Feld.
     *
     * @return list<string>
     */
    private function tagsNamed(string $html, string $pattern, ?string $value): array
    {
        preg_match_all('/<(?:input|select|textarea)\b[^>]*\sname="(?:'.$pattern.')"[^>]*>/', $html, $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $tag) => $value === null || str_contains($tag, 'value="'.$value.'"'),
        ));
    }
}
