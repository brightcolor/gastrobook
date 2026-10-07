<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NavigationLayoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_daily_pages_show_the_grouped_main_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/reservations')->assertOk();

        $response->assertSee('aria-label="Hauptnavigation"', false)
            ->assertSeeInOrder(['data-nav-key="dashboard"', 'Service', 'data-nav-key="board"', 'Buchungen', 'data-nav-key="reservations"'], false)
            ->assertSee('data-nav-key="settings"', false)
            ->assertSee('aria-label="Schnellzugriff"', false)
            ->assertSee('data-more-open', false)
            ->assertDontSee('data-nav-key="webhooks"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="reservations"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_settings_pages_swap_the_sidebar_for_the_settings_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/users')->assertOk();

        $response->assertSee('aria-label="Einstellungen"', false)
            ->assertSee('Zurück zum Betrieb')
            ->assertSee('Einstellungen durchsuchen')
            ->assertSee('data-nav-key="webhooks"', false)
            ->assertDontSee('aria-label="Hauptnavigation"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="team"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_roles_without_settings_get_no_settings_entry(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/reservations')
            ->assertOk()
            ->assertDontSee('data-nav-key="settings"', false);
    }

    public function test_salon_labels_reach_the_layout(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/reservations')
            ->assertOk()
            ->assertSee('Termine')
            ->assertSee('data-nav-key="staff-calendar"', false);
    }

    public function test_layout_and_navigation_use_no_inline_event_handlers(): void
    {
        $files = [
            resource_path('views/layouts/admin.blade.php'),
            resource_path('views/admin/partials/nav/main.blade.php'),
            resource_path('views/admin/partials/nav/settings.blade.php'),
            resource_path('views/admin/partials/nav/user.blade.php'),
            resource_path('views/admin/partials/nav/location-switch.blade.php'),
        ];

        foreach ($files as $file) {
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input|keydown)\s*=/i', (string) file_get_contents($file), $file);
        }
    }
}
