<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use App\Models\RestaurantTable;
use App\Models\SpecialOpeningHour;
use App\Models\TableBlock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Eine Ausnahme schreibt ihre Zeilen in Sammel-Inserts.
 *
 * Vorher entstand jede Zeile einzeln: zwei Wochen Sperre für zwölf Tische
 * waren 168 Anfragen nur für die Tischsperren, alle in einer Transaktion.
 * Jetzt gehen sie in Blöcken zu swayy.exceptions.insert_chunk Zeilen
 * (SWAYY_EXCEPTIONS_INSERT_CHUNK) in die Datenbank, mit denselben Werten und
 * Zeitstempeln wie vorher.
 */
class ExceptionBulkWriteTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'Europe/Berlin'));
    }

    /**
     * @return array{0: array<string, mixed>, 1: mixed, 2: list<int>}
     */
    private function restaurantWithTables(int $tables): array
    {
        $setup = $this->createTenantSetup(array_fill(0, $tables, ['min' => 1, 'max' => 4]));
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        return [$setup, $owner, array_map(fn (RestaurantTable $t) => $t->id, $setup['tables'])];
    }

    /**
     * Anzahl der INSERT-Anfragen je Tabelle während $run.
     *
     * @return array<string, int>
     */
    private function inserts(callable $run): array
    {
        $counts = [];
        DB::listen(function ($query) use (&$counts) {
            if (preg_match('/^insert into "?(\w+)"?/i', $query->sql, $m) === 1) {
                $counts[$m[1]] = ($counts[$m[1]] ?? 0) + 1;
            }
        });
        $run();

        return $counts;
    }

    public function test_a_long_lock_of_many_tables_needs_few_inserts_and_keeps_every_row(): void
    {
        [$setup, $owner, $tableIds] = $this->restaurantWithTables(12);
        $room = $setup['room'];

        $counts = $this->inserts(fn () => $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-01', 'until' => '2026-12-14', 'label' => 'Umbau', 'effects' => ['hours', 'limit', 'lock'],
            'windows' => [['opens_at' => '11:00', 'closes_at' => '14:00'], ['opens_at' => '17:00', 'closes_at' => '22:00']],
            'limit_covers' => 20, 'limit_from' => '17:00', 'limit_until' => '22:00',
            'lock_rooms' => [$room->id], 'lock_tables' => $tableIds, 'lock_from' => '18:00', 'lock_until' => '23:00',
        ])->assertOk());

        // 14 Tage × 12 Tische = 168 Sperren, 28 Öffnungszeiten, 14 Grenzen und 14 Raumsperren.
        $this->assertSame(168, TableBlock::withoutGlobalScopes()->count());
        $this->assertSame(28, SpecialOpeningHour::withoutGlobalScopes()->count());
        $this->assertSame(28, BlackoutPeriod::withoutGlobalScopes()->count());
        $this->assertSame(1, $counts['table_blocks'] ?? 0);
        $this->assertSame(1, $counts['special_opening_hours'] ?? 0);
        $this->assertSame(1, $counts['blackout_periods'] ?? 0);

        // Werte wie beim einzelnen Anlegen: Zeitraum in UTC, Gruppe, Bezeichnung, Ersteller, Zeitstempel.
        $block = TableBlock::withoutGlobalScopes()->where('restaurant_table_id', $tableIds[3])->orderBy('starts_at')->first();
        $this->assertSame('2026-12-01 17:00:00', $block->starts_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-12-01 22:00:00', $block->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('Umbau', $block->reason);
        $this->assertSame($owner->id, $block->created_by);
        $this->assertSame($setup['tenant']->id, $block->tenant_id);
        $this->assertNotNull($block->exception_group);
        $this->assertSame('2026-10-12 08:00:00', $block->created_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, TableBlock::withoutGlobalScopes()->distinct()->count('exception_group'));

        $hours = SpecialOpeningHour::withoutGlobalScopes()->where('date', '2026-12-07')->orderBy('opens_at')->get();
        $this->assertSame([['11:00', '14:00'], ['17:00', '22:00']], $hours->map(fn ($h) => [substr((string) $h->opens_at, 0, 5), substr((string) $h->closes_at, 0, 5)])->all());
        $this->assertFalse((bool) $hours->first()->closed);

        $limit = BlackoutPeriod::withoutGlobalScopes()->whereNotNull('reduce_covers_to')->orderBy('starts_at')->first();
        $this->assertSame([20, null], [$limit->reduce_covers_to, $limit->room_id]);
        $this->assertSame(14, BlackoutPeriod::withoutGlobalScopes()->where('room_id', $room->id)->whereNull('reduce_covers_to')->count());

        // Die Liste zeigt den Eintrag wie gewohnt.
        $this->actingAs($owner)->get('/admin/settings/exceptions')->assertOk()->assertSee('Umbau');
    }

    public function test_the_block_size_comes_from_the_settings(): void
    {
        config(['swayy.exceptions.insert_chunk' => 50]);
        [, $owner, $tableIds] = $this->restaurantWithTables(12);

        $counts = $this->inserts(fn () => $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-01', 'until' => '2026-12-14', 'effects' => ['lock'],
            'lock_tables' => $tableIds, 'lock_from' => '18:00', 'lock_until' => '23:00',
        ])->assertOk());

        // 168 Zeilen in Blöcken zu 50.
        $this->assertSame(4, $counts['table_blocks'] ?? 0);
        $this->assertSame(168, TableBlock::withoutGlobalScopes()->count());
    }

    public function test_closed_days_are_written_in_one_insert(): void
    {
        [, $owner] = $this->restaurantWithTables(1);

        $counts = $this->inserts(fn () => $this->actingAs($owner)->postJson('/admin/settings/exceptions', [
            'from' => '2026-12-24', 'until' => '2027-01-06', 'label' => 'Betriebsferien', 'effects' => ['closed'],
        ])->assertOk());

        $this->assertSame(1, $counts['special_opening_hours'] ?? 0);
        $days = SpecialOpeningHour::withoutGlobalScopes()->orderBy('date')->get();
        $this->assertCount(14, $days);
        $this->assertTrue($days->every(fn ($d) => (bool) $d->closed && $d->label === 'Betriebsferien' && $d->opens_at === null));
        $this->assertSame('2027-01-06', $days->last()->date->toDateString());
    }
}
