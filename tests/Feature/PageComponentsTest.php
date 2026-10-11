<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PageComponentsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Eingaben wie nach einer abgelehnten Anfrage: old() liest sie aus der Sitzung der aktuellen Anfrage. */
    private function flashOldInput(array $input): void
    {
        $session = $this->app['session']->driver();
        $session->put('_old_input', $input);
        $this->app['request']->setLaravelSession($session);
    }

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

    public function test_settings_section_columns_never_grow_wider_than_the_screen(): void
    {
        // Ohne feste Spaltenbreite wächst die Spalte auf die Breite der
        // längsten Zeile, etwa eines Codebeispiels, und die Seite scrollt
        // auf dem Smartphone seitlich.
        $this->blade('<x-settings.section id="beispiel" title="Beispiel"> Inhalt</x-settings.section>')
            ->assertSee('class="grid grid-cols-1 ', false)
            ->assertSee('<div class="min-w-0 lg:col-span-2">', false);
    }

    public function test_settings_section_places_the_aside_after_the_card_and_under_the_explanation_on_wide_screens(): void
    {
        // Ohne Slot "aside" bleibt der Aufbau wie bisher.
        $this->blade('<x-settings.section id="beispiel" title="Beispiel"> Inhalt</x-settings.section>')
            ->assertDontSee('lg:row-span-2', false)
            ->assertDontSee('lg:grid-rows-', false)
            ->assertDontSee('lg:sticky', false);

        // Der Slot steht im Dokument nach der Karte (Smartphone: darunter), ab 1024 px links unter der Erklärung.
        $this->blade('<x-settings.section id="woche" title="Woche"><x-slot:description>Erklärung links.</x-slot:description> Karteninhalt <x-slot:aside>Vorschau unten.</x-slot:aside></x-settings.section>')
            ->assertSeeInOrder(['Erklärung links.', 'Karteninhalt', 'Vorschau unten.'], false)
            ->assertSee('lg:grid-rows-[auto_1fr]', false)
            ->assertSee('<div class="min-w-0 lg:col-span-2 lg:row-span-2">', false)
            ->assertSee('lg:sticky lg:top-20 lg:col-start-1 lg:row-start-2', false);
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

    public function test_save_bar_names_the_unit_of_its_counter(): void
    {
        $this->blade('<x-save-bar one="1 Tag geändert" many=":count Tage geändert" />')
            ->assertSee('data-count-one="1 Tag geändert"', false)
            ->assertSee('data-count-many=":count Tage geändert"', false)
            ->assertSee('data-save-bar-count', false);

        $this->blade('<x-save-bar />')
            ->assertSee('data-count-one="1 Einstellung geändert"', false)
            ->assertSee('data-count-many=":count Einstellungen geändert"', false);
    }

    public function test_page_header_groups_scope_and_actions_for_the_save_bar(): void
    {
        $this->blade('<x-page-header title="Tags" scope="tenant"><x-slot:actions><button type="button">Tag hinzufügen</button></x-slot:actions></x-page-header>')
            ->assertSeeInOrder(['data-page-header-controls', 'Gilt für alle Standorte', 'Tag hinzufügen'], false);
    }

    public function test_field_can_explain_minutes_in_hours(): void
    {
        $this->blade('<x-field name="buffer_minutes" label="Puffer" type="number" unit="Min." :minutes="true" value="90" />')
            ->assertSee('data-minutes-hint', false)
            ->assertSee('<p id="f-buffer_minutes-minutes" data-minutes-hint-for="f-buffer_minutes"', false)
            ->assertSee('aria-describedby="f-buffer_minutes-unit f-buffer_minutes-minutes"', false)
            ->assertDontSee('data-minutes-unit', false);
    }

    public function test_field_can_explain_hours_in_days(): void
    {
        $this->blade('<x-field name="reminder_hours_before" label="Stunden vorher" type="number" unit="Std." minutes="hours" value="36" />')
            ->assertSee('data-minutes-hint', false)
            ->assertSee('data-minutes-unit="hours"', false)
            ->assertSee('<p id="f-reminder_hours_before-minutes" data-minutes-hint-for="f-reminder_hours_before"', false);
    }

    public function test_checkbox_sends_zero_without_a_tick_and_links_its_help(): void
    {
        $this->blade('<x-checkbox name="waitlist_enabled" label="Warteliste anbieten" :checked="true" help="Gäste tragen sich ein, wenn alles belegt ist." />')
            ->assertSeeInOrder([
                '<input type="hidden" name="waitlist_enabled" value="0">',
                'id="f-waitlist_enabled" type="checkbox" name="waitlist_enabled" value="1"',
            ], false)
            ->assertSee('checked', false)
            ->assertSee('aria-describedby="f-waitlist_enabled-help"', false)
            ->assertSee('<label for="f-waitlist_enabled"', false);

        $this->blade('<x-checkbox name="walkins_enabled" label="Walk-ins erfassen" :checked="false" />')
            ->assertDontSee('checked', false)
            ->assertDontSee('aria-describedby', false);
    }

    public function test_checkbox_box_is_large_enough_to_hit(): void
    {
        $html = Blade::render('<x-checkbox name="waitlist_enabled" label="Warteliste anbieten" />');

        // 24 px (h-6 w-6), oben so verschoben, dass die Mitte auf der ersten Textzeile der Beschriftung liegt.
        $this->assertMatchesRegularExpression('/<input id="f-waitlist_enabled" type="checkbox"[^>]*class="-mt-0\.5 h-6 w-6 shrink-0"/', $html);
    }

    public function test_checkbox_follows_the_old_input_after_a_rejected_save(): void
    {
        $this->flashOldInput(['waitlist_enabled' => '1']);
        $this->blade('<x-checkbox name="waitlist_enabled" label="Warteliste anbieten" :checked="false" />')
            ->assertSee('value="1" checked', false);

        $this->flashOldInput(['waitlist_enabled' => '0']);
        $this->blade('<x-checkbox name="waitlist_enabled" label="Warteliste anbieten" :checked="true" />')
            ->assertDontSee('checked', false);
    }

    public function test_checkbox_passes_extra_attributes_to_the_box_and_the_class_to_the_wrapper(): void
    {
        $html = Blade::render('<x-checkbox name="waitlist_enabled" label="Warteliste anbieten" class="mt-4" data-foo="bar" />');

        $this->assertMatchesRegularExpression('/<input id="f-waitlist_enabled" type="checkbox"[^>]*data-foo="bar"/', $html);
        $this->assertMatchesRegularExpression('/<div class="[^"]*mt-4[^"]*">/', $html);
        $this->assertSame(1, substr_count($html, 'data-foo'));
        $this->assertDoesNotMatchRegularExpression('/<div[^>]*data-foo/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*class="[^"]*mt-4/', $html);
    }

    public function test_change_dot_starts_hidden_and_names_the_state(): void
    {
        $this->blade('<x-change-dot />')
            ->assertSee('data-change-dot hidden', false)
            ->assertSee('<span class="sr-only">Geändert</span>', false);
    }
}
