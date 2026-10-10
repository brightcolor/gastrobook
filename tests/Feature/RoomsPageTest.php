<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Http\Controllers\Admin\Settings\RoomsController;
use App\Models\AuditLog;
use App\Models\Location;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\TableCombination;
use App\Models\Tenant;
use App\Services\PlanLimitService;
use App\Support\PlanLimitMessage;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RoomsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_rooms_tables_and_drawers(): void
    {
        $setup = $this->createTenantSetup([['min' => 2, 'max' => 4]]);
        $setup['tables'][0]->update(['online_bookable' => false, 'accessible' => true]);
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 20])]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk();

        $room = $setup['room'];
        $table = $setup['tables'][0];
        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('1 von 20 Tischen deines Tarifs genutzt (alle Standorte)')
            ->assertSee('data-drawer-open="raum-'.$room->id.'"', false)
            ->assertSee('data-drawer-open="tisch-neu-'.$room->id.'"', false)
            ->assertSee('id="tisch-'.$table->id.'" data-drawer hidden', false)
            ->assertSee('<span class="sr-only">Nur intern</span>', false)
            ->assertSee('2–4 Personen · kombinierbar · barrierefrei')
            ->assertSee('id="kombinationen"', false)
            ->assertSee('name="high_chair_possible"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="rooms"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_salons_and_the_service_team_are_turned_away(): void
    {
        $salon = $this->createTenantSetup();
        $salon['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($salon['tenant'], 'tenant_owner');
        $restaurant = $this->createTenantSetup();
        $host = $this->createMember($restaurant['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($owner)->getJson('/admin/settings/rooms')
            ->assertForbidden()
            ->assertJsonPath('message', 'Räume und Tische gibt es bei Restaurants. Salons richten unter „Platz & Zeiten“ Leistungen und Mitarbeiter ein.');
        $this->actingAs($host)->get('/admin/settings/rooms')->assertForbidden();

        // Die Navigation des Salons führt die Seite nicht auf.
        $this->actingAs($owner)->get('/admin/settings/tags')->assertOk()->assertDontSee('data-nav-key="rooms"', false);
    }

    public function test_a_new_room_answers_with_its_id(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/rooms', ['name' => 'Wintergarten', 'is_outdoor' => '0'])
            ->assertOk()
            ->assertJsonPath('message', 'Raum angelegt.');

        $room = Room::withoutGlobalScopes()->where('name', 'Wintergarten')->firstOrFail();
        $this->assertSame($room->id, $response->json('room_id'));
    }

    public function test_a_room_with_tables_names_them_and_stays(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/rooms/{$setup['room']->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.room.0', '„'.$setup['room']->name.'“ enthält noch 3 Tische. Bitte zuerst die Tische löschen.');

        $this->assertNotNull($setup['room']->fresh());
    }

    public function test_new_tables_keep_unticked_switches_and_the_high_chair(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/tables', [
            'room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
            'online_bookable' => '1', 'joinable' => '0', 'accessible' => '0', 'high_chair_possible' => '1', 'outdoor' => '0',
        ])->assertOk()->assertExactJson(['message' => 'Tisch angelegt.']);

        $table = RestaurantTable::withoutGlobalScopes()->where('name', 'T9')->firstOrFail();
        $this->assertFalse($table->joinable);
        $this->assertTrue($table->high_chair_possible);
        $this->assertTrue($table->online_bookable);
    }

    public function test_editing_changes_only_the_switches_that_were_sent(): void
    {
        $setup = $this->createTenantSetup();
        $table = $setup['tables'][0];
        $table->update(['outdoor' => true, 'joinable' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/settings/tables/{$table->id}", [
            'name' => 'Fenster', 'min_capacity' => 1, 'max_capacity' => 2, 'joinable' => '0', 'high_chair_possible' => '1',
        ])->assertOk();

        $fresh = $table->fresh();
        $this->assertSame('Fenster', $fresh->name);
        $this->assertFalse($fresh->joinable);
        $this->assertTrue($fresh->high_chair_possible);
        $this->assertTrue($fresh->outdoor);
    }

    public function test_table_size_follows_the_settings(): void
    {
        config(['swayy.tables.capacity_max' => 12]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/settings/tables/{$table->id}", ['name' => 'T1', 'min_capacity' => 2, 'max_capacity' => 14])
            ->assertStatus(422)
            ->assertJsonPath('errors.max_capacity.0', 'Die größte Personenzahl darf höchstens 12 betragen. Bitte eine kleinere Zahl eintragen.');
        $this->actingAs($owner)->putJson("/admin/floorplan/tables/{$table->id}", ['name' => 'T1', 'min_capacity' => 2, 'max_capacity' => 14])
            ->assertStatus(422)
            ->assertJsonPath('errors.max_capacity.0', 'Die größte Personenzahl darf höchstens 12 betragen. Bitte eine kleinere Zahl eintragen.');
        $this->actingAs($owner)->postJson('/admin/floorplan/tables', ['room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 13])
            ->assertStatus(422);
        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->assertSee('max="12"', false);
        $this->actingAs($owner)->get('/admin/floorplan')->assertOk()->assertSee('max="12"', false);
        $this->actingAs($owner)->get('/admin/settings')->assertOk()->assertSee('max="12"', false);
    }

    public function test_a_table_with_an_upcoming_reservation_names_the_count(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $reservation->tables()->attach($table->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/tables/{$table->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.table.0', '„'.$table->name.'“ hat noch eine kommende oder laufende Reservierung. Bitte sie zuerst an einen anderen Tisch legen oder stornieren.');
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservationOn(array $setup, RestaurantTable $table, CarbonImmutable $start, CarbonImmutable $end, ReservationStatus $status): Reservation
    {
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $end->utc(),
            'timezone' => $setup['location']->timezone, 'status' => $status, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $reservation->tables()->attach($table->id);

        return $reservation;
    }

    public function test_a_running_reservation_keeps_the_table_until_it_has_ended(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $now = CarbonImmutable::now($setup['location']->timezone);
        // Die Gäste sitzen seit einer Stunde, die Reservierung endet in einer Stunde.
        $seated = $this->reservationOn($setup, $table, $now->subHour(), $now->addHour(), ReservationStatus::Seated);
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/tables/{$table->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.table.0', '„'.$table->name.'“ hat noch eine kommende oder laufende Reservierung. Bitte sie zuerst an einen anderen Tisch legen oder stornieren.');
        $this->assertFalse($table->fresh()->trashed());
        $this->assertSame(0, AuditLog::where('action', 'table.deleted')->count());

        // Zwei Reservierungen: Die Meldung nennt die Zahl.
        $upcoming = $this->reservationOn($setup, $table, $now->addDay(), $now->addDay()->addHours(2), ReservationStatus::Confirmed);
        $this->actingAs($owner)->deleteJson("/admin/settings/tables/{$table->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.table.0', '„'.$table->name.'“ hat noch 2 kommende oder laufende Reservierungen. Bitte sie zuerst an andere Tische legen oder stornieren.');

        // Endet die laufende Reservierung in der Vergangenheit und liegt keine weitere an, geht das Löschen.
        $seated->update(['start_at' => $now->subHours(3)->utc(), 'end_at' => $now->subMinutes(5)->utc(), 'status' => ReservationStatus::Completed]);
        $upcoming->update(['status' => ReservationStatus::CancelledByRestaurant]);
        $this->actingAs($owner)->deleteJson("/admin/settings/tables/{$table->id}")->assertOk()->assertJsonPath('message', 'Tisch gelöscht.');
        $this->assertTrue($table->fresh()->trashed());
        $this->assertSame(1, AuditLog::where('action', 'table.deleted')->count());
    }

    public function test_a_combination_needs_two_joinable_tables(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tables'][0]->update(['joinable' => true]);
        $setup['tables'][1]->update(['joinable' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => 'Tafel', 'min_capacity' => 4, 'max_capacity' => 8,
            'table_ids' => [$setup['tables'][0]->id, $setup['tables'][1]->id],
        ])->assertStatus(422)
            ->assertJsonPath('errors.table_ids.0', 'Eine Kombination braucht mindestens zwei Tische mit „Kombinierbar“. Bitte zwei solche Tische wählen oder bei einem Tisch „Kombinierbar“ einschalten.');
    }

    public function test_room_size_answers_with_a_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->patchJson("/admin/floorplan/rooms/{$setup['room']->id}/size", ['plan_width_m' => 8, 'plan_height_m' => 12])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Maße von „'.$setup['room']->name.'“ gespeichert.');
    }

    public function test_the_plan_limit_names_a_step_the_role_can_take(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 3])]);
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->postJson('/admin/settings/tables', [
            'room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Dein Tarif umfasst 3 Tische, und alle sind schon angelegt. Für weitere Tische bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.');
    }

    public function test_the_old_settings_page_sends_every_table_switch(): void
    {
        $source = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));

        foreach (['online_bookable', 'joinable', 'accessible', 'high_chair_possible'] as $flag) {
            $this->assertStringContainsString('<input type="hidden" name="'.$flag.'" value="0">', $source, $flag);
        }
    }

    public function test_the_page_uses_no_inline_handlers(): void
    {
        foreach (['rooms', 'partials/room-fields', 'partials/table-fields', 'partials/combination-fields'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/settings/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }

    // ── Meldungen ────────────────────────────────────────────────────────

    public function test_a_rejected_entry_names_the_cause_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/rooms', ['name' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Bitte einen Namen eintragen.');
        $this->actingAs($owner)->postJson('/admin/settings/rooms', ['name' => str_repeat('a', 81)])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 80 Zeichen lang sein. Bitte kürze ihn.');

        $update = fn (array $payload) => $this->actingAs($owner)
            ->putJson("/admin/settings/tables/{$table->id}", $payload + ['name' => 'T1', 'min_capacity' => 1, 'max_capacity' => 4]);

        $update(['name' => str_repeat('a', 41)])
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 40 Zeichen lang sein. Bitte kürze ihn.');
        $update(['min_capacity' => 0])
            ->assertJsonPath('errors.min_capacity.0', 'Die kleinste Personenzahl muss mindestens 1 betragen. Bitte eine größere Zahl eintragen.');
        $update(['min_capacity' => 'viele'])
            ->assertJsonPath('errors.min_capacity.0', 'Bitte die kleinste Personenzahl als ganze Zahl eintragen.');
        $update(['max_capacity' => ''])
            ->assertJsonPath('errors.max_capacity.0', 'Bitte die größte Personenzahl als ganze Zahl eintragen.');
        // Fehlt die kleinste Zahl, meldet nur ihr Feld einen Fehler; ein Vergleich mit einem leeren Wert entfällt.
        foreach (['', 'viele', 3.5] as $unusable) {
            $update(['min_capacity' => $unusable])
                ->assertJsonPath('errors.min_capacity.0', 'Bitte die kleinste Personenzahl als ganze Zahl eintragen.')
                ->assertJsonMissingPath('errors.max_capacity');
        }
        $update(['min_capacity' => 4, 'max_capacity' => 2])
            ->assertJsonPath('errors.max_capacity.0', 'Die größte Personenzahl ist kleiner als die kleinste. Bitte mindestens 4 eintragen.');
        $update(['joinable' => 'vielleicht'])
            ->assertJsonPath('errors.joinable.0', 'Bitte „Kombinierbar“ ein- oder ausschalten.');

        $this->actingAs($owner)->postJson('/admin/settings/tables', ['name' => 'T9', 'min_capacity' => 1, 'max_capacity' => 4])
            ->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Bitte einen Raum wählen.');
        $this->actingAs($owner)->postJson('/admin/settings/combinations', ['name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 8])
            ->assertStatus(422)
            ->assertJsonPath('errors.table_ids.0', 'Bitte mindestens zwei Tische für die Kombination wählen.');
        $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 256, 'table_ids' => [$table->id, $setup['tables'][1]->id],
        ])->assertStatus(422)
            ->assertJsonPath('errors.max_capacity.0', 'Die größte Personenzahl darf höchstens 255 betragen. Bitte eine kleinere Zahl eintragen.');
    }

    public function test_names_follow_the_settings(): void
    {
        config(['swayy.tables.name_max' => 12, 'swayy.tables.room_name_max' => 15, 'swayy.tables.combination_name_max' => 20]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/settings/tables/{$table->id}", ['name' => str_repeat('a', 13), 'min_capacity' => 1, 'max_capacity' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 12 Zeichen lang sein. Bitte kürze ihn.');
        $this->actingAs($owner)->putJson("/admin/settings/tables/{$table->id}", ['name' => str_repeat('a', 12), 'min_capacity' => 1, 'max_capacity' => 2])
            ->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/rooms', ['name' => str_repeat('b', 16)])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 15 Zeichen lang sein. Bitte kürze ihn.');
        $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => str_repeat('c', 21), 'min_capacity' => 2, 'max_capacity' => 8, 'table_ids' => [$setup['tables'][1]->id, $setup['tables'][2]->id],
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 20 Zeichen lang sein. Bitte kürze ihn.');
        $this->actingAs($owner)->postJson('/admin/floorplan/tables', ['room_id' => $setup['room']->id, 'name' => str_repeat('d', 13), 'min_capacity' => 1, 'max_capacity' => 2])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Der Name darf höchstens 12 Zeichen lang sein. Bitte kürze ihn.');

        $html = (string) $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->getContent();
        $this->assertStringContainsString('maxlength="12"', $html);
        $this->assertStringContainsString('maxlength="15"', $html);
        $this->assertStringContainsString('maxlength="20"', $html);

        // Die Felder im Tischplan (neuer Tisch, Tisch bearbeiten, Kombination) folgen denselben Einstellungen.
        $plan = (string) $this->actingAs($owner)->get('/admin/floorplan')->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/<input name="name" required maxlength="12"/', $plan));
        $this->assertMatchesRegularExpression('/<input id="comboNameInput"[^>]*maxlength="20"/', $plan);
    }

    // ── Raummaße und Spaltengrößen ───────────────────────────────────────

    public function test_room_size_follows_the_settings(): void
    {
        config(['swayy.tables.room_size_max_m' => 20]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $url = "/admin/floorplan/rooms/{$setup['room']->id}/size";
        $this->clearTenantContext();

        $this->actingAs($owner)->patchJson($url, ['plan_width_m' => 21, 'plan_height_m' => 5])
            ->assertStatus(422)
            ->assertJsonPath('errors.plan_width_m.0', 'Die Breite darf höchstens 20 Meter betragen. Bitte eine kleinere Zahl eintragen.');
        $this->actingAs($owner)->patchJson($url, ['plan_width_m' => 5, 'plan_height_m' => 20.5])
            ->assertStatus(422)
            ->assertJsonPath('errors.plan_height_m.0', 'Die Tiefe darf höchstens 20 Meter betragen. Bitte eine kleinere Zahl eintragen.');
        $this->actingAs($owner)->patchJson($url, ['plan_width_m' => 0.5])
            ->assertStatus(422)
            ->assertJsonPath('errors.plan_width_m.0', 'Die Breite muss mindestens 1 Meter betragen. Bitte eine größere Zahl eintragen.');
        $this->actingAs($owner)->patchJson($url, ['plan_width' => 2001])->assertStatus(422);
        $this->actingAs($owner)->patchJson($url, ['plan_width_m' => 20, 'plan_height_m' => 20])->assertOk();

        $room = $setup['room']->fresh();
        $this->assertSame(2000, $room->plan_width);
        $this->assertSame(2000, $room->plan_height);
        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->assertSee('max="20"', false);
    }

    public function test_room_size_ends_at_the_columns_of_the_plan(): void
    {
        // rooms.plan_width und plan_height sind smallint (höchstens 32767 cm).
        [$config, $warnings] = $this->swayyConfig(['SWAYY_ROOM_SIZE_MAX_M' => '2000']);
        $this->assertSame(327, $config['tables']['room_size_max_m']);
        $this->assertStringContainsString('SWAYY_ROOM_SIZE_MAX_M', $warnings);

        [$config, $warnings] = $this->swayyConfig(['SWAYY_ROOM_SIZE_MAX_M' => '327']);
        $this->assertSame(327, $config['tables']['room_size_max_m']);
        $this->assertSame('', $warnings);

        [$config] = $this->swayyConfig([]);
        $this->assertLessThanOrEqual(32767, $config['tables']['room_size_max_m'] * 100);
        $this->assertLessThanOrEqual(255, $config['tables']['capacity_max']);
    }

    public function test_the_largest_allowed_values_fit_their_columns(): void
    {
        config(['swayy.tables.capacity_max' => 255, 'swayy.tables.room_size_max_m' => 327]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/settings/tables/{$table->id}", ['name' => 'Saal', 'min_capacity' => 255, 'max_capacity' => 255])->assertOk();
        // Das Maß im Tischplan wächst mit der Platzzahl und bleibt in der smallint-Spalte.
        $this->actingAs($owner)->postJson('/admin/settings/tables', [
            'room_id' => $setup['room']->id, 'name' => 'Festtafel', 'min_capacity' => 255, 'max_capacity' => 255,
        ])->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => 'Halle', 'min_capacity' => 255, 'max_capacity' => 255, 'table_ids' => [$setup['tables'][1]->id, $setup['tables'][2]->id],
        ])->assertOk();
        $this->actingAs($owner)->patchJson("/admin/floorplan/rooms/{$setup['room']->id}/size", ['plan_width_m' => 327, 'plan_height_m' => 327])->assertOk();

        $this->assertSame(255, $table->fresh()->max_capacity);
        $this->assertSame(255, RestaurantTable::withoutGlobalScopes()->where('name', 'Festtafel')->value('max_capacity'));
        $this->assertLessThanOrEqual(32767, RestaurantTable::withoutGlobalScopes()->where('name', 'Festtafel')->value('width'));
        $this->assertSame(255, TableCombination::withoutGlobalScopes()->where('name', 'Halle')->value('max_capacity'));
        $this->assertSame(32700, $setup['room']->fresh()->plan_width);
    }

    // ── Mandanten und Standorte ──────────────────────────────────────────

    public function test_entries_of_other_tenants_and_other_locations_answer_404(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $foreign = $this->createTenantSetup();
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $otherRoom = Room::factory()->create(['location_id' => $otherLocation->id]);
        $otherTable = RestaurantTable::factory()->create(['room_id' => $otherRoom->id]);
        $otherTable2 = RestaurantTable::factory()->create(['room_id' => $otherRoom->id]);
        $otherCombo = $otherLocation->tableCombinations()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Fern', 'min_capacity' => 2, 'max_capacity' => 6]);
        $otherCombo->tables()->sync([$otherTable->id, $otherTable2->id]);
        $foreignCombo = $foreign['location']->tableCombinations()->create(['tenant_id' => $foreign['tenant']->id, 'name' => 'Fremd', 'min_capacity' => 2, 'max_capacity' => 6]);
        $foreignCombo->tables()->sync([$foreign['tables'][0]->id, $foreign['tables'][1]->id]);
        $this->clearTenantContext();

        $entries = [
            ['rooms', $foreign['room']->id], ['rooms', $otherRoom->id],
            ['tables', $foreign['tables'][0]->id], ['tables', $otherTable->id],
            ['combinations', $foreignCombo->id], ['combinations', $otherCombo->id],
        ];
        $payloads = [
            'rooms' => ['name' => 'X'],
            'tables' => ['name' => 'X', 'min_capacity' => 1, 'max_capacity' => 2],
            'combinations' => ['name' => 'X', 'min_capacity' => 2, 'max_capacity' => 4, 'table_ids' => [$setup['tables'][0]->id, $setup['tables'][1]->id]],
        ];
        foreach ($entries as [$kind, $id]) {
            $this->actingAs($owner)->putJson("/admin/settings/{$kind}/{$id}", $payloads[$kind])->assertNotFound();
            $this->actingAs($owner)->deleteJson("/admin/settings/{$kind}/{$id}")->assertNotFound();
        }

        $this->assertNotNull(Room::withoutGlobalScopes()->find($otherRoom->id));
        $this->assertNotNull(RestaurantTable::withoutGlobalScopes()->find($otherTable->id));
        $this->assertNotNull(TableCombination::withoutGlobalScopes()->find($otherCombo->id));
        $this->assertNotNull(TableCombination::withoutGlobalScopes()->find($foreignCombo->id));
        $this->assertSame(0, AuditLog::where('action', 'like', '%.deleted')->count());
    }

    public function test_foreign_ids_in_a_payload_are_refused_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $foreign = $this->createTenantSetup();
        $otherLocation = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $otherRoom = Room::factory()->create(['location_id' => $otherLocation->id]);
        $otherTable = RestaurantTable::factory()->create(['room_id' => $otherRoom->id]);
        $this->clearTenantContext();

        foreach ([$foreign['room']->id, $otherRoom->id, 999999] as $roomId) {
            $this->actingAs($owner)->postJson('/admin/settings/tables', ['room_id' => $roomId, 'name' => 'T9', 'min_capacity' => 1, 'max_capacity' => 4])
                ->assertStatus(422)
                ->assertJsonPath('errors.room_id.0', 'Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.');
        }
        $this->actingAs($owner)->postJson('/admin/floorplan/tables', ['room_id' => $foreign['room']->id, 'name' => 'T9', 'min_capacity' => 1, 'max_capacity' => 4])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden.');
        $this->assertSame(0, RestaurantTable::withoutGlobalScopes()->where('name', 'T9')->count());

        // Zwei eigene Tische und ein fremder: abgelehnt, nichts angelegt.
        foreach ([$foreign['tables'][0]->id, $otherTable->id] as $foreignTable) {
            $this->actingAs($owner)->postJson('/admin/settings/combinations', [
                'name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 8,
                'table_ids' => [$setup['tables'][0]->id, $setup['tables'][1]->id, $foreignTable],
            ])->assertStatus(422)
                ->assertJsonPath('errors.table_ids.0', 'Mindestens ein gewählter Tisch gehört nicht zu diesem Standort oder ist nicht mehr kombinierbar. Bitte die Seite neu laden und die Tische neu wählen.');
        }
        // Ein eigener und ein fremder: zu wenig eigene Tische.
        $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 8, 'table_ids' => [$setup['tables'][0]->id, $foreign['tables'][0]->id],
        ])->assertStatus(422)
            ->assertJsonPath('errors.table_ids.0', 'Eine Kombination braucht mindestens zwei Tische mit „Kombinierbar“. Bitte zwei solche Tische wählen oder bei einem Tisch „Kombinierbar“ einschalten.');
        $this->assertSame(0, TableCombination::withoutGlobalScopes()->where('name', 'Tafel')->count());
    }

    public function test_a_combination_is_stored_with_its_own_joinable_tables(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $ids = [$setup['tables'][0]->id, $setup['tables'][1]->id];

        $response = $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => 'Tafel', 'min_capacity' => 3, 'max_capacity' => 8, 'table_ids' => [...$ids, $ids[0]],
        ])->assertOk()->assertJsonPath('message', 'Tischkombination angelegt.')->assertJsonPath('combination.name', 'Tafel');

        $combination = TableCombination::withoutGlobalScopes()->findOrFail($response->json('combination.id'));
        $this->assertEqualsCanonicalizing($ids, $combination->tables()->pluck('restaurant_tables.id')->all());

        $this->actingAs($owner)->putJson("/admin/settings/combinations/{$combination->id}", [
            'name' => 'Lange Tafel', 'min_capacity' => 4, 'max_capacity' => 12, 'table_ids' => [$setup['tables'][1]->id, $setup['tables'][2]->id],
        ])->assertOk()->assertJsonPath('message', 'Tischkombination aktualisiert.');
        $this->assertEqualsCanonicalizing(
            [$setup['tables'][1]->id, $setup['tables'][2]->id],
            $combination->fresh()->tables()->pluck('restaurant_tables.id')->all(),
        );
        $this->assertSame(1, AuditLog::where('action', 'table_combination.created')->count());
        $this->assertSame(1, AuditLog::where('action', 'table_combination.updated')->count());
    }

    // ── Tarifgrenze ──────────────────────────────────────────────────────

    public function test_the_limit_is_named_for_every_count(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $tenant = $setup['tenant'];

        $message = function (int $limit) use ($tenant, $owner): string {
            $tenant->plan->update(['limits' => array_merge((array) $tenant->plan->limits, ['max_tables' => $limit])]);

            return PlanLimitMessage::tables($tenant->fresh(), $owner);
        };

        $this->assertSame('Dein Tarif umfasst keine Tische. Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.', $message(0));
        $this->assertSame('Dein Tarif umfasst einen Tisch, und der ist schon angelegt. Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.', $message(1));
        $this->assertSame('Dein Tarif umfasst 7 Tische, und alle sind schon angelegt. Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.', $message(7));
    }

    public function test_the_limit_stops_the_last_table_and_the_floorplan_uses_the_same_words(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 4])]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $payload = ['room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4];

        $this->actingAs($owner)->postJson('/admin/settings/tables', $payload)->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/tables', ['name' => 'T10'] + $payload)->assertStatus(422);
        $this->actingAs($owner)->postJson('/admin/floorplan/tables', ['name' => 'T11'] + $payload)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Dein Tarif umfasst 4 Tische, und alle sind schon angelegt. Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.');

        $this->assertSame(4, RestaurantTable::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count());
    }

    public function test_adding_within_the_limit_counts_again_inside_the_lock(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 2])]);
        $service = app(PlanLimitService::class);
        $stale = $setup['tenant']->fresh();
        $make = fn () => RestaurantTable::factory()->create(['room_id' => $setup['room']->id]);

        $this->assertInstanceOf(RestaurantTable::class, $service->createWithinLimit($stale, 'max_tables', $make));

        $called = false;
        $this->assertNull($service->createWithinLimit($stale, 'max_tables', function () use (&$called) {
            $called = true;

            return 'angelegt';
        }));
        $this->assertFalse($called, 'Bei erreichter Grenze läuft das Anlegen nicht.');

        // Die Grenze kommt frisch aus der Datenbank, auch wenn das Modell aus der Anfrage älter ist.
        Plan::query()->whereKey($setup['tenant']->plan_id)->update(['limits' => json_encode(['max_tables' => 3])]);
        $this->assertSame('angelegt', $service->createWithinLimit($stale, 'max_tables', fn () => 'angelegt'));
    }

    public function test_the_limit_check_locks_the_row_of_the_tenant_without_blocking_foreign_keys_on_postgresql(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren gibt es nur unter PostgreSQL (SQLite sperrt die ganze Datei).');
        }

        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        DB::enableQueryLog();
        app(PlanLimitService::class)->createWithinLimit($setup['tenant'], 'max_tables', fn () => 'angelegt');

        $locks = collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "tenants"') && str_contains($sql, 'for no key update'));
        $this->assertCount(1, $locks);

        // Ein 'for update' würde die Fremdschlüsselprüfung jeder Reservierung und jedes Protokolleintrags des Betriebs aufhalten.
        $this->assertCount(0, collect(DB::getQueryLog())->pluck('query')
            ->filter(fn (string $sql) => str_contains($sql, 'from "tenants"') && str_ends_with(trim($sql), 'for update')));
    }

    public function test_a_room_deleted_while_a_table_is_created_is_refused_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $room = Room::factory()->create(['location_id' => $setup['location']->id, 'name' => 'Flüchtig']);
        $this->clearTenantContext();

        $this->roomVanishesBeforeTheTableIsCreated($room->id);

        $this->actingAs($owner)->postJson('/admin/settings/tables', [
            'room_id' => $room->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
        ])->assertStatus(422)
            ->assertJsonPath('errors.room_id.0', 'Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.');

        $this->assertSame(0, RestaurantTable::withoutGlobalScopes()->where('name', 'T9')->count());
        $this->assertSame(0, AuditLog::where('action', 'table.created')->count());
    }

    public function test_a_room_deleted_while_a_table_is_placed_in_the_floorplan_is_refused_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $room = Room::factory()->create(['location_id' => $setup['location']->id, 'name' => 'Flüchtig']);
        $this->clearTenantContext();
        $this->roomVanishesBeforeTheTableIsCreated($room->id);

        $this->actingAs($owner)->postJson('/admin/floorplan/tables', [
            'room_id' => $room->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden.');

        $this->assertSame(0, RestaurantTable::withoutGlobalScopes()->where('name', 'T9')->count());
    }

    /**
     * Der Raum verschwindet zwischen der Prüfung und dem Anlegen, wie bei einem zweiten Fenster:
     * Die Grenzprüfung löscht ihn vor ihrer Sperre und legt dann an.
     */
    private function roomVanishesBeforeTheTableIsCreated(int $roomId): void
    {
        $this->app->bind(PlanLimitService::class, fn () => new class($roomId) extends PlanLimitService
        {
            public function __construct(private readonly int $roomId) {}

            public function createWithinLimit(Tenant $tenant, string $limitKey, Closure $create): mixed
            {
                DB::table('rooms')->where('id', $this->roomId)->delete();

                return parent::createWithinLimit($tenant, $limitKey, $create);
            }
        });
    }

    // ── Kombination mit einem Tisch, der nicht mehr kombinierbar ist ──────

    public function test_a_combination_keeps_a_member_that_is_no_longer_joinable(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        [$one, $two, $three] = $setup['tables'];
        $combo = $setup['location']->tableCombinations()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 6]);
        $combo->tables()->sync([$one->id, $two->id]);
        $two->update(['joinable' => false]);
        $this->clearTenantContext();

        // Die Seitenleiste führt den Tisch weiter auf: angehakt und als „nicht kombinierbar“ benannt, nur in dieser Kombination.
        $html = (string) $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('/name="table_ids\[\]" value="'.$two->id.'"/', $html));
        $this->assertMatchesRegularExpression('/name="table_ids\[\]" value="'.$two->id.'" checked[^>]*>\s*<span[^>]*>T2 <span[^>]*>\([^)]*nicht kombinierbar\)/', $html);
        $this->assertStringContainsString('gehören bereits zur Kombination', $html);

        // Nur den Namen ändern: Der Tisch bleibt in der Kombination.
        $this->actingAs($owner)->putJson("/admin/settings/combinations/{$combo->id}", [
            'name' => 'Lange Tafel', 'min_capacity' => 2, 'max_capacity' => 6, 'table_ids' => [$one->id, $two->id],
        ])->assertOk()->assertJsonPath('combination.name', 'Lange Tafel');
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $combo->fresh()->tables()->pluck('restaurant_tables.id')->all());

        // Neu hinzufügen darf man nur einen kombinierbaren Tisch.
        $three->update(['joinable' => false]);
        $reason = 'Mindestens ein gewählter Tisch gehört nicht zu diesem Standort oder ist nicht mehr kombinierbar. Bitte die Seite neu laden und die Tische neu wählen.';
        $this->actingAs($owner)->putJson("/admin/settings/combinations/{$combo->id}", [
            'name' => 'Lange Tafel', 'min_capacity' => 2, 'max_capacity' => 6, 'table_ids' => [$one->id, $two->id, $three->id],
        ])->assertStatus(422)->assertJsonPath('errors.table_ids.0', $reason);
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $combo->fresh()->tables()->pluck('restaurant_tables.id')->all());

        // Eine neue Kombination nimmt ihn nicht auf, auch wenn er in einer anderen steckt.
        $three->update(['joinable' => true]);
        $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => 'Neu', 'min_capacity' => 2, 'max_capacity' => 6, 'table_ids' => [$one->id, $two->id, $three->id],
        ])->assertStatus(422)->assertJsonPath('errors.table_ids.0', $reason);

        // Wer den Tisch abwählt, nimmt ihn heraus; danach lässt er sich nicht wieder hinzufügen.
        $this->actingAs($owner)->putJson("/admin/settings/combinations/{$combo->id}", [
            'name' => 'Lange Tafel', 'min_capacity' => 2, 'max_capacity' => 6, 'table_ids' => [$one->id, $three->id],
        ])->assertOk();
        $this->actingAs($owner)->putJson("/admin/settings/combinations/{$combo->id}", [
            'name' => 'Lange Tafel', 'min_capacity' => 2, 'max_capacity' => 6, 'table_ids' => [$one->id, $three->id, $two->id],
        ])->assertStatus(422)->assertJsonPath('errors.table_ids.0', $reason);
    }

    // ── Doppelter Klick beim Löschen ─────────────────────────────────────

    public function test_a_second_delete_of_the_same_entry_logs_nothing_more(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $emptyRoom = Room::factory()->create(['location_id' => $setup['location']->id, 'name' => 'Leer']);
        $combo = $setup['location']->tableCombinations()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 6]);
        $combo->tables()->sync([$setup['tables'][0]->id, $setup['tables'][1]->id]);
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $this->actingAs($owner);
        $request = Request::create('/', 'DELETE', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setUserResolver(fn () => $owner);
        $controller = app(RoomsController::class);

        // Beide Aufrufe halten dasselbe Modell, wie zwei Anfragen, die den Eintrag vor dem Löschen geladen haben.
        foreach ([[$emptyRoom, 'deleteRoom', 'room.deleted', 'Raum gelöscht.'],
            [$setup['tables'][2], 'deleteTable', 'table.deleted', 'Tisch gelöscht.'],
            [$combo, 'deleteCombination', 'table_combination.deleted', 'Tischkombination gelöscht.']] as [$model, $method, $action, $message]) {
            foreach ([1, 2] as $run) {
                $response = $controller->{$method}($request, $model);
                $this->assertSame(200, $response->getStatusCode(), "{$method} #{$run}");
                $this->assertSame($message, $response->getData(true)['message']);
            }
            $this->assertSame(1, AuditLog::where('action', $action)->count(), $action);
        }
    }

    // ── Aufbau der Seite ─────────────────────────────────────────────────

    public function test_drawers_and_rows_carry_the_hooks_of_the_script(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tables'][0]->update(['joinable' => true]);
        $setup['tables'][1]->update(['joinable' => true]);
        $combo = $setup['location']->tableCombinations()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Tafel', 'min_capacity' => 2, 'max_capacity' => 6]);
        $combo->tables()->sync([$setup['tables'][0]->id, $setup['tables'][1]->id]);
        $setup['room']->update(['plan_width_m' => 8.5, 'plan_height_m' => 12]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->getContent();
        $room = $setup['room'];

        foreach (['raum-neu', 'raum-'.$room->id, 'tisch-neu-'.$room->id, 'tisch-'.$setup['tables'][0]->id, 'kombi-neu', 'kombi-'.$combo->id] as $drawer) {
            $this->assertStringContainsString('id="'.$drawer.'" data-drawer hidden', $html, $drawer);
        }
        // Formulare zum Anlegen setzen zurück und laden neu; keines trägt die Speicherleiste.
        $this->assertSame(1, preg_match('/<form id="form-tisch-neu-'.$room->id.'"[^>]*>/', $html, $form));
        $this->assertStringContainsString('data-ajax data-reset-after-save data-reload-after-save', $form[0]);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*data-savebar/', $html);
        $this->assertStringContainsString('<input type="hidden" name="room_id" value="'.$room->id.'">', $html);
        // Die Maße des Raums stehen als Zahl im Feld (8,5 m als 8.5, 12 m als 12).
        $this->assertMatchesRegularExpression('/name="plan_width_m"[^>]*value="8\.5"/', $html);
        $this->assertMatchesRegularExpression('/name="plan_height_m"[^>]*value="12"/', $html);
        // Die Kombination führt ihre Tische angehakt auf, die Tische vor dem Löschen eine Rückfrage.
        $this->assertMatchesRegularExpression('/name="table_ids\[\]" value="'.$setup['tables'][0]->id.'" checked/', $html);
        $this->assertStringContainsString('T1 + T2 · 2–6 Personen', $html);
        $this->assertStringContainsString('Kombination „Tafel“ löschen? Die Tische bleiben.', $html);
        $this->assertStringContainsString('Tisch „T1“ löschen? Er verschwindet aus dem Tischplan; vergangene Reservierungen behalten ihn.', $html);
        $this->assertStringContainsString('data-inline-confirm-group', $html);
        // Der Hilfesatz zu „Online buchbar“ beschreibt beide Zustände des Hakens positiv.
        $this->assertStringContainsString('Mit Haken buchen Gäste den Tisch online. Ist der Haken entfernt, vergibt dein Team den Tisch.', $html);
        $this->assertStringNotContainsString('Ohne Haken', $html);
    }

    public function test_counts_read_naturally(): void
    {
        $setup = $this->createTenantSetup([]);
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => null])]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->getContent();
        $this->assertStringContainsString('Keine Tische an diesem Standort', $html);
        $this->assertStringContainsString('Drinnen · Keine Tische', $html);
        $this->assertStringContainsString('Noch keine Tische in diesem Raum.', $html);
        $this->assertStringContainsString('Noch keine Kombinationen', $html);
        $this->assertStringContainsString('Kombinationen brauchen mindestens zwei Tische mit „Kombinierbar“.', $html);
        $this->assertStringContainsString('Raum „'.$setup['room']->name.'“ löschen?', $html);
        $this->assertStringNotContainsString(' 0 Tische', $html);

        RestaurantTable::factory()->create(['room_id' => $setup['room']->id]);
        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()
            ->assertSee('1 Tisch an diesem Standort')
            ->assertSee('Drinnen · 1 Tisch')
            ->assertDontSee('1 Tische');

        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 1])]);
        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()
            ->assertSee('1 von 1 Tisch deines Tarifs genutzt (alle Standorte)');

        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 0])]);
        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()
            ->assertSee('Dein Tarif umfasst keine Tische (alle Standorte)')
            ->assertDontSee('von 0 Tischen');
    }

    public function test_tables_are_listed_in_natural_order(): void
    {
        $setup = $this->createTenantSetup([]);
        foreach (['T10', 'T2', 'T1'] as $name) {
            RestaurantTable::factory()->create(['room_id' => $setup['room']->id, 'name' => $name]);
        }
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->getContent();

        $positions = array_map(fn (string $name) => strpos($html, '>'.$name.'</button>'), ['T1', 'T2', 'T10']);
        $this->assertNotContains(false, $positions);
        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_without_rooms_the_page_offers_the_first_one(): void
    {
        $setup = $this->createTenantSetup([]);
        $setup['room']->delete();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()
            ->assertSee('Noch keine Räume')
            ->assertSee('id="raum-neu" data-drawer hidden', false)
            ->getContent();
        $this->assertMatchesRegularExpression('/Noch keine Räume<\/p>.*?data-drawer-open="raum-neu"[^>]*>Raum hinzufügen<\/button>/s', $html);
    }

    public function test_the_usage_counts_only_the_tables_of_the_tenant(): void
    {
        // Die Zahl „genutzt“ zählt die Tische des eigenen Betriebs über alle Standorte.
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2]]);
        $other = Tenant::factory()->create(['plan_id' => Plan::factory()]);
        $otherLocation = Location::factory()->create(['tenant_id' => $other->id]);
        $otherRoom = Room::factory()->create(['location_id' => $otherLocation->id]);
        RestaurantTable::factory()->count(5)->create(['room_id' => $otherRoom->id]);
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 9])]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()
            ->assertSee('1 von 9 Tischen deines Tarifs genutzt (alle Standorte)');
    }

    /**
     * Lädt config/swayy.php mit den gesetzten Umgebungsvariablen.
     *
     * @param  array<string, string>  $env
     * @return array{0: array<string, mixed>, 1: string} Konfiguration und Warnungen im Fehlerprotokoll
     */
    private function swayyConfig(array $env): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'swayy-config');
        $previous = ini_set('error_log', $file);
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        try {
            $config = require config_path('swayy.php');
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }
            ini_set('error_log', $previous === false ? '' : $previous);
            $warnings = (string) file_get_contents($file);
            @unlink($file);
        }

        return [$config, $warnings];
    }
}
