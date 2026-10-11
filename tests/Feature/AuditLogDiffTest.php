<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Http\Controllers\Admin\Settings\BookingFormController;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditLogDiffTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_field_changes_are_humanised(): void
    {
        $log = new AuditLog([
            'action' => 'reservation.status_changed',
            'old_values' => ['status' => 'confirmed', 'party_size' => 2, 'guest_note' => 'alt'],
            'new_values' => ['status' => 'seated', 'party_size' => 2, 'is_vip' => true, 'price_minor' => 2500],
        ]);

        $changes = collect($log->fieldChanges())->keyBy('field');

        // Status value is translated, not the raw enum code.
        $this->assertSame('Status', $changes['status']['label']);
        $this->assertSame('Bestätigt', $changes['status']['from']);
        $this->assertSame('Am Tisch', $changes['status']['to']);
        // Unchanged fields dropped.
        $this->assertArrayNotHasKey('party_size', $changes->all());
        // Removed value: from without to.
        $this->assertSame('Gastnotiz', $changes['guest_note']['label']);
        $this->assertNull($changes['guest_note']['to']);
        // Boolean → Ja/Nein.
        $this->assertSame('VIP', $changes['is_vip']['label']);
        $this->assertSame('Ja', $changes['is_vip']['to']);
        // *_minor → euro amount.
        $this->assertSame('Preis', $changes['price_minor']['label']);
        $this->assertSame('25,00 €', $changes['price_minor']['to']);
    }

    public function test_action_label_is_plain_german(): void
    {
        $this->assertSame('Reservierung: Status geändert', (new AuditLog(['action' => 'reservation.status_changed']))->actionLabel());
        $this->assertSame('Tisch gelöscht', (new AuditLog(['action' => 'table.deleted']))->actionLabel());
        $this->assertSame('Gast geändert', (new AuditLog(['action' => 'guest.updated']))->actionLabel());
        $this->assertSame('Leistung angelegt', (new AuditLog(['action' => 'service.created']))->actionLabel());
    }

    public function test_audit_page_renders_readable_diff(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');

        AuditLog::create([
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $admin->id,
            'action' => 'reservation.status_changed',
            'old_values' => ['status' => 'confirmed'],
            'new_values' => ['status' => 'seated'],
            'created_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/audit')
            ->assertOk()
            ->assertSee('Reservierung: Status geändert')
            ->assertSee('Vorher')
            ->assertSee('Nachher')
            ->assertSee('Bestätigt')
            ->assertSee('Am Tisch')
            ->assertDontSee('reservation.status_changed'); // no raw technical key
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     * @return array<string, array{field: string, label: string, from: ?string, to: ?string}>
     */
    private function rows(string $action, array $old, array $new): array
    {
        return collect((new AuditLog(['action' => $action, 'old_values' => $old, 'new_values' => $new]))->fieldChanges())
            ->keyBy('field')
            ->all();
    }

    public function test_type_values_and_field_rules_read_as_words(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');

        // Betriebsart als Wort.
        $type = $this->rows('tenant.type_changed', ['type' => 'restaurant'], ['type' => 'salon']);
        $this->assertSame('Betriebstyp', $type['type']['label']);
        $this->assertSame('Restaurant', $type['type']['from']);
        $this->assertSame('Salon', $type['type']['to']);

        // Feldregeln: „note“ heißt im Formular „Anmerkung“, die Werte stehen wie in der Auswahl der Seite.
        $rules = $this->rows(
            'location.field_rules_updated',
            ['note' => 'hidden', 'phone' => 'required', 'email' => 'optional', 'occasion' => 'optional', 'allergies' => 'hidden'],
            ['note' => 'optional', 'phone' => 'hidden', 'email' => 'required', 'occasion' => 'hidden', 'allergies' => 'optional'],
        );
        $this->assertSame('Anmerkung', $rules['note']['label']);
        $this->assertSame('Ausgeblendet', $rules['note']['from']);
        $this->assertSame('Freiwillig', $rules['note']['to']);
        $this->assertSame('Telefon', $rules['phone']['label']);
        $this->assertSame('Pflichtfeld', $rules['phone']['from']);
        $this->assertSame('Ausgeblendet', $rules['phone']['to']);
        $this->assertSame('E-Mail', $rules['email']['label']);
        $this->assertSame('Freiwillig', $rules['email']['from']);
        $this->assertSame('Pflichtfeld', $rules['email']['to']);
        $this->assertSame('Anlass', $rules['occasion']['label']);
        $this->assertSame('Allergien', $rules['allergies']['label']);

        // Dieselben Einträge auf der Seite.
        foreach ([
            ['tenant.type_changed', ['type' => 'restaurant'], ['type' => 'salon']],
            ['location.field_rules_updated', ['note' => 'hidden'], ['note' => 'optional']],
        ] as [$action, $old, $new]) {
            AuditLog::create([
                'tenant_id' => $setup['tenant']->id,
                'user_id' => $admin->id,
                'action' => $action,
                'old_values' => $old,
                'new_values' => $new,
                'created_at' => now(),
            ]);
        }
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/audit')
            ->assertOk()
            ->assertSee('Betriebstyp')
            ->assertSee('Restaurant')
            ->assertSee('Salon')
            ->assertSee('Anmerkung')
            ->assertSee('Ausgeblendet')
            ->assertSee('Freiwillig');
    }

    public function test_field_rule_words_follow_the_choices_of_the_form(): void
    {
        foreach (BookingFormController::CHOICES as $code => $word) {
            $rows = $this->rows('location.field_rules_updated', ['phone' => 'unbekannt'], ['phone' => $code]);

            $this->assertSame($word, $rows['phone']['to'], "Die Auswahl „{$word}“ des Formulars heißt im Protokoll anders.");
        }
    }

    public function test_the_same_codes_in_other_actions_stay_as_they_are(): void
    {
        // Außerhalb der Feldregeln heißt „note“ weiter „Notiz“ und „hidden“ bleibt ein gewöhnlicher Wert.
        $other = $this->rows('reservation.updated', ['note' => 'hidden'], ['note' => 'optional']);
        $this->assertSame('Notiz', $other['note']['label']);
        $this->assertSame('hidden', $other['note']['from']);
        $this->assertSame('optional', $other['note']['to']);

        // „type“ anderer Aktionen (Kampagnen) bleibt unverändert, solange der Wert keine Betriebsart nennt.
        $campaign = $this->rows('marketing_campaign.updated', ['type' => 'birthday'], ['type' => 'winback']);
        $this->assertSame('birthday', $campaign['type']['from']);
        $this->assertSame('winback', $campaign['type']['to']);
    }

    public function test_choices_of_the_settings_pages_read_as_words(): void
    {
        $refund = $this->rows(
            'location.refunds_updated',
            ['refund_mode' => 'off', 'refund_processing' => 'immediate'],
            ['refund_mode' => 'manual', 'refund_processing' => 'scheduled'],
        );
        $this->assertSame('Aus', $refund['refund_mode']['from']);
        $this->assertSame('Von Hand freigeben', $refund['refund_mode']['to']);
        $this->assertSame('Sofort', $refund['refund_processing']['from']);
        $this->assertSame('Gesammelt nach Zeitplan', $refund['refund_processing']['to']);
        $this->assertSame('Automatisch', $this->rows('location.refunds_updated', ['refund_mode' => 'off'], ['refund_mode' => 'auto'])['refund_mode']['to']);

        $address = $this->rows('location.notifications_updated', ['guest_address' => 'du'], ['guest_address' => 'Sie']);
        $this->assertSame('du', $address['guest_address']['from']);
        $this->assertSame('Sie', $address['guest_address']['to']);

        $capacity = $this->rows('location.capacity_updated', ['capacity_mode' => 'table'], ['capacity_mode' => 'person']);
        $this->assertSame('Nach Tischen', $capacity['capacity_mode']['from']);
        $this->assertSame('Nach Personen', $capacity['capacity_mode']['to']);
        $this->assertSame('Beides', $this->rows('location.capacity_updated', ['capacity_mode' => 'table'], ['capacity_mode' => 'hybrid'])['capacity_mode']['to']);

        // Online-Buchungen: Das Protokoll hält „auto_confirm“; die Seite nennt die Wahl „Sofort bestätigen“ oder „Von Hand freigeben“.
        $confirmation = $this->rows('location.booking_rules_updated', ['auto_confirm' => true], ['auto_confirm' => false]);
        $this->assertSame('Online-Buchungen', $confirmation['auto_confirm']['label']);
        $this->assertSame('Sofort bestätigen', $confirmation['auto_confirm']['from']);
        $this->assertSame('Von Hand freigeben', $confirmation['auto_confirm']['to']);

        $paypal = $this->rows('integration.paypal_updated', [], ['mode' => 'sandbox', 'status' => 'connected']);
        $this->assertSame('Modus', $paypal['mode']['label']);
        $this->assertSame('Test (Sandbox)', $paypal['mode']['to']);
        $this->assertSame('Verbunden', $paypal['status']['to']);
        $this->assertSame('Live', $this->rows('integration.paypal_updated', [], ['mode' => 'live'])['mode']['to']);

        $status = $this->rows('integration.sms_updated', ['status' => 'connected'], ['status' => 'disconnected']);
        $this->assertSame('Verbunden', $status['status']['from']);
        $this->assertSame('Ausgeschaltet', $status['status']['to']);
        $this->assertSame('Fehler beim letzten Test', $this->rows('integration.sms_updated', [], ['status' => 'error'])['status']['to']);

        // Reservierungsstatus bleiben, wie sie waren.
        $this->assertSame('Bestätigt', $this->rows('reservation.status_changed', ['status' => 'seated'], ['status' => 'confirmed'])['status']['to']);
    }

    public function test_the_words_of_the_log_match_the_settings_pages(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['auto_confirm' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Jedes Wort, das das Protokoll für eine Auswahl zeigt, steht so auch auf der Seite, auf der man sie trifft
        // (Beschriftung der Auswahl: <span class="block font-semibold">Wort</span>).
        $choices = [
            ['/admin/settings/deposits', 'location.refunds_updated', 'refund_mode', ['off' => 'Aus', 'manual' => 'Von Hand freigeben', 'auto' => 'Automatisch']],
            ['/admin/settings/deposits', 'location.refunds_updated', 'refund_processing', ['immediate' => 'Sofort', 'scheduled' => 'Gesammelt nach Zeitplan']],
            ['/admin/settings/capacity', 'location.capacity_updated', 'capacity_mode', ['table' => 'Nach Tischen', 'person' => 'Nach Personen', 'hybrid' => 'Beides']],
            ['/admin/settings/payment-providers', 'integration.paypal_updated', 'mode', ['sandbox' => 'Test (Sandbox)', 'live' => 'Live']],
        ];
        foreach ($choices as [$url, $action, $field, $words]) {
            $page = $this->actingAs($owner)->get($url)->assertOk();
            foreach ($words as $code => $word) {
                $page->assertSee('font-semibold">'.$word.'</span>', false);
                $this->assertSame($word, $this->rows($action, [], [$field => $code])[$field]['to'], "„{$word}“ ({$field}={$code}) heißt im Protokoll anders als auf der Seite.");
            }
        }

        // Online-Buchungen: Die Seite nennt die beiden Wahlmöglichkeiten, auf die das Protokoll „auto_confirm“ abbildet.
        $rules = $this->actingAs($owner)->get('/admin/settings/rules')->assertOk();
        foreach (['Sofort bestätigen', 'Von Hand freigeben'] as $word) {
            $rules->assertSee('font-semibold">'.$word.'</span>', false);
        }
        $rules->assertSee('Online-Buchungen');
    }

    public function test_an_action_without_a_label_shows_the_area_and_the_technical_name(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach (['location.brand_new_thing', 'zzz.other_thing', 'table.deleted'] as $action) {
            AuditLog::create(['tenant_id' => $setup['tenant']->id, 'user_id' => $admin->id, 'action' => $action, 'created_at' => now()]);
        }
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->get('/admin/audit')->assertOk();

        // Bereich in Deutsch, Aktion mit technischem Namen in <code>.
        $response->assertSee('Standort: Aktion <code', false)
            ->assertSee('>location.brand_new_thing</code>', false)
            ->assertSee('Aktion <code', false)
            ->assertSee('>zzz.other_thing</code>', false)
            ->assertDontSee('Brand New Thing')
            ->assertDontSee('Zzz Other Thing')
            // Mit Bezeichnung erscheint die Zeile als gewöhnlicher Text.
            ->assertSee('Tisch gelöscht')
            ->assertDontSee('>table.deleted</code>', false);
    }

    public function test_table_times_read_like_the_capacity_page(): void
    {
        $rows = $this->rows(
            'location.capacity_updated',
            ['duration_rules' => [['min_party' => 1, 'max_party' => 2, 'duration' => 75]]],
            ['duration_rules' => [['min_party' => 1, 'max_party' => 2, 'duration' => 75], ['min_party' => 3, 'max_party' => 6, 'duration' => 120]]],
        );

        $this->assertSame('Tischzeiten', $rows['duration_rules']['label']);
        $this->assertSame('1–2 Personen: 75 Min.', $rows['duration_rules']['from']);
        $this->assertSame('1–2 Personen: 75 Min.; 3–6 Personen: 120 Min.', $rows['duration_rules']['to']);

        // Eine leere Liste (alle Tischzeiten entfernt) erscheint als Strich.
        $removed = $this->rows('location.capacity_updated', ['duration_rules' => [['min_party' => 1, 'max_party' => 2, 'duration' => 75]]], ['duration_rules' => []]);
        $this->assertSame('—', $removed['duration_rules']['to']);
    }

    public function test_templates_rights_and_events_read_as_on_their_pages(): void
    {
        $template = new AuditLog(['action' => 'template.updated', 'new_values' => ['key' => 'reservation_confirmed']]);
        $this->assertSame('Vorlage', $template->fieldChanges()[0]['label']);
        $this->assertSame('Reservierung bestätigt', $template->fieldChanges()[0]['to']);
        $this->assertSame('Termin bestätigt', $template->fieldChanges(salon: true)[0]['to']);
        $this->assertSame('Erinnerung', (new AuditLog(['action' => 'template.reset', 'new_values' => ['key' => 'reservation_reminder']]))->fieldChanges()[0]['to']);
        // Eine unbekannte Kennung bleibt, wie sie ist.
        $this->assertSame('brand_new', (new AuditLog(['action' => 'template.updated', 'new_values' => ['key' => 'brand_new']]))->fieldChanges()[0]['to']);

        $token = new AuditLog(['action' => 'api_token.created', 'new_values' => ['name' => 'Kasse', 'scopes' => ['reservations:read', 'guests:read', 'alt:unbekannt']]]);
        $scopes = collect($token->fieldChanges())->keyBy('field')['scopes'];
        $this->assertSame('Rechte', $scopes['label']);
        $this->assertSame('Reservierungen lesen, Gäste lesen, alt:unbekannt', $scopes['to']);
        $this->assertSame('Termine lesen, Kunden lesen, alt:unbekannt', collect($token->fieldChanges(salon: true))->keyBy('field')['scopes']['to']);

        $hook = new AuditLog(['action' => 'webhook.created', 'new_values' => ['url' => 'https://beispiel.test/hook', 'events' => ['reservation.created', 'payment.succeeded']]]);
        $events = collect($hook->fieldChanges())->keyBy('field')['events'];
        $this->assertSame('Ereignisse', $events['label']);
        $this->assertSame('Neue Reservierung, Zahlung eingegangen', $events['to']);
        $this->assertSame('Neuer Termin, Zahlung eingegangen', collect($hook->fieldChanges(salon: true))->keyBy('field')['events']['to']);
        $all = new AuditLog(['action' => 'webhook.created', 'new_values' => ['events' => ['*']]]);
        $this->assertSame('Alle Ereignisse', $all->fieldChanges()[0]['to']);

        // Dieselben Feldnamen in anderen Aktionen behalten ihre Kennungen.
        $other = $this->rows('reservation.updated', [], ['events' => ['reservation.created'], 'scopes' => ['guests:read'], 'key' => 'reservation_confirmed']);
        $this->assertSame('reservation.created', $other['events']['to']);
        $this->assertSame('guests:read', $other['scopes']['to']);
        $this->assertSame('reservation_confirmed', $other['key']['to']);
    }

    public function test_the_audit_page_uses_the_wording_of_the_business_type(): void
    {
        $restaurant = $this->createTenantSetup();
        $salon = $this->createTenantSetup();
        $salon['tenant']->update(['type' => TenantType::Salon]);
        $restaurantOwner = $this->createMember($restaurant['tenant'], 'tenant_owner');
        $salonOwner = $this->createMember($salon['tenant'], 'tenant_owner');
        foreach ([$restaurant, $salon] as $setup) {
            AuditLog::create(['tenant_id' => $setup['tenant']->id, 'action' => 'template.updated', 'new_values' => ['key' => 'reservation_confirmed'], 'created_at' => now()]);
        }
        $this->clearTenantContext();

        $this->actingAs($restaurantOwner)->get('/admin/audit')->assertOk()->assertSee('Reservierung bestätigt')->assertDontSee('Termin bestätigt');
        $this->actingAs($salonOwner)->get('/admin/audit')->assertOk()->assertSee('Termin bestätigt')->assertDontSee('Reservierung bestätigt');
    }
}
