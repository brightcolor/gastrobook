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
        $this->assertSame(url('/admin/settings/menu'), route('admin.settings.menu'));
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
            ->assertSee('<title>Keine Berechtigung</title>', false)
            ->assertSee('Für die Einstellungen fehlt die Berechtigung.');
    }

    public function test_the_data_entry_opens_the_data_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Eintrag 'data' in config/navigation.php: admin.settings.data.show
        $this->actingAs($owner)->get('/admin/settings/menu')
            ->assertOk()
            ->assertSee('href="'.route('admin.settings.data.show').'"', false)
            ->assertSee('Export#export', false);

        $this->actingAs($owner)->get(route('admin.settings.data.show'))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('id="export"', false);
    }

    public function test_a_role_sees_only_the_pages_it_may_open(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'marketing_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/menu')
            ->assertOk()
            ->assertSee('data-nav-key="templates"', false)
            ->assertDontSee('data-nav-key="webhooks"', false)
            ->assertDontSee('data-nav-key="general"', false)
            ->assertDontSee('Gästekommunikation', false);
    }
}
