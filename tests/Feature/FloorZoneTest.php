<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FloorZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class FloorZoneTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_zone_can_be_created_updated_and_deleted(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        // Create — this regression-guards the audit logging bug that returned 500.
        $create = $this->actingAs($admin)->postJson('/admin/floorplan/zones', [
            'room_id' => $setup['room']->id,
            'name' => 'VIP',
            'color' => '#f59e0b',
            'opacity' => 30,
            'points' => [[100, 100], [400, 100], [400, 300], [100, 300]],
        ]);

        $create->assertCreated();
        $zoneId = $create->json('id');
        $this->assertNotNull($zoneId);
        $this->assertDatabaseHas('audit_logs', ['action' => 'zone.created']);

        // Update — reshape the polygon (move vertices).
        $update = $this->actingAs($admin)->putJson("/admin/floorplan/zones/{$zoneId}", [
            'name' => 'VIP-Lounge',
            'points' => [[120, 120], [450, 120], [450, 350], [120, 350]],
        ]);

        $update->assertOk();
        $update->assertJsonPath('name', 'VIP-Lounge');
        $this->assertSame([[120, 120], [450, 120], [450, 350], [120, 350]], FloorZone::find($zoneId)->points);

        // Delete.
        $this->actingAs($admin)->deleteJson("/admin/floorplan/zones/{$zoneId}")->assertOk();
        $this->assertDatabaseMissing('floor_zones', ['id' => $zoneId]);
    }
}
