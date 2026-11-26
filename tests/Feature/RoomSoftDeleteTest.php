<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\DepositRule;
use App\Models\FloorZone;
use App\Models\Plan;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Tenant;
use App\Services\AccountExportService;
use App\Services\AccountImportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Ein gelöschter Raum nimmt seine alten Tische nicht mehr mit.
 *
 * Ein Raum lässt sich löschen, sobald keine Tische mehr darin stehen. Weich
 * gelöschte Tische zählen dabei nicht, hängen aber weiter an vergangenen
 * Reservierungen. Wurde der Raum endgültig gelöscht, löschte die Datenbank
 * diese Tische mit, und die Reservierungen verloren ihre Tischzuordnung.
 * Jetzt wird auch der Raum weich gelöscht.
 */
class RoomSoftDeleteTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * Ein Raum „Wintergarten“ mit einem gelöschten Tisch W1, an dem eine
     * vergangene Reservierung hängt.
     *
     * @return array{0: array<string, mixed>, 1: Room, 2: RestaurantTable, 3: Reservation}
     */
    private function roomWithAnOldTable(): array
    {
        $setup = $this->createTenantSetup();
        $room = Room::factory()->create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Wintergarten']);
        $table = RestaurantTable::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'room_id' => $room->id,
            'name' => 'W1', 'min_capacity' => 2, 'max_capacity' => 4,
        ]);

        $start = CarbonImmutable::now($setup['location']->timezone)->subDays(5)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Completed,
            'source' => 'phone', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $reservation->tables()->attach($table->id);
        $table->delete();

        return [$setup, $room, $table, $reservation];
    }

    public function test_deleting_a_room_keeps_its_old_tables_on_past_reservations(): void
    {
        [$setup, $room, $table, $reservation] = $this->roomWithAnOldTable();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/rooms/{$room->id}")
            ->assertOk()->assertJsonPath('message', 'Raum gelöscht.');

        $this->assertFalse(Room::withoutGlobalScopes()->whereKey($room->id)->whereNull('deleted_at')->exists());
        $this->assertTrue(Room::withoutGlobalScopes()->withTrashed()->findOrFail($room->id)->trashed());
        $this->assertNotNull(RestaurantTable::withoutGlobalScopes()->withTrashed()->find($table->id));
        $this->assertTrue(DB::table('reservation_tables')->where('reservation_id', $reservation->id)->where('restaurant_table_id', $table->id)->exists());

        // Der Raum taucht in der Verwaltung nicht mehr auf.
        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->assertDontSee('Wintergarten');
    }

    public function test_zones_and_background_of_a_deleted_room_go_with_it(): void
    {
        Storage::fake('public');
        [$setup, $room] = $this->roomWithAnOldTable();
        Storage::disk('public')->put('rooms/plan.png', 'png');
        $room->forceFill(['background_path' => 'rooms/plan.png'])->save();
        FloorZone::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'room_id' => $room->id,
            'name' => 'Raucherecke', 'color' => '#aabbcc', 'opacity' => 30, 'points' => [[0, 0], [10, 0], [10, 10]],
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/rooms/{$room->id}")->assertOk();

        $this->assertSame(0, FloorZone::withoutGlobalScopes()->where('room_id', $room->id)->count());
        Storage::disk('public')->assertMissing('rooms/plan.png');
        $this->assertNull(Room::withoutGlobalScopes()->findOrFail($room->id)->background_path);
    }

    public function test_a_room_with_a_deposit_rule_stays_and_names_the_rule(): void
    {
        // Vorher machte das Löschen aus einer Regel für den Raum stillschweigend
        // eine Regel für den ganzen Standort.
        [$setup, $room] = $this->roomWithAnOldTable();
        DepositRule::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'room_id' => $room->id,
            'name' => 'Wintergarten am Samstag', 'type' => 'deposit', 'amount_per_person_minor' => 1000,
            'currency' => 'EUR', 'payment_deadline_minutes' => 60,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/rooms/{$room->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.room.0', '„Wintergarten“ gilt noch in der Anzahlungsregel „Wintergarten am Samstag“. Bitte die Regel zuerst unter „Anzahlungen“ ändern oder löschen.');

        $this->assertNotNull(Room::withoutGlobalScopes()->find($room->id));
    }

    public function test_a_move_keeps_the_deleted_room_and_its_old_table(): void
    {
        [$setup, $room] = $this->roomWithAnOldTable();
        $room->delete();

        $export = app(AccountExportService::class)->export($setup['tenant']);
        $setup['tenant']->forceDelete();
        $this->clearTenantContext();

        $target = Tenant::factory()->create(['plan_id' => Plan::factory()]);
        app(AccountImportService::class)->import($target, $export);

        $moved = Reservation::withoutGlobalScopes()->where('tenant_id', $target->id)->sole();
        $table = RestaurantTable::withoutGlobalScopes()->withTrashed()->findOrFail(
            DB::table('reservation_tables')->where('reservation_id', $moved->id)->value('restaurant_table_id')
        );
        $this->assertSame('W1', $table->name);
        $this->assertSame('Wintergarten', $table->room?->name);
        $this->assertTrue($table->room->trashed());
        $this->assertSame(['Wintergarten'], Room::withoutGlobalScopes()->where('tenant_id', $target->id)->whereNotNull('deleted_at')->pluck('name')->all());
    }
}
