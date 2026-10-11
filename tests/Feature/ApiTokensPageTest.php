<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class ApiTokensPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    public function test_the_page_uses_the_new_pattern(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();

        $response->assertSee('Einstellungen › Verbindungen')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('id="schluessel-neu"', false)
            ->assertSee('Noch keine API-Schlüssel')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="api"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_creating_answers_with_json_and_shows_the_key_once(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['api_enabled' => true]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read']])
            ->assertOk()
            ->assertJsonPath('reload', true);

        $page = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();
        $page->assertSee('Kasse')->assertSee('data-copy=', false);
        $this->actingAs($owner)->get('/admin/api-tokens')->assertDontSee('Jetzt kopieren');
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_a_missing_scope_names_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['api_enabled' => true]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => []])->assertStatus(422);

        $this->assertSame('Bitte mindestens ein Recht anhaken. Für die meisten Programme reicht „Reservierungen lesen“.', $response->json('errors.scopes.0'));
    }

    public function test_a_plan_without_api_explains_where_to_upgrade(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['api_enabled' => false]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/api-tokens')
            ->assertSee('Die Schnittstelle ist in deinem Tarif nicht enthalten.')
            ->assertSee('href="'.route('admin.billing.show').'"', false);
        $response = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read']])->assertForbidden();
        $this->assertStringContainsString('Abo & Rechnungen', (string) $response->json('message'));
    }

    public function test_without_the_feature_every_way_to_create_a_key_is_switched_off(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['api_enabled' => false]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/api-tokens')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/<button(?![^>]*\bdisabled\b)[^>]*data-drawer-open="schluessel-neu"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*form="form-schluessel-neu"[^>]*disabled/', $html);
        $this->assertStringContainsString('id="schluessel-neu"', $html);
    }

    public function test_with_the_feature_the_create_buttons_are_enabled(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/api-tokens')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<button(?![^>]*\bdisabled\b)[^>]*data-drawer-open="schluessel-neu"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*form="form-schluessel-neu"[^>]*disabled/', $html);
        $this->assertStringNotContainsString('in deinem Tarif nicht enthalten', $html);
    }

    public function test_the_new_key_stays_out_of_every_later_page_and_the_list(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read']])->assertOk();
        $plain = (string) $this->app['session.store']->get('new_token');
        $this->assertNotSame('', $plain);

        $first = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();
        $first->assertSee('Jetzt kopieren: Swayy zeigt diesen Schlüssel nur einmal.')
            ->assertSee('data-copy="'.$plain.'"', false);
        $this->assertStringContainsString('<code', (string) $first->getContent());

        $second = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();
        $second->assertDontSee($plain)->assertDontSee('Jetzt kopieren');
        $this->actingAs($owner)->get('/admin/settings/general')->assertDontSee($plain);
    }

    public function test_creating_without_javascript_redirects_and_still_shows_the_key_once(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->post('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read', 'availability:read']])
            ->assertRedirect()->assertSessionHas('success');

        $this->actingAs($owner)->get('/admin/api-tokens')->assertSee('Jetzt kopieren');
        $token = PersonalAccessToken::firstOrFail();
        $this->assertContains('availability:read', $token->abilities);
        $this->assertContains('tenant:'.$setup['tenant']->id, $token->abilities);
    }

    public function test_the_success_message_names_the_key(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read']])
            ->assertOk()
            ->assertJsonPath('message', 'API-Schlüssel „Kasse“ angelegt. Kopiere ihn jetzt; Swayy zeigt ihn nur einmal.');
    }

    public function test_the_list_shows_name_rights_owner_and_last_use_in_words(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $colleague = $this->createMember($setup['tenant'], 'host');
        $abilities = ['tenant:'.$setup['tenant']->id, 'reservations:read', 'availability:read'];
        $owner->createToken('Eigene Kasse', $abilities);
        $used = $colleague->createToken('Website', $abilities)->accessToken;
        $used->forceFill(['last_used_at' => now()->subDays(3)])->save();
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();

        $response->assertSee('Eigene Kasse')
            ->assertSee('Website')
            ->assertSee('Reservierungen lesen, Freie Zeiten abfragen')
            ->assertSee('Besitzer: '.$colleague->name)
            ->assertSee('noch nie genutzt')
            ->assertSee('zuletzt genutzt vor 3 Tagen')
            ->assertDontSee('Besitzer: '.$owner->name);
    }

    public function test_salons_read_appointments_and_customers(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/api-tokens')->assertOk();

        $response->assertSee('Termine lesen')
            ->assertSee('Termine anlegen und ändern')
            ->assertSee('Kunden lesen')
            ->assertDontSee('Reservierungen lesen')
            ->assertDontSee('Gäste lesen');
    }

    public function test_restaurants_read_reservations_and_guests(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/api-tokens')->assertOk()
            ->assertSee('Reservierungen lesen')
            ->assertSee('Reservierungen anlegen und ändern')
            ->assertSee('Freie Zeiten abfragen')
            ->assertSee('Gäste lesen')
            ->assertDontSee('Termine lesen');
    }

    public function test_every_right_is_offered_with_a_label_and_a_sentence(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/api-tokens')->assertOk()->getContent();

        foreach (['reservations:read', 'reservations:write', 'guests:read', 'guests:write', 'availability:read', 'waitlist:write', 'events:read', 'events:write', 'webhooks:manage', 'reports:read'] as $scope) {
            $this->assertStringContainsString('name="scopes[]" value="'.$scope.'"', $html, $scope);
        }
        // Gruppenkästchen senden vor dem Kästchen keine 0: Sie würde als Recht ankommen.
        $this->assertStringNotContainsString('name="scopes[]" value="0"', $html);
        $this->assertStringContainsString('<fieldset id="f-scopes"', $html);
    }

    public function test_the_page_has_no_inline_handlers_and_no_secret_in_the_list(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $token = $owner->createToken('Kasse', ['tenant:'.$setup['tenant']->id, 'reservations:read']);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/api-tokens')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input|keydown)\s*=/i', $html);
        $this->assertStringContainsString('data-inline-confirm', $html);
        $this->assertStringNotContainsString($token->plainTextToken, $html);
        $this->assertStringNotContainsString(explode('|', $token->plainTextToken)[1], $html);
        $this->assertStringContainsString('Programme mit diesem Schlüssel verlieren sofort den Zugang.', $html);
    }

    public function test_the_example_section_links_the_description_of_the_interface(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/api-tokens')->assertOk()
            ->assertSee('So nutzt ein Programm den Schlüssel')
            ->assertSee('Authorization: Bearer')
            ->assertSee('href="'.url('/api/v1/openapi.yaml').'"', false);
    }

    public function test_revoking_answers_with_json_and_names_the_key(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $token = $owner->createToken('Kasse', ['tenant:'.$setup['tenant']->id, 'reservations:read'])->accessToken;
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson('/admin/api-tokens/'.$token->id)
            ->assertOk()
            ->assertJsonPath('reload', true)
            ->assertJsonPath('message', 'API-Schlüssel „Kasse“ widerrufen.');

        $this->assertSame(0, PersonalAccessToken::count());
        $entry = AuditLog::withoutGlobalScopes()->where('action', 'api_token.deleted')->firstOrFail();
        $this->assertSame('Kasse', $entry->old_values['name']);
    }

    public function test_a_key_of_another_business_cannot_be_revoked(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $foreignOwner = $this->createMember($other['tenant'], 'tenant_owner');
        $foreign = $foreignOwner->createToken('Fremd', ['tenant:'.$other['tenant']->id, 'reservations:read'])->accessToken;
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson('/admin/api-tokens/'.$foreign->id)->assertNotFound();
        $this->assertSame(1, PersonalAccessToken::count());
    }

    public function test_unknown_rights_and_names_are_refused_with_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $unknown = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read', 'alles:loeschen']])->assertStatus(422);
        $this->assertSame('Dieses Recht gibt es nicht. Bitte die Seite neu laden.', $unknown->json('errors')['scopes.1'][0]);

        $noName = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => '', 'scopes' => ['reservations:read']])->assertStatus(422);
        $this->assertSame('Bitte einen Namen eintragen, etwa das Programm, das den Schlüssel nutzt.', $noName->json('errors.name.0'));

        $this->assertSame(0, PersonalAccessToken::count());
    }

    public function test_the_name_limit_comes_from_the_settings(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $default = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => str_repeat('n', 81), 'scopes' => ['reservations:read']])->assertStatus(422);
        $this->assertSame('Der Name darf höchstens 80 Zeichen haben.', $default->json('errors.name.0'));

        config(['swayy.api_tokens.name_max' => 12]);
        $html = (string) $this->actingAs($owner)->get('/admin/api-tokens')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="name"[^>]*maxlength="12"/', $html);

        $custom = $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => str_repeat('n', 13), 'scopes' => ['reservations:read']])->assertStatus(422);
        $this->assertSame('Der Name darf höchstens 12 Zeichen haben.', $custom->json('errors.name.0'));
        $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => str_repeat('n', 12), 'scopes' => ['reservations:read']])->assertOk();
    }

    public function test_the_name_limit_stays_in_its_bounds_and_is_documented(): void
    {
        [$config, $warnings] = $this->swayyConfig(['SWAYY_API_TOKEN_NAME_MAX' => '90000']);
        $this->assertSame(255, $config['api_tokens']['name_max']);
        $this->assertStringContainsString('SWAYY_API_TOKEN_NAME_MAX', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_API_TOKEN_NAME_MAX' => '1']);
        $this->assertSame(10, $config['api_tokens']['name_max']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(80, $config['api_tokens']['name_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_API_TOKEN_NAME_MAX']);
    }

    public function test_the_longest_allowed_name_is_stored_and_read_back(): void
    {
        config(['swayy.api_tokens.name_max' => 255]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $name = str_repeat('ä', 255);
        $this->actingAs($owner)->postJson('/admin/api-tokens', ['name' => $name, 'scopes' => ['reservations:read']])->assertOk();

        $this->assertSame($name, PersonalAccessToken::firstOrFail()->name);
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'api');
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->values()->unique();
        $this->assertContains('schluessel-neu', $targets->all());

        $response = $this->actingAs($owner)->get('/admin/api-tokens');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }

    public function test_service_staff_cannot_manage_keys(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/api-tokens')->assertForbidden();
        $this->actingAs($staff)->postJson('/admin/api-tokens', ['name' => 'Kasse', 'scopes' => ['reservations:read']])->assertForbidden();
    }
}
