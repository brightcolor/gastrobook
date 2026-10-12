<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\SpecialOpeningHour;
use App\Models\TableBlock;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ExceptionsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Berlin'));
    }

    public function test_the_page_lists_upcoming_by_month_and_past_separately(): void
    {
        $setup = $this->createTenantSetup();
        $group = (string) Str::uuid();
        foreach (['2026-12-24', '2026-12-25'] as $date) {
            SpecialOpeningHour::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'date' => $date, 'closed' => true, 'label' => 'Weihnachten', 'exception_group' => $group]);
        }
        SpecialOpeningHour::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'date' => '2026-10-03', 'closed' => true, 'label' => 'Feiertag']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk();

        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('Dezember 2026')
            ->assertSee('Weihnachten')
            ->assertSee('Geschlossen')
            ->assertSee('Vergangen')
            ->assertSee('Feiertag')
            ->assertSee('id="ausnahme-neu"', false)
            ->assertSee('id="ausnahme-g-'.$group.'"', false)
            ->assertSee('data-preview-url="'.route('admin.settings.exceptions.preview').'"', false)
            ->assertDontSee('onclick', false);
        $this->assertMatchesRegularExpression('/data-nav-key="exceptions"[^>]*aria-current="page"/', (string) $response->getContent());
        // Navigation und Seitenbeschreibung nennen „Feiertag“ schon vor der Liste; die Reihenfolge gilt für die Bezeichnungen der Einträge.
        $content = (string) $response->getContent();
        $this->assertLessThan(strpos($content, '>Feiertag<'), strpos($content, '>Weihnachten<'));
    }

    public function test_the_empty_state_offers_the_first_exception(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/exceptions')
            ->assertSee('Noch keine Ausnahmen')
            ->assertSee('data-drawer-open="ausnahme-neu"', false);
    }

    public function test_a_table_from_the_query_is_preselected(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][1];
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)->get('/admin/settings/exceptions?tisch='.$table->id)->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="ausnahme-neu-lock_tables-'.$table->id.'"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="ausnahme-neu-effects-lock"[^>]*checked/', $content);
    }

    public function test_a_foreign_table_in_the_query_is_ignored(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)->get('/admin/settings/exceptions?tisch='.$other['tables'][0]->id)->assertOk()->getContent();

        $this->assertStringNotContainsString('lock_tables-'.$other['tables'][0]->id, $content);
        $this->assertDoesNotMatchRegularExpression('/id="ausnahme-neu-effects-lock"[^>]*checked/', $content);
    }

    public function test_old_blackouts_without_form_show_their_note_and_only_delete(): void
    {
        $setup = $this->createTenantSetup();
        $blackout = BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'starts_at' => CarbonImmutable::parse('2026-11-20 18:00', 'Europe/Berlin')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-11-22 12:00', 'Europe/Berlin')->utc(),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/exceptions')
            ->assertSee('Zum Ändern bitte löschen und neu anlegen.')
            ->assertDontSee('id="ausnahme-b-'.$blackout->id.'"', false)
            ->assertSee(route('admin.settings.exceptions.destroy', 'b-'.$blackout->id), false);
    }

    public function test_salons_see_two_effects(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => 'salon']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/exceptions')
            ->assertSee('Andere Öffnungszeiten')
            ->assertDontSee('Weniger Gäste')
            ->assertDontSee('Räume oder Tische sperren');
    }

    public function test_rooms_page_and_floorplan_offer_the_lock_action(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $host = $this->createMember($setup['tenant'], 'host');
        $table = $setup['tables'][0];
        $url = route('admin.settings.exceptions.show', ['tisch' => $table->id]).'#ausnahme-neu';
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/rooms')->assertSee(e($url), false)->assertSee('Sperren…');
        $this->actingAs($owner)->get('/admin/floorplan')->assertSee('data-lock-url="'.route('admin.settings.exceptions.show').'"', false);
        $this->actingAs($host)->get('/admin/floorplan')->assertDontSee('data-lock-url=', false);
    }

    // --- Zusätzliche Prüfungen ---------------------------------------------------

    public function test_the_page_needs_both_permissions(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/exceptions')->assertForbidden();
    }

    public function test_the_page_shows_calendar_sheets_for_one_day_a_range_and_a_range_over_months(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        foreach ([
            ['from' => '2026-12-24', 'label' => 'Heiligabend'],
            ['from' => '2026-12-27', 'until' => '2026-12-29', 'label' => 'Zwischen den Jahren'],
            ['from' => '2026-12-30', 'until' => '2027-01-02', 'label' => 'Jahreswechsel'],
        ] as $data) {
            $this->actingAs($owner)->postJson('/admin/settings/exceptions', $data + ['effects' => ['closed']])->assertOk();
        }

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk();

        $response->assertSee('Heiligabend')
            ->assertSee('<time datetime="2026-12-24">', false)
            ->assertSee('<time datetime="2026-12-27">', false)
            ->assertSee('<time datetime="2026-12-29">', false)
            ->assertSee('<time datetime="2027-01-02">', false)
            ->assertSee('24.12.2026')
            ->assertSee('27.12.2026 bis 29.12.2026')
            ->assertSee('30.12.2026 bis 02.01.2027');
        // Der Dezember hat zwei Einträge, der Januar hat keinen eigenen Abschnitt: Der Eintrag steht im Monat seines Beginns.
        $response->assertSee('Dezember 2026')->assertDontSee('Januar 2027');
    }

    public function test_editable_entries_get_a_drawer_filled_with_their_values(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][2];
        $room = $setup['room'];
        $this->clearTenantContext();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'until' => '2026-12-26', 'label' => 'Betriebsferien', 'effects' => ['hours', 'limit', 'lock'],
            'windows' => [['opens_at' => '11:00', 'closes_at' => '15:00']],
            'limit_covers' => 12, 'limit_room_id' => $room->id, 'limit_from' => '17:00', 'limit_until' => '21:00',
            'lock_tables' => [$table->id], 'lock_from' => '18:00', 'lock_until' => '22:00',
        ])->assertOk();
        $key = 'g-'.SpecialOpeningHour::where('location_id', $setup['location']->id)->value('exception_group');

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk();
        $content = (string) $response->getContent();
        $prefix = 'ausnahme-'.$key;

        $response->assertSee('id="'.$prefix.'"', false)
            ->assertSee('action="'.route('admin.settings.exceptions.update', $key).'"', false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('name="key" value="'.$key.'"', false)
            ->assertSee('value="2026-12-24"', false)
            ->assertSee('value="2026-12-26"', false)
            ->assertSee('value="Betriebsferien"', false)
            ->assertSee('value="11:00"', false)
            ->assertSee('value="15:00"', false)
            ->assertSee('value="12"', false)
            ->assertSee('value="17:00"', false)
            ->assertSee('value="22:00"', false)
            ->assertSee('Höchstens 12 Gäste gleichzeitig (Raum „'.$room->name.'“), 17:00–21:00')
            ->assertSee('Gesperrt: Tisch '.$table->name.', 18:00–22:00')
            ->assertSee('Geöffnet 11:00–15:00');
        foreach (['hours', 'limit', 'lock'] as $effect) {
            $this->assertMatchesRegularExpression('/id="'.preg_quote($prefix, '/').'-effects-'.$effect.'"[^>]*checked/', $content);
        }
        $this->assertDoesNotMatchRegularExpression('/id="'.preg_quote($prefix, '/').'-effects-closed"[^>]*checked/', $content);
        $this->assertMatchesRegularExpression('/id="'.preg_quote($prefix, '/').'-lock_tables-'.$table->id.'"[^>]*checked/', $content);
        $this->assertDoesNotMatchRegularExpression('/id="'.preg_quote($prefix, '/').'-lock_tables-'.$setup['tables'][0]->id.'"[^>]*checked/', $content);
        // Das Formular zum Anlegen sendet keinen Schlüssel.
        $this->assertSame(1, preg_match('/<form id="form-ausnahme-neu".*?<\/form>/s', $content, $creating));
        $this->assertStringNotContainsString('name="key"', $creating[0]);
    }

    public function test_past_entries_offer_only_delete(): void
    {
        $setup = $this->createTenantSetup();
        SpecialOpeningHour::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'date' => '2026-09-20', 'closed' => true, 'label' => 'Betriebsausflug', 'exception_group' => $group = (string) Str::uuid()]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk();

        $response->assertSee('Vergangen (1)')
            ->assertSee('Betriebsausflug')
            ->assertSee(route('admin.settings.exceptions.destroy', 'g-'.$group), false)
            ->assertDontSee('id="ausnahme-g-'.$group.'"', false)
            ->assertDontSee('data-drawer-open="ausnahme-g-'.$group.'"', false);
    }

    public function test_the_delete_question_names_the_entry_and_its_consequence(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'label' => 'Heiligabend', 'effects' => ['closed']])->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-31', 'effects' => ['closed']])->assertOk();

        $this->actingAs($owner)->get('/admin/settings/exceptions')
            ->assertSee('Ausnahme „Heiligabend“ löschen? Swayy nimmt danach wieder Buchungen nach den regulären Zeiten an.')
            ->assertSee('Ausnahme „31.12.2026“ löschen? Swayy nimmt danach wieder Buchungen nach den regulären Zeiten an.')
            ->assertSee('data-reload-after-save', false);
    }

    public function test_the_delete_question_speaks_of_appointments_in_a_salon(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => 'salon']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'label' => 'Heiligabend', 'effects' => ['closed']])->assertOk();

        $this->actingAs($owner)->get('/admin/settings/exceptions')
            ->assertSee('Ausnahme „Heiligabend“ löschen? Swayy nimmt danach wieder Termine nach den regulären Zeiten an.')
            ->assertDontSee('wieder Buchungen');
    }

    public function test_limits_and_delay_come_from_the_settings(): void
    {
        config([
            'swayy.exceptions.label_max' => 33,
            'swayy.exceptions.windows_max' => 2,
            'swayy.exceptions.covers_max' => 77,
            'swayy.ui.preview_delay_ms' => 650,
        ]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk();

        $response->assertSee('maxlength="33"', false)
            ->assertSee('data-rows-max="2"', false)
            ->assertSee('max="77"', false)
            ->assertSee('data-preview-delay="650"', false);
    }

    public function test_the_form_offers_rooms_and_tables_of_this_location_only(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $setup['tables'][1]->update(['is_active' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk();
        $content = (string) $response->getContent();

        $response->assertSee('id="ausnahme-neu-lock_rooms-'.$setup['room']->id.'"', false)
            ->assertSee('id="ausnahme-neu-lock_tables-'.$setup['tables'][0]->id.'"', false)
            ->assertSee('Ganzer Raum „'.$setup['room']->name.'“')
            ->assertSee('Nur: '.$setup['room']->name)
            ->assertSee('Tisch T1 · 1–2 Personen')
            ->assertDontSee('id="ausnahme-neu-lock_tables-'.$setup['tables'][1]->id.'"', false)
            ->assertDontSee('lock_rooms-'.$other['room']->id.'"', false);
        $this->assertStringNotContainsString('lock_tables-'.$other['tables'][0]->id.'"', $content);
    }

    public function test_the_sentence_sits_in_a_live_region_above_the_buttons(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk();

        $response->assertSee('data-effect-sentence', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('Wähle Tage und was anders ist; hier steht dann, was Gäste erleben.')
            ->assertSee('Ausnahme anlegen');
    }

    public function test_salons_speak_of_customers(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => 'salon']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/exceptions')
            ->assertSee('Wähle Tage und was anders ist; hier steht dann, was Kunden erleben.')
            ->assertSee('Trag Feiertage, Betriebsferien oder Tage mit anderen Öffnungszeiten ein. Kunden sehen die Änderung sofort auf der Buchungsseite.')
            ->assertDontSee('Gäste');
    }

    public function test_the_opening_hours_page_links_to_the_exceptions(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/opening-hours')
            ->assertSee('<a href="'.route('admin.settings.exceptions.show').'"', false);
    }

    public function test_the_floorplan_popup_has_no_inline_handler_and_links_to_the_lock_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/floorplan')->assertOk()
            ->assertDontSee('onclick', false)
            ->assertSee('Tisch sperren…')
            ->assertSee('Sperren ansehen…')
            ->assertSee('#ausnahme-neu', false);
    }

    public function test_links_to_the_exceptions_need_both_permissions(): void
    {
        // Fehlt einer Rolle das Recht für Sondertage, führt kein Link zur Seite Ausnahmen: Sie verlangt beide Rechte.
        config(['permissions.roles.location_manager' => array_values(array_diff((array) config('permissions.roles.location_manager'), ['special_hours.manage']))]);
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/exceptions')->assertForbidden();
        $this->actingAs($manager)->get('/admin/settings/rooms')->assertOk()
            ->assertDontSee('Sperren…')
            ->assertDontSee('admin/settings/exceptions', false);
        $this->actingAs($manager)->get('/admin/floorplan')->assertOk()->assertDontSee('data-lock-url=', false);
        $this->actingAs($manager)->get('/admin/settings/opening-hours')->assertOk()
            ->assertSee('trägst du unter')
            ->assertDontSee('<a href="'.route('admin.settings.exceptions.show').'"', false);
    }

    // --- Nachbesserung: Inaktives, Voreinstellung, Tischplan ---------------------------

    public function test_editing_keeps_locks_and_limits_that_point_at_inactive_rooms_and_tables(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2], ['min' => 2, 'max' => 4]]);
        $make = fn (array $attributes) => Room::factory()->create($attributes + ['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id]);
        $limitRoom = $make(['name' => 'Terrasse']);
        $lockRoom = $make(['name' => 'Wintergarten']);
        $cellar = $make(['name' => 'Keller']);
        $cellarTable = RestaurantTable::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'room_id' => $cellar->id,
            'name' => 'K1', 'min_capacity' => 2, 'max_capacity' => 6,
        ]);
        [$activeTable, $inactiveTable] = $setup['tables'];
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'label' => 'Feier', 'effects' => ['limit', 'lock'],
            'limit_covers' => 12, 'limit_room_id' => $limitRoom->id,
            'lock_rooms' => [$lockRoom->id], 'lock_tables' => [$activeTable->id, $inactiveTable->id, $cellarTable->id],
        ])->assertOk();
        $key = 'g-'.BlackoutPeriod::where('location_id', $setup['location']->id)->value('exception_group');
        // Erst nach dem Anlegen werden drei Räume und ein Tisch inaktiv; der Tisch im Keller bleibt aktiv.
        foreach ([$limitRoom, $lockRoom, $cellar] as $room) {
            $room->update(['is_active' => false]);
        }
        $inactiveTable->update(['is_active' => false]);

        $content = (string) $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk()->getContent();
        $prefix = preg_quote('ausnahme-'.$key, '/');

        $this->assertMatchesRegularExpression('/id="'.$prefix.'-lock_rooms-'.$lockRoom->id.'"[^>]*checked/', $content);
        foreach ([$activeTable, $inactiveTable, $cellarTable] as $table) {
            $this->assertMatchesRegularExpression('/id="'.$prefix.'-lock_tables-'.$table->id.'"[^>]*checked/', $content);
        }
        $this->assertMatchesRegularExpression('/<option value="'.$limitRoom->id.'" selected>Nur: Terrasse \(inaktiv\)<\/option>/', $content);
        $this->assertStringContainsString('Ganzer Raum „Wintergarten“ (inaktiv)', $content);
        $this->assertStringContainsString('Tisch '.$inactiveTable->name.' · 2–4 Personen (inaktiv)', $content);
        $this->assertStringContainsString('Tisch K1 · 2–6 Personen (inaktiv)', $content);
        // Das Formular zum Anlegen bietet Inaktives nicht an.
        $this->assertSame(1, preg_match('/<form id="form-ausnahme-neu".*?<\/form>/s', $content, $creating));
        foreach (['lock_rooms-'.$lockRoom->id, 'lock_tables-'.$inactiveTable->id, 'lock_tables-'.$cellarTable->id, 'value="'.$limitRoom->id.'"'] as $needle) {
            $this->assertStringNotContainsString($needle, $creating[0]);
        }

        // Unverändert gespeichert bleiben alle drei Verweise.
        $this->actingAs($owner)->putJson(route('admin.settings.exceptions.update', $key), $this->fieldsOf($content, 'form-ausnahme-'.$key))->assertOk();

        $limit = BlackoutPeriod::where('location_id', $setup['location']->id)->whereNotNull('reduce_covers_to')->sole();
        $this->assertSame([$limitRoom->id, 12], [$limit->room_id, $limit->reduce_covers_to]);
        $this->assertSame([$lockRoom->id], BlackoutPeriod::where('location_id', $setup['location']->id)->whereNull('reduce_covers_to')->pluck('room_id')->all());
        $this->assertEqualsCanonicalizing(
            [$activeTable->id, $inactiveTable->id, $cellarTable->id],
            TableBlock::where('location_id', $setup['location']->id)->pluck('restaurant_table_id')->all(),
        );
    }

    public function test_the_new_entry_form_marks_the_preselected_table_for_the_script(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)->get('/admin/settings/exceptions?tisch='.$table->id)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="ausnahme-neu-effects-lock"[^>]*data-preset="tisch"/', $content);
        $this->assertMatchesRegularExpression('/id="ausnahme-neu-lock_tables-'.$table->id.'"[^>]*data-preset="tisch"/', $content);

        $plain = (string) $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-preset', $plain);
    }

    public function test_the_popup_offers_the_lock_link_for_every_table_state(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)->get('/admin/floorplan')->assertOk()->getContent();

        // Der Link hängt nach dem Zusammenbau des Fensters an, gleich für jeden Zustand; nur gesperrte Tische führen zur Liste.
        $this->assertStringContainsString("const lockLink = t.status === 'blocked' ? 'view' : 'lock';", $content);
        $this->assertStringNotContainsString("lockLink = 'lock';", $content);
    }

    /**
     * Die Felder eines Formulars der Seite, wie ein Browser sie sendet: angehakte Kästchen, gewählte Optionen, alle
     * anderen Felder mit ihrem Wert. Zeilenvorlagen (template) und die Steuerfelder _token und _method fehlen.
     *
     * @return array<string, mixed>
     */
    private function fieldsOf(string $html, string $formId): array
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);
        $form = $xpath->query('//form[@id="'.$formId.'"]')->item(0);
        $this->assertNotNull($form, 'Formular '.$formId.' fehlt.');

        $pairs = [];
        foreach ($xpath->query('.//input|.//select|.//textarea', $form) as $field) {
            $name = $field->getAttribute('name');
            if ($name === '' || in_array($name, ['_token', '_method'], true) || $xpath->query('ancestor::template', $field)->length > 0) {
                continue;
            }
            if ($field->nodeName === 'select') {
                $options = $xpath->query('.//option', $field);
                $value = $options->length > 0 ? $options->item(0)->getAttribute('value') : '';
                foreach ($options as $option) {
                    if ($option->hasAttribute('selected')) {
                        $value = $option->getAttribute('value');
                    }
                }
            } elseif (in_array(strtolower($field->getAttribute('type')), ['checkbox', 'radio'], true)) {
                if (! $field->hasAttribute('checked')) {
                    continue;
                }
                $value = $field->getAttribute('value');
            } else {
                $value = $field->getAttribute('value');
            }
            $pairs[] = rawurlencode($name).'='.rawurlencode($value);
        }
        parse_str(implode('&', $pairs), $data);

        return $data;
    }
}
