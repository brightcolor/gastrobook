<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BlackoutPeriod;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\SpecialOpeningHour;
use App\Models\TableBlock;
use App\Models\User;
use App\Services\ReservationLifecycleService;
use App\Services\ScheduleExceptions;
use App\Services\TimeSlotService;
use App\Support\ScheduleExceptionRules;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class ScheduleExceptionsTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Berlin'));
    }

    /** @return array{0: array, 1: User} */
    private function owner(): array
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['timezone' => 'Europe/Berlin']);
        $setup['location']->settings()->update(['slot_interval_minutes' => 30, 'default_duration_minutes' => 90]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        return [$setup, $owner];
    }

    public function test_closed_over_three_days_is_one_entry_with_one_row_per_day(): void
    {
        [$setup, $owner] = $this->owner();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'until' => '2026-12-26', 'label' => 'Weihnachten', 'effects' => ['closed'],
        ])->assertOk()->assertJsonPath('reload', true);

        $rows = SpecialOpeningHour::where('location_id', $setup['location']->id)->orderBy('date')->get();
        $this->assertCount(3, $rows);
        $this->assertTrue($rows->every(fn ($r) => $r->closed && $r->exception_group === $rows[0]->exception_group && $r->label === 'Weihnachten'));

        $entries = app(ScheduleExceptions::class)->entries($setup['location']->fresh());
        $this->assertSame(['Dezember 2026'], array_keys($entries['upcoming']));
        $entry = $entries['upcoming']['Dezember 2026'][0];
        $this->assertSame(['g-'.$rows[0]->exception_group, '2026-12-24', '2026-12-26'], [$entry['key'], $entry['from'], $entry['until']]);
        $this->assertSame([['type' => 'closed', 'text' => 'Geschlossen']], $entry['effects']);
    }

    public function test_other_hours_write_one_row_per_day_and_window(): void
    {
        [$setup, $owner] = $this->owner();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-31', 'until' => '2027-01-01', 'effects' => ['hours'],
            'windows' => [['opens_at' => '12:00', 'closes_at' => '15:00'], ['opens_at' => '18:00', 'closes_at' => '02:00']],
        ])->assertOk();

        $this->assertSame(4, SpecialOpeningHour::where('location_id', $setup['location']->id)->where('closed', false)->count());
        $entry = app(ScheduleExceptions::class)->entries($setup['location']->fresh())['upcoming']['Dezember 2026'][0];
        $this->assertSame([['type' => 'hours', 'text' => 'Geöffnet 12:00–15:00 und 18:00–02:00']], $entry['effects']);
    }

    public function test_limit_with_times_across_the_end_of_summer_time(): void
    {
        [$setup, $owner] = $this->owner();
        $room = $setup['room'];

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-10-24', 'until' => '2026-10-26', 'label' => 'Messe', 'effects' => ['limit'],
            'limit_covers' => 20, 'limit_room_id' => $room->id, 'limit_from' => '18:00', 'limit_until' => '22:00',
        ])->assertOk();

        $rows = BlackoutPeriod::where('location_id', $setup['location']->id)->orderBy('starts_at')->get();
        $this->assertSame(
            [['2026-10-24 16:00', '2026-10-24 20:00'], ['2026-10-25 17:00', '2026-10-25 21:00'], ['2026-10-26 17:00', '2026-10-26 21:00']],
            $rows->map(fn ($r) => [$r->starts_at->utc()->format('Y-m-d H:i'), $r->ends_at->utc()->format('Y-m-d H:i')])->all(),
        );
        $this->assertTrue($rows->every(fn ($r) => $r->reduce_covers_to === 20 && $r->room_id === $room->id && $r->reason === 'Messe'));
    }

    public function test_lock_rooms_and_tables_without_times_spans_whole_days(): void
    {
        [$setup, $owner] = $this->owner();
        $terrace = Room::factory()->create(['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id, 'name' => 'Terrasse']);

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-02', 'effects' => ['lock'], 'lock_rooms' => [$terrace->id], 'lock_tables' => [$setup['tables'][0]->id],
        ])->assertOk();

        $blackout = BlackoutPeriod::where('location_id', $setup['location']->id)->sole();
        $this->assertSame([$terrace->id, null, '2026-11-01 23:00', '2026-11-02 23:00'], [
            $blackout->room_id, $blackout->reduce_covers_to, $blackout->starts_at->utc()->format('Y-m-d H:i'), $blackout->ends_at->utc()->format('Y-m-d H:i'),
        ]);
        $block = TableBlock::where('location_id', $setup['location']->id)->sole();
        $this->assertSame([$setup['tables'][0]->id, $owner->id, $blackout->exception_group], [$block->restaurant_table_id, $block->created_by, $block->exception_group]);
    }

    public function test_update_replaces_all_rows_and_keeps_the_group(): void
    {
        [$setup, $owner] = $this->owner();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'until' => '2026-12-26', 'effects' => ['closed']])->assertOk();
        $group = SpecialOpeningHour::first()->exception_group;

        $this->actingAs($owner)->putJson('/admin/settings/exceptions/g-'.$group, [
            'from' => '2026-12-24', 'effects' => ['hours'], 'windows' => [['opens_at' => '11:00', 'closes_at' => '14:00']],
        ])->assertOk();

        $rows = SpecialOpeningHour::where('location_id', $setup['location']->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame([$group, false, '11:00'], [$rows[0]->exception_group, (bool) $rows[0]->closed, substr((string) $rows[0]->opens_at, 0, 5)]);
        $this->assertSame(1, AuditLog::where('action', 'exception.updated')->count());
    }

    public function test_an_old_single_row_appears_alone_and_becomes_a_group_when_edited(): void
    {
        [$setup, $owner] = $this->owner();
        $old = SpecialOpeningHour::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'date' => '2026-11-11', 'closed' => true, 'label' => 'Alt']);

        $entry = app(ScheduleExceptions::class)->entries($setup['location']->fresh())['upcoming']['November 2026'][0];
        $this->assertSame('s-'.$old->id, $entry['key']);
        $this->assertTrue($entry['editable']);

        $this->actingAs($owner)->putJson('/admin/settings/exceptions/s-'.$old->id, [
            'from' => '2026-11-11', 'until' => '2026-11-12', 'label' => 'Alt', 'effects' => ['closed'],
        ])->assertOk();

        $this->assertNull(SpecialOpeningHour::find($old->id));
        $this->assertSame(1, SpecialOpeningHour::where('location_id', $setup['location']->id)->distinct()->count('exception_group'));
        $this->assertSame(2, SpecialOpeningHour::where('location_id', $setup['location']->id)->count());
    }

    public function test_an_old_continuous_blackout_can_only_be_deleted(): void
    {
        [$setup] = $this->owner();
        BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'starts_at' => CarbonImmutable::parse('2026-11-20 18:00', 'Europe/Berlin')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-11-22 12:00', 'Europe/Berlin')->utc(),
            'reason' => 'Umbau',
        ]);

        $entry = app(ScheduleExceptions::class)->entries($setup['location']->fresh())['upcoming']['November 2026'][0];

        $this->assertFalse($entry['editable']);
        $this->assertSame('Diese ältere Sperre läuft durchgehend vom 20.11.2026, 18:00 bis 22.11.2026, 12:00. Zum Ändern bitte löschen und neu anlegen.', $entry['note']);
        $this->assertSame([['type' => 'lock', 'text' => 'Ganzer Betrieb gesperrt']], $entry['effects']);
    }

    public function test_delete_removes_every_row_of_the_group(): void
    {
        [$setup, $owner] = $this->owner();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-02', 'until' => '2026-11-03', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][1]->id], 'lock_from' => '12:00', 'lock_until' => '15:00',
        ])->assertOk();
        $group = TableBlock::first()->exception_group;

        $this->actingAs($owner)->deleteJson('/admin/settings/exceptions/g-'.$group)->assertOk()->assertJsonPath('message', 'Ausnahme gelöscht.');

        $this->assertSame(0, TableBlock::count());
    }

    public function test_foreign_rooms_tables_and_keys_are_refused(): void
    {
        [$setup, $owner] = $this->owner();
        $other = $this->createTenantSetup();
        $otherGroup = (string) Str::uuid();
        SpecialOpeningHour::create(['tenant_id' => $other['tenant']->id, 'location_id' => $other['location']->id, 'date' => '2026-11-11', 'closed' => true, 'exception_group' => $otherGroup]);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-02', 'effects' => ['limit', 'lock'], 'limit_covers' => 10, 'limit_room_id' => $other['room']->id,
            'lock_tables' => [$other['tables'][0]->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['limit_room_id', 'lock_tables']);

        $this->actingAs($owner)->putJson('/admin/settings/exceptions/g-'.$otherGroup, ['from' => '2026-11-11', 'effects' => ['closed']])->assertNotFound();
        $this->actingAs($owner)->deleteJson('/admin/settings/exceptions/g-'.$otherGroup)->assertNotFound();
        $this->assertSame(1, SpecialOpeningHour::withoutGlobalScopes()->where('exception_group', $otherGroup)->count());
    }

    public function test_input_errors_name_the_reason(): void
    {
        config(['swayy.exceptions.max_days' => 5]);
        [$setup, $owner] = $this->owner();

        $cases = [
            [['from' => '2026-12-24', 'effects' => ['closed', 'limit'], 'limit_covers' => 5], 'effects', '„Geschlossen“ lässt sich mit keiner anderen Wirkung verbinden. Bitte nur „Geschlossen“ wählen oder es abwählen.'],
            [['from' => '2026-12-01', 'until' => '2026-12-10', 'effects' => ['closed']], 'until', 'Eine Ausnahme umfasst höchstens 5 Tage. Bitte den Zeitraum teilen.'],
            [['from' => '2026-12-10', 'until' => '2026-12-01', 'effects' => ['closed']], 'until', 'Das Ende liegt vor dem Beginn. Bitte ein späteres Datum wählen.'],
            [['from' => '2026-10-01', 'until' => '2026-10-02', 'effects' => ['closed']], 'until', 'Dieser Zeitraum liegt in der Vergangenheit. Bitte ein Datum ab heute wählen.'],
            [['from' => '2026-12-24', 'effects' => ['lock']], 'lock_rooms', 'Bitte mindestens einen Raum oder Tisch wählen.'],
            [['from' => '2026-12-24', 'effects' => ['limit'], 'limit_covers' => 5, 'limit_from' => '18:00'], 'limit_until', 'Bitte beide Uhrzeiten eintragen oder beide leer lassen.'],
            [['from' => '2026-12-24', 'effects' => []], 'effects', 'Bitte wählen, was an diesen Tagen anders ist.'],
        ];

        foreach ($cases as [$payload, $field, $message]) {
            $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions', $payload)->assertStatus(422);
            $this->assertSame($message, $response->json('errors.'.$field.'.0'), json_encode($payload));
        }
        $this->assertSame(0, SpecialOpeningHour::count() + BlackoutPeriod::count() + TableBlock::count());
    }

    public function test_salons_only_get_closed_and_other_hours(): void
    {
        [$setup, $owner] = $this->owner();
        $setup['tenant']->update(['type' => 'salon']);

        $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'effects' => ['limit'], 'limit_covers' => 3,
        ])->assertStatus(422);

        $this->assertSame('Diese Wirkung gibt es für Salons nicht. Bitte „Geschlossen“ oder „Andere Öffnungszeiten“ wählen.', $response->json('errors')['effects.0'][0]);
    }

    public function test_locking_a_table_with_a_booking_is_refused_and_bookings_in_the_period_are_counted(): void
    {
        [$setup, $owner] = $this->owner();
        $start = CarbonImmutable::parse('2026-11-05 19:00', 'Europe/Berlin');
        app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2, 'start_local' => $start, 'source' => 'manual', 'guest_name' => 'Gast', 'table_ids' => [$setup['tables'][0]->id],
        ]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-05', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][0]->id],
        ])->assertStatus(422);
        $this->assertSame('Am 05.11.2026 um 19:00 liegt eine Reservierung auf Tisch T1. Bitte zuerst umbelegen oder stornieren.', $response->json('errors.lock_tables.0'));

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-05', 'effects' => ['limit'], 'limit_covers' => 10,
        ])->assertOk()->assertJsonPath('message', 'Ausnahme gespeichert. In diesem Zeitraum liegt 1 Reservierung; sie bleibt bestehen. Informiere die Gäste bei Bedarf.');
    }

    public function test_the_preview_sentence(): void
    {
        [$setup, $owner] = $this->owner();
        $setup['room']->update(['name' => 'Terrasse']);

        $closed = $this->actingAs($owner)->postJson('/admin/settings/exceptions/preview', ['from' => '2026-12-24', 'until' => '2026-12-26', 'effects' => ['closed']]);
        $this->assertSame('Vom 24.12.2026 bis 26.12.2026 kann niemand online buchen.', $closed->json('sentence'));

        $hours = $this->actingAs($owner)->postJson('/admin/settings/exceptions/preview', ['from' => '2026-12-31', 'effects' => ['hours'], 'windows' => [['opens_at' => '18:00', 'closes_at' => '23:00']]]);
        $this->assertSame('Am 31.12.2026 buchen Gäste zwischen 18:00 und 23:00, letzte Uhrzeit 21:30.', $hours->json('sentence'));

        $mixed = $this->actingAs($owner)->postJson('/admin/settings/exceptions/preview', [
            'from' => '2026-11-07', 'effects' => ['limit', 'lock'], 'limit_covers' => 20, 'limit_from' => '18:00', 'limit_until' => '22:00',
            'lock_rooms' => [$setup['room']->id],
        ]);
        $this->assertSame('Am 07.11.2026 gelten von 18:00 bis 22:00 höchstens 20 Gäste gleichzeitig im ganzen Betrieb; „Terrasse“ ist gesperrt; letzte Uhrzeit 21:30.', $mixed->json('sentence'));
    }

    public function test_a_host_has_no_access(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'effects' => ['closed']])->assertForbidden();
        $this->actingAs($host)->postJson('/admin/settings/exceptions/preview', ['from' => '2026-12-24', 'effects' => ['closed']])->assertForbidden();
    }

    // --- Grenzen, Meldungen und Schlüssel ---------------------------------------------------

    /**
     * Legt eine Reservierung mit Beginn und Dauer in Standortzeit an, ohne Tisch.
     *
     * @param  array<string, mixed>  $setup
     */
    private function booking(array $setup, string $start, int $minutes = 90): Reservation
    {
        $begin = CarbonImmutable::parse($start, 'Europe/Berlin');

        return Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'reservation_date' => $begin->toDateString(), 'start_at' => $begin->utc(), 'end_at' => $begin->addMinutes($minutes)->utc(),
            'guest_name_snapshot' => 'Gast', 'guest_email_snapshot' => null,
        ]);
    }

    public function test_the_limits_stay_within_the_columns_and_are_documented(): void
    {
        // special_opening_hours.label, blackout_periods.reason und table_blocks.reason sind varchar(255), reduce_covers_to ist smallint.
        [$config, $warnings] = $this->swayyConfig([
            'SWAYY_EXCEPTIONS_MAX_DAYS' => '9999', 'SWAYY_EXCEPTIONS_WINDOWS_MAX' => '99', 'SWAYY_EXCEPTIONS_LABEL_MAX' => '4000',
            'SWAYY_EXCEPTIONS_COVERS_MAX' => '99999', 'SWAYY_EXCEPTIONS_PAST_MONTHS' => '999',
        ]);
        $this->assertSame(['max_days' => 366, 'windows_max' => 12, 'label_max' => 200, 'covers_max' => 32767, 'past_months' => 60], $config['exceptions']);
        foreach (['MAX_DAYS', 'WINDOWS_MAX', 'LABEL_MAX', 'COVERS_MAX', 'PAST_MONTHS'] as $name) {
            $this->assertStringContainsString('SWAYY_EXCEPTIONS_'.$name, $warnings);
        }
        $this->assertLessThanOrEqual(255, $config['exceptions']['label_max']);
        $this->assertLessThanOrEqual(32767, $config['exceptions']['covers_max']);

        [$config] = $this->swayyConfig([
            'SWAYY_EXCEPTIONS_MAX_DAYS' => '0', 'SWAYY_EXCEPTIONS_WINDOWS_MAX' => '0', 'SWAYY_EXCEPTIONS_LABEL_MAX' => '1',
            'SWAYY_EXCEPTIONS_COVERS_MAX' => '0', 'SWAYY_EXCEPTIONS_PAST_MONTHS' => '0',
        ]);
        $this->assertSame(['max_days' => 1, 'windows_max' => 1, 'label_max' => 20, 'covers_max' => 1, 'past_months' => 1], $config['exceptions']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(['max_days' => 93, 'windows_max' => 6, 'label_max' => 120, 'covers_max' => 10000, 'past_months' => 12], $config['exceptions']);

        $this->assertDocumentedInEnvExample([
            'SWAYY_EXCEPTIONS_MAX_DAYS', 'SWAYY_EXCEPTIONS_WINDOWS_MAX', 'SWAYY_EXCEPTIONS_LABEL_MAX',
            'SWAYY_EXCEPTIONS_COVERS_MAX', 'SWAYY_EXCEPTIONS_PAST_MONTHS',
        ]);
    }

    public function test_the_largest_allowed_values_are_stored_and_read_back(): void
    {
        config([
            'swayy.exceptions.max_days' => 366, 'swayy.exceptions.windows_max' => 12,
            'swayy.exceptions.label_max' => 200, 'swayy.exceptions.covers_max' => 32767,
        ]);
        [$setup, $owner] = $this->owner();
        $label = str_repeat('ä', 200);

        // 366 Tage: 12.10.2026 bis 12.10.2027. Die Grenze der Öffnungszeiten (6 Fenster je Tag) gilt hier nicht.
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-10-12', 'until' => '2027-10-12', 'label' => $label, 'effects' => ['closed'],
        ])->assertOk();
        $this->assertSame(366, SpecialOpeningHour::where('location_id', $setup['location']->id)->count());
        $this->assertSame($label, app(ScheduleExceptions::class)->entries($setup['location']->fresh())['upcoming']['Oktober 2026'][0]['label']);

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-10-12', 'until' => '2027-10-13', 'effects' => ['closed'],
        ])->assertStatus(422)->assertJsonPath('errors.until.0', 'Eine Ausnahme umfasst höchstens 366 Tage. Bitte den Zeitraum teilen.');

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-02', 'label' => $label, 'effects' => ['limit'], 'limit_covers' => 32767,
        ])->assertOk();
        $blackout = BlackoutPeriod::where('location_id', $setup['location']->id)->sole();
        $this->assertSame([32767, $label], [$blackout->reduce_covers_to, $blackout->reason]);

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-03', 'label' => $label.'x', 'effects' => ['limit'], 'limit_covers' => 32768,
        ])->assertStatus(422)
            ->assertJsonPath('errors.label.0', 'Die Bezeichnung darf höchstens 200 Zeichen haben. Bitte kürzer fassen.')
            ->assertJsonPath('errors.limit_covers.0', 'Die Höchstzahl der Gäste darf höchstens 32767 betragen. Bitte eine kleinere Zahl eintragen.');

        $windows = fn (int $count) => array_map(fn (int $hour) => ['opens_at' => sprintf('%02d:00', $hour), 'closes_at' => sprintf('%02d:30', $hour)], range(0, $count - 1));
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2027-11-04', 'effects' => ['hours'], 'windows' => $windows(12),
        ])->assertOk();
        $this->assertSame(12, SpecialOpeningHour::where('location_id', $setup['location']->id)->whereDate('date', '2027-11-04')->count());
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2027-11-05', 'effects' => ['hours'], 'windows' => $windows(13),
        ])->assertStatus(422)->assertJsonPath('errors.windows.0', 'Höchstens 12 Zeitfenster. Bitte Fenster zusammenlegen.');
    }

    public function test_the_limits_apply_with_other_values_than_the_defaults(): void
    {
        config(['swayy.exceptions.windows_max' => 2, 'swayy.exceptions.label_max' => 20, 'swayy.exceptions.covers_max' => 50]);
        [, $owner] = $this->owner();

        $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-04', 'label' => str_repeat('x', 21), 'effects' => ['limit', 'hours'], 'limit_covers' => 51,
            'windows' => [['opens_at' => '10:00', 'closes_at' => '11:00'], ['opens_at' => '12:00', 'closes_at' => '13:00'], ['opens_at' => '14:00', 'closes_at' => '15:00']],
        ])->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertSame('Die Bezeichnung darf höchstens 20 Zeichen haben. Bitte kürzer fassen.', $errors['label'][0]);
        $this->assertSame('Die Höchstzahl der Gäste darf höchstens 50 betragen. Bitte eine kleinere Zahl eintragen.', $errors['limit_covers'][0]);

        // Die Grenze für Fenster prüft erst, wenn die einzelnen Felder stimmen.
        $windows = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-04', 'effects' => ['hours'],
            'windows' => [['opens_at' => '10:00', 'closes_at' => '11:00'], ['opens_at' => '12:00', 'closes_at' => '13:00'], ['opens_at' => '14:00', 'closes_at' => '15:00']],
        ])->assertStatus(422);
        $this->assertSame('Höchstens 2 Zeitfenster. Bitte Fenster zusammenlegen.', $windows->json('errors.windows.0'));
    }

    public function test_every_rule_has_its_own_message(): void
    {
        foreach ([false, true] as $salon) {
            $messages = ScheduleExceptionRules::messages($salon);
            foreach (ScheduleExceptionRules::rules($salon) as $field => $rules) {
                foreach ($rules as $rule) {
                    $name = Str::before($rule, ':');
                    if ($name !== 'nullable') {
                        $this->assertArrayHasKey("{$field}.{$name}", $messages, "{$field}.{$name}");
                    }
                }
            }
        }
    }

    public function test_field_errors_come_with_texts_that_name_the_next_step(): void
    {
        [, $owner] = $this->owner();

        $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '24.12.2026', 'until' => 'morgen', 'label' => ['x'], 'effects' => 'closed',
            'limit_covers' => 'viele', 'limit_room_id' => 'a', 'limit_from' => '6 Uhr', 'lock_rooms' => 'a', 'lock_tables' => ['t1'],
        ])->assertStatus(422);

        $errors = $response->json('errors');
        $this->assertSame('Bitte das Datum als TT.MM.JJJJ wählen.', $errors['from'][0]);
        $this->assertSame('Bitte das Datum als TT.MM.JJJJ wählen.', $errors['until'][0]);
        $this->assertSame('Bitte die Bezeichnung als Text eintragen.', $errors['label'][0]);
        $this->assertSame('Die Auswahl hat ein unbekanntes Format. Bitte die Seite neu laden.', $errors['effects'][0]);
        $this->assertSame('Bitte die Zahl der Gäste als ganze Zahl eintragen.', $errors['limit_covers'][0]);
        $this->assertSame('Dieser Raum ist unbekannt. Bitte die Seite neu laden und einen Raum aus der Liste wählen.', $errors['limit_room_id'][0]);
        $this->assertSame('Bitte die Uhrzeit als HH:MM eintragen, etwa 18:00.', $errors['limit_from'][0]);
        $this->assertSame('Die Auswahl hat ein unbekanntes Format. Bitte die Seite neu laden.', $errors['lock_rooms'][0]);
        $this->assertSame('Dieser Tisch ist unbekannt. Bitte die Seite neu laden und Tische aus der Liste wählen.', $errors['lock_tables.0'][0]);

        $missing = $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['effects' => ['closed']])->assertStatus(422);
        $this->assertSame('Bitte den ersten Tag eintragen.', $missing->json('errors.from.0'));
        $absent = $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24'])->assertStatus(422);
        $this->assertSame('Bitte wählen, was an diesen Tagen anders ist.', $absent->json('errors.effects.0'));
        $unknown = $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'effects' => ['party']])->assertStatus(422);
        $this->assertSame('Diese Wirkung ist unbekannt. Bitte die Seite neu laden.', $unknown->json('errors')['effects.0'][0]);
    }

    public function test_the_windows_are_checked_against_each_other(): void
    {
        [, $owner] = $this->owner();

        $overlap = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'effects' => ['hours'],
            'windows' => [['opens_at' => '12:00', 'closes_at' => '15:00'], ['opens_at' => '14:00', 'closes_at' => '16:00']],
        ])->assertStatus(422);
        $this->assertSame('Dieses Zeitfenster überschneidet sich mit 12:00–15:00 am selben Tag. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.', $overlap->json('errors')['windows.1.opens_at'][0]);

        $same = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'effects' => ['hours'], 'windows' => [['opens_at' => '12:00', 'closes_at' => '12:00']],
        ])->assertStatus(422);
        $this->assertSame('Öffnet und schließt zur selben Uhrzeit. Bitte eine andere Schließzeit wählen.', $same->json('errors')['windows.0.closes_at'][0]);

        $none = $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'effects' => ['hours'], 'windows' => []])->assertStatus(422);
        $this->assertSame('Bitte mindestens ein Zeitfenster eintragen.', $none->json('errors.windows.0'));

        $incomplete = $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'effects' => ['hours'], 'windows' => [['opens_at' => '12:00']]])->assertStatus(422);
        $this->assertSame('Bitte bei jedem Zeitfenster eintragen, wann es endet.', $incomplete->json('errors')['windows.0.closes_at'][0]);

        // Ein Fenster über Mitternacht reicht in den Folgetag desselben Datums; berühren dürfen sich Fenster.
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'effects' => ['hours'], 'windows' => [['opens_at' => '00:00', 'closes_at' => '02:00'], ['opens_at' => '18:00', 'closes_at' => '02:00']],
        ])->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-25', 'effects' => ['hours'], 'windows' => [['opens_at' => '02:00', 'closes_at' => '04:00'], ['opens_at' => '04:00', 'closes_at' => '06:00']],
        ])->assertOk();
    }

    public function test_fields_of_effects_that_were_not_chosen_are_ignored(): void
    {
        [$setup, $owner] = $this->owner();
        $other = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'effects' => ['closed'], 'limit_covers' => 5, 'limit_from' => '18:00',
            'lock_tables' => [$other['tables'][0]->id], 'lock_from' => '10:00', 'windows' => [['opens_at' => '10:00', 'closes_at' => '12:00']],
        ])->assertOk();

        $this->assertSame(1, SpecialOpeningHour::where('location_id', $setup['location']->id)->where('closed', true)->count());
        $this->assertSame(0, SpecialOpeningHour::where('location_id', $setup['location']->id)->where('closed', false)->count());
        $this->assertSame(0, BlackoutPeriod::count() + TableBlock::count());
    }

    public function test_rooms_and_tables_of_another_location_of_the_same_business_are_refused(): void
    {
        [$setup, $owner] = $this->owner();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $foreignRoom = Room::factory()->create(['location_id' => $second->id, 'tenant_id' => $setup['tenant']->id]);
        $foreignTable = RestaurantTable::factory()->create(['location_id' => $second->id, 'tenant_id' => $setup['tenant']->id, 'room_id' => $foreignRoom->id]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-02', 'effects' => ['lock'], 'lock_rooms' => [$setup['room']->id, $foreignRoom->id], 'lock_tables' => [$foreignTable->id],
        ])->assertStatus(422);

        $this->assertSame('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.', $response->json('errors.lock_rooms.0'));
        $this->assertSame('Mindestens ein Tisch gehört nicht zu diesem Standort. Bitte die Seite neu laden und Tische aus der Liste wählen.', $response->json('errors.lock_tables.0'));
        $this->assertSame(0, BlackoutPeriod::count() + TableBlock::count());
    }

    public function test_a_deleted_table_is_refused_too(): void
    {
        [$setup, $owner] = $this->owner();
        $setup['tables'][2]->delete();
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-02', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][2]->id],
        ])->assertStatus(422)->assertJsonValidationErrors(['lock_tables']);
    }

    public function test_malformed_and_unknown_keys_answer_404(): void
    {
        [$setup, $owner] = $this->owner();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $special = SpecialOpeningHour::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $second->id, 'date' => '2026-11-11', 'closed' => true]);
        $blackout = BlackoutPeriod::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $second->id, 'starts_at' => '2026-11-11 10:00', 'ends_at' => '2026-11-11 12:00']);
        $block = TableBlock::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $second->id, 'restaurant_table_id' => $setup['tables'][0]->id, 'starts_at' => '2026-11-11 10:00', 'ends_at' => '2026-11-11 12:00']);
        $this->clearTenantContext();

        $keys = [
            'g-not-a-uuid', 'g-'.Str::uuid(), 's-0', 's-007', 's-99999999999999999999999', 's-abc', 'b-1-2', 't-0',
            's-'.$special->id, 'b-'.$blackout->id, 't-'.$block->id, 'x-1',
        ];
        foreach ($keys as $key) {
            $this->actingAs($owner)->putJson('/admin/settings/exceptions/'.$key, ['from' => '2026-12-24', 'effects' => ['closed']])->assertNotFound();
            $this->actingAs($owner)->deleteJson('/admin/settings/exceptions/'.$key)->assertNotFound();
        }
        $this->assertSame([1, 1, 1], [
            SpecialOpeningHour::withoutGlobalScopes()->count(), BlackoutPeriod::withoutGlobalScopes()->count(), TableBlock::withoutGlobalScopes()->count(),
        ]);
        $this->assertSame(['upcoming' => [], 'past' => []], app(ScheduleExceptions::class)->entries($setup['location']->fresh()));
        $this->assertNull(app(ScheduleExceptions::class)->find($setup['location']->fresh(), 'b-'.$blackout->id));
    }

    // --- Ältere Zeilen, Formular und Liste ----------------------------------------------------

    public function test_an_old_blackout_or_block_that_fits_the_form_is_editable(): void
    {
        [$setup, $owner] = $this->owner();
        $setup['room']->update(['name' => 'Saal']);
        $terrace = Room::factory()->create(['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id, 'name' => 'Terrasse']);
        $berlin = fn (string $moment) => CarbonImmutable::parse($moment, 'Europe/Berlin')->utc();
        $blackout = fn (array $values) => BlackoutPeriod::create($values + ['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id]);

        $days = $blackout(['starts_at' => $berlin('2026-11-03 00:00'), 'ends_at' => $berlin('2026-11-06 00:00'), 'reduce_covers_to' => 15]);
        $night = $blackout(['starts_at' => $berlin('2026-11-07 18:00'), 'ends_at' => $berlin('2026-11-08 02:00'), 'room_id' => $terrace->id]);
        $whole = $blackout(['starts_at' => $berlin('2026-11-09 00:00'), 'ends_at' => $berlin('2026-11-10 00:00')]);
        $table = TableBlock::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'restaurant_table_id' => $setup['tables'][0]->id,
            'starts_at' => $berlin('2026-11-12 18:00'), 'ends_at' => $berlin('2026-11-12 22:00'),
        ]);
        $service = app(ScheduleExceptions::class);
        $location = $setup['location']->fresh();

        $entry = $service->find($location, 'b-'.$days->id);
        $this->assertSame(['2026-11-03', '2026-11-05', true, null], [$entry['from'], $entry['until'], $entry['editable'], $entry['note']]);
        $this->assertSame([['type' => 'limit', 'text' => 'Höchstens 15 Gäste gleichzeitig (ganzer Betrieb)']], $entry['effects']);
        $this->assertSame(
            ['from' => '2026-11-03', 'until' => '2026-11-05', 'label' => null, 'effects' => ['limit'], 'windows' => [], 'limit_covers' => 15, 'limit_room_id' => null,
                'limit_from' => null, 'limit_until' => null, 'lock_rooms' => [], 'lock_tables' => [], 'lock_from' => null, 'lock_until' => null],
            $entry['form'],
        );

        $entry = $service->find($location, 'b-'.$night->id);
        $this->assertSame(['2026-11-07', '2026-11-07', true], [$entry['from'], $entry['until'], $entry['editable']]);
        $this->assertSame([['type' => 'lock', 'text' => 'Gesperrt: „Terrasse“, 18:00–02:00']], $entry['effects']);
        $this->assertSame([[$terrace->id], '18:00', '02:00', null], [$entry['form']['lock_rooms'], $entry['form']['lock_from'], $entry['form']['lock_until'], $entry['form']['until']]);

        $entry = $service->find($location, 'b-'.$whole->id);
        $this->assertFalse($entry['editable']);
        $this->assertSame('Diese ältere Sperre schließt den ganzen Betrieb. Zum Ändern bitte löschen und als „Geschlossen“ oder „Andere Öffnungszeiten“ neu anlegen.', $entry['note']);
        $this->assertSame([['type' => 'lock', 'text' => 'Ganzer Betrieb gesperrt']], $entry['effects']);

        $entry = $service->find($location, 't-'.$table->id);
        $this->assertSame([['type' => 'lock', 'text' => 'Gesperrt: Tisch T1, 18:00–22:00']], $entry['effects']);
        $this->assertSame([true, [$setup['tables'][0]->id], '18:00', '22:00'], [$entry['editable'], $entry['form']['lock_tables'], $entry['form']['lock_from'], $entry['form']['lock_until']]);

        // Das Formular ersetzt eine solche Zeile durch eine Gruppe.
        $this->actingAs($owner)->putJson('/admin/settings/exceptions/b-'.$days->id, [
            'from' => '2026-11-03', 'until' => '2026-11-05', 'effects' => ['limit'], 'limit_covers' => 20,
        ])->assertOk();
        $this->assertNull(BlackoutPeriod::find($days->id));
        $group = BlackoutPeriod::where('reduce_covers_to', 20)->sole();
        $this->assertNotNull($group->exception_group);
        $this->assertSame('2026-11-02 23:00', $group->starts_at->utc()->format('Y-m-d H:i'));

        // Eine Sperre des ganzen Betriebs und eine Tischsperre lassen sich löschen.
        $this->actingAs($owner)->deleteJson('/admin/settings/exceptions/b-'.$whole->id)->assertOk();
        $this->assertNull(BlackoutPeriod::find($whole->id));
        $this->actingAs($owner)->deleteJson('/admin/settings/exceptions/t-'.$table->id)->assertOk();
        $this->assertSame(0, TableBlock::count());
    }

    public function test_the_form_gives_back_what_was_saved(): void
    {
        [$setup, $owner] = $this->owner();
        $setup['room']->update(['name' => 'Saal']);
        $terrace = Room::factory()->create(['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id, 'name' => 'Terrasse']);

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-09', 'until' => '2026-11-10', 'label' => 'Fest', 'effects' => ['lock', 'hours', 'limit'],
            'windows' => [['opens_at' => '18:00', 'closes_at' => '23:00'], ['opens_at' => '12:00', 'closes_at' => '15:00']],
            'limit_covers' => 30, 'limit_room_id' => $setup['room']->id, 'limit_from' => '18:00', 'limit_until' => '22:00',
            'lock_rooms' => [$terrace->id], 'lock_tables' => [$setup['tables'][1]->id], 'lock_from' => '20:00', 'lock_until' => '02:00',
        ])->assertOk();

        $this->assertSame([4, 4, 2], [SpecialOpeningHour::count(), BlackoutPeriod::count(), TableBlock::count()]);
        $entry = app(ScheduleExceptions::class)->entries($setup['location']->fresh())['upcoming']['November 2026'][0];
        $this->assertSame(['2026-11-09', '2026-11-10', 'Fest', true, null], [$entry['from'], $entry['until'], $entry['label'], $entry['editable'], $entry['note']]);
        $this->assertSame([
            ['type' => 'hours', 'text' => 'Geöffnet 12:00–15:00 und 18:00–23:00'],
            ['type' => 'limit', 'text' => 'Höchstens 30 Gäste gleichzeitig (Raum „Saal“), 18:00–22:00'],
            ['type' => 'lock', 'text' => 'Gesperrt: „Terrasse“, Tisch T2, 20:00–02:00'],
        ], $entry['effects']);
        $this->assertSame([
            'from' => '2026-11-09', 'until' => '2026-11-10', 'label' => 'Fest', 'effects' => ['hours', 'limit', 'lock'],
            'windows' => [['opens_at' => '12:00', 'closes_at' => '15:00'], ['opens_at' => '18:00', 'closes_at' => '23:00']],
            'limit_covers' => 30, 'limit_room_id' => $setup['room']->id, 'limit_from' => '18:00', 'limit_until' => '22:00',
            'lock_rooms' => [$terrace->id], 'lock_tables' => [$setup['tables'][1]->id], 'lock_from' => '20:00', 'lock_until' => '02:00',
        ], $entry['form']);

        // Das Formular, unverändert gesendet, ergibt dieselben Zeilen.
        $form = $entry['form'];
        $this->actingAs($owner)->putJson('/admin/settings/exceptions/'.$entry['key'], $form)->assertOk();
        $this->assertSame([4, 4, 2], [SpecialOpeningHour::count(), BlackoutPeriod::count(), TableBlock::count()]);
        $this->assertSame($form, app(ScheduleExceptions::class)->find($setup['location']->fresh(), $entry['key'])['form']);
    }

    public function test_the_list_sorts_by_month_and_puts_a_running_entry_under_this_month(): void
    {
        [$setup] = $this->owner();
        $row = fn (string $date, string $label) => SpecialOpeningHour::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'date' => $date, 'closed' => true, 'label' => $label,
        ]);
        $row('2026-12-24', 'Weihnachten');
        $row('2026-11-20', 'November');
        $row('2026-10-30', 'Spät');
        $row('2026-10-05', 'Vorbei');
        BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'reduce_covers_to' => 5, 'reason' => 'Laufend',
            'exception_group' => (string) Str::uuid(),
            'starts_at' => CarbonImmutable::parse('2026-09-25 00:00', 'Europe/Berlin')->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-21 00:00', 'Europe/Berlin')->utc(),
        ]);

        $entries = app(ScheduleExceptions::class)->entries($setup['location']->fresh());

        $this->assertSame(['Oktober 2026', 'November 2026', 'Dezember 2026'], array_keys($entries['upcoming']));
        $this->assertSame(['Laufend', 'Spät'], array_column($entries['upcoming']['Oktober 2026'], 'label'));
        $this->assertSame(['2026-09-25', '2026-10-20'], [$entries['upcoming']['Oktober 2026'][0]['from'], $entries['upcoming']['Oktober 2026'][0]['until']]);
        $this->assertSame(['Vorbei'], array_column($entries['past'], 'label'));
    }

    public function test_the_past_list_reaches_back_as_far_as_configured(): void
    {
        config(['swayy.exceptions.past_months' => 2]);
        [$setup] = $this->owner();
        $service = app(ScheduleExceptions::class);
        $row = fn (string $date, string $label, ?string $group = null) => SpecialOpeningHour::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'date' => $date, 'closed' => true, 'label' => $label, 'exception_group' => $group,
        ]);
        $group = (string) Str::uuid();
        $row('2026-08-11', 'Zu alt');
        $row('2026-08-12', 'Grenze');
        foreach (['2026-08-10', '2026-08-11', '2026-08-12', '2026-08-13'] as $date) {
            $row($date, 'Ferien', $group);
        }

        $past = $service->entries($setup['location']->fresh())['past'];

        // Eine Gruppe mit Zeilen im Zeitraum kommt vollständig, mit ihrem ersten Tag.
        $this->assertSame(['Grenze', 'Ferien'], array_column($past, 'label'));
        $this->assertSame(['2026-08-10', '2026-08-13'], [$past[1]['from'], $past[1]['until']]);

        config(['swayy.exceptions.past_months' => 3]);
        $this->assertSame(['Grenze', 'Zu alt', 'Ferien'], array_column($service->entries($setup['location']->fresh())['past'], 'label'));
    }

    // --- Reservierungen im Zeitraum -----------------------------------------------------------

    public function test_a_save_in_conflict_leaves_the_old_rows_untouched(): void
    {
        [$setup, $owner] = $this->owner();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-11-05', 'until' => '2026-11-06', 'label' => 'Alt', 'effects' => ['closed']])->assertOk();
        $key = 'g-'.SpecialOpeningHour::first()->exception_group;
        $this->booking($setup, '2026-11-06 19:00')->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/exceptions/'.$key, [
            'from' => '2026-11-05', 'until' => '2026-11-06', 'label' => 'Neu', 'effects' => ['lock'], 'lock_rooms' => [$setup['room']->id], 'lock_tables' => [$setup['tables'][0]->id],
        ])->assertStatus(422)->assertJsonPath('errors.lock_tables.0', 'Am 06.11.2026 um 19:00 liegt eine Reservierung auf Tisch T1. Bitte zuerst umbelegen oder stornieren.');

        $this->assertSame(['Alt', 'Alt'], SpecialOpeningHour::pluck('label')->all());
        $this->assertSame(0, BlackoutPeriod::count() + TableBlock::count());
        $this->assertSame(0, AuditLog::where('action', 'exception.updated')->count());

        // Die Reservierung läuft von 19:00 bis 20:30. Eine Sperre, die genau dort endet oder beginnt, steht nicht im Weg.
        foreach ([['20:30', '23:00'], ['17:00', '19:00']] as [$from, $until]) {
            $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
                'from' => '2026-11-06', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][0]->id], 'lock_from' => $from, 'lock_until' => $until,
            ])->assertOk();
        }
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-06', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][0]->id], 'lock_from' => '20:00', 'lock_until' => '22:00',
        ])->assertStatus(422);
    }

    public function test_a_cancelled_booking_does_not_block_a_table_lock(): void
    {
        [$setup, $owner] = $this->owner();
        $booking = $this->booking($setup, '2026-11-06 19:00');
        $booking->tables()->attach($setup['tables'][0]->id);
        $booking->update(['status' => 'cancelled_by_guest']);

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-06', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][0]->id],
        ])->assertOk()->assertJsonPath('message', 'Ausnahme gespeichert.');
    }

    public function test_the_message_counts_the_bookings_that_stay(): void
    {
        [$setup, $owner] = $this->owner();
        $this->booking($setup, '2026-11-05 19:00');
        $this->booking($setup, '2026-11-05 20:00');
        $this->booking($setup, '2026-11-07 19:00');
        $this->booking($setup, '2026-11-05 11:00', 60)->update(['status' => 'cancelled_by_guest']);

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-11-05', 'effects' => ['closed']])
            ->assertOk()->assertJsonPath('message', 'Ausnahme gespeichert. In diesem Zeitraum liegen 2 Reservierungen; sie bleiben bestehen. Informiere die Gäste bei Bedarf.');

        // Mit Uhrzeiten zählen nur die Reservierungen, die sich mit einer der Spannen überschneiden.
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-05', 'until' => '2026-11-07', 'effects' => ['limit'], 'limit_covers' => 4, 'limit_from' => '20:45', 'limit_until' => '22:00',
        ])->assertOk()->assertJsonPath('message', 'Ausnahme gespeichert. In diesem Zeitraum liegt 1 Reservierung; sie bleibt bestehen. Informiere die Gäste bei Bedarf.');
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-11-08', 'effects' => ['limit'], 'limit_covers' => 4,
        ])->assertOk()->assertJsonPath('message', 'Ausnahme gespeichert.');
    }

    public function test_salons_read_appointments_and_customers(): void
    {
        [$setup, $owner] = $this->owner();
        $setup['tenant']->update(['type' => 'salon']);
        $this->booking($setup, '2026-11-05 19:00');

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-11-05', 'effects' => ['closed']])
            ->assertOk()->assertJsonPath('message', 'Ausnahme gespeichert. In diesem Zeitraum liegt 1 Termin; er bleibt bestehen. Informiere die Kunden bei Bedarf.');
        $this->booking($setup, '2026-11-05 15:00');
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-11-05', 'effects' => ['closed']])
            ->assertOk()->assertJsonPath('message', 'Ausnahme gespeichert. In diesem Zeitraum liegen 2 Termine; sie bleiben bestehen. Informiere die Kunden bei Bedarf.');

        $service = app(ScheduleExceptions::class);
        $this->assertSame(
            'Am 31.12.2026 buchen Kunden zwischen 18:00 und 23:00, letzte Uhrzeit 21:30.',
            $service->describe($setup['location']->fresh(), ['from' => '2026-12-31', 'effects' => ['hours'], 'windows' => [['opens_at' => '18:00', 'closes_at' => '23:00']]]),
        );
        $this->assertSame(
            'Am 31.12.2026 buchen Kunden zwischen 18:00 und 18:30, finden aber keine Uhrzeit: Kein Fenster ist lang genug für einen Termin.',
            $service->describe($setup['location']->fresh(), ['from' => '2026-12-31', 'effects' => ['hours'], 'windows' => [['opens_at' => '18:00', 'closes_at' => '18:30']]]),
        );

        $response = $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'effects' => ['hours']])->assertStatus(422);
        $this->assertSame('Bitte mindestens ein Zeitfenster eintragen.', $response->json('errors.windows.0'));
    }

    // --- Satz „So wirkt es“ -------------------------------------------------------------------

    public function test_the_sentences_for_each_combination(): void
    {
        [$setup] = $this->owner();
        $setup['room']->update(['name' => 'Saal']);
        $terrace = Room::factory()->create(['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id, 'name' => 'Terrasse']);
        $location = $setup['location']->fresh();
        $service = app(ScheduleExceptions::class);

        $cases = [
            ['Am 24.12.2026 kann niemand online buchen.', ['from' => '2026-12-24', 'effects' => ['closed']]],
            ['Vom 31.12.2026 bis 01.01.2027 buchen Gäste zwischen 12:00 und 02:00, letzte Uhrzeit 00:30.', [
                'from' => '2026-12-31', 'until' => '2027-01-01', 'effects' => ['hours'], 'windows' => [['opens_at' => '18:00', 'closes_at' => '02:00'], ['opens_at' => '12:00', 'closes_at' => '15:00']],
            ]],
            ['Am 31.12.2026 buchen Gäste zwischen 18:00 und 18:30, finden aber keine Uhrzeit: Kein Fenster ist lang genug für einen Besuch.', [
                'from' => '2026-12-31', 'effects' => ['hours'], 'windows' => [['opens_at' => '18:00', 'closes_at' => '18:30']],
            ]],
            ['Am 07.11.2026 gelten höchstens 1 Gast gleichzeitig im Raum „Saal“; letzte Uhrzeit 21:30.', [
                'from' => '2026-11-07', 'effects' => ['limit'], 'limit_covers' => 1, 'limit_room_id' => $setup['room']->id,
            ]],
            ['Am 07.11.2026 ist Tisch T1 gesperrt; letzte Uhrzeit 21:30.', [
                'from' => '2026-11-07', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][0]->id],
            ]],
            ['Vom 07.11.2026 bis 08.11.2026 sind „Saal“, „Terrasse“, Tisch T1 und Tisch T2 gesperrt von 18:00 bis 22:00; letzte Uhrzeit 21:30.', [
                'from' => '2026-11-07', 'until' => '2026-11-08', 'effects' => ['lock'], 'lock_rooms' => [$terrace->id, $setup['room']->id],
                'lock_tables' => [$setup['tables'][1]->id, $setup['tables'][0]->id], 'lock_from' => '18:00', 'lock_until' => '22:00',
            ]],
            ['Am 31.12.2026 buchen Gäste zwischen 18:00 und 23:00; es gelten höchstens 20 Gäste gleichzeitig im ganzen Betrieb; „Terrasse“ ist gesperrt; letzte Uhrzeit 21:30.', [
                'from' => '2026-12-31', 'effects' => ['limit', 'hours', 'lock'], 'windows' => [['opens_at' => '18:00', 'closes_at' => '23:00']],
                'limit_covers' => 20, 'lock_rooms' => [$terrace->id],
            ]],
            ['', ['from' => '2026-12-31', 'effects' => []]],
        ];
        foreach ($cases as [$expected, $data]) {
            $this->assertSame($expected, $service->describe($location, $data), json_encode($data));
        }

        // Ein Wochentag ohne reguläre Öffnung (Samstag) und einer mit zu kurzem Fenster (Sonntag).
        $location->openingHours()->where('weekday', 5)->delete();
        $this->assertSame('Am 07.11.2026 gelten höchstens 20 Gäste gleichzeitig im ganzen Betrieb.', $service->describe($location, ['from' => '2026-11-07', 'effects' => ['limit'], 'limit_covers' => 20]));
        $location->openingHours()->where('weekday', 6)->update(['closes_at' => '12:30']);
        $this->assertSame('Am 08.11.2026 gelten höchstens 20 Gäste gleichzeitig im ganzen Betrieb; Gäste finden keine Uhrzeit: Kein Fenster ist lang genug für einen Besuch.', $service->describe($location, ['from' => '2026-11-08', 'effects' => ['limit'], 'limit_covers' => 20]));
    }

    public function test_the_last_time_follows_interval_and_duration_like_the_booking_page(): void
    {
        [$setup, $owner] = $this->owner();
        $service = app(ScheduleExceptions::class);
        $day = CarbonImmutable::parse('2026-12-31', 'Europe/Berlin');

        foreach ([[15, 60], [20, 150], [45, 90], [30, 120]] as [$interval, $duration]) {
            $setup['location']->settings()->update(['slot_interval_minutes' => $interval, 'default_duration_minutes' => $duration]);
            $location = $setup['location']->fresh();

            foreach ([[['opens_at' => '18:00', 'closes_at' => '23:00']], [['opens_at' => '12:00', 'closes_at' => '15:00'], ['opens_at' => '18:00', 'closes_at' => '02:00']]] as $windows) {
                $payload = ['from' => '2026-12-31', 'effects' => ['hours'], 'windows' => $windows];
                $sentence = $service->describe($location, $payload);

                // Gespeichert rechnet die Buchungsseite dieselbe letzte Uhrzeit.
                $this->actingAs($owner)->postJson('/admin/settings/exceptions', $payload)->assertOk();
                $starts = app(TimeSlotService::class)->slotStarts($location, $day, $duration);
                $this->assertNotSame([], $starts);
                $this->assertStringEndsWith(', letzte Uhrzeit '.$starts[array_key_last($starts)]->format('H:i').'.', $sentence);

                $this->actingAs($owner)->deleteJson('/admin/settings/exceptions/g-'.SpecialOpeningHour::query()->latest('id')->value('exception_group'))->assertOk();
            }
        }
    }

    public function test_the_preview_writes_nothing_and_checks_like_saving(): void
    {
        [$setup, $owner] = $this->owner();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions/preview', ['from' => '2026-12-24', 'effects' => ['closed']])->assertOk();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions/preview', ['from' => '2026-12-24', 'effects' => ['hours']])
            ->assertStatus(422)->assertJsonPath('errors.windows.0', 'Bitte mindestens ein Zeitfenster eintragen.');
        $this->actingAs($owner)->postJson('/admin/settings/exceptions/preview', ['from' => '2026-12-24', 'effects' => ['lock'], 'lock_tables' => [$setup['tables'][0]->id]])->assertOk();

        $this->assertSame(0, SpecialOpeningHour::count() + BlackoutPeriod::count() + TableBlock::count() + AuditLog::count());
    }

    // --- Zeitumstellung, Standorte, Protokoll -------------------------------------------------

    public function test_whole_days_follow_the_clock_changes(): void
    {
        [$setup, $owner] = $this->owner();

        // 25.10.2026 hat 25 Stunden, 28.03.2027 hat 23.
        foreach ([['2026-10-25', '2026-10-24 22:00', '2026-10-25 23:00'], ['2027-03-28', '2027-03-27 23:00', '2027-03-28 22:00']] as [$day, $start, $end]) {
            $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => $day, 'effects' => ['lock'], 'lock_rooms' => [$setup['room']->id]])->assertOk();
            $row = BlackoutPeriod::orderByDesc('id')->first();
            $this->assertSame([$start, $end], [$row->starts_at->utc()->format('Y-m-d H:i'), $row->ends_at->utc()->format('Y-m-d H:i')]);
            $entry = app(ScheduleExceptions::class)->find($setup['location']->fresh(), 'g-'.$row->exception_group);
            $this->assertSame([$day, $day, null], [$entry['from'], $entry['until'], $entry['form']['lock_from']]);
        }
    }

    public function test_an_entry_of_one_location_is_not_listed_for_another(): void
    {
        [$setup, $owner] = $this->owner();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'timezone' => 'Europe/Berlin']);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'effects' => ['closed']])->assertOk();

        $this->assertSame(['Dezember 2026'], array_keys(app(ScheduleExceptions::class)->entries($setup['location']->fresh())['upcoming']));
        $this->assertSame(['upcoming' => [], 'past' => []], app(ScheduleExceptions::class)->entries($second));
        $key = 'g-'.SpecialOpeningHour::first()->exception_group;
        $this->assertNull(app(ScheduleExceptions::class)->find($second, $key));
        $this->assertFalse(app(ScheduleExceptions::class)->delete($second, $key));
    }

    public function test_every_save_and_delete_is_logged_with_readable_values(): void
    {
        [$setup, $owner] = $this->owner();

        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'until' => '2026-12-26', 'label' => 'Weihnachten', 'effects' => ['closed']])->assertOk();
        $key = 'g-'.SpecialOpeningHour::first()->exception_group;
        $this->actingAs($owner)->putJson('/admin/settings/exceptions/'.$key, [
            'from' => '2026-12-24', 'label' => 'Heiligabend', 'effects' => ['hours'], 'windows' => [['opens_at' => '11:00', 'closes_at' => '14:00']],
        ])->assertOk();
        $this->actingAs($owner)->deleteJson('/admin/settings/exceptions/'.$key)->assertOk();

        $log = AuditLog::whereIn('action', ['exception.created', 'exception.updated', 'exception.deleted'])->orderBy('id')->get();
        $this->assertSame(['exception.created', 'exception.updated', 'exception.deleted'], $log->pluck('action')->all());
        $this->assertSame([$owner->id, $setup['tenant']->id], [$log[0]->user_id, $log[0]->tenant_id]);
        $this->assertSame(['from' => '2026-12-24', 'until' => '2026-12-26', 'label' => 'Weihnachten', 'effects' => 'Geschlossen'], $log[0]->new_values);
        $this->assertSame($log[0]->new_values, $log[1]->old_values);
        $this->assertSame(['from' => '2026-12-24', 'until' => '2026-12-24', 'label' => 'Heiligabend', 'effects' => 'Geöffnet 11:00–14:00'], $log[1]->new_values);
        $this->assertSame($log[1]->new_values, $log[2]->old_values);
        $this->assertNull($log[2]->new_values);

        $this->assertSame(['Ausnahme angelegt', 'Ausnahme geändert', 'Ausnahme gelöscht'], $log->map(fn (AuditLog $entry) => AuditLog::labelFor($entry->action))->all());
        $this->assertSame('Ausnahmen', AuditLog::areaLabel('exception.created'));
        foreach (['from', 'until', 'effects', 'label'] as $field) {
            $this->assertTrue(AuditLog::hasFieldLabel($field, 'exception.updated'), $field);
        }
        $this->assertSame(['Von', 'Bis', 'Bezeichnung', 'Wirkung'], array_column($log[0]->fieldChanges(), 'label'));
        $this->assertSame(['Bis', 'Bezeichnung', 'Wirkung'], array_column($log[1]->fieldChanges(), 'label'));
    }

    public function test_row_locks_serialize_concurrent_saves_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Zeilensperren lassen sich nur unter PostgreSQL prüfen.');
        }
        [, $owner] = $this->owner();
        $this->actingAs($owner)->postJson('/admin/settings/exceptions', ['from' => '2026-12-24', 'effects' => ['closed']])->assertOk();
        $key = 'g-'.SpecialOpeningHour::first()->exception_group;

        DB::enableQueryLog();
        $this->actingAs($owner)->putJson('/admin/settings/exceptions/'.$key, ['from' => '2026-12-24', 'effects' => ['closed']])->assertOk();
        $locked = array_filter(array_column(DB::getQueryLog(), 'query'), fn (string $sql) => str_contains($sql, 'for update'));

        // Der Standort und die Zeilen der drei Tabellen werden vor dem Löschen gesperrt.
        $this->assertGreaterThanOrEqual(4, count($locked));
    }
}
