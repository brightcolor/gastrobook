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
            ->assertSee('aria-describedby="f-buffer_minutes-unit f-buffer_minutes-help"', false)
            ->assertSee('id="f-buffer_minutes-unit"', false)
            ->assertSee('min="0"', false)
            ->assertSee('value="15"', false);
    }

    public function test_field_shows_the_server_error_at_the_field(): void
    {
        $this->withViewErrors(['buffer_minutes' => 'Bitte eine Zahl von 0 bis 120 eintragen.'])
            ->blade('<x-field name="buffer_minutes" label="Puffer" type="number" />')
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('id="f-buffer_minutes-error"', false)
            ->assertSee('aria-describedby="f-buffer_minutes-error"', false)
            ->assertSee('Bitte eine Zahl von 0 bis 120 eintragen.');
    }

    public function test_field_ids_stay_valid_for_array_names(): void
    {
        $this->blade('<x-field name="hours[0][opens_at]" label="Beginn" type="time" />')
            ->assertSee('id="f-hours-0-opens_at"', false)
            ->assertSee('name="hours[0][opens_at]"', false);
    }

    public function test_field_marks_required_and_skips_describedby_without_help(): void
    {
        $this->blade('<x-field name="business_name" label="Name des Betriebs" required class="sm:col-span-2" />')
            ->assertSee('required aria-required="true"', false)
            ->assertSee('<span class="text-red-700" aria-hidden="true"> *</span>', false)
            ->assertSee('class="space-y-1.5 sm:col-span-2"', false)
            ->assertDontSee('aria-describedby', false);
    }

    public function test_field_finds_errors_of_array_names(): void
    {
        $this->withViewErrors(['hours.0.opens_at' => 'Bitte eine Uhrzeit eintragen.'])
            ->blade('<x-field name="hours[0][opens_at]" label="Beginn" type="time" />')
            ->assertSee('aria-describedby="f-hours-0-opens_at-error"', false)
            ->assertSee('Bitte eine Uhrzeit eintragen.');
    }

    public function test_field_slot_replaces_the_input_and_keeps_label_and_help(): void
    {
        $this->blade('<x-field name="brand_primary_color" label="Farbe" help="Ein Klick öffnet die Auswahl."> <input id="f-brand_primary_color" type="color" name="brand_primary_color"> </x-field>')
            ->assertSee('<label for="f-brand_primary_color"', false)
            ->assertSee('type="color"', false)
            ->assertSee('id="f-brand_primary_color-help"', false)
            ->assertDontSee('type="text"', false);
    }

    public function test_scope_switch_names_the_only_location(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $this->blade('<x-scope-switch scope="location" />')
            ->assertSee('Gilt für Standort')
            ->assertSee($setup['location']->name)
            ->assertDontSee('data-autosubmit', false);
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
            ->assertSee('Zweiter Standort')
            ->assertSee('value="'.$setup['location']->id.'" selected', false);
    }

    public function test_settings_section_pairs_explanation_and_card(): void
    {
        // Leerzeichen nach </x-slot:description>: Blade macht daraus " @endslot";
        // ohne Leerraum entstünde "@endslotKarteninhalt" und der Slot bliebe offen.
        $this->blade('<x-settings.section id="betrieb" title="Betrieb"><x-slot:description>Erklärung links.</x-slot:description> Karteninhalt</x-settings.section>')
            ->assertSee('aria-labelledby="betrieb-title"', false)
            ->assertSeeInOrder(['id="betrieb-title"', 'Betrieb', 'Erklärung links.', 'Karteninhalt'], false)
            ->assertSee('Erklärung links.</div>', false)
            ->assertDontSee('@endslot', false);
    }

    public function test_save_bar_starts_hidden(): void
    {
        $this->blade('<x-save-bar />')
            ->assertSee('data-save-bar hidden', false)
            ->assertSee('Ungespeicherte Änderungen')
            ->assertSee('data-save-bar-discard', false)
            ->assertSee('data-save-bar-save', false)
            ->assertSee('<p class="sr-only" role="status" data-save-bar-status></p>', false);
    }
}
