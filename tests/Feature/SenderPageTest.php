<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class SenderPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    public function test_owner_sees_name_reply_address_and_relay(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/sender')->assertOk();

        $response->assertSee('Einstellungen › Gästekommunikation')
            ->assertSee('id="absender"', false)
            ->assertSee('id="antworten"', false)
            ->assertSee('name="mail_from_name"', false)
            ->assertSee('name="mail_reply_to"', false)
            ->assertSee('name="mail_relay_enabled"', false)
            ->assertSee('data-ajax data-savebar', false);
        $this->assertMatchesRegularExpression('/data-nav-key="sender"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_saving_writes_only_the_three_fields_and_answers_with_json(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => '#123456']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', [
            'mail_from_name' => 'Trattoria Test',
            'mail_reply_to' => 'antwort@example.test',
            'mail_relay_enabled' => '0',
        ])->assertOk()->assertJsonPath('message', 'Absender und Antworten gespeichert.');

        $tenant = $setup['tenant']->fresh();
        $this->assertSame('Trattoria Test', $tenant->mail_from_name);
        $this->assertSame('antwort@example.test', $tenant->mail_reply_to);
        $this->assertFalse((bool) $tenant->mail_relay_enabled);
        $this->assertSame('#123456', $tenant->brand_primary_color);
    }

    public function test_the_relay_needs_a_configured_domain_and_says_so(): void
    {
        config(['swayy.guest_mail_relay.domain' => '']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => '', 'mail_reply_to' => '', 'mail_relay_enabled' => '1'])
            ->assertStatus(422);

        $this->assertStringContainsString('Bitte wende dich an den Betreiber', $response->json('errors.mail_relay_enabled.0'));
    }

    public function test_staff_without_settings_rights_is_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/sender')->assertForbidden();
    }

    public function test_roles_with_tables_but_without_tenant_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/sender')->assertForbidden();
        $this->actingAs($manager)->putJson('/admin/settings/guest-mail', ['mail_from_name' => 'X'])->assertForbidden();
    }

    public function test_the_fields_show_what_applies_when_they_stay_empty(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['mail_from_name' => null, 'mail_reply_to' => null]);
        $setup['location']->update(['name' => 'Waldhaus am See', 'email' => 'info@waldhaus.test']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/sender')
            ->assertOk()
            ->assertSee('placeholder="Waldhaus am See"', false)
            ->assertSee('Leer: „Waldhaus am See“.')
            ->assertSee('placeholder="info@waldhaus.test"', false)
            ->assertSee('Leer: info@waldhaus.test')
            ->assertDontSee('erreichen Antworten von Gästen niemanden');
    }

    public function test_without_any_reply_address_a_hint_asks_for_one(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['mail_reply_to' => null]);
        $setup['location']->update(['email' => null]);
        $setup['location']->settings()->update(['owner_notification_email' => null]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/sender')
            ->assertOk()
            ->assertSee('Bitte eintragen. Solange hier und am Standort keine Adresse steht, erreichen Antworten von Gästen niemanden.')
            ->assertSee('data-show-if="mail_reply_to:"', false);
    }

    public function test_the_relay_switch_is_locked_until_the_operator_set_up_the_domain(): void
    {
        config(['swayy.guest_mail_relay.domain' => '']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/sender')->assertOk()
            ->assertSee('Diese Funktion richtet der Betreiber von Swayy ein. Bitte frag dort nach.')
            ->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*type="checkbox"[^>]*name="mail_relay_enabled"[^>]*disabled/', $html);

        config(['swayy.guest_mail_relay.domain' => 'antwort.example.test']);
        $html = (string) $this->actingAs($owner)->get('/admin/settings/sender')->assertOk()
            ->assertDontSee('Diese Funktion richtet der Betreiber von Swayy ein.')
            ->assertSee('Antworten erscheinen im Buchungsverlauf und gehen zusätzlich an deine Adresse.')
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="checkbox"[^>]*name="mail_relay_enabled"[^>]*disabled/', $html);
    }

    public function test_the_relay_can_be_switched_on_and_off_with_a_domain(): void
    {
        config(['swayy.guest_mail_relay.domain' => 'antwort.example.test']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => '', 'mail_reply_to' => '', 'mail_relay_enabled' => '1'])->assertOk();
        $this->assertTrue($setup['tenant']->fresh()->mail_relay_enabled);

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => '', 'mail_reply_to' => '', 'mail_relay_enabled' => '0'])->assertOk();
        $this->assertFalse($setup['tenant']->fresh()->mail_relay_enabled);
    }

    public function test_empty_fields_are_saved_as_empty(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['mail_from_name' => 'Alt', 'mail_reply_to' => 'alt@example.test']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => '', 'mail_reply_to' => '', 'mail_relay_enabled' => '0'])->assertOk();

        $tenant = $setup['tenant']->fresh();
        $this->assertNull($tenant->mail_from_name);
        $this->assertNull($tenant->mail_reply_to);
    }

    public function test_every_rejection_names_the_field_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/guest-mail', [
            'mail_from_name' => 'Pizza <Mario>',
            'mail_reply_to' => 'keine-adresse',
            'mail_relay_enabled' => '0',
        ])->assertStatus(422);

        $this->assertSame('Der Absendername darf keine Zeichen wie <, >, @ oder Anführungszeichen enthalten. Bitte nur den Namen des Betriebs eintragen.', $response->json('errors.mail_from_name.0'));
        $this->assertSame('Die Antwortadresse ist keine gültige E-Mail-Adresse. Bitte so eintragen: info@dein-betrieb.de', $response->json('errors.mail_reply_to.0'));
    }

    public function test_the_lengths_come_from_the_settings(): void
    {
        config(['swayy.guest_mail.from_name_max' => 12, 'swayy.guest_mail.reply_to_max' => 30]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/sender')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="mail_from_name"[^>]*maxlength="12"/', $html);
        $this->assertMatchesRegularExpression('/name="mail_reply_to"[^>]*maxlength="30"/', $html);

        $response = $this->actingAs($owner)->putJson('/admin/settings/guest-mail', [
            'mail_from_name' => str_repeat('a', 13),
            'mail_reply_to' => str_repeat('a', 20).'@example.test',
            'mail_relay_enabled' => '0',
        ])->assertStatus(422);
        $this->assertSame('Der Absendername darf höchstens 12 Zeichen haben. Bitte kürzen.', $response->json('errors.mail_from_name.0'));
        $this->assertSame('Die Antwortadresse darf höchstens 30 Zeichen haben. Bitte kürzen.', $response->json('errors.mail_reply_to.0'));

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', [
            'mail_from_name' => str_repeat('a', 12),
            'mail_reply_to' => 'a@example.test',
            'mail_relay_enabled' => '0',
        ])->assertOk();
    }

    public function test_the_limits_fit_the_columns_and_are_documented(): void
    {
        // tenants.mail_from_name und mail_reply_to sind varchar(255).
        [$config, $warnings] = $this->swayyConfig(['SWAYY_GUEST_MAIL_FROM_NAME_MAX' => '5000', 'SWAYY_GUEST_MAIL_REPLY_TO_MAX' => '5000']);
        $this->assertSame(255, $config['guest_mail']['from_name_max']);
        $this->assertSame(255, $config['guest_mail']['reply_to_max']);
        $this->assertStringContainsString('SWAYY_GUEST_MAIL_FROM_NAME_MAX', $warnings);
        $this->assertStringContainsString('SWAYY_GUEST_MAIL_REPLY_TO_MAX', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_GUEST_MAIL_FROM_NAME_MAX' => '1', 'SWAYY_GUEST_MAIL_REPLY_TO_MAX' => '1']);
        $this->assertSame(10, $config['guest_mail']['from_name_max']);
        $this->assertSame(30, $config['guest_mail']['reply_to_max']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(100, $config['guest_mail']['from_name_max']);
        $this->assertSame(200, $config['guest_mail']['reply_to_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_GUEST_MAIL_FROM_NAME_MAX', 'SWAYY_GUEST_MAIL_REPLY_TO_MAX']);
    }

    public function test_the_largest_allowed_values_fit_their_columns(): void
    {
        config(['swayy.guest_mail.from_name_max' => 255, 'swayy.guest_mail.reply_to_max' => 255]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $name = str_repeat('ä', 255);
        $address = str_repeat('a', 64).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 55).'.test';
        $this->assertLessThanOrEqual(255, strlen($address));
        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => $name, 'mail_reply_to' => $address, 'mail_relay_enabled' => '0'])->assertOk();

        $tenant = $setup['tenant']->fresh();
        $this->assertSame($name, $tenant->mail_from_name);
        $this->assertSame($address, $tenant->mail_reply_to);
    }

    public function test_a_classic_save_returns_to_the_page_with_the_message_or_the_errors(): void
    {
        config(['swayy.guest_mail_relay.domain' => '']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/sender')
            ->put('/admin/settings/guest-mail', ['mail_from_name' => 'Trattoria Test', 'mail_reply_to' => '', 'mail_relay_enabled' => '0'])
            ->assertRedirect('/admin/settings/sender')
            ->assertSessionHas('success', 'Absender und Antworten gespeichert.');

        $this->actingAs($owner)->from('/admin/settings/sender')
            ->put('/admin/settings/guest-mail', ['mail_from_name' => 'Trattoria Test', 'mail_reply_to' => '', 'mail_relay_enabled' => '1'])
            ->assertRedirect('/admin/settings/sender')
            ->assertSessionHasErrors('mail_relay_enabled');
        $this->assertFalse($setup['tenant']->fresh()->mail_relay_enabled);
    }

    /**
     * Die alte Sammelseite sendet das Kästchen nur angehakt: ein fehlender
     * Schalter gilt als „aus“.
     */
    public function test_the_old_combined_page_still_saves_without_the_switch(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['mail_relay_enabled' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $page = $this->actingAs($owner)->get('/admin/settings')->assertOk();
        $page->assertSee('action="'.route('admin.settings.guest-mail').'"', false);

        $this->actingAs($owner)->put('/admin/settings/guest-mail', ['mail_from_name' => 'Alt-Seite', 'mail_reply_to' => 'alt@example.test'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $tenant = $setup['tenant']->fresh();
        $this->assertSame('Alt-Seite', $tenant->mail_from_name);
        $this->assertSame('alt@example.test', $tenant->mail_reply_to);
        $this->assertFalse($tenant->mail_relay_enabled);
    }

    public function test_the_old_combined_page_offers_the_configured_lengths(): void
    {
        config(['swayy.guest_mail.from_name_max' => 25, 'swayy.guest_mail.reply_to_max' => 60]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="mail_from_name"[^>]*maxlength="25"/', $html);
        $this->assertMatchesRegularExpression('/name="mail_reply_to"[^>]*maxlength="60"/', $html);
    }

    public function test_saving_leaves_other_businesses_untouched(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $other['tenant']->update(['mail_from_name' => 'Fremder Betrieb', 'mail_reply_to' => 'fremd@example.test']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => 'Eigener Betrieb', 'mail_reply_to' => '', 'mail_relay_enabled' => '0'])->assertOk();

        $untouched = Tenant::findOrFail($other['tenant']->id);
        $this->assertSame('Fremder Betrieb', $untouched->mail_from_name);
        $this->assertSame('fremd@example.test', $untouched->mail_reply_to);
    }

    public function test_the_log_records_old_and_new_values_with_german_labels(): void
    {
        config(['swayy.guest_mail_relay.domain' => 'antwort.example.test']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['mail_from_name' => 'Alt', 'mail_reply_to' => null, 'mail_relay_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/guest-mail', ['mail_from_name' => 'Neu', 'mail_reply_to' => 'neu@example.test', 'mail_relay_enabled' => '1'])->assertOk();

        $log = AuditLog::where('action', 'tenant.guest_mail_updated')->firstOrFail();
        $this->assertSame('Absender für Gästemails geändert', $log->actionLabel());
        $changes = collect($log->fieldChanges())->keyBy('field');
        $this->assertSame('Absendername', $changes['mail_from_name']['label']);
        $this->assertSame('Alt', $changes['mail_from_name']['from']);
        $this->assertSame('Neu', $changes['mail_from_name']['to']);
        $this->assertSame('Antwortadresse', $changes['mail_reply_to']['label']);
        $this->assertSame('Antworten über Swayy', $changes['mail_relay_enabled']['label']);
        $this->assertSame('Nein', $changes['mail_relay_enabled']['from']);
        $this->assertSame('Ja', $changes['mail_relay_enabled']['to']);
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'sender');
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('fragment', $item);
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->values()->unique();
        $this->assertGreaterThanOrEqual(2, $targets->count());

        $response = $this->actingAs($owner)->get('/admin/settings/sender');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }
}
