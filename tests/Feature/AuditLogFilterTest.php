<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class AuditLogFilterTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    private function log(int $tenantId, string $action, ?string $entityType = null, ?int $entityId = null, ?array $old = null, ?array $new = null): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'old_values' => $old,
            'new_values' => $new,
            'created_at' => now(),
        ]);
    }

    public function test_lists_in_the_address_are_ignored(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'guest.updated');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?area[]=guest&entity[]=reservation&entity_id[]=1&action[]=x&from[]=2026-01-01&until[]=x&user_id[]=1')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('guest.updated'));

        $this->actingAs($owner)->get('/admin/audit?from=gestern&entity=reservation&entity_id=abc')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('guest.updated'))
            ->assertDontSee('Reservierung Nr.');

        // Unmögliche Kalendertage gelten als leer (PostgreSQL würde sie mit einem Fehler ablehnen).
        $this->actingAs($owner)->get('/admin/audit?from=2026-02-30&until=2026-13-01')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('guest.updated'))
            ->assertDontSee('Filter aktiv');
    }

    public function test_filters_never_reach_another_tenant(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($other['tenant']->id, 'reservation.cancelled', Reservation::class, 42);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?entity=reservation&entity_id=42&area=reservation')
            ->assertOk()
            ->assertDontSee(AuditLog::labelFor('reservation.cancelled'))
            ->assertSee('Keine passenden Einträge');
    }

    public function test_the_entity_filter_shows_only_one_reservation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.status_changed', Reservation::class, 41);
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 42);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?entity=reservation&entity_id=42')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('reservation.cancelled'))
            ->assertDontSee(AuditLog::labelFor('reservation.status_changed'))
            ->assertSee('Reservierung Nr. 42');
    }

    public function test_an_unknown_entity_is_ignored(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 42);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?entity=App%5CModels%5CUser&entity_id=42')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('reservation.cancelled'));
    }

    public function test_the_area_filter_uses_the_action_prefix(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'table.deleted');
        $this->log($setup['tenant']->id, 'table_block.created');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?area=table')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('table.deleted'))
            ->assertDontSee(AuditLog::labelFor('table_block.created'))
            ->assertSee('<option value="table" selected', false);
    }

    public function test_page_size_comes_from_the_settings(): void
    {
        config(['swayy.audit.per_page' => 10]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach (range(1, 12) as $i) {
            $this->log($setup['tenant']->id, 'guest.updated', null, $i);
        }
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/audit')->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'Gast geändert'));
    }

    public function test_defaults_are_defined_in_one_place(): void
    {
        $this->assertSame(100, config('swayy.audit.per_page'));
        $this->assertSame(100, config('swayy.audit.search_max'));
        $this->assertSame(Reservation::class, config('swayy.audit.entities.reservation'));
    }

    public function test_without_entries_the_page_says_what_will_appear(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit')
            ->assertOk()
            ->assertSee('Noch keine Einträge');

        $this->actingAs($owner)->get('/admin/audit?area=guest')
            ->assertOk()
            ->assertSee('Keine passenden Einträge')
            ->assertSee('Filter zurücksetzen');
    }

    public function test_reservation_detail_links_to_its_history_for_roles_with_audit_access(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $host = $this->createMember($setup['tenant'], 'host');
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(2)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'staff',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $this->clearTenantContext();
        $link = e(route('admin.audit.index', ['entity' => 'reservation', 'entity_id' => $reservation->id]));

        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee($link, false)
            ->assertSee('Alle Änderungen im Protokoll');

        $this->actingAs($host)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertDontSee('Alle Änderungen im Protokoll');
    }

    // ── Freitextsuche ────────────────────────────────────────────────────

    public function test_free_text_finds_entries_by_label_and_values(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'table.updated', null, null, ['name' => 'Tisch 6'], ['name' => 'Tisch 7']);
        $this->log($setup['tenant']->id, 'guest.updated', null, null, ['name' => 'Anna'], ['name' => 'Berta']);
        $this->log($setup['tenant']->id, 'webhook.created');
        $this->clearTenantContext();

        // Ein Wert in new_values: genau ein Eintrag, gleich in welcher Schreibweise.
        foreach (['Tisch 7', 'tisch 7', 'TISCH 7', '  Tisch 7  '] as $term) {
            $this->actingAs($owner)->get('/admin/audit?'.http_build_query(['q' => $term]))
                ->assertOk()
                ->assertSee('Tisch geändert')
                ->assertDontSee('Gast geändert')
                ->assertDontSee('Webhook angelegt');
        }

        // Ein Wert in old_values.
        $this->actingAs($owner)->get('/admin/audit?q=anna')
            ->assertOk()
            ->assertSee('Gast geändert')
            ->assertDontSee('Tisch geändert');

        // Die Bezeichnung der Aktion, auch in Teilen.
        $this->actingAs($owner)->get('/admin/audit?q=webhook+angel')
            ->assertOk()
            ->assertSee('Webhook angelegt')
            ->assertDontSee('Tisch geändert')
            ->assertDontSee('Gast geändert');
        $this->actingAs($owner)->get('/admin/audit?q=GEÄNDERT')
            ->assertOk()
            ->assertSee('Tisch geändert')
            ->assertSee('Gast geändert')
            ->assertDontSee('Webhook angelegt');

        // Der technische Name der Aktion.
        $this->actingAs($owner)->get('/admin/audit?q=guest.upd')
            ->assertOk()
            ->assertSee('Gast geändert')
            ->assertDontSee('Tisch geändert');

        // Ohne Treffer nennt der Leerzustand den Suchbegriff und bietet das Zurücksetzen an.
        $this->actingAs($owner)->get('/admin/audit?q=Nirgendwo')
            ->assertOk()
            ->assertSee('Keine passenden Einträge')
            ->assertSee('Nirgendwo')
            ->assertSee('Filter zurücksetzen')
            ->assertSee('href="'.e(route('admin.audit.index')).'"', false)
            ->assertDontSee('Tisch geändert');
    }

    public function test_free_text_works_together_with_the_other_filters_and_the_entity(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'table.updated', null, null, null, ['name' => 'Tisch 7']);
        $this->log($setup['tenant']->id, 'table_block.created', null, null, null, ['reason' => 'Tisch 7 gesperrt']);
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 42, null, ['reason' => 'Tisch 7 fehlt']);
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 43, null, ['reason' => 'Tisch 7 fehlt']);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?q=Tisch+7&area=table')
            ->assertOk()
            ->assertSee('Tisch geändert')
            ->assertDontSee('Tischsperre angelegt')
            ->assertDontSee('Reservierung storniert');

        $response = $this->actingAs($owner)->get('/admin/audit?q=Tisch+7&entity=reservation&entity_id=42')->assertOk();
        $response->assertSee('Reservierung storniert')
            ->assertSee('Reservierung Nr. 42')
            ->assertDontSee('Tischsperre angelegt')
            ->assertDontSee('Tisch geändert')
            ->assertSee('Filter aktiv')
            ->assertSee('Suche:');
        $this->assertSame(1, substr_count((string) $response->getContent(), 'Reservierung storniert'));
        // Das Suchfeld behält den Begriff, die Abfrage den Eintrag (verstecktes Feld).
        $response->assertSee('value="Tisch 7"', false)
            ->assertSee('name="entity_id" value="42"', false);
    }

    public function test_free_text_never_reaches_another_tenant(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($other['tenant']->id, 'table.updated', null, null, null, ['name' => 'Fremdes Geheimnis']);
        $this->log($setup['tenant']->id, 'guest.updated', null, null, null, ['name' => 'Eigenes']);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?q=Geheimnis')
            ->assertOk()
            ->assertDontSee('Tisch geändert')
            ->assertSee('Keine passenden Einträge');
        $this->actingAs($owner)->get('/admin/audit?q=geändert')
            ->assertOk()
            ->assertSee('Gast geändert')
            ->assertDontSee('Tisch geändert');
    }

    public function test_wildcards_in_the_search_text_are_taken_literally(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'guest.updated', null, null, null, ['note' => 'Rabatt 100% sicher']);
        $this->log($setup['tenant']->id, 'guest.created', null, null, null, ['note' => 'Rabatt 1000 sicher']);
        $this->log($setup['tenant']->id, 'table.updated', null, null, null, ['name' => 'a_b']);
        $this->log($setup['tenant']->id, 'table.created', null, null, null, ['name' => 'axb']);
        $this->log($setup['tenant']->id, 'event.created', null, null, null, ['name' => 'Pfad a!b']);
        $this->log($setup['tenant']->id, 'event.updated', null, null, null, ['name' => 'Pfad a\\b']);
        $this->log($setup['tenant']->id, 'event.deleted', null, null, null, ['name' => 'Pfad anb']);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('100%'))
            ->assertOk()
            ->assertSee('Gast geändert')
            ->assertDontSee('Gast angelegt');
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('a_b'))
            ->assertOk()
            ->assertSee('Tisch geändert')
            ->assertDontSee('Tisch angelegt');
        // Das Zeichen, das die Abfrage als Fluchtzeichen nutzt, und der Rückstrich suchen sich selbst.
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('a!b'))
            ->assertOk()
            ->assertSee('Event angelegt')
            ->assertDontSee('Event geändert')
            ->assertDontSee('Event gelöscht');
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('a\\b'))
            ->assertOk()
            ->assertSee('Event geändert')
            ->assertDontSee('Event angelegt')
            ->assertDontSee('Event gelöscht');
        // Ein einzelnes Prozentzeichen findet nur Einträge mit einem Prozentzeichen.
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('%'))
            ->assertOk()
            ->assertSee('Gast geändert')
            ->assertDontSee('Gast angelegt')
            ->assertDontSee('Tisch geändert');
    }

    public function test_values_with_umlauts_slashes_and_quotes_are_found(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        // Die Spalten halten JSON; Umlaute, Schrägstriche und Anführungszeichen stehen dort maskiert.
        $this->log($setup['tenant']->id, 'webhook.created', null, null, null, ['url' => 'https://beispiel.test/hook', 'name' => 'Müller & Söhne']);
        $this->log($setup['tenant']->id, 'webhook.deleted', null, null, ['name' => 'MÜLLER GMBH'], null);
        $this->log($setup['tenant']->id, 'webhook.updated', null, null, null, ['name' => 'Er sagte "Hallo"']);
        $this->log($setup['tenant']->id, 'guest.updated', null, null, null, ['name' => 'Anderer']);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('beispiel.test/hook'))
            ->assertOk()
            ->assertSee('Webhook angelegt')
            ->assertDontSee('Webhook gelöscht')
            ->assertDontSee('Gast geändert');
        foreach (['müller', 'Müller', 'MÜLLER'] as $term) {
            $this->actingAs($owner)->get('/admin/audit?q='.urlencode($term))
                ->assertOk()
                ->assertSee('Webhook angelegt')
                ->assertSee('Webhook gelöscht')
                ->assertDontSee('Webhook geändert')
                ->assertDontSee('Gast geändert');
        }
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('söhne'))
            ->assertOk()
            ->assertSee('Webhook angelegt')
            ->assertDontSee('Webhook gelöscht');
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('"Hallo"'))
            ->assertOk()
            ->assertSee('Webhook geändert')
            ->assertDontSee('Webhook angelegt');
    }

    public function test_the_search_length_comes_from_the_settings(): void
    {
        config(['swayy.audit.search_max' => 20]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'guest.updated', null, null, null, ['note' => str_repeat('a', 20).'END']);
        $this->log($setup['tenant']->id, 'guest.created', null, null, null, ['note' => str_repeat('a', 19).'b']);
        $this->log($setup['tenant']->id, 'table.updated', null, null, null, ['name' => str_repeat('ä', 20).'END']);
        $this->clearTenantContext();
        $long = str_repeat('a', 20).'XYZ';

        // 23 Zeichen, erlaubt sind 20: Die Suche gilt den ersten 20, das Feld zeigt dieselbe Grenze.
        $this->actingAs($owner)->get('/admin/audit?q='.$long)
            ->assertOk()
            ->assertSee('Gast geändert')
            ->assertDontSee('Gast angelegt')
            ->assertSee('maxlength="20"', false)
            ->assertSee('value="'.str_repeat('a', 20).'"', false)
            ->assertDontSee('XYZ');
        // Die Grenze zählt Zeichen: Auch ein Umlaut ist eins.
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode(str_repeat('ä', 25)))
            ->assertOk()
            ->assertSee('Tisch geändert')
            ->assertDontSee('Gast geändert');

        // Mit einer größeren Grenze gilt der ganze Begriff und findet nichts.
        config(['swayy.audit.search_max' => 100]);
        $this->actingAs($owner)->get('/admin/audit?q='.$long)
            ->assertOk()
            ->assertSee('Keine passenden Einträge')
            ->assertSee('maxlength="100"', false)
            ->assertSee($long);
    }

    public function test_the_search_length_has_bounds_defaults_and_documentation(): void
    {
        [$config, $warnings] = $this->swayyConfig([]);
        $this->assertSame(100, $config['audit']['search_max']);
        $this->assertSame('', $warnings);

        [$config, $warnings] = $this->swayyConfig(['SWAYY_AUDIT_SEARCH_MAX' => '5']);
        $this->assertSame(20, $config['audit']['search_max']);
        $this->assertStringContainsString('SWAYY_AUDIT_SEARCH_MAX', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_AUDIT_SEARCH_MAX' => '500']);
        $this->assertSame(200, $config['audit']['search_max']);

        [$config] = $this->swayyConfig(['SWAYY_AUDIT_SEARCH_MAX' => '50']);
        $this->assertSame(50, $config['audit']['search_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_AUDIT_SEARCH_MAX']);
    }

    public function test_unusable_search_input_is_ignored(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'guest.updated', null, null, null, ['note' => 'abc']);
        $this->clearTenantContext();

        // Listen, Steuerzeichen und ungültige Bytes lassen die Suche leer; die Seite antwortet normal.
        foreach (['q[]=x', 'q%5Ba%5D=x', 'q=%FF%FE', 'q=%00abc', 'q=%20%20'] as $query) {
            $this->actingAs($owner)->get('/admin/audit?'.$query)
                ->assertOk()
                ->assertSee('Gast geändert');
        }
        // Steuerzeichen, Zeilenumbrüche und Tabulatoren zählen als Leerzeichen.
        $this->actingAs($owner)->get('/admin/audit?q=a%00b')->assertOk()->assertSee('Keine passenden Einträge');
        $this->actingAs($owner)->get('/admin/audit?q='.urlencode("a\tb\nc"))->assertOk()->assertSee('Keine passenden Einträge');
    }

    public function test_pages_keep_the_search_text(): void
    {
        config(['swayy.audit.per_page' => 10]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach (range(1, 12) as $i) {
            $this->log($setup['tenant']->id, 'guest.updated', null, $i, null, ['note' => 'Tisch 7']);
        }
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/audit?q=Tisch+7')->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'Gast geändert'));
        // Der Link zur zweiten Seite trägt den Suchtext mit.
        $this->assertMatchesRegularExpression('/href="[^"]*q=Tisch%207&amp;page=2"/', $html);
    }

    public function test_the_filter_area_offers_the_search_field_and_every_area(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/audit')->assertOk();

        $response->assertSee('for="audit-q"', false)
            ->assertSee('Suchen')
            ->assertSee('id="audit-q"', false)
            ->assertSee('name="q"', false)
            ->assertDontSee('Filter aktiv');
        foreach (array_keys(AuditLog::SUBJECTS) as $prefix) {
            $response->assertSee('<option value="'.$prefix.'"', false);
        }
        foreach (['Anmeldung', 'Support', 'Kundenpanel'] as $area) {
            $response->assertSee('>'.$area.'</option>', false);
        }
    }

    public function test_the_action_prefix_filter_stays_available_in_the_address(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'guest.updated');
        $this->log($setup['tenant']->id, 'table.deleted');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?action=guest.')
            ->assertOk()
            ->assertSee('Gast geändert')
            ->assertDontSee('Tisch gelöscht')
            ->assertSee('Aktion:')
            ->assertSee('Filter aktiv');
    }

    public function test_the_search_text_appears_escaped(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?q='.urlencode('<script>alert(1)</script>'))
            ->assertOk()
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false);
    }
}
