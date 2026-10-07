<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsMenuTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_all_settings_groups(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/menu')
            ->assertOk()
            ->assertSeeInOrder(['Betrieb', 'Allgemein', 'Platz & Zeiten', 'Öffnungszeiten', 'Verbindungen', 'Webhooks'], false)
            ->assertSee('data-keywords="Tarif|Abo|Lastschrift|Rechnung"', false)
            ->assertSee('data-settings-search', false);
    }

    public function test_salon_sees_services_and_staff_instead_of_rooms(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/menu')
            ->assertOk()
            ->assertSee('data-nav-key="services"', false)
            ->assertSee('data-nav-key="staff"', false)
            ->assertDontSee('data-nav-key="rooms"', false);
    }

    public function test_without_any_settings_page_the_menu_explains_the_missing_permission(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/settings/menu')
            ->assertForbidden()
            ->assertSee('Für die Einstellungen fehlt die Berechtigung.');
    }
}
