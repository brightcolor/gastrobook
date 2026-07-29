<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\RestaurantTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TableSeatPositionsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function table(array $attrs): RestaurantTable
    {
        $setup = $this->createTenantSetup([]);

        return RestaurantTable::factory()->create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'room_id' => $setup['room']->id,
        ], $attrs));
    }

    public function test_rect_table_uses_head_seats_by_default(): void
    {
        // 9 covers → odd, so one head seat is used
        $table = $this->table(['shape' => 'rect', 'min_capacity' => 1, 'max_capacity' => 9]);

        $seats = $table->seatPositions();
        $sides = collect($seats)->pluck('side');

        $this->assertCount(9, $seats);
        $this->assertTrue($sides->contains('right'), 'Kopfende sollte belegt sein');
    }

    public function test_head_seats_can_be_disabled(): void
    {
        $table = $this->table([
            'shape' => 'rect', 'min_capacity' => 1, 'max_capacity' => 9,
            'head_seats_enabled' => false,
        ]);

        $seats = $table->seatPositions();
        $sides = collect($seats)->pluck('side');

        $this->assertCount(9, $seats, 'Kapazität bleibt gleich, nur die Verteilung ändert sich');
        $this->assertFalse($sides->contains('right'), 'Kein Stuhl an der Stirnseite');
        $this->assertFalse($sides->contains('left'));
        $this->assertTrue($sides->every(fn ($s) => in_array($s, ['top', 'bottom'], true)));
    }

    public function test_round_table_spreads_seats_on_the_circle(): void
    {
        $table = $this->table(['shape' => 'round', 'min_capacity' => 1, 'max_capacity' => 6]);

        $seats = $table->seatPositions();

        $this->assertCount(6, $seats);
        $this->assertTrue(collect($seats)->every(fn ($s) => $s['side'] === 'round'));
        // all seats lie on the unit circle around the centre
        foreach ($seats as $s) {
            $r = sqrt(($s['x'] - 0.5) ** 2 + ($s['y'] - 0.5) ** 2);
            $this->assertEqualsWithDelta(0.5, $r, 0.001);
        }
    }

    public function test_head_seat_flag_is_editable_via_floorplan(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson("/admin/floorplan/tables/{$table->id}", [
            'name' => $table->name,
            'min_capacity' => $table->min_capacity,
            'max_capacity' => $table->max_capacity,
            'head_seats_enabled' => false,
        ])->assertOk()->assertJsonPath('table.head_seats_enabled', false);

        $this->assertFalse((bool) $table->fresh()->head_seats_enabled);
    }
}
