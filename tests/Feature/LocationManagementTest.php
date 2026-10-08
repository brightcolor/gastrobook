<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LocationManagementTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

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
            'timezone' => 'Europe/Berlin',
            'address_line1' => 'Marktplatz 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'public_intro' => 'Direkt am Markt.',
        ])->assertOk()->assertJson(['message' => 'Standort „Am Markt“ gespeichert.', 'reload' => true]);

        $fresh = $setup['location']->fresh();
        $this->assertSame('Am Markt', $fresh->name);
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
}
