<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Http\Controllers\Admin\Settings\NotificationsController;
use App\Models\AuditLog;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\TableCombination;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Felder, die Standorte, Team, „Allgemein“ und die Seiten für Platz und
 * Buchungen protokollieren, erscheinen im Protokoll mit deutschem Namen;
 * Rollen mit ihrer Bezeichnung aus lang/de/roles.php.
 */
class AuditFieldLabelTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{label: string, from: ?string, to: ?string}>
     */
    private function changes(array $old, array $new): array
    {
        $log = new AuditLog(['action' => 'location.updated', 'old_values' => $old, 'new_values' => $new]);

        return collect($log->fieldChanges())->keyBy('field')->all();
    }

    public function test_location_profile_fields_have_german_labels(): void
    {
        $changes = $this->changes(
            ['address_line1' => 'Alt 1', 'postal_code' => '11111', 'city' => 'Altstadt', 'public_intro' => 'Alt'],
            ['address_line1' => 'Neu 2', 'postal_code' => '22222', 'city' => 'Neustadt', 'public_intro' => 'Neu'],
        );

        $this->assertSame('Straße und Hausnummer', $changes['address_line1']['label']);
        $this->assertSame('PLZ', $changes['postal_code']['label']);
        $this->assertSame('Ort', $changes['city']['label']);
        $this->assertSame('Begrüßung', $changes['public_intro']['label']);
    }

    public function test_team_fields_have_german_labels_and_role_names(): void
    {
        $changes = $this->changes(
            ['role' => 'staff', 'all_locations' => true, 'location_ids' => [1], 'user_id' => 7],
            ['role' => 'host', 'all_locations' => false, 'location_ids' => [1, 2], 'user_id' => 8],
        );

        $this->assertSame('Rolle', $changes['role']['label']);
        $this->assertSame('Service', $changes['role']['from']);
        $this->assertSame('Empfang', $changes['role']['to']);
        $this->assertSame('Alle Standorte', $changes['all_locations']['label']);
        $this->assertSame('Standorte', $changes['location_ids']['label']);
        $this->assertSame('Benutzer-Nr.', $changes['user_id']['label']);
    }

    public function test_general_settings_fields_have_german_labels(): void
    {
        $changes = $this->changes(
            ['imprint_url' => 'https://a.test/i', 'privacy_url' => 'https://a.test/d', 'terms_url' => 'https://a.test/a', 'brand_primary_color' => '#000000', 'type' => 'restaurant'],
            ['imprint_url' => 'https://b.test/i', 'privacy_url' => 'https://b.test/d', 'terms_url' => 'https://b.test/a', 'brand_primary_color' => '#ffffff', 'type' => 'salon'],
        );

        $this->assertSame('Impressum', $changes['imprint_url']['label']);
        $this->assertSame('Datenschutzhinweise', $changes['privacy_url']['label']);
        $this->assertSame('AGB', $changes['terms_url']['label']);
        $this->assertSame('Markenfarbe', $changes['brand_primary_color']['label']);
        $this->assertSame('Art', $changes['type']['label']);
    }

    public function test_field_rules_name_the_fields_of_the_form(): void
    {
        $this->assertSame(
            ['note' => 'Anmerkung', 'occasion' => 'Anlass', 'allergies' => 'Allergien', 'email' => 'E-Mail', 'phone' => 'Telefon'],
            AuditLog::ACTION_FIELD_LABELS['location.field_rules_updated'],
        );

        $log = new AuditLog(['action' => 'location.field_rules_updated', 'old_values' => ['note' => 'hidden'], 'new_values' => ['note' => 'optional']]);
        $this->assertSame('Anmerkung', $log->fieldChanges()[0]['label']);

        // Dieselbe Spalte heißt in anderen Aktionen weiter „Notiz“.
        $other = new AuditLog(['action' => 'reservation.updated', 'old_values' => ['note' => 'a'], 'new_values' => ['note' => 'b']]);
        $this->assertSame('Notiz', $other->fieldChanges()[0]['label']);
        $this->assertTrue(AuditLog::hasFieldLabel('note'));
        $this->assertTrue(AuditLog::hasFieldLabel('note', 'location.field_rules_updated'));
        $this->assertFalse(AuditLog::hasFieldLabel('brand_new_field'));
        $this->assertFalse(AuditLog::hasFieldLabel('brand_new_field', 'location.field_rules_updated'));
    }

    public function test_fields_of_the_pages_from_part_2c_have_labels(): void
    {
        // Felder aus den Controllern der Seiten Benachrichtigungen, Erstattung, Absender, SMS,
        // Zahlungsanbieter, Anzahlungen, Newsletter, API-Schlüssel, Webhooks und Vorlagen.
        $fields = array_merge(NotificationsController::FIELDS, [
            'refund_mode', 'refund_percent', 'refund_processing',
            'mail_from_name', 'mail_reply_to', 'mail_relay_enabled',
            'sender_id', 'mode', 'status', 'api_url', 'list_uid',
            'name', 'min_party_size', 'amount_per_person', 'flat_amount', 'from_time', 'until_time', 'weekdays',
            'payment_deadline_minutes', 'cancel_unpaid_automatically', 'service_id', 'room_id',
            'scopes', 'owner_id', 'url', 'events', 'key',
        ]);

        $ohne = array_values(array_filter($fields, fn (string $field) => ! AuditLog::hasFieldLabel($field)));

        $this->assertSame([], $ohne, 'Für diese Felder fehlt eine Bezeichnung in AuditLog::FIELD_LABELS: '.implode(', ', $ohne));
    }

    /**
     * @param  list<string>  $actions
     * @return list<string> Felder, die diese Aktionen in old_values und new_values festhalten
     */
    private function loggedFields(array $actions): array
    {
        $fields = [];
        foreach (AuditLog::whereIn('action', $actions)->get() as $log) {
            $fields[] = array_merge(array_keys((array) $log->old_values), array_keys((array) $log->new_values));
        }

        return array_values(array_unique(array_merge([], ...$fields)));
    }

    /**
     * Die Aktion muss tatsächlich geschrieben worden sein, sonst prüft der Test nichts.
     *
     * @param  list<string>  $actions
     */
    private function assertLogged(array $actions): void
    {
        foreach ($actions as $action) {
            $this->assertTrue(AuditLog::where('action', $action)->exists(), "Die Aktion {$action} wurde nicht protokolliert, die Anfrage dazu hat nichts geschrieben.");
        }
    }

    public function test_every_field_the_restaurant_pages_log_has_a_label(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->actingAs($owner);
        $flags = ['online_bookable' => '1', 'joinable' => '1', 'accessible' => '1', 'high_chair_possible' => '1', 'outdoor' => '1'];

        $this->putJson('/admin/settings/capacity', [
            'slot_interval_minutes' => 15, 'default_duration_minutes' => 105, 'buffer_minutes' => 10, 'capacity_mode' => 'hybrid',
            'max_covers_per_slot' => 60, 'duration_rules' => ['n1' => ['min_party' => 1, 'max_party' => 4, 'duration' => 90]],
        ])->assertOk();
        $this->putJson('/admin/settings/rules', [
            'min_lead_minutes' => 90, 'max_advance_days' => 60, 'min_party_online' => 2, 'max_party_online' => 12,
            'large_group_email' => 'gruppen@beispiel.test', 'cancellation_deadline_minutes' => 1440, 'modification_deadline_minutes' => 240,
            'booking_confirmation_mode' => 'manual', 'require_email_confirmation' => '1', 'waitlist_enabled' => '1', 'walkins_enabled' => '1',
        ])->assertOk();
        $this->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1', 'public_floorplan_enabled' => '1'])->assertOk();
        $this->postJson('/admin/settings/rooms', ['name' => 'Wintergarten', 'is_outdoor' => '1'])->assertOk();
        $this->putJson("/admin/settings/rooms/{$setup['room']->id}", ['name' => 'Saal', 'is_outdoor' => '0'])->assertOk();
        $this->postJson('/admin/settings/tables', ['room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 1, 'max_capacity' => 4] + $flags)->assertOk();
        $this->putJson("/admin/settings/tables/{$setup['tables'][0]->id}", ['name' => 'Fenster', 'min_capacity' => 1, 'max_capacity' => 2] + $flags)->assertOk();
        $tableIds = [$setup['tables'][0]->id, $setup['tables'][1]->id];
        $this->postJson('/admin/settings/combinations', ['name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 6, 'table_ids' => $tableIds])->assertOk();
        $combination = TableCombination::withoutGlobalScopes()->where('name', 'Tafel')->firstOrFail();
        $this->putJson("/admin/settings/combinations/{$combination->id}", ['name' => 'Lange Tafel', 'min_capacity' => 2, 'max_capacity' => 8, 'table_ids' => $tableIds])->assertOk();

        $actions = [
            'location.capacity_updated', 'location.booking_rules_updated', 'location.booking_page_updated',
            'room.created', 'room.updated', 'table.created', 'table.updated', 'table_combination.created', 'table_combination.updated',
        ];
        $this->assertLogged($actions);

        $ohne = array_values(array_filter($this->loggedFields($actions), fn (string $field) => ! AuditLog::hasFieldLabel($field)));
        $this->assertSame([], $ohne, 'Diese Felder protokollieren die Seiten für Restaurants ohne deutsche Bezeichnung: '.implode(', ', $ohne));
    }

    public function test_every_field_the_salon_pages_log_has_a_label(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->actingAs($owner);

        $this->putJson('/admin/settings/capacity', ['slot_interval_minutes' => 15, 'buffer_minutes' => 10, 'gap_optimization_enabled' => '1'])->assertOk();
        $this->putJson('/admin/settings/rules', [
            'min_lead_minutes' => 90, 'max_advance_days' => 60, 'cancellation_deadline_minutes' => 1440, 'modification_deadline_minutes' => 240,
            'booking_confirmation_mode' => 'auto', 'require_email_confirmation' => '0', 'waitlist_enabled' => '1',
        ])->assertOk();
        $this->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1'])->assertOk();

        $this->postJson('/admin/staff', ['name' => 'Anna', 'bio' => 'Meisterin', 'color' => '#aa3300', 'is_active' => '1'])->assertOk();
        $member = StaffMember::withoutGlobalScopes()->where('name', 'Anna')->firstOrFail();
        $this->postJson('/admin/services', [
            'name' => 'Färben', 'description' => 'Mit Pflege', 'duration_minutes' => 90, 'price' => '49.90', 'color' => '#aa3300',
            'is_active' => '1', 'staff_ids' => [$member->id],
        ])->assertOk();
        $service = Service::withoutGlobalScopes()->where('name', 'Färben')->firstOrFail();
        $this->putJson("/admin/services/{$service->id}", [
            'name' => 'Färben lang', 'description' => 'Mit Kur', 'duration_minutes' => 120, 'price' => '59.90', 'color' => '#003388',
            'is_active' => '0', 'staff_ids' => [$member->id],
        ])->assertOk();
        $this->putJson("/admin/staff/{$member->id}", [
            'name' => 'Anna B.', 'bio' => 'Chefin', 'color' => '#003388', 'is_active' => '1', 'service_ids' => [$service->id],
        ])->assertOk();
        $this->putJson("/admin/staff/{$member->id}/working-hours", ['hours' => [['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00']]])->assertOk();
        $this->postJson("/admin/staff/{$member->id}/absences", [
            'starts_on' => CarbonImmutable::now()->addDays(30)->toDateString(), 'ends_on' => CarbonImmutable::now()->addDays(31)->toDateString(), 'reason' => 'Urlaub',
        ])->assertOk();

        $actions = [
            'location.capacity_updated', 'location.booking_rules_updated', 'location.booking_page_updated',
            'staff_member.created', 'staff_member.updated', 'staff_member.working_hours_updated', 'staff_member.absence_created',
            'service.created', 'service.updated',
        ];
        $this->assertLogged($actions);

        $ohne = array_values(array_filter($this->loggedFields($actions), fn (string $field) => ! AuditLog::hasFieldLabel($field)));
        $this->assertSame([], $ohne, 'Diese Felder protokollieren die Seiten für Salons ohne deutsche Bezeichnung: '.implode(', ', $ohne));
    }
}
