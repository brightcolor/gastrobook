<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PageComponentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_field_puts_the_label_above_and_links_help_text(): void
    {
        $this->blade('<x-field name="buffer_minutes" label="Puffer" type="number" value="15" unit="Min." help="Zeit zwischen zwei Buchungen am selben Tisch." min="0" max="120" />')
            ->assertSeeInOrder(['<label for="f-buffer_minutes"', 'Puffer', 'id="f-buffer_minutes"', 'Min.', 'Zeit zwischen zwei Buchungen'], false)
            ->assertSee('aria-describedby="f-buffer_minutes-help"', false)
            ->assertSee('min="0"', false)
            ->assertSee('value="15"', false);
    }

    public function test_field_shows_the_server_error_at_the_field(): void
    {
        $this->withViewErrors(['buffer_minutes' => 'Bitte eine Zahl von 0 bis 120 eintragen.'])
            ->blade('<x-field name="buffer_minutes" label="Puffer" type="number" />')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="f-buffer_minutes-error"', false)
            ->assertSee('Bitte eine Zahl von 0 bis 120 eintragen.');
    }

    public function test_field_ids_stay_valid_for_array_names(): void
    {
        $this->blade('<x-field name="hours[0][opens_at]" label="Beginn" type="time" />')
            ->assertSee('id="f-hours-0-opens_at"', false)
            ->assertSee('name="hours[0][opens_at]"', false);
    }

    public function test_page_header_names_the_scope_for_all_locations(): void
    {
        $this->blade('<x-page-header title="Allgemein" description="Gilt für den Betrieb." :crumb="[\'Einstellungen\', \'Betrieb\']" scope="tenant" />')
            ->assertSeeInOrder(['Einstellungen › Betrieb', 'Allgemein', 'Gilt für den Betrieb.', 'Gilt für alle Standorte'], false);
    }

    public function test_page_header_offers_the_location_switch_with_several_locations(): void
    {
        $setup = $this->createTenantSetup();
        Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Zweiter Standort']);
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $this->blade('<x-page-header title="Öffnungszeiten" scope="location" />')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-autosubmit', false)
            ->assertSee('Zweiter Standort');
    }

    public function test_settings_section_pairs_explanation_and_card(): void
    {
        $this->blade('<x-settings.section id="betrieb" title="Betrieb"><x-slot:description>Erklärung links.</x-slot:description>Karteninhalt</x-settings.section>')
            ->assertSee('aria-labelledby="betrieb-title"', false)
            ->assertSeeInOrder(['id="betrieb-title"', 'Betrieb', 'Erklärung links.', 'Karteninhalt'], false);
    }

    public function test_save_bar_starts_hidden(): void
    {
        $this->blade('<x-save-bar />')
            ->assertSee('data-save-bar hidden', false)
            ->assertSee('Ungespeicherte Änderungen')
            ->assertSee('data-save-bar-discard', false)
            ->assertSee('data-save-bar-save', false);
    }
}
