<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SettingsGeneralPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_the_general_page_in_the_settings_area(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['name' => 'Beispiel Gastro GmbH']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/general')->assertOk();

        $response->assertSee('Einstellungen › Betrieb')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('value="Beispiel Gastro GmbH"', false)
            ->assertSee('data-save-bar', false)
            ->assertSee('data-ajax data-savebar', false)
            ->assertSee('Zurück zum Betrieb');
        $this->assertMatchesRegularExpression('/data-nav-key="general"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_the_brand_color_falls_back_to_the_configured_default(): void
    {
        config(['swayy.ui.default_brand_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => null]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/general')
            ->assertOk()
            ->assertSee('value="#123456"', false);
    }

    public function test_roles_without_tenant_settings_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/general')->assertForbidden();
    }

    public function test_saving_business_data_leaves_the_location_untouched(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $locationName = $setup['location']->name;
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->putJson('/admin/settings/general', [
                'business_name' => 'Nordlicht Events',
                'imprint_url' => 'https://nordlicht.test/impressum',
                'privacy_url' => 'https://nordlicht.test/datenschutz',
                'terms_url' => '',
            ])
            ->assertOk()
            ->assertJson(['message' => 'Stammdaten gespeichert.']);

        $tenant = $setup['tenant']->fresh();
        $this->assertSame('Nordlicht Events', $tenant->name);
        $this->assertSame('https://nordlicht.test/impressum', $tenant->imprint_url);
        $this->assertNull($tenant->terms_url);
        $this->assertSame($locationName, $setup['location']->fresh()->name);
        $this->assertTrue(AuditLog::where('action', 'tenant.general_updated')->exists());
    }

    public function test_an_invalid_link_names_the_field_and_the_fix(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->putJson('/admin/settings/general', ['business_name' => 'Nordlicht Events', 'imprint_url' => 'impressum'])
            ->assertStatus(422)
            ->assertJsonPath('errors.imprint_url.0', 'Die Adresse des Impressums ist ungültig. Bitte vollständig eintragen, etwa https://dein-betrieb.de/impressum.');
    }

    public function test_renaming_the_business_reloads_the_page_so_the_navigation_shows_the_new_name(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['name' => 'Nordlicht Events']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->putJson('/admin/settings/general', ['business_name' => 'Nordlicht Catering'])
            ->assertOk()
            ->assertJsonPath('reload', true);

        $this->actingAs($owner)
            ->putJson('/admin/settings/general', [
                'business_name' => 'Nordlicht Catering',
                'imprint_url' => 'https://nordlicht.test/impressum',
            ])
            ->assertOk()
            ->assertJsonMissingPath('reload');
    }

    public function test_a_location_name_without_timezone_names_the_missing_field(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->putJson('/admin/settings/general', ['business_name' => 'Nordlicht Events', 'location_name' => 'Hafen'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('timezone');
    }

    public function test_the_type_cards_describe_each_business_type(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/general')
            ->assertOk()
            ->assertSee('Räume, Tische und Reservierungen')
            ->assertSee('Termine je Mitarbeiter und Leistung');
    }

    public function test_a_rejected_brand_color_is_marked_on_the_field_and_shows_the_saved_color(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => '#abcdef']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->from('/admin/settings/general')
            ->put('/admin/settings/branding', ['brand_primary_color' => 'rot'])
            ->assertRedirect('/admin/settings/general');

        // Ein Farbfeld kann „rot“ nicht anzeigen und zeigte Schwarz; es bleibt die gespeicherte Farbe.
        $this->actingAs($owner)->get('/admin/settings/general')
            ->assertOk()
            ->assertSee('value="#abcdef"', false)
            ->assertDontSee('value="rot"', false)
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="f-brand_primary_color-help f-brand_primary_color-error"', false);
    }
}
