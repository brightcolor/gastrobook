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
            ->assertSee('<body data-admin', false)
            ->assertSee('id="swayyToast" data-overlay-layer role="status" aria-live="polite"', false)
            ->assertSee('<header data-sheet-inert', false)
            ->assertDontSee('data-nav-key="webhooks"', false);
        $content = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/data-nav-key="reservations"[^>]*aria-current="page"/', $content);

        // Die untere Leiste trägt den aktiven Eintrag mit zweitem Merkmal neben der Farbe.
        $this->assertSame(1, preg_match('#<nav aria-label="Schnellzugriff".*?</nav>#s', $content, $bar));
        $this->assertStringContainsString('data-sheet-inert', $bar[0]);
        $this->assertMatchesRegularExpression('/data-nav-key="reservations"\s+aria-current="page"[^>]*font-semibold/', $bar[0]);
        $this->assertDoesNotMatchRegularExpression('/data-nav-key="board"[^>]*aria-current/', $bar[0]);
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

    public function test_collapsed_rail_offers_the_location_and_an_expand_control(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $name = e($setup['location']->name);

        $this->actingAs($owner)->get('/admin/reservations')
            ->assertOk()
            ->assertSee('data-nav-expand', false)
            ->assertSee('title="Standort: '.$name.'"', false)
            ->assertSee('Standort: '.$name.' – Navigation ausklappen', false);
    }

    public function test_impersonation_stop_stays_reachable_in_the_collapsed_rail(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)
            ->withSession(['impersonating_tenant_id' => $setup['tenant']->id])
            ->get('/admin/reservations')
            ->assertOk()
            ->getContent();

        // Ausführlicher Nutzerbereich und Symbolknopf der eingeklappten Leiste.
        $this->assertGreaterThanOrEqual(2, substr_count($html, 'Supportzugriff beenden'));
        $this->assertStringContainsString('>Supportzugriff beenden</button>', $html);
        $this->assertStringContainsString('<span class="sr-only">Supportzugriff beenden</span>', $html);
    }

    public function test_stop_button_is_absent_without_impersonation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/reservations')
            ->assertOk()
            ->assertDontSee('Supportzugriff beenden');
    }

    public function test_settings_overview_header_links_back_to_the_dashboard(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/menu')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<header.*?</header>#s', $html, $header));
        $this->assertStringContainsString('href="'.route('admin.dashboard').'"', $header[0]);
        $this->assertStringContainsString('Übersicht', $header[0]);
        $this->assertStringNotContainsString('href="'.route('admin.settings.menu').'"', $header[0]);
        // Die Live-Region bleibt sichtbar im Dokument; nur ihr Text wechselt.
        $this->assertStringContainsString('data-settings-noresult role="status"', $html);
        $this->assertStringNotContainsString('data-settings-noresult hidden', $html);
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

    public function test_layout_marks_overlay_layers_and_hands_over_search_examples(): void
    {
        config(['navigation.search_examples' => ['Tisch', 'Logo']]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/settings/general')->assertOk()->getContent();

        $this->assertStringContainsString('id="swayyToast" data-overlay-layer', $html);
        $this->assertStringContainsString('id="confirmModal" data-overlay-layer', $html);
        $this->assertStringContainsString('data-examples="Tisch|Logo"', $html);
        $this->assertStringContainsString('data-page-header', $html);
        $this->assertStringContainsString("e.target.hasAttribute('data-ajax')", $html);
        // Escape im Bestätigungsdialog verbraucht die Taste (preventDefault), damit eine darunter offene Seitenleiste bleibt.
        $this->assertMatchesRegularExpression(
            "/e\.key === 'Escape' && !modal\.classList\.contains\('hidden'\)\)\s*\{\s*e\.preventDefault\(\);\s*closeModal\(\);/",
            $html,
        );
    }
}
