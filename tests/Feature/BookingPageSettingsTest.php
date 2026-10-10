<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use App\Models\Location;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingPageSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** Inhalt der Seite; die Navigation nennt die Suchbegriffe aller Seiten. */
    private function mainContent(string $html): string
    {
        $this->assertSame(1, preg_match('/<main.*?<\/main>/s', $html, $main));

        return $main[0];
    }

    public function test_owner_sees_the_address_switches_and_widget_sources(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => '#aa3300']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $landing = route('booking.landing', $setup['tenant']->slug);

        $response = $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk();

        $response->assertSee('Einstellungen › Buchungen')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-copy="'.$landing.'"', false)
            ->assertSee('data-copy-message="Adresse der Buchungsseite kopiert."', false)
            ->assertSee('data-popup-src="'.route('booking.widget.popup.single', $setup['tenant']->slug).'"', false)
            ->assertSee('data-embed-src="'.route('booking.embed.single', $setup['tenant']->slug).'"', false)
            ->assertSee('data-booking-url="'.$landing.'"', false)
            ->assertSee('data-default-label="Jetzt reservieren"', false)
            ->assertSee('data-default-color="#aa3300"', false)
            ->assertSee('value="#aa3300"', false)
            ->assertSee('data-ajax data-savebar', false)
            ->assertSee('for="w-color"', false)
            ->assertSee('Farbe des Knopfs')
            ->assertSee('for="w-link-color"', false)
            ->assertSee('Farbe des Links')
            ->assertSee('name="public_floorplan_enabled"', false)
            ->assertSee('name="confetti_on_booking"', false)
            ->assertSee('<input type="hidden" name="confetti_on_booking" value="0">', false)
            ->assertSee('id="buchungsseite"', false)
            ->assertSee('id="popup"', false)
            ->assertSee('id="eingebettet"', false)
            ->assertSee('id="direktlink"', false)
            ->assertSee('href="'.$landing.'" target="_blank" rel="noopener" class="btn btn-secondary">Buchungsseite ansehen', false);
        $html = (string) $response->getContent();
        foreach (['popup', 'inline', 'link'] as $kind) {
            $this->assertStringContainsString('data-widget-snippet="'.$kind.'"', $html);
            $this->assertStringContainsString('data-widget-copy="'.$kind.'"', $html);
        }
        foreach (['label', 'color', 'float', 'link-label', 'link-color'] as $input) {
            $this->assertStringContainsString('data-widget-input="'.$input.'"', $html);
        }
        $this->assertStringNotContainsString('onclick=', $html);
        $this->assertMatchesRegularExpression('/data-nav-key="booking-page"[^>]*aria-current="page"/', $html);
    }

    public function test_the_switches_show_the_stored_values(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['public_floorplan_enabled' => true, 'confetti_on_booking' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input id="f-public_floorplan_enabled" type="checkbox" name="public_floorplan_enabled" value="1" checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input id="f-confetti_on_booking" type="checkbox" name="confetti_on_booking" value="1" checked/', $html);
    }

    public function test_restaurants_read_in_restaurant_words(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $main = $this->mainContent((string) $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk()->getContent());

        $this->assertStringContainsString('Tischplan zeigen', $main);
        $this->assertStringContainsString('wählen ihren Tisch selbst', $main);
        $this->assertStringContainsString('Unterseite „Tisch reservieren“', $main);
    }

    public function test_several_bookable_locations_get_the_address_of_the_current_one(): void
    {
        $setup = $this->createTenantSetup();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $slug = $setup['tenant']->slug;
        $locationSlug = $setup['location']->slug;
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk();

        $response->assertSee('data-booking-url="'.route('booking.show', [$slug, $locationSlug]).'"', false)
            ->assertSee('data-copy="'.route('booking.show', [$slug, $locationSlug]).'"', false)
            ->assertSee('data-embed-src="'.route('booking.embed', [$slug, $locationSlug]).'"', false)
            ->assertSee('data-popup-src="'.route('booking.widget.popup', [$slug, $locationSlug]).'"', false)
            ->assertSee('Die Adresse führt zum Standort „'.$setup['location']->name.'“. Unter '.route('booking.landing', $slug).' wählen Gäste zuerst den Standort.');
        $this->assertStringNotContainsString($second->slug, (string) $response->getContent());
    }

    public function test_a_single_bookable_location_among_several_uses_the_short_addresses(): void
    {
        $setup = $this->createTenantSetup();
        Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'is_active' => false]);
        Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'online_booking_enabled' => false]);
        $slug = $setup['tenant']->slug;
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertSee('data-booking-url="'.route('booking.landing', $slug).'"', false)
            ->assertSee('data-embed-src="'.route('booking.embed.single', $slug).'"', false)
            ->assertSee('data-popup-src="'.route('booking.widget.popup.single', $slug).'"', false)
            ->assertDontSee('wählen Gäste zuerst den Standort');
    }

    public function test_the_location_named_like_the_business_uses_the_short_widget_addresses(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['slug' => $setup['tenant']->slug]);
        Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $slug = $setup['tenant']->slug;
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertSee('data-embed-src="'.route('booking.embed.single', $slug).'"', false)
            ->assertSee('data-popup-src="'.route('booking.widget.popup.single', $slug).'"', false)
            ->assertSee('data-booking-url="'.route('booking.show', [$slug, $slug]).'"', false);
    }

    public function test_salons_get_their_own_label_and_no_floor_plan_switch(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertSee('data-default-label="Termin buchen"', false)
            ->assertSee('name="confetti_on_booking"', false)
            ->assertDontSee('name="public_floorplan_enabled"', false);

        $main = $this->mainContent((string) $response->getContent());
        // Die Erklärung spricht von Terminen.
        $this->assertStringContainsString('value="Termin buchen"', $main);
        $this->assertStringContainsString('Unterseite „Termin buchen“', $main);
        foreach (['Tisch', 'Reservier', 'Grundriss'] as $restaurantOnly) {
            $this->assertStringNotContainsString($restaurantOnly, $main);
        }
    }

    public function test_a_location_without_online_booking_is_named(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['online_booking_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertSee($setup['location']->name.' nimmt gerade keine Online-Buchungen an.')
            ->assertSee('Bitte frag den Support')
            ->assertDontSee('Buchungsseite ansehen');
    }

    public function test_an_inactive_location_points_to_the_locations_page(): void
    {
        $setup = $this->createTenantSetup();
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'is_active' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $owner->forceFill(['current_location_id' => $second->id])->save();
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertSee($second->name.' nimmt gerade keine Online-Buchungen an.')
            ->assertSee('href="'.route('admin.locations.index').'"', false)
            ->assertDontSee('Bitte frag den Support')
            ->assertDontSee('Buchungsseite ansehen');
    }

    public function test_a_bookable_location_shows_no_notice(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertDontSee('nimmt gerade keine Online-Buchungen an');
    }

    public function test_location_names_are_escaped_in_the_notice(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['online_booking_enabled' => false, 'name' => 'Café <b>"Mitte"</b>']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk()->getContent();

        $this->assertStringContainsString('Café &lt;b&gt;&quot;Mitte&quot;&lt;/b&gt; nimmt gerade keine Online-Buchungen an.', $html);
        $this->assertStringNotContainsString('<b>"Mitte"</b>', $html);
    }

    public function test_a_stored_brand_color_that_is_no_hex_color_falls_back_to_the_default(): void
    {
        config(['swayy.ui.default_brand_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Die Spalte fasst 9 Zeichen (varchar(9)); das Maskieren langer Eingaben prüfen die JS-Tests von widget.js.
        // Je Fall der gespeicherte Wert und das Bruchstück, das die Seite nie ausgeben darf (leer: es zählt die Rückkehr zur Vorgabe).
        $stored = [
            ['red', ''],
            ['#12345', ''],
            ["#aa3300\n", ''],
            ['#gggggg', '#gggggg'],
            ['#AbCdEf ', '#AbCdEf'],
            ['"><b>x', '<b>x'],
            ['#aa3300"x', '#aa3300"'],
        ];
        foreach ($stored as [$value, $forbidden]) {
            $setup['tenant']->update(['brand_primary_color' => $value]);

            $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk()->getContent();

            $this->assertStringContainsString('data-default-color="#123456"', $html, $value);
            $this->assertSame(2, substr_count($html, 'type="color" value="#123456"'), $value);
            if ($forbidden !== '') {
                $this->assertStringNotContainsString($forbidden, $html, $value);
            }
        }
    }

    public function test_the_brand_color_of_the_business_is_the_start_color(): void
    {
        config(['swayy.ui.default_brand_color' => '#123456']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => '#ABCDEF']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk()->getContent();

        $this->assertStringContainsString('data-default-color="#ABCDEF"', $html);
        $this->assertSame(2, substr_count($html, 'type="color" value="#ABCDEF"'));
    }

    public function test_the_longest_button_text_comes_from_the_settings(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/<input id="w-(?:link-)?label"[^>]*maxlength="40"/', $html));

        config(['swayy.ui.widget_label_max' => 25]);
        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk()->getContent();
        $this->assertSame(2, preg_match_all('/<input id="w-(?:link-)?label"[^>]*maxlength="25"/', $html));
    }

    public function test_roles_without_settings_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/booking-page')->assertForbidden();
        $this->actingAs($host)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1', 'public_floorplan_enabled' => '1'])->assertForbidden();
    }

    public function test_a_location_manager_may_open_and_save_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/booking-page')->assertOk();
        $this->actingAs($manager)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1', 'public_floorplan_enabled' => '1'])->assertOk();
    }

    public function test_the_routes_ask_for_the_rights_of_the_page(): void
    {
        $show = Route::getRoutes()->getByName('admin.settings.booking-page.show');
        $update = Route::getRoutes()->getByName('admin.settings.booking-page');

        $this->assertNotNull($show);
        $this->assertNotNull($update);
        $this->assertContains('permission:tables.manage', $show->gatherMiddleware());
        $this->assertNotContains('permission:opening_hours.manage', $show->gatherMiddleware());
        $this->assertContains('permission:tables.manage', $update->gatherMiddleware());
        $this->assertContains('permission:opening_hours.manage', $update->gatherMiddleware());
    }

    public function test_saving_writes_only_the_two_switches(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['confetti_on_booking' => true, 'public_floorplan_enabled' => false, 'buffer_minutes' => 20, 'waitlist_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '0', 'public_floorplan_enabled' => '1'])
            ->assertOk()
            ->assertExactJson(['message' => 'Buchungsseite gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertTrue($settings->public_floorplan_enabled);
        $this->assertSame(20, $settings->buffer_minutes);
        $this->assertFalse($settings->waitlist_enabled);
        $this->assertTrue(AuditLog::where('action', 'location.booking_page_updated')->exists());
    }

    public function test_every_switch_can_be_turned_off(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['confetti_on_booking' => true, 'public_floorplan_enabled' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '0', 'public_floorplan_enabled' => '0'])->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertFalse($settings->public_floorplan_enabled);
    }

    public function test_saving_without_javascript_returns_to_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/booking-page')
            ->put('/admin/settings/booking-page', ['confetti_on_booking' => '1', 'public_floorplan_enabled' => '1'])
            ->assertRedirect('/admin/settings/booking-page')
            ->assertSessionHas('success', 'Buchungsseite gespeichert.');
    }

    public function test_saving_changes_only_the_current_location(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['confetti_on_booking' => false, 'public_floorplan_enabled' => false]);
        $second = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $second->settings()->create(['tenant_id' => $setup['tenant']->id, 'confetti_on_booking' => false, 'public_floorplan_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1', 'public_floorplan_enabled' => '1'])->assertOk();

        $this->assertTrue($setup['location']->settings()->first()->confetti_on_booking);
        $this->assertFalse($second->settings()->first()->confetti_on_booking);
        $this->assertFalse($second->settings()->first()->public_floorplan_enabled);
    }

    public function test_the_audit_entry_names_the_old_and_new_values_in_german(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['confetti_on_booking' => false, 'public_floorplan_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1', 'public_floorplan_enabled' => '1'])->assertOk();

        $entry = AuditLog::where('action', 'location.booking_page_updated')->sole();
        $this->assertSame('Standort: Buchungsseite geändert', $entry->actionLabel());
        $this->assertSame(['confetti_on_booking' => false, 'public_floorplan_enabled' => false], $entry->old_values);
        $this->assertSame(['confetti_on_booking' => true, 'public_floorplan_enabled' => true], $entry->new_values);
        $changes = collect($entry->fieldChanges())->keyBy('field');
        $this->assertSame('Konfetti nach dem Buchen', $changes['confetti_on_booking']['label']);
        $this->assertSame('Tischplan zeigen', $changes['public_floorplan_enabled']['label']);
    }

    public function test_a_salon_saves_confetti_and_keeps_the_floor_plan_switch(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update(['public_floorplan_enabled' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1'])->assertOk();
        $this->assertTrue($setup['location']->settings()->first()->public_floorplan_enabled);

        // Eine Seite aus der Zeit vor dem Wechsel zum Salon sendet den Schalter für den Tischplan mit; er bleibt unberührt.
        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '0', 'public_floorplan_enabled' => '0'])->assertOk();
        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertTrue($settings->public_floorplan_enabled);
        $this->assertSame(['confetti_on_booking'], array_keys(AuditLog::where('action', 'location.booking_page_updated')->latest('id')->first()->new_values));
    }

    public function test_a_missing_switch_is_named_with_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['public_floorplan_enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.confetti_on_booking.0', 'Die Einstellung „Konfetti nach dem Buchen“ fehlt im gesendeten Formular. Bitte die Seite neu laden und erneut speichern.');

        // Das Formular eines Salons lässt den Tischplan weg; ein Restaurant braucht ihn.
        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.public_floorplan_enabled.0', 'Die Einstellung „Tischplan zeigen“ fehlt im gesendeten Formular. Bitte die Seite neu laden und erneut speichern.');
    }

    public function test_an_invalid_switch_value_is_named_with_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['confetti_on_booking' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => 'vielleicht', 'public_floorplan_enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.confetti_on_booking.0', 'Bitte „Konfetti nach dem Buchen“ ein- oder ausschalten.');

        $this->assertTrue($setup['location']->settings()->first()->confetti_on_booking);
    }

    public function test_a_rejected_form_without_javascript_keeps_the_input(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['public_floorplan_enabled' => false, 'confetti_on_booking' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $content = (string) $this->actingAs($owner)
            ->from('/admin/settings/booking-page')
            ->followingRedirects()
            ->put('/admin/settings/booking-page', ['public_floorplan_enabled' => '1', 'confetti_on_booking' => 'vielleicht'])
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('/<input id="f-public_floorplan_enabled" type="checkbox" name="public_floorplan_enabled" value="1" checked/', $content);
        $this->assertStringContainsString('Bitte „Konfetti nach dem Buchen“ ein- oder ausschalten.', $content);
    }

    public function test_the_setup_assistant_links_to_the_page(): void
    {
        $source = (string) file_get_contents(resource_path('views/admin/onboarding/index.blade.php'));

        $this->assertStringContainsString("route('admin.settings.booking-page.show') }}#popup", $source);
        $this->assertStringNotContainsString("route('admin.settings.index') }}#widget", $source);
    }
}
