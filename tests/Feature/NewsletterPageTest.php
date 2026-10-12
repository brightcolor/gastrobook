<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class NewsletterPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    private const URL = 'https://93.184.216.34/api';

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function connect(int $tenantId, string $status = 'connected', array $credentials = ['api_url' => self::URL, 'api_key' => 'alter-mailwizz-schluessel', 'list_uid' => 'ab12cd']): IntegrationConnection
    {
        return IntegrationConnection::create([
            'tenant_id' => $tenantId, 'location_id' => null, 'provider' => 'mailwizz', 'status' => $status,
            'credentials_encrypted' => Crypt::encryptString(json_encode($credentials)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialsOf(IntegrationConnection $connection): array
    {
        return json_decode(Crypt::decryptString($connection->fresh()->credentials_encrypted), true);
    }

    public function test_the_page_shows_url_list_state_and_last_error_never_the_key(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => 'mailwizz', 'status' => 'error',
            'settings' => ['last_error' => 'MailWizz lehnt den API-Key ab (Status 401). Bitte den API-Key in MailWizz prüfen und neu eintragen.'],
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_url' => 'https://news.example.test/api', 'api_key' => 'mailwizz-geheim', 'list_uid' => 'ab12cd'])),
        ]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk();

        $response->assertSee('Einstellungen › Verbindungen')
            ->assertSee('id="mailwizz"', false)
            ->assertSee('value="https://news.example.test/api"', false)
            ->assertSee('value="ab12cd"', false)
            ->assertSee('MailWizz lehnt den API-Key ab (Status 401).')
            ->assertDontSee('mailwizz-geheim');
        $this->assertMatchesRegularExpression('/data-nav-key="newsletter"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_internal_address_is_refused_with_the_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => 'http://127.0.0.1/api', 'api_key' => 'k', 'list_uid' => 'x', 'enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('api_url');
    }

    public function test_the_state_is_named_in_words(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()->assertSee('Nicht eingerichtet');

        $connection = $this->connect($setup['tenant']->id);
        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertSee('Verbunden');

        $connection->update(['status' => 'disconnected']);
        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertSee('Ausgeschaltet');
    }

    public function test_restaurants_read_guests_and_salons_read_customers(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()
            ->assertSee('Gäste mit Newsletter-Einwilligung gehen an deine Liste in MailWizz.')
            ->assertSee('Stimmt ein Gast beim Buchen dem Newsletter zu')
            ->assertSee('Gäste mit Einwilligung übertragen')
            ->assertSee('es geht aber kein Gast an MailWizz')
            ->assertDontSee('Kunden mit Newsletter-Einwilligung')
            ->assertDontSee('Kunden mit Einwilligung übertragen');

        $setup['tenant']->update(['type' => TenantType::Salon]);
        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()
            ->assertSee('Kunden mit Newsletter-Einwilligung gehen an deine Liste in MailWizz.')
            ->assertSee('Stimmt ein Kunde beim Buchen dem Newsletter zu')
            ->assertSee('Kunden mit Einwilligung übertragen')
            ->assertSee('es geht aber kein Kunde an MailWizz')
            ->assertDontSee('Gäste mit Newsletter-Einwilligung')
            ->assertDontSee('Stimmt ein Gast')
            ->assertDontSee('Gäste mit Einwilligung übertragen')
            ->assertDontSee('kein Gast an MailWizz');
    }

    public function test_an_old_error_reason_stays_hidden_while_the_connection_works(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id);
        $connection->update(['settings' => ['last_error' => 'Alter Grund, der nicht mehr gilt.']]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()->assertDontSee('Alter Grund, der nicht mehr gilt.');
    }

    public function test_an_empty_key_keeps_the_stored_one_and_a_new_key_replaces_it(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => '', 'list_uid' => 'neu99', 'enabled' => '1'])
            ->assertOk()->assertJsonPath('reload', true);
        $credentials = $this->credentialsOf($connection);
        $this->assertSame('alter-mailwizz-schluessel', $credentials['api_key']);
        $this->assertSame('neu99', $credentials['list_uid']);

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => 'neuer-schluessel', 'list_uid' => 'neu99', 'enabled' => '1'])->assertOk();
        $this->assertSame('neuer-schluessel', $this->credentialsOf($connection)['api_key']);
    }

    public function test_a_missing_key_names_where_to_find_it(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => '', 'list_uid' => 'ab12cd', 'enabled' => '1'])->assertStatus(422);

        $this->assertSame('Der API-Key fehlt. Bitte den API-Key aus MailWizz eintragen (in MailWizz unter Account → API).', $response->json('errors.api_key.0'));
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());
    }

    public function test_missing_and_malformed_entries_name_the_cause_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $empty = $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => '', 'api_key' => 'k', 'list_uid' => '', 'enabled' => '1'])->assertStatus(422);
        $this->assertStringContainsString('API-URL', $empty->json('errors.api_url.0'));
        $this->assertStringContainsString('Account → API', $empty->json('errors.api_url.0'));
        $this->assertStringContainsString('Unique ID', $empty->json('errors.list_uid.0'));

        $malformed = $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => 'news.example.test', 'api_key' => 'k', 'list_uid' => 'ab12cd', 'enabled' => '1'])->assertStatus(422);
        $this->assertStringContainsString('https://', $malformed->json('errors.api_url.0'));

        $switch = $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => 'k', 'list_uid' => 'ab12cd', 'enabled' => 'vielleicht'])->assertStatus(422);
        $this->assertStringContainsString('Seite neu laden', $switch->json('errors.enabled.0'));
    }

    public function test_the_page_never_shows_a_typed_key_after_a_rejection(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->put('/admin/settings/mailwizz', ['api_url' => 'http://127.0.0.1/api', 'api_key' => 'getippter-geheimer-schluessel', 'list_uid' => 'ab12cd'])
            ->assertSessionHasErrors('api_url');

        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()
            ->assertSee('value="http://127.0.0.1/api"', false)
            ->assertDontSee('getippter-geheimer-schluessel');
    }

    /**
     * Die alte Sammelseite sendet vor dem Kästchen eine 0. Eine ausgeschaltete
     * Anbindung bleibt nach dem Speichern ausgeschaltet.
     */
    public function test_an_old_page_payload_with_a_zero_keeps_a_switched_off_connection_off(): void
    {
        Http::fake();
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id, 'disconnected');
        $this->clearTenantContext();

        $this->actingAs($owner)->put('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => '', 'list_uid' => 'ab12cd', 'enabled' => '0'])
            ->assertRedirect()->assertSessionHas('success', 'MailWizz-Integration gespeichert. Die Synchronisierung ist ausgeschaltet.');

        $this->assertSame('disconnected', $connection->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * Ein Aufrufer ohne Angabe zum Schalter lässt den Zustand einer bestehenden
     * Anbindung, wie er ist.
     */
    public function test_an_absent_switch_keeps_the_state_of_an_existing_connection(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $off = $this->connect($setup['tenant']->id, 'disconnected');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => '', 'list_uid' => 'ab12cd'])
            ->assertOk()->assertJsonPath('message', 'MailWizz-Integration gespeichert. Die Synchronisierung ist ausgeschaltet.');

        $this->assertSame('disconnected', $off->fresh()->status);
        Http::assertNothingSent();

        $off->update(['status' => 'connected']);

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => '', 'list_uid' => 'ab12cd'])
            ->assertOk()->assertJsonPath('message', 'MailWizz-Integration gespeichert und Verbindung getestet.');
        $this->assertSame('connected', $off->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_an_absent_switch_tests_a_connection_that_failed_before_again(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id, 'error');
        $connection->update(['settings' => ['last_error' => 'Alter Grund.']]);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => '', 'list_uid' => 'ab12cd'])->assertOk();

        $connection->refresh();
        $this->assertSame('connected', $connection->status);
        $this->assertNull($connection->settings['last_error'] ?? null);
    }

    public function test_a_new_connection_without_the_switch_starts_on(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => 'k', 'list_uid' => 'ab12cd'])->assertOk();

        $this->assertSame('connected', IntegrationConnection::withoutGlobalScopes()->where('provider', 'mailwizz')->firstOrFail()->status);
    }

    public function test_switching_off_with_a_zero_keeps_the_key_and_skips_the_test(): void
    {
        Http::fake();
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => '', 'list_uid' => 'ab12cd', 'enabled' => '0'])
            ->assertOk()->assertJsonPath('reload', true);

        $this->assertSame('disconnected', $connection->fresh()->status);
        $this->assertSame('alter-mailwizz-schluessel', $this->credentialsOf($connection)['api_key']);
        Http::assertNothingSent();
    }

    public function test_the_new_page_sends_a_zero_before_the_checkbox(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'disconnected');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input type="hidden" name="enabled" value="0">\s*<input id="f-enabled" type="checkbox" name="enabled" value="1"[^>]*>/', $html);
        $this->assertStringContainsString('Speichern und Verbindung testen', $html);
    }

    public function test_the_change_is_logged_with_readable_field_names(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => 'geheimer-eintrag', 'list_uid' => 'ab12cd', 'enabled' => '1'])->assertOk();

        $entry = AuditLog::withoutGlobalScopes()->where('action', 'integration.mailwizz_updated')->firstOrFail();
        $this->assertSame('Newsletter-Anbindung geändert', $entry->actionLabel());
        $labels = collect($entry->fieldChanges())->pluck('label')->all();
        $this->assertContains('API-URL', $labels);
        $this->assertContains('Listen-UID', $labels);
        $this->assertStringNotContainsString('geheimer-eintrag', json_encode($entry->new_values));
    }

    public function test_another_businesss_connection_stays_out_of_reach(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $foreign = $this->connect($other['tenant']->id, 'connected', ['api_url' => 'https://93.184.216.34/fremd', 'api_key' => 'fremder-schluessel', 'list_uid' => 'fremd99']);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()
            ->assertSee('Nicht eingerichtet')
            ->assertDontSee('fremd99')
            ->assertDontSee('fremder-schluessel');

        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => 'eigener-schluessel', 'list_uid' => 'eigen11', 'enabled' => '1'])->assertOk();

        $untouched = json_decode(Crypt::decryptString(IntegrationConnection::withoutGlobalScopes()->findOrFail($foreign->id)->credentials_encrypted), true);
        $this->assertSame('fremder-schluessel', $untouched['api_key']);
        $this->assertSame('fremd99', $untouched['list_uid']);
        $this->assertSame(2, IntegrationConnection::withoutGlobalScopes()->where('provider', 'mailwizz')->count());
    }

    public function test_only_members_with_the_right_may_open_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/newsletter')->assertForbidden();
        $this->actingAs($manager)->putJson('/admin/settings/mailwizz', ['api_url' => self::URL, 'api_key' => 'k', 'list_uid' => 'ab12cd'])->assertForbidden();
    }

    public function test_the_limits_come_from_the_settings_and_name_the_next_step(): void
    {
        config(['swayy.newsletter.api_url_max' => 40, 'swayy.newsletter.api_key_max' => 40, 'swayy.newsletter.list_uid_max' => 10]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/newsletter')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="api_url"[^>]*maxlength="40"/', $html);
        $this->assertMatchesRegularExpression('/name="api_key"[^>]*maxlength="40"/', $html);
        $this->assertMatchesRegularExpression('/name="list_uid"[^>]*maxlength="10"/', $html);

        $response = $this->actingAs($owner)->putJson('/admin/settings/mailwizz', [
            'api_url' => 'https://93.184.216.34/'.str_repeat('a', 40),
            'api_key' => str_repeat('k', 41),
            'list_uid' => str_repeat('u', 11),
            'enabled' => '1',
        ])->assertStatus(422);

        $this->assertSame('Die Adresse ist zu lang (höchstens 40 Zeichen). Bitte die API-URL aus MailWizz unverändert einfügen.', $response->json('errors.api_url.0'));
        $this->assertSame('Der API-Key ist zu lang (höchstens 40 Zeichen). Bitte ihn aus MailWizz kopieren und unverändert einfügen.', $response->json('errors.api_key.0'));
        $this->assertSame('Die Listen-UID ist zu lang (höchstens 10 Zeichen). Bitte sie in MailWizz in der Listenübersicht unter „Unique ID“ kopieren.', $response->json('errors.list_uid.0'));
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());
    }

    public function test_the_limits_stay_in_their_bounds_and_are_documented(): void
    {
        [$config, $warnings] = $this->swayyConfig(['SWAYY_NEWSLETTER_API_URL_MAX' => '90000', 'SWAYY_NEWSLETTER_API_KEY_MAX' => '90000', 'SWAYY_NEWSLETTER_LIST_UID_MAX' => '90000']);
        $this->assertSame(2000, $config['newsletter']['api_url_max']);
        $this->assertSame(1000, $config['newsletter']['api_key_max']);
        $this->assertSame(255, $config['newsletter']['list_uid_max']);
        $this->assertStringContainsString('SWAYY_NEWSLETTER_API_URL_MAX', $warnings);
        $this->assertStringContainsString('SWAYY_NEWSLETTER_API_KEY_MAX', $warnings);
        $this->assertStringContainsString('SWAYY_NEWSLETTER_LIST_UID_MAX', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_NEWSLETTER_API_URL_MAX' => '1', 'SWAYY_NEWSLETTER_API_KEY_MAX' => '1', 'SWAYY_NEWSLETTER_LIST_UID_MAX' => '1']);
        $this->assertSame(30, $config['newsletter']['api_url_max']);
        $this->assertSame(32, $config['newsletter']['api_key_max']);
        $this->assertSame(8, $config['newsletter']['list_uid_max']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(255, $config['newsletter']['api_url_max']);
        $this->assertSame(255, $config['newsletter']['api_key_max']);
        $this->assertSame(64, $config['newsletter']['list_uid_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_NEWSLETTER_API_URL_MAX', 'SWAYY_NEWSLETTER_API_KEY_MAX', 'SWAYY_NEWSLETTER_LIST_UID_MAX']);
    }

    public function test_the_largest_allowed_values_are_stored_and_read_back(): void
    {
        Http::fake(['*' => Http::response(['status' => 'success'], 200)]);
        config(['swayy.newsletter.api_url_max' => 2000, 'swayy.newsletter.api_key_max' => 1000, 'swayy.newsletter.list_uid_max' => 255]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $url = 'https://93.184.216.34/'.str_repeat('a', 2000 - strlen('https://93.184.216.34/'));
        $key = str_repeat('k', 1000);
        $uid = str_repeat('u', 255);
        $this->actingAs($owner)->putJson('/admin/settings/mailwizz', ['api_url' => $url, 'api_key' => $key, 'list_uid' => $uid, 'enabled' => '1'])->assertOk();

        $credentials = $this->credentialsOf(IntegrationConnection::withoutGlobalScopes()->where('provider', 'mailwizz')->firstOrFail());
        $this->assertSame($url, $credentials['api_url']);
        $this->assertSame($key, $credentials['api_key']);
        $this->assertSame($uid, $credentials['list_uid']);
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'newsletter');
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('fragment', $item);
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->values()->unique();
        $this->assertContains('mailwizz', $targets->all());

        $response = $this->actingAs($owner)->get('/admin/settings/newsletter');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }
}
