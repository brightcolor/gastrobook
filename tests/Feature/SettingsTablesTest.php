<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsTablesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_floorplan_page_shows_table_modal(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/floorplan')
            ->assertOk()
            ->assertSee('Neuer Tisch')
            ->assertSee('id="newTableBack"', false);
    }

    public function test_settings_page_no_longer_shows_table_creation(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertDontSee('tableModalBack')
            ->assertSee('Tischplan');
    }

    public function test_table_is_created_from_modal_payload(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        // Modal posts seats as max_capacity (button) + min_capacity hidden = 1
        $this->actingAs($admin)->post('/admin/settings/tables', [
            'room_id' => $setup['room']->id,
            'name' => 'M5',
            'min_capacity' => 1,
            'max_capacity' => 6,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('restaurant_tables', [
            'location_id' => $setup['location']->id,
            'name' => 'M5',
            'max_capacity' => 6,
        ]);
    }

    public function test_table_keeps_chosen_minimum_party(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/tables', [
            'room_id' => $setup['room']->id,
            'name' => 'M6',
            'min_capacity' => 3,
            'max_capacity' => 6,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('restaurant_tables', [
            'name' => 'M6',
            'min_capacity' => 3,
            'max_capacity' => 6,
        ]);
    }
}
