<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Http\Controllers\Admin\WebhookController;
use App\Jobs\DeliverWebhook;
use App\Models\AuditLog;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class WebhooksPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    private const URL = 'https://93.184.216.34/swayy-hook';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function endpoint(int $tenantId, array $attributes = []): WebhookEndpoint
    {
        return WebhookEndpoint::create(array_merge([
            'tenant_id' => $tenantId, 'url' => 'https://hooks.example.test/swayy', 'secret' => 'altes-geheimnis-xyz', 'events' => ['*'], 'is_active' => true,
        ], $attributes));
    }

    public function test_the_page_uses_the_new_pattern(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/webhooks')->assertOk();

        $response->assertSee('Einstellungen › Verbindungen')
            ->assertSee('id="webhook-neu"', false)
            ->assertSee('Noch keine Webhooks')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="webhooks"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    /**
     * Die Datenbank hält UTC. Die Seite zeigt die Ortszeit des Standorts und sagt es mit „Uhr“, wie das Protokoll.
     */
    public function test_times_appear_in_the_location_timezone_with_uhr(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['webhooks_enabled' => true]]);
        $setup['location']->update(['timezone' => 'Asia/Tokyo']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id, ['is_active' => false, 'failure_count' => 5, 'disabled_at' => '2026-03-10 20:15:00']);
        $delivery = WebhookDelivery::create([
            'tenant_id' => $setup['tenant']->id, 'webhook_endpoint_id' => $endpoint->id, 'event' => 'ping',
            'payload' => [], 'attempt' => 1, 'status' => 'success', 'response_code' => 200,
        ]);
        $delivery->forceFill(['created_at' => '2026-03-10 15:30:00'])->save();
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/webhooks')->assertOk()
            ->assertSee('inaktiv seit 11.03.2026 05:15 Uhr')
            ->assertSee('11.03.2026 00:30 Uhr')
            ->assertDontSee('10.03.2026 20:15')
            ->assertDontSee('10.03.2026 15:30');
    }

    public function test_times_without_an_active_location_use_the_default_zone_of_the_settings(): void
    {
        config(['swayy.locations.default_timezone' => 'Asia/Tokyo']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['webhooks_enabled' => true]]);
        $setup['location']->update(['is_active' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $delivery = WebhookDelivery::create([
            'tenant_id' => $setup['tenant']->id, 'webhook_endpoint_id' => $endpoint->id, 'event' => 'ping',
            'payload' => [], 'attempt' => 1, 'status' => 'success', 'response_code' => 200,
        ]);
        $delivery->forceFill(['created_at' => '2026-03-10 15:30:00'])->save();
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/webhooks')->assertOk()->assertSee('11.03.2026 00:30 Uhr');
    }

    public function test_actions_answer_with_json(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['webhooks_enabled' => true]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = WebhookEndpoint::create(['tenant_id' => $setup['tenant']->id, 'url' => 'https://hooks.example.test/swayy', 'secret' => 'alt', 'events' => ['*'], 'is_active' => true]);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/toggle')->assertOk()->assertJsonPath('message', 'Webhook pausiert.');
        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/rotate-secret')->assertOk()->assertJsonPath('reload', true);
        $this->actingAs($owner)->deleteJson('/admin/webhooks/'.$endpoint->id)->assertOk()->assertJsonPath('message', 'Webhook gelöscht.');
    }

    public function test_creating_answers_with_json_and_shows_the_secret_once(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks', ['url' => self::URL, 'events' => ['reservation.created']])
            ->assertOk()
            ->assertJsonPath('reload', true)
            ->assertJsonPath('message', 'Webhook angelegt. Kopiere das Geheimnis jetzt; Swayy zeigt es nur einmal.');

        $endpoint = WebhookEndpoint::withoutGlobalScopes()->firstOrFail();
        $plain = $endpoint->secret;
        // Ein Flash der Sitzung: Er überlebt genau die nächste Anfrage.
        $this->assertContains('new_secret', (array) session('_flash.old'));
        $this->assertContains('new_secret_url', (array) session('_flash.old'));

        $first = $this->actingAs($owner)->get('/admin/webhooks')->assertOk();
        $first->assertSee('Jetzt kopieren: Swayy zeigt dieses Geheimnis nur einmal.')
            ->assertSee('data-copy="'.$plain.'"', false)
            ->assertSee('X-Gastrobook-Signature')
            ->assertSee(self::URL);

        $second = $this->actingAs($owner)->get('/admin/webhooks')->assertOk();
        $second->assertDontSee($plain)->assertDontSee('Jetzt kopieren');
    }

    public function test_creating_without_javascript_redirects_and_keeps_the_old_flow(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->post('/admin/webhooks', ['url' => self::URL, 'events' => ['*']])
            ->assertRedirect()->assertSessionHas('new_secret')->assertSessionHas('success');
    }

    public function test_rotating_names_the_endpoint_and_shows_the_new_secret_once(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/rotate-secret')->assertOk()->assertJsonPath('reload', true);
        $fresh = WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint->id)->secret;
        $this->assertNotSame('altes-geheimnis-xyz', $fresh);

        $this->actingAs($owner)->get('/admin/webhooks')->assertSee('data-copy="'.$fresh.'"', false)
            ->assertSee('Geheimnis für https://hooks.example.test/swayy');
        $this->actingAs($owner)->get('/admin/webhooks')->assertDontSee($fresh);
    }

    public function test_a_secret_never_appears_in_the_list(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/webhooks')->assertOk()->getContent();

        $this->assertStringNotContainsString('altes-geheimnis-xyz', $html);
        $this->assertStringContainsString('https://hooks.example.test/swayy', $html);
    }

    public function test_pausing_and_activating_answer_with_the_state_in_words(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/toggle')->assertOk()->assertJsonPath('reload', true);
        $this->assertFalse(WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint->id)->is_active);
        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/toggle')->assertOk()->assertJsonPath('message', 'Webhook aktiviert.');
        $this->assertTrue(WebhookEndpoint::withoutGlobalScopes()->findOrFail($endpoint->id)->is_active);
    }

    public function test_the_audit_log_names_a_pause_in_german(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/toggle')->assertOk();
        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/toggle')->assertOk();

        $this->assertSame('Webhook pausiert', AuditLog::labelFor('webhook.deactivated'));
        $this->assertTrue(AuditLog::hasGermanLabel('webhook.deactivated'));
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'webhook.deactivated')->count());
        $this->assertSame(1, AuditLog::withoutGlobalScopes()->where('action', 'webhook.activated')->count());
    }

    public function test_the_list_names_the_state_in_words(): void
    {
        config(['swayy.webhooks.disable_after' => 5]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->endpoint($setup['tenant']->id, ['url' => 'https://aktiv.example.test/a']);
        $this->endpoint($setup['tenant']->id, ['url' => 'https://pause.example.test/p', 'is_active' => false, 'failure_count' => 2, 'disabled_at' => now()]);
        $this->endpoint($setup['tenant']->id, ['url' => 'https://aus.example.test/x', 'is_active' => false, 'failure_count' => 5, 'disabled_at' => now()]);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/webhooks')->assertOk()->getContent();

        $rows = preg_split('/<li\b/', $html);
        $row = fn (string $needle) => collect($rows)->first(fn (string $chunk) => str_contains($chunk, $needle));
        $this->assertStringContainsString('>aktiv</span>', (string) $row('https://aktiv.example.test/a'));
        $this->assertStringContainsString('>pausiert</span>', (string) $row('https://pause.example.test/p'));
        $this->assertStringContainsString('>abgeschaltet nach Fehlern</span>', (string) $row('https://aus.example.test/x'));
        $this->assertStringNotContainsString('abgeschaltet nach Fehlern', (string) $row('https://pause.example.test/p'));
        $this->assertStringContainsString('2 Fehler in Folge', (string) $row('https://pause.example.test/p'));
        $this->assertStringContainsString('inaktiv seit', (string) $row('https://pause.example.test/p'));
        $this->assertStringNotContainsString('inaktiv seit', (string) $row('https://aktiv.example.test/a'));
    }

    public function test_events_read_as_german_words_with_the_technical_name_beside_them(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->endpoint($setup['tenant']->id, ['events' => ['reservation.created', 'payment.succeeded']]);
        $this->endpoint($setup['tenant']->id, ['url' => 'https://alle.example.test/x', 'events' => ['*']]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/webhooks')->assertOk();

        $response->assertSee('Neue Reservierung, Zahlung eingegangen')
            ->assertSee('Alle Ereignisse')
            ->assertSee('<code>reservation.created</code>', false);
    }

    public function test_every_event_has_a_label_for_restaurants_and_for_salons(): void
    {
        $this->assertSame([], array_values(array_diff(WebhookController::EVENTS, array_keys(WebhookController::EVENT_LABELS))));
        $this->assertSame([], array_values(array_diff(array_keys(WebhookController::EVENT_LABELS), WebhookController::EVENTS)));
        $this->assertSame([], array_values(array_diff(array_keys(WebhookController::EVENT_LABELS_SALON), WebhookController::EVENTS)));
        $this->assertSame('Neue Reservierung', WebhookController::EVENT_LABELS['reservation.created']);
    }

    public function test_salons_read_appointments_and_customers(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/webhooks')->assertOk()
            ->assertSee('Neuer Termin')
            ->assertSee('Termin bestätigt')
            ->assertSee('Ein Webhook meldet Ereignisse, etwa einen neuen Termin, automatisch')
            ->assertDontSee('Neue Reservierung')
            ->assertDontSee('Reservierung bestätigt')
            ->assertDontSee('etwa eine neue Reservierung');
    }

    public function test_restaurants_read_reservations(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/webhooks')->assertOk()
            ->assertSee('Neue Reservierung')
            ->assertSee('Reservierung bestätigt')
            ->assertSee('Ein Webhook meldet Ereignisse, etwa eine neue Reservierung, automatisch')
            ->assertDontSee('Neuer Termin')
            ->assertDontSee('etwa einen neuen Termin');
    }

    public function test_the_drawer_offers_all_events_and_one_box_per_event_without_a_zero(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/webhooks')->assertOk()->getContent();

        $this->assertStringContainsString('name="events[]" value="*"', $html);
        foreach (WebhookController::EVENTS as $event) {
            $this->assertStringContainsString('name="events[]" value="'.$event.'"', $html, $event);
        }
        $this->assertStringNotContainsString('name="events[]" value="0"', $html);
        $this->assertStringContainsString('<fieldset id="f-events"', $html);
        $this->assertMatchesRegularExpression('/name="url"[^>]*maxlength="255"/', $html);
    }

    public function test_the_actions_use_small_forms_and_inline_confirmations_without_handlers(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/webhooks')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input|keydown)\s*=/i', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/webhooks\/'.$endpoint->id.'\/toggle"[^>]*data-ajax/', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/webhooks\/'.$endpoint->id.'\/ping"[^>]*data-ajax/', $html);
        $this->assertStringContainsString('Testereignis senden', $html);
        $this->assertStringContainsString('Die Gegenstelle prüft danach mit dem neuen Geheimnis; bis zur Umstellung schlagen Zustellungen fehl.', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/webhooks\/'.$endpoint->id.'\/rotate-secret"[^>]*data-ajax/', $html);
        $this->assertMatchesRegularExpression('/<form[^>]*action="[^"]*\/webhooks\/'.$endpoint->id.'"[^>]*data-ajax/', $html);
        $this->assertStringContainsString('data-inline-confirm', $html);
    }

    public function test_a_test_event_answers_with_json_and_goes_through_the_queue(): void
    {
        Queue::fake();
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks/'.$endpoint->id.'/ping')
            ->assertOk()
            ->assertJsonPath('message', 'Testereignis verschickt. Das Ergebnis steht gleich im Zustellprotokoll.');

        Queue::assertPushed(DeliverWebhook::class);
        $this->assertSame('ping', WebhookDelivery::withoutGlobalScopes()->firstOrFail()->event);
    }

    public function test_the_checks_name_the_cause_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $empty = $this->actingAs($owner)->postJson('/admin/webhooks', ['url' => '', 'events' => []])->assertStatus(422);
        $this->assertStringContainsString('Adresse', $empty->json('errors.url.0'));
        $this->assertSame('Bitte mindestens ein Ereignis anhaken oder „Alle Ereignisse“ wählen.', $empty->json('errors.events.0'));

        $malformed = $this->actingAs($owner)->postJson('/admin/webhooks', ['url' => 'hooks.example.test', 'events' => ['*']])->assertStatus(422);
        $this->assertSame('Bitte eine vollständige Adresse mit https:// eintragen.', $malformed->json('errors.url.0'));

        $unknown = $this->actingAs($owner)->postJson('/admin/webhooks', ['url' => self::URL, 'events' => ['reservation.exploded']])->assertStatus(422);
        $this->assertSame('Dieses Ereignis gibt es nicht. Bitte die Seite neu laden.', $unknown->json('errors')['events.0'][0]);

        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_an_internal_address_is_refused_with_the_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks', ['url' => 'https://127.0.0.1/hook', 'events' => ['*']])
            ->assertStatus(422)->assertJsonValidationErrors('url');
    }

    public function test_a_plan_without_webhooks_explains_where_to_upgrade(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['webhooks_enabled' => false]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $endpoint = $this->endpoint($setup['tenant']->id);
        $this->clearTenantContext();

        $page = $this->actingAs($owner)->get('/admin/webhooks')->assertOk();
        $page->assertSee('Webhooks sind in deinem Tarif nicht enthalten.')
            ->assertSee('href="'.route('admin.billing.show').'"', false);
        $html = (string) $page->getContent();
        $this->assertDoesNotMatchRegularExpression('/<button(?![^>]*\bdisabled\b)[^>]*data-drawer-open="webhook-neu"/', $html);
        $this->assertMatchesRegularExpression('/<button[^>]*type="submit"[^>]*form="form-webhook-neu"[^>]*disabled/', $html);

        foreach ([
            ['postJson', '/admin/webhooks', ['url' => self::URL, 'events' => ['*']]],
            ['postJson', '/admin/webhooks/'.$endpoint->id.'/rotate-secret', []],
            ['postJson', '/admin/webhooks/'.$endpoint->id.'/ping', []],
        ] as [$method, $uri, $payload]) {
            $response = $this->actingAs($owner)->{$method}($uri, $payload)->assertForbidden();
            $this->assertSame('Webhooks sind in deinem Tarif nicht enthalten. Unter „Abo & Rechnungen“ kannst du den Tarif wechseln.', $response->json('message'));
        }
    }

    public function test_another_businesss_endpoint_is_out_of_reach_for_every_action(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $foreign = $this->endpoint($other['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/webhooks/'.$foreign->id.'/toggle')->assertNotFound();
        $this->actingAs($owner)->postJson('/admin/webhooks/'.$foreign->id.'/rotate-secret')->assertNotFound();
        $this->actingAs($owner)->postJson('/admin/webhooks/'.$foreign->id.'/ping')->assertNotFound();
        $this->actingAs($owner)->deleteJson('/admin/webhooks/'.$foreign->id)->assertNotFound();
        $this->assertSame('altes-geheimnis-xyz', WebhookEndpoint::withoutGlobalScopes()->findOrFail($foreign->id)->secret);
    }

    public function test_the_retry_section_keeps_reading_the_settings(): void
    {
        config(['swayy.webhooks.tries' => 3, 'swayy.webhooks.backoff' => [90, 3600], 'swayy.webhooks.disable_after' => 7]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/webhooks')->assertOk()
            ->assertSee('id="wiederholungen"', false)
            ->assertSee('Fehlversuche werden wiederholt: 3 Versuche je Ereignis, Abstände von 90 Sek. bis 1 Std.')
            ->assertSee('Nach 7 gescheiterten Ereignissen in Folge schaltet sich der Endpunkt selbst ab');
    }

    public function test_the_address_limit_comes_from_the_settings(): void
    {
        config(['swayy.webhooks.url_max' => 40]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/webhooks')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="url"[^>]*maxlength="40"/', $html);

        $response = $this->actingAs($owner)->postJson('/admin/webhooks', ['url' => 'https://93.184.216.34/'.str_repeat('a', 40), 'events' => ['*']])->assertStatus(422);
        $this->assertSame('Die Adresse ist zu lang (höchstens 40 Zeichen). Bitte eine kürzere Adresse eintragen.', $response->json('errors.url.0'));
        $this->assertSame(0, WebhookEndpoint::withoutGlobalScopes()->count());
    }

    public function test_the_address_limit_stays_in_its_bounds_and_the_longest_address_fits_the_column(): void
    {
        [$config, $warnings] = $this->swayyConfig(['SWAYY_WEBHOOK_URL_MAX' => '90000']);
        $this->assertSame(255, $config['webhooks']['url_max']);
        $this->assertStringContainsString('SWAYY_WEBHOOK_URL_MAX', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_WEBHOOK_URL_MAX' => '1']);
        $this->assertSame(30, $config['webhooks']['url_max']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(255, $config['webhooks']['url_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_WEBHOOK_URL_MAX']);

        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $url = 'https://93.184.216.34/'.str_repeat('a', 255 - strlen('https://93.184.216.34/'));
        $this->actingAs($owner)->postJson('/admin/webhooks', ['url' => $url, 'events' => ['*']])->assertOk();
        $this->assertSame($url, WebhookEndpoint::withoutGlobalScopes()->firstOrFail()->url);
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'webhooks');
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->values()->unique();
        $this->assertContains('webhook-neu', $targets->all());

        $response = $this->actingAs($owner)->get('/admin/webhooks');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }

    public function test_service_staff_cannot_manage_webhooks(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/webhooks')->assertForbidden();
        $this->actingAs($staff)->postJson('/admin/webhooks', ['url' => self::URL, 'events' => ['*']])->assertForbidden();
    }
}
