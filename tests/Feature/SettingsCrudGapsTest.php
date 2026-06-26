<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BlackoutPeriod;
use App\Models\Room;
use App\Models\SpecialOpeningHour;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsCrudGapsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    // ── Räume ────────────────────────────────────────────────────────────
    public function test_room_can_be_renamed_and_deleted_when_empty(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $emptyRoom = Room::factory()->create(['location_id' => $setup['location']->id, 'tenant_id' => $setup['tenant']->id, 'name' => 'Alt']);
        $this->clearTenantContext();

        $this->actingAs($admin)->put("/admin/settings/rooms/{$emptyRoom->id}", ['name' => 'Wintergarten'])
            ->assertRedirect();
        $this->assertSame('Wintergarten', $emptyRoom->fresh()->name);

        $this->actingAs($admin)->delete("/admin/settings/rooms/{$emptyRoom->id}")->assertRedirect();
        $this->assertNull(Room::find($emptyRoom->id));
    }

    public function test_room_with_tables_cannot_be_deleted(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $roomWithTable = $setup['room']; // createTenantSetup put a table in this room
        $this->clearTenantContext();

        $this->actingAs($admin)->delete("/admin/settings/rooms/{$roomWithTable->id}")
            ->assertSessionHasErrors('room');
        $this->assertNotNull(Room::find($roomWithTable->id));
    }

    // ── Tische ───────────────────────────────────────────────────────────
    public function test_table_name_and_capacity_can_be_edited(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($admin)->put("/admin/settings/tables/{$table->id}", [
            'name' => 'Fenstertisch', 'min_capacity' => 2, 'max_capacity' => 6,
        ])->assertRedirect();

        $fresh = $table->fresh();
        $this->assertSame('Fenstertisch', $fresh->name);
        $this->assertSame(6, $fresh->max_capacity);
    }

    // ── Sonderöffnungszeiten ─────────────────────────────────────────────
    public function test_special_hours_can_be_deleted(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $sh = SpecialOpeningHour::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'date' => now()->addWeek()->toDateString(), 'closed' => true,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->delete("/admin/settings/special-hours/{$sh->id}")->assertRedirect();
        $this->assertNull(SpecialOpeningHour::find($sh->id));
    }

    // ── Sperrzeiten (Blackouts) ──────────────────────────────────────────
    public function test_blackout_can_be_created_and_deleted(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/blackouts', [
            'starts_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'reason' => 'Betriebsfeier',
        ])->assertRedirect();

        $bo = BlackoutPeriod::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->first();
        $this->assertNotNull($bo);
        $this->assertSame('Betriebsfeier', $bo->reason);

        $this->actingAs($admin)->delete("/admin/settings/blackouts/{$bo->id}")->assertRedirect();
        $this->assertNull(BlackoutPeriod::withoutGlobalScopes()->find($bo->id));
    }

    public function test_blackout_requires_manage_permission(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->post('/admin/settings/blackouts', [
            'starts_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
        ])->assertForbidden();
    }
}
