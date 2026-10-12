<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsEntryTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_old_address_leads_to_the_first_allowed_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $marketing = $this->createMember($setup['tenant'], 'marketing_manager');
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings')->assertRedirect(route('admin.settings.general.show'));
        $this->actingAs($manager)->get('/admin/settings')->assertRedirect(route('admin.settings.opening-hours.show'));
        $this->actingAs($marketing)->get('/admin/settings')->assertRedirect(route('admin.templates.index'));
        $this->actingAs($host)->get('/admin/settings')->assertForbidden()->assertSee('Für die Einstellungen fehlt die Berechtigung');
    }

    public function test_settings_pages_carry_the_legacy_anchor_map_for_allowed_pages_only(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $owners = $this->actingAs($owner)->get(route('admin.settings.general.show'))->assertOk();
        $owners->assertSee('data-settings-anchors', false);
        $map = $this->anchors((string) $owners->getContent());
        $this->assertSame(route('admin.settings.opening-hours.show'), $map['zeiten']);
        $this->assertSame(route('admin.settings.payment-providers.show'), $map['zahlungen']);
        $this->assertSame(route('admin.settings.booking-page.show').'#popup', $map['widget']);

        $managers = $this->anchors((string) $this->actingAs($manager)->get(route('admin.settings.opening-hours.show'))->getContent());
        $this->assertArrayNotHasKey('zahlungen', $managers);
        $this->assertSame(route('admin.settings.rooms.index'), $managers['raeume']);
    }

    public function test_salons_get_services_for_the_rooms_anchor(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => 'salon']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $map = $this->anchors((string) $this->actingAs($owner)->get(route('admin.settings.general.show'))->getContent());

        $this->assertSame(route('admin.services.index'), $map['raeume']);
    }

    /** @return array<string, string> */
    private function anchors(string $html): array
    {
        $this->assertSame(1, preg_match('/data-settings-anchors="([^"]*)"/', $html, $m));

        return json_decode(html_entity_decode($m[1]), true, 512, JSON_THROW_ON_ERROR);
    }
}
