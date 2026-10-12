<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class LocationManagementTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    public function test_owner_can_add_a_second_location(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        // Plan with room for 5 locations.
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 5]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->post('/admin/locations', [
            'name' => 'Filiale Süd',
            'timezone' => 'Europe/Berlin',
        ])->assertRedirect(route('admin.locations.index'));

        $loc = Location::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)
            ->where('name', 'Filiale Süd')
            ->first();

        $this->assertNotNull($loc);
        $this->assertSame('filiale-sud', $loc->slug);
        $this->assertTrue($loc->is_active);
        // Settings row is created alongside the location.
        $this->assertNotNull($loc->settings()->withoutGlobalScopes()->first());
    }

    public function test_plan_limit_blocks_additional_locations(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Tenant already has its initial location → limit of 1 is reached.
        $this->actingAs($owner)->post('/admin/locations', [
            'name' => 'Zweite (verboten)',
            'timezone' => 'Europe/Berlin',
        ])->assertSessionHasErrors('name');

        $this->assertSame(1, Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->count());
    }

    public function test_owner_can_rename_location_without_changing_slug(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $location = $setup['location'];
        $originalSlug = $location->slug;
        $this->clearTenantContext();

        $this->actingAs($owner)->put("/admin/locations/{$location->id}", [
            'name' => 'Neuer Name',
            'timezone' => 'Europe/Vienna',
        ])->assertRedirect();

        $fresh = $location->fresh();
        $this->assertSame('Neuer Name', $fresh->name);
        $this->assertSame('Europe/Vienna', $fresh->timezone);
        $this->assertSame($originalSlug, $fresh->slug, 'Slug must stay stable so booking links keep working.');
    }

    public function test_last_active_location_cannot_be_deactivated(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->post("/admin/locations/{$setup['location']->id}/toggle-active")
            ->assertSessionHasErrors('active');

        $this->assertTrue($setup['location']->fresh()->is_active);
    }

    public function test_staff_cannot_manage_locations(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/locations')->assertForbidden();
        $this->actingAs($staff)->post('/admin/locations', [
            'name' => 'X', 'timezone' => 'Europe/Berlin',
        ])->assertForbidden();
    }

    public function test_cannot_edit_location_of_other_tenant(): void
    {
        $a = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $b = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $ownerA = $this->createMember($a['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($ownerA)->put("/admin/locations/{$b['location']->id}", [
            'name' => 'Hijack', 'timezone' => 'Europe/Berlin',
        ])->assertNotFound();

        $this->assertNotSame('Hijack', $b['location']->fresh()->name);
    }

    public function test_json_store_answers_with_message_and_reload(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 5]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/locations', [
            'name' => 'Filiale Nord',
            'timezone' => 'Europe/Vienna',
            'public_intro' => 'Willkommen im Norden.',
        ])->assertOk()->assertExactJson([
            'message' => 'Standort „Filiale Nord“ angelegt. Als Nächstes Öffnungszeiten und Tische einrichten.',
            'reload' => true,
        ]);

        $loc = Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->where('name', 'Filiale Nord')->firstOrFail();
        $this->assertSame('Europe/Vienna', $loc->timezone);
        $this->assertSame('Willkommen im Norden.', $loc->public_intro);
    }

    public function test_json_store_names_the_plan_limit_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/locations', ['name' => 'Zweite', 'timezone' => 'Europe/Berlin'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Dein Tarif umfasst einen Standort, und der ist schon angelegt. Für einen weiteren Standort den Tarif unter „Abo & Rechnungen“ wechseln.');
    }

    public function test_json_validation_explains_what_to_enter(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", ['name' => '', 'timezone' => 'Mond/Basis'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Bitte einen Namen für den Standort eintragen.')
            ->assertJsonPath('errors.timezone.0', 'Bitte eine Zeitzone aus der Liste wählen.');
    }

    public function test_a_missing_timezone_names_the_field(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", ['name' => 'Hafen'])
            ->assertStatus(422)
            ->assertJsonPath('errors.timezone.0', 'Bitte eine Zeitzone aus der Liste wählen.');
    }

    public function test_json_validation_names_the_field_and_the_length_limit(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", [
            'name' => 'Am Markt',
            'timezone' => 'Europe/Berlin',
            'public_intro' => str_repeat('a', 1001),
            'email' => 'kein-at-zeichen',
        ])->assertStatus(422)
            ->assertJsonPath('errors.public_intro.0', 'Begrüßung ist zu lang. Bitte auf höchstens 1000 Zeichen kürzen.')
            ->assertJsonPath('errors.email.0', 'Die E-Mail-Adresse ist unvollständig. Bitte in der Form name@beispiel.de eintragen.');
    }

    public function test_json_update_saves_profile_and_keeps_the_slug(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $slug = $setup['location']->slug;
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", [
            'name' => 'Am Markt',
            'timezone' => 'Europe/Vienna',
            'phone' => '+49 30 123456',
            'email' => 'hallo@aurora.test',
            'address_line1' => 'Marktplatz 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'public_intro' => 'Direkt am Markt.',
        ])->assertOk()->assertJson(['message' => 'Standort „Am Markt“ gespeichert.', 'reload' => true]);

        $fresh = $setup['location']->fresh();
        $this->assertSame('Am Markt', $fresh->name);
        $this->assertSame('Europe/Vienna', $fresh->timezone);
        $this->assertSame(['+49 30 123456', 'hallo@aurora.test'], [$fresh->phone, $fresh->email]);
        $this->assertSame('Direkt am Markt.', $fresh->public_intro);
        $this->assertSame('Musterstadt', $fresh->city);
        $this->assertSame($slug, $fresh->slug);
    }

    public function test_json_toggle_explains_why_the_last_active_location_stays_active(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson("/admin/locations/{$setup['location']->id}/toggle-active")
            ->assertStatus(422)
            ->assertJsonPath('errors.active.0', fn (string $message) => str_contains($message, 'einzige aktive Standort')
                && str_contains($message, 'Lege zuerst einen weiteren Standort an'));
        $this->assertTrue($setup['location']->fresh()->is_active);
    }

    public function test_index_uses_drawers_status_icons_and_no_inline_handlers(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/locations')->assertOk()->getContent();

        $this->assertStringContainsString('data-drawer-open="standort-'.$setup['location']->id.'"', $html);
        $this->assertStringContainsString('id="standort-'.$setup['location']->id.'" data-drawer hidden', $html);
        $this->assertStringContainsString('<span class="sr-only">Aktiv</span>', $html);
        $this->assertStringContainsString('name="public_intro"', $html);
        $this->assertStringContainsString('Logo hochladen', $html);

        foreach (['index', 'partials/fields', 'partials/logo'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/locations/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }

    public function test_new_location_form_suggests_the_default_timezone_from_the_settings(): void
    {
        config(['swayy.locations.default_timezone' => 'Europe/Vienna']);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 5]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/locations')->assertOk()->getContent();
        $drawer = (string) substr($html, (int) strpos($html, 'id="standort-neu"'));
        $drawer = (string) substr($drawer, 0, (int) strpos($drawer, 'id="standort-'.$setup['location']->id.'"'));

        $this->assertStringContainsString('<option value="Europe/Vienna" selected>', $drawer);
        $this->assertStringNotContainsString('<option value="Europe/Berlin" selected>', $drawer);
    }

    public function test_operations_manager_edits_locations_without_logo_controls(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/locations')
            ->assertOk()
            ->assertSee('name="public_intro"', false)
            ->assertDontSee('Logo hochladen');
    }

    public function test_the_plan_limit_names_a_next_step_the_role_can_take(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1]])->id]);
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        // Die Betriebsleitung legt Standorte an, öffnet „Abo & Rechnungen“ aber nicht.
        $this->actingAs($manager)->postJson('/admin/locations', ['name' => 'Zweite', 'timezone' => 'Europe/Berlin'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Dein Tarif umfasst einen Standort, und der ist schon angelegt. Für einen weiteren Standort bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.');
    }

    public function test_the_intro_limit_comes_from_the_settings(): void
    {
        config(['swayy.locations.intro_max' => 120]);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/locations')
            ->assertOk()
            ->assertSee('bis 120 Zeichen')
            ->assertSee('maxlength="120"', false);

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", [
            'name' => 'Mitte', 'timezone' => 'Europe/Berlin', 'public_intro' => str_repeat('a', 121),
        ])->assertStatus(422)
            ->assertJsonValidationErrors('public_intro')
            ->assertJsonPath('errors.public_intro.0', 'Begrüßung ist zu lang. Bitte auf höchstens 120 Zeichen kürzen.');

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", [
            'name' => 'Mitte', 'timezone' => 'Europe/Berlin', 'public_intro' => str_repeat('a', 120),
        ])->assertOk();
    }

    public function test_the_field_limits_come_from_the_settings(): void
    {
        $limits = ['name' => 30, 'address_line1' => 50, 'postal_code' => 8, 'city' => 25, 'phone' => 15, 'email' => 60];
        config([
            'swayy.locations.name_max' => 30, 'swayy.locations.address_max' => 50, 'swayy.locations.postal_code_max' => 8,
            'swayy.locations.city_max' => 25, 'swayy.locations.phone_max' => 15, 'swayy.locations.email_max' => 60,
        ]);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $id = $setup['location']->id;
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/locations')->assertOk()->getContent();
        foreach ($limits as $field => $max) {
            $this->assertSame(1, preg_match('/<input\b[^>]*\bid="loc-'.$id.'-'.$field.'"[^>]*>/', $html, $input), "Das Feld {$field} fehlt.");
            $this->assertStringContainsString('maxlength="'.$max.'"', $input[0], "{$field} trägt nicht maxlength {$max}.");
        }

        $valid = ['name' => 'Mitte', 'timezone' => 'Europe/Berlin'];
        $attributes = ['name' => 'Name', 'address_line1' => 'Straße und Hausnummer', 'postal_code' => 'PLZ', 'city' => 'Ort', 'phone' => 'Telefon', 'email' => 'E-Mail'];
        foreach ($limits as $field => $max) {
            // Eine E-Mail-Adresse bleibt auch am Limit gültig: Der lokale Teil füllt auf.
            $atLimit = $field === 'email' ? str_repeat('a', $max - 12).'@example.com' : str_repeat('ä', $max);
            $tooLong = $field === 'email' ? str_repeat('a', $max - 11).'@example.com' : str_repeat('ä', $max + 1);

            $this->actingAs($owner)->putJson("/admin/locations/{$id}", [$field => $tooLong] + $valid)
                ->assertStatus(422)
                ->assertJsonPath("errors.{$field}.0", "{$attributes[$field]} ist zu lang. Bitte auf höchstens {$max} Zeichen kürzen.");
            $this->actingAs($owner)->putJson("/admin/locations/{$id}", [$field => $atLimit] + $valid)->assertOk();
        }
    }

    public function test_the_field_limits_default_to_the_former_values_and_stay_within_the_columns(): void
    {
        $keys = ['name_max', 'phone_max', 'email_max', 'address_max', 'postal_code_max', 'city_max'];
        $variables = ['SWAYY_LOCATION_NAME_MAX', 'SWAYY_LOCATION_PHONE_MAX', 'SWAYY_LOCATION_EMAIL_MAX', 'SWAYY_LOCATION_ADDRESS_MAX', 'SWAYY_LOCATION_POSTAL_CODE_MAX', 'SWAYY_LOCATION_CITY_MAX'];

        [$config] = $this->swayyConfig([]);
        $this->assertSame(
            ['name_max' => 120, 'phone_max' => 40, 'email_max' => 200, 'address_max' => 200, 'postal_code_max' => 20, 'city_max' => 100],
            array_intersect_key($config['locations'], array_flip($keys)),
        );

        // locations.name, phone, email, address_line1, postal_code und city sind varchar(255).
        [$config, $warnings] = $this->swayyConfig(array_fill_keys($variables, '9999'));
        foreach ($keys as $key) {
            $this->assertSame(255, $config['locations'][$key]);
        }
        foreach ($variables as $variable) {
            $this->assertStringContainsString($variable, $warnings);
        }

        [$config] = $this->swayyConfig(array_fill_keys($variables, '1'));
        $this->assertSame(
            ['name_max' => 10, 'phone_max' => 10, 'email_max' => 30, 'address_max' => 20, 'postal_code_max' => 5, 'city_max' => 10],
            array_intersect_key($config['locations'], array_flip($keys)),
        );

        $this->assertDocumentedInEnvExample($variables);
    }

    public function test_the_slug_of_a_longest_name_fits_its_column(): void
    {
        config(['swayy.locations.name_max' => 255]);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 5]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Aus jedem „ä“ wird im Kürzel „ae“: Der Name hat 255 Zeichen, das ungekürzte Kürzel 510.
        $name = str_repeat('ä', 255);
        foreach ([1, 2] as $_) {
            $this->actingAs($owner)->postJson('/admin/locations', ['name' => $name, 'timezone' => 'Europe/Berlin'])->assertOk();
        }

        $slugs = Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->where('name', $name)->pluck('slug');
        $this->assertCount(2, $slugs);
        foreach ($slugs as $slug) {
            $this->assertLessThanOrEqual(255, strlen($slug));
        }
    }

    public function test_a_cut_slug_never_ends_in_a_hyphen(): void
    {
        config(['swayy.locations.name_max' => 255]);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Das Kürzel hat 239 Buchstaben, dann einen Bindestrich: Der Schnitt bei 240 Zeichen träfe den Bindestrich.
        $name = str_repeat('a', 239).' b';
        $this->actingAs($owner)->postJson('/admin/locations', ['name' => $name, 'timezone' => 'Europe/Berlin'])->assertOk();

        $slug = (string) Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->where('name', $name)->value('slug');
        $this->assertSame(str_repeat('a', 239), $slug);
    }

    public function test_a_rejected_form_keeps_the_input_and_marks_the_fields(): void
    {
        config(['swayy.locations.intro_max' => 100]);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $id = $setup['location']->id;
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->from('/admin/locations')
            ->followingRedirects()
            ->put("/admin/locations/{$id}", ['name' => 'Mitte', 'timezone' => 'Europe/Vienna', 'public_intro' => str_repeat('a', 101)])
            ->getContent();

        $this->assertMatchesRegularExpression('/<option value="Europe\/Vienna" selected>/', $html);
        $this->assertMatchesRegularExpression('/id="loc-'.$id.'-public_intro"[^>]*aria-describedby="loc-'.$id.'-public_intro-help loc-'.$id.'-public_intro-error"[^>]*aria-invalid="true"/s', $html);
        $this->assertStringContainsString('id="loc-'.$id.'-public_intro-error"', $html);
        $this->assertStringContainsString('Begrüßung ist zu lang. Bitte auf höchstens 100 Zeichen kürzen.', $html);
    }

    public function test_an_intro_at_the_highest_allowed_limit_is_saved_in_full(): void
    {
        config(['swayy.locations.intro_max' => 5000]);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $intro = str_repeat('ä', 5000);
        $this->clearTenantContext();

        $this->assertSame(5000, mb_strlen($intro));
        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", [
            'name' => 'Mitte', 'timezone' => 'Europe/Berlin', 'public_intro' => $intro,
        ])->assertOk();

        $this->assertSame($intro, $setup['location']->fresh()->public_intro);
    }

    public function test_the_intro_limit_defaults_to_one_thousand_characters(): void
    {
        $this->assertSame(1000, config('swayy.locations.intro_max'));
    }

    public function test_the_timezone_is_marked_as_required(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/locations')->getContent();

        $this->assertMatchesRegularExpression('/Zeitzone<span class="text-red-700" aria-hidden="true"> \*<\/span>/', $html);
    }

    public function test_more_locations_than_the_plan_allows_reads_plainly(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1]])->id]);
        Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/locations')
            ->assertOk()
            ->assertSee('2 Standorte angelegt, dein Tarif umfasst einen.')
            ->assertDontSee('2 von 1 Standorten');
    }

    public function test_the_location_count_reads_plainly_for_every_plan_size(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 3]])->id]);
        Location::factory()->count(2)->create(['tenant_id' => $setup['tenant']->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/locations')
            ->assertOk()
            ->assertSee('3 von 3 Standorten deines Tarifs genutzt');

        Location::factory()->create(['tenant_id' => $setup['tenant']->id]);

        $this->actingAs($owner)->get('/admin/locations')
            ->assertOk()
            ->assertSee('4 Standorte angelegt, dein Tarif umfasst 3.')
            ->assertDontSee('4 von 3 Standorten');

        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 0]])->id]);

        $this->actingAs($owner)->get('/admin/locations')
            ->assertOk()
            ->assertSee('4 Standorte angelegt, dein Tarif umfasst keinen.');
    }
}
