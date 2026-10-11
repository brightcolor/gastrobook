<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use App\Models\NotificationTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class TemplatesPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    public function test_the_page_lists_every_template_with_its_state_and_drawer(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        NotificationTemplate::create(['tenant_id' => $setup['tenant']->id, 'location_id' => null, 'key' => 'reservation_reminder', 'locale' => 'de', 'subject' => 'Bis morgen', 'body' => 'Hallo {guest_name}', 'is_active' => true]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/templates')->assertOk();

        $response->assertSee('Einstellungen › Gästekommunikation')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('id="vorlage-reservation_reminder"', false)
            ->assertSee('id="vorlage-feedback_request"', false)
            ->assertSee('Angepasst')
            ->assertSee('Standard')
            ->assertSee('{guest_name}')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="templates"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_saving_answers_with_json_for_the_drawer(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/templates/reservation_reminder', ['subject' => 'Bis morgen', 'body' => 'Hallo {guest_name}'])
            ->assertOk()
            ->assertJsonPath('message', 'Vorlage „Erinnerung“ gespeichert.')
            ->assertJsonPath('reload', true);
    }

    public function test_limits_come_from_the_settings_and_name_the_next_step(): void
    {
        config(['swayy.templates.subject_max' => 20]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/templates/reservation_reminder', ['subject' => str_repeat('x', 21), 'body' => 'Text'])
            ->assertStatus(422);

        $this->assertSame('Der Betreff darf höchstens 20 Zeichen haben. Bitte kürzen.', $response->json('errors.subject.0'));
        $this->actingAs($owner)->get('/admin/templates')->assertSee('maxlength="20"', false);
    }

    public function test_an_unknown_template_answers_404(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/templates/gibt_es_nicht', ['subject' => 'x', 'body' => 'y'])->assertNotFound();
    }

    public function test_reset_answers_with_json_and_removes_only_this_business(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach ([$setup['tenant'], $other['tenant']] as $tenant) {
            NotificationTemplate::create(['tenant_id' => $tenant->id, 'location_id' => null, 'key' => 'reservation_reminder', 'locale' => 'de', 'subject' => 'S', 'body' => 'B', 'is_active' => true]);
        }
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson('/admin/templates/reservation_reminder')
            ->assertOk()
            ->assertJsonPath('message', 'Vorlage „Erinnerung“ auf Standard zurückgesetzt.');

        $this->assertSame(1, NotificationTemplate::withoutGlobalScopes()->where('key', 'reservation_reminder')->count());
        $this->assertSame($other['tenant']->id, NotificationTemplate::withoutGlobalScopes()->where('key', 'reservation_reminder')->value('tenant_id'));
    }

    public function test_the_text_limit_comes_from_the_settings_too(): void
    {
        config(['swayy.templates.body_max' => 600]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/templates')->assertOk();
        $this->assertMatchesRegularExpression('/<textarea[^>]*name="body"[^>]*maxlength="600"/', (string) $response->getContent());

        $rejected = $this->actingAs($owner)->putJson('/admin/templates/feedback_request', ['subject' => 'Betreff', 'body' => str_repeat('x', 601)])
            ->assertStatus(422);
        $this->assertSame('Der Text darf höchstens 600 Zeichen haben. Bitte kürzen.', $rejected->json('errors.body.0'));

        $this->actingAs($owner)->putJson('/admin/templates/feedback_request', ['subject' => 'Betreff', 'body' => str_repeat('x', 600)])->assertOk();
    }

    public function test_empty_fields_name_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/templates/reservation_reminder', ['subject' => '', 'body' => ''])
            ->assertStatus(422);

        $this->assertSame('Bitte einen Betreff eintragen.', $response->json('errors.subject.0'));
        $this->assertSame('Bitte einen Text eintragen. Zum Zurücksetzen auf den Standard gibt es „Auf Standard zurücksetzen“.', $response->json('errors.body.0'));
        $this->assertSame(0, NotificationTemplate::withoutGlobalScopes()->count());
    }

    public function test_a_value_that_is_no_text_is_refused_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/templates/reservation_reminder', ['subject' => ['a'], 'body' => ['b']])
            ->assertStatus(422);

        $this->assertStringContainsString('Bitte', $response->json('errors.subject.0'));
        $this->assertStringContainsString('Bitte', $response->json('errors.body.0'));
    }

    public function test_a_classic_save_returns_to_the_page_with_the_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/templates')
            ->put('/admin/templates/feedback_request', ['subject' => 'Wie war es?', 'body' => 'Sag uns Bescheid.'])
            ->assertRedirect('/admin/templates')
            ->assertSessionHas('success', 'Vorlage „Feedback-Anfrage“ gespeichert.');

        $this->actingAs($owner)->from('/admin/templates')
            ->delete('/admin/templates/feedback_request')
            ->assertRedirect('/admin/templates')
            ->assertSessionHas('success', 'Vorlage „Feedback-Anfrage“ auf Standard zurückgesetzt.');
    }

    public function test_the_limits_fit_the_columns_and_are_documented(): void
    {
        // notification_templates.subject ist varchar(255), body ein text ohne Obergrenze der Länge.
        [$config, $warnings] = $this->swayyConfig(['SWAYY_TEMPLATE_SUBJECT_MAX' => '4000', 'SWAYY_TEMPLATE_BODY_MAX' => '900000']);
        $this->assertSame(255, $config['templates']['subject_max']);
        $this->assertSame(20000, $config['templates']['body_max']);
        $this->assertStringContainsString('SWAYY_TEMPLATE_SUBJECT_MAX', $warnings);
        $this->assertStringContainsString('SWAYY_TEMPLATE_BODY_MAX', $warnings);

        [$config, $warnings] = $this->swayyConfig(['SWAYY_TEMPLATE_SUBJECT_MAX' => '5', 'SWAYY_TEMPLATE_BODY_MAX' => '10']);
        $this->assertSame(20, $config['templates']['subject_max']);
        $this->assertSame(500, $config['templates']['body_max']);
        $this->assertStringContainsString('SWAYY_TEMPLATE_SUBJECT_MAX', $warnings);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(200, $config['templates']['subject_max']);
        $this->assertSame(5000, $config['templates']['body_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_TEMPLATE_SUBJECT_MAX', 'SWAYY_TEMPLATE_BODY_MAX']);
    }

    public function test_the_largest_allowed_values_fit_their_columns(): void
    {
        config(['swayy.templates.subject_max' => 255, 'swayy.templates.body_max' => 20000]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $subject = str_repeat('ä', 255);
        $body = str_repeat('ö', 20000);
        $this->actingAs($owner)->putJson('/admin/templates/reservation_reminder', ['subject' => $subject, 'body' => $body])->assertOk();

        $template = NotificationTemplate::withoutGlobalScopes()->where('key', 'reservation_reminder')->firstOrFail();
        $this->assertSame($subject, $template->subject);
        $this->assertSame($body, $template->body);
    }

    public function test_only_changed_templates_offer_the_reset_and_ask_before(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        NotificationTemplate::create(['tenant_id' => $setup['tenant']->id, 'location_id' => null, 'key' => 'reservation_reminder', 'locale' => 'de', 'subject' => 'Bis morgen', 'body' => 'Hallo', 'is_active' => true]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/templates')->assertOk();

        $response->assertSee('id="vorlage-zuruecksetzen-reservation_reminder"', false)
            ->assertDontSee('id="vorlage-zuruecksetzen-feedback_request"', false)
            ->assertSee('Vorlage „Erinnerung“ auf den Standardtext zurücksetzen? Deine Fassung geht dabei verloren.')
            ->assertSee('data-reload-after-save', false);
    }

    public function test_every_template_has_a_drawer_with_its_own_form(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/templates')->assertOk()->getContent();

        foreach (['reservation_confirmed', 'reservation_requested', 'reservation_cancelled', 'reservation_rejected', 'reservation_reminder',
            'feedback_request', 'waitlist_offer', 'payment_pending', 'payment_reminder', 'payment_expired'] as $key) {
            $this->assertStringContainsString('id="vorlage-'.$key.'"', $html);
            $this->assertStringContainsString('data-drawer-open="vorlage-'.$key.'"', $html);
            $this->assertMatchesRegularExpression('/<form[^>]*action="'.preg_quote(route('admin.templates.update', $key), '/').'"[^>]*data-ajax/', $html);
        }
    }

    public function test_the_page_describes_the_mail_the_way_it_is_sent(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/templates')
            ->assertOk()
            ->assertSee('Swayy ersetzt sie beim Versand durch die Angaben der Buchung.')
            ->assertSee('data-copy="{guest_name}"', false)
            ->assertDontSee('reiner Text');
    }

    public function test_templates_of_other_businesses_stay_out(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        NotificationTemplate::create(['tenant_id' => $other['tenant']->id, 'location_id' => null, 'key' => 'reservation_reminder', 'locale' => 'de', 'subject' => 'Fremder Betreff', 'body' => 'Fremder Text', 'is_active' => true]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/templates')
            ->assertOk()
            ->assertDontSee('Fremder Betreff')
            ->assertDontSee('vorlage-zuruecksetzen-reservation_reminder', false);
    }

    public function test_salons_read_appointments(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/templates')
            ->assertOk()
            ->assertSee('Termin bestätigt')
            ->assertDontSee('Reservierung bestätigt');
        $this->actingAs($owner)->putJson('/admin/templates/reservation_cancelled', ['subject' => 'Abgesagt', 'body' => 'Schade.'])
            ->assertOk()
            ->assertJsonPath('message', 'Vorlage „Termin storniert“ gespeichert.');
    }

    public function test_saving_and_resetting_are_logged_with_german_labels(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/templates/feedback_request', ['subject' => 'Wie war es?', 'body' => 'Sag uns Bescheid.'])->assertOk();
        $this->actingAs($owner)->deleteJson('/admin/templates/feedback_request')->assertOk();

        $saved = AuditLog::where('action', 'template.updated')->firstOrFail();
        $this->assertSame('E-Mail-Vorlage geändert', $saved->actionLabel());
        $this->assertSame('Vorlage', $saved->fieldChanges()[0]['label']);
        $this->assertSame('E-Mail-Vorlage zurückgesetzt', AuditLog::where('action', 'template.reset')->firstOrFail()->actionLabel());
    }

    public function test_salons_do_not_see_placeholders_for_tables_and_rooms(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/templates')->assertOk()
            ->assertSee('data-copy="{table_name}"', false)
            ->assertSee('data-copy="{room_name}"', false);

        $setup['tenant']->update(['type' => TenantType::Salon]);
        $this->actingAs($owner)->get('/admin/templates')->assertOk()
            ->assertDontSee('data-copy="{table_name}"', false)
            ->assertDontSee('data-copy="{room_name}"', false)
            ->assertSee('data-copy="{guest_name}"', false);
    }

    public function test_the_search_terms_lead_to_drawers_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'templates');
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->values()->unique();
        $this->assertGreaterThanOrEqual(3, $targets->count());
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('fragment', $item);

        $response = $this->actingAs($owner)->get('/admin/templates');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }

    public function test_roles_without_the_template_right_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/templates')->assertForbidden();
        $this->actingAs($manager)->putJson('/admin/templates/reservation_reminder', ['subject' => 'x', 'body' => 'y'])->assertForbidden();
        $this->actingAs($manager)->deleteJson('/admin/templates/reservation_reminder')->assertForbidden();
    }
}
