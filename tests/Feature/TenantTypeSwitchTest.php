<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantTypeSwitchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_can_switch_to_salon(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $response = $this->actingAs($admin)->put('/admin/settings/tenant-type', [
            'type' => 'salon',
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');

        $this->assertSame(TenantType::Salon, $setup['tenant']->fresh()->type);
    }

    public function test_ajax_switch_returns_reload_flag(): void
    {
        // Das Settings-JS schickt das Formular per fetch mit Accept: application/json
        // ab und lädt die Seite nur neu, wenn die Antwort reload=true enthält.
        // Ohne dieses Flag bleibt die UI auf dem alten Typ stehen.
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $response = $this->actingAs($admin)
            ->putJson('/admin/settings/tenant-type', ['type' => 'salon']);

        $response->assertOk();
        $response->assertJson(['reload' => true]);
        $this->assertSame(TenantType::Salon, $setup['tenant']->fresh()->type);
    }
}
