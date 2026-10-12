<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsCheckboxes;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class PaymentProvidersPageTest extends TestCase
{
    use CreatesTenants, ReadsCheckboxes, ReadsSwayyConfig, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function connect(int $tenantId, string $provider, array $credentials, string $status = 'connected'): IntegrationConnection
    {
        return IntegrationConnection::create([
            'tenant_id' => $tenantId, 'location_id' => null, 'provider' => $provider, 'status' => $status,
            'credentials_encrypted' => Crypt::encryptString(json_encode($credentials)),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialsOf(string $provider): array
    {
        return json_decode(Crypt::decryptString(IntegrationConnection::where('provider', $provider)->firstOrFail()->credentials_encrypted), true);
    }

    public function test_the_page_shows_both_providers_and_never_a_secret(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk_test_geheim', 'webhook_secret' => 'whsec_geheim']);
        $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'paypal-client-geheim', 'secret' => 'paypal-secret-geheim', 'mode' => 'sandbox'], 'disconnected');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk();

        $response->assertSee('Einstellungen › Zahlungen')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('id="stripe"', false)
            ->assertSee('id="paypal"', false)
            ->assertSee(route('webhooks.stripe'))
            ->assertSee('data-copy="'.route('webhooks.stripe').'"', false)
            ->assertDontSee('sk_test_geheim')
            ->assertDontSee('whsec_geheim')
            ->assertDontSee('paypal-client-geheim')
            ->assertDontSee('paypal-secret-geheim')
            ->assertDontSee('onsubmit', false);
        $this->assertMatchesRegularExpression('/data-nav-key="payment-providers"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    /**
     * Eine neue Anbindung startet eingeschaltet, ein Fehler beim Test schaltet den Anbieter nicht aus; nur
     * ausdrücklich ausgeschaltet bleibt das Kästchen leer. Beide Anbieter haben ihr eigenes Kästchen.
     */
    public function test_the_switches_are_checked_unless_the_provider_was_switched_off(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $page = fn () => (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();

        $html = $page();
        $this->assertTrue($this->checkboxIsChecked($html, 'f-stripe-enabled'), 'Stripe, neue Anbindung');
        $this->assertTrue($this->checkboxIsChecked($html, 'f-paypal-enabled'), 'PayPal, neue Anbindung');

        $stripe = $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk', 'webhook_secret' => 'wh'], 'error');
        $paypal = $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'live'], 'error');
        $html = $page();
        $this->assertTrue($this->checkboxIsChecked($html, 'f-stripe-enabled'), 'Stripe, Fehler beim letzten Test');
        $this->assertTrue($this->checkboxIsChecked($html, 'f-paypal-enabled'), 'PayPal, Fehler beim letzten Test');

        // Jeder Anbieter folgt seinem eigenen Zustand.
        $stripe->update(['status' => 'disconnected']);
        $html = $page();
        $this->assertFalse($this->checkboxIsChecked($html, 'f-stripe-enabled'), 'Stripe, ausgeschaltet');
        $this->assertTrue($this->checkboxIsChecked($html, 'f-paypal-enabled'), 'PayPal, weiter eingeschaltet');

        $stripe->update(['status' => 'connected']);
        $paypal->update(['status' => 'disconnected']);
        $html = $page();
        $this->assertTrue($this->checkboxIsChecked($html, 'f-stripe-enabled'), 'Stripe, verbunden');
        $this->assertFalse($this->checkboxIsChecked($html, 'f-paypal-enabled'), 'PayPal, ausgeschaltet');
    }

    public function test_empty_secrets_keep_the_stored_ones(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk_test_alt', 'webhook_secret' => 'whsec_alt']);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => '', 'webhook_secret' => 'whsec_neu', 'enabled' => '1'])
            ->assertOk();

        $credentials = json_decode(Crypt::decryptString(IntegrationConnection::where('provider', 'stripe')->value('credentials_encrypted')), true);
        $this->assertSame('sk_test_alt', $credentials['secret_key']);
        $this->assertSame('whsec_neu', $credentials['webhook_secret']);
    }

    public function test_missing_paypal_secrets_are_named_at_their_fields(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => '', 'secret' => '', 'mode' => 'live', 'enabled' => '1'])
            ->assertStatus(422);

        $this->assertStringContainsString('Apps & Credentials', $response->json('errors.client_id.0'));
        $this->assertStringContainsString('Apps & Credentials', $response->json('errors.secret.0'));
    }

    public function test_an_unknown_paypal_mode_names_the_choices(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => 'a', 'secret' => 'b', 'mode' => 'test'])->assertStatus(422);

        $this->assertSame('Bitte „Test (Sandbox)“ oder „Live“ wählen.', $response->json('errors.mode.0'));
    }

    public function test_roles_without_integration_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/payment-providers')->assertForbidden();
    }

    public function test_the_page_and_the_saving_routes_need_the_integration_right(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/payment-providers')->assertForbidden();
        $this->actingAs($manager)->putJson('/admin/settings/stripe', ['secret_key' => 'k', 'webhook_secret' => 'w', 'enabled' => '1'])->assertForbidden();
        $this->actingAs($manager)->putJson('/admin/settings/paypal', ['client_id' => 'a', 'secret' => 'b', 'mode' => 'live', 'enabled' => '1'])->assertForbidden();
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());
    }

    public function test_the_status_is_shown_in_words(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'Nicht eingerichtet</span>'));

        $stripe = $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk', 'webhook_secret' => 'wh']);
        $paypal = $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'c', 'secret' => 's', 'mode' => 'live'], 'disconnected');
        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Verbunden</span>'));
        $this->assertSame(1, substr_count($html, 'Ausgeschaltet</span>'));
        $this->assertSame(0, substr_count($html, 'Nicht eingerichtet</span>'));

        $stripe->update(['status' => 'disconnected']);
        $paypal->update(['status' => 'connected']);
        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, 'Verbunden</span>'));
        $this->assertSame(1, substr_count($html, 'Ausgeschaltet</span>'));
    }

    public function test_the_page_says_when_stripe_last_reported_in_the_time_of_the_location(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['timezone' => 'Europe/Berlin']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk', 'webhook_secret' => 'wh']);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()
            ->assertSee('Stripe hat sich noch nicht gemeldet. Prüfe die Webhook-Adresse im Stripe-Dashboard.')
            ->assertDontSee('Letzte Meldung von Stripe');

        AuditLog::create(['tenant_id' => $setup['tenant']->id, 'action' => 'payment.webhook_received', 'created_at' => '2026-03-05 23:30:00']);

        $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()
            ->assertSee('Letzte Meldung von Stripe:')
            ->assertSee('06.03.2026 00:30 Uhr')
            ->assertDontSee('Stripe hat sich noch nicht gemeldet');
    }

    public function test_the_stripe_report_of_another_business_does_not_count(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk', 'webhook_secret' => 'wh']);
        AuditLog::create(['tenant_id' => $other['tenant']->id, 'action' => 'payment.webhook_received', 'created_at' => now()]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()
            ->assertSee('Stripe hat sich noch nicht gemeldet.');
    }

    public function test_stored_secrets_change_the_hint_and_do_not_need_to_be_filled_in_again(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertStringNotContainsString('Gespeichert. Leer lassen, um ihn zu behalten.', $html);
        foreach (['secret_key', 'webhook_secret', 'client_id', 'secret'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="'.$field.'"[^>]*required/', $html, $field);
        }

        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk_test_x', 'webhook_secret' => 'whsec_x']);
        $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'live']);

        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, 'Gespeichert. Leer lassen, um ihn zu behalten.'));
        foreach (['secret_key', 'webhook_secret', 'client_id', 'secret'] as $field) {
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="'.$field.'"[^>]*required/', $html, $field);
            $this->assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="'.$field.'"[^>]*autocomplete="new-password"/', $html, $field);
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="'.$field.'"[^>]*value=/', $html, $field);
        }
    }

    public function test_the_hint_for_one_missing_secret_stays_with_that_field(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk_test_x']);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'Gespeichert. Leer lassen, um ihn zu behalten.'));
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="secret_key"[^>]*required/', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="webhook_secret"[^>]*required/', $html);
    }

    public function test_no_secret_comes_back_into_the_page_after_a_rejected_classic_save(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Die abgelehnte Eingabe kommt mit der Fehlermeldung zurück, ohne den Schlüssel.
        $html = (string) $this->actingAs($owner)->from('/admin/settings/payment-providers')->followingRedirects()
            ->put('/admin/settings/stripe', ['secret_key' => 'gerade-getippter-stripe-schluessel', 'webhook_secret' => '', 'enabled' => '1'])
            ->assertOk()
            ->assertSee('id="f-webhook_secret-error"', false)
            ->assertSee('Das Webhook-Signing-Secret fehlt.')
            ->getContent();
        $this->assertStringNotContainsString('gerade-getippter-stripe-schluessel', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="secret_key"[^>]*value=/', $html);

        $html = (string) $this->actingAs($owner)->from('/admin/settings/payment-providers')->followingRedirects()
            ->put('/admin/settings/paypal', ['client_id' => 'gerade-getippte-client-id', 'secret' => '', 'mode' => 'live', 'enabled' => '1'])
            ->assertOk()
            ->assertSee('id="f-secret-error"', false)
            ->assertSee('Das Secret fehlt.')
            ->getContent();
        $this->assertStringNotContainsString('gerade-getippte-client-id', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="client_id"[^>]*value=/', $html);
    }

    public function test_switching_off_keeps_the_secrets_and_says_so(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk_test_bleibt', 'webhook_secret' => 'whsec_bleibt']);
        $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'cid-bleibt', 'secret' => 'sec-bleibt', 'mode' => 'live']);
        $this->clearTenantContext();

        $stripe = $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => '', 'webhook_secret' => '', 'enabled' => '0'])
            ->assertOk()->assertJsonPath('reload', true);
        $this->assertStringContainsString('ausgeschaltet', (string) $stripe->json('message'));
        $paypal = $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => '', 'secret' => '', 'mode' => 'live', 'enabled' => '0'])
            ->assertOk()->assertJsonPath('reload', true);
        $this->assertStringContainsString('ausgeschaltet', (string) $paypal->json('message'));

        $this->assertSame('disconnected', IntegrationConnection::where('provider', 'stripe')->value('status'));
        $this->assertSame('disconnected', IntegrationConnection::where('provider', 'paypal')->value('status'));
        $this->assertSame('sk_test_bleibt', $this->credentialsOf('stripe')['secret_key']);
        $this->assertSame('sec-bleibt', $this->credentialsOf('paypal')['secret']);
    }

    public function test_switching_on_says_where_the_webhook_address_goes(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => 'sk_test_neu', 'webhook_secret' => 'whsec_neu', 'enabled' => '1'])
            ->assertOk()->assertJsonPath('reload', true);

        $this->assertStringContainsString(route('webhooks.stripe'), (string) $response->json('message'));
        $this->assertSame('connected', IntegrationConnection::where('provider', 'stripe')->value('status'));
    }

    public function test_paypal_mode_is_saved_and_shown(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox', 'enabled' => '1'])->assertOk();

        $this->assertSame('sandbox', $this->credentialsOf('paypal')['mode']);
        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input[^>]*type="radio"[^>]*name="mode"[^>]*value="sandbox"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="radio"[^>]*name="mode"[^>]*value="live"[^>]*checked/', $html);
        $this->assertStringContainsString('Test (Sandbox)', $html);
    }

    public function test_the_connection_of_another_business_stays_out(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($other['tenant']->id, 'stripe', ['secret_key' => 'sk_fremd', 'webhook_secret' => 'wh_fremd']);
        $this->connect($other['tenant']->id, 'paypal', ['client_id' => 'cid_fremd', 'secret' => 'sec_fremd', 'mode' => 'sandbox']);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'Nicht eingerichtet</span>'));
        $this->assertStringNotContainsString('sk_fremd', $html);

        // Wer speichert, legt eine eigene Verbindung an und lässt die fremde unberührt.
        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => '', 'webhook_secret' => 'wh_neu', 'enabled' => '1'])->assertStatus(422);
        $this->assertSame(2, IntegrationConnection::withoutGlobalScopes()->count());
    }

    public function test_unreadable_credentials_do_not_break_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach (['stripe', 'paypal'] as $provider) {
            IntegrationConnection::create([
                'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => $provider, 'status' => 'connected',
                'credentials_encrypted' => 'kein-gueltiger-wert',
            ]);
        }
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        $this->assertSame(2, substr_count($html, 'Nicht eingerichtet</span>'));

        $response = $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => '', 'webhook_secret' => '', 'enabled' => '1'])->assertStatus(422);
        $this->assertStringContainsString('Developers', $response->json('errors.secret_key.0'));
        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => 'sk_neu', 'webhook_secret' => 'wh_neu', 'enabled' => '1'])->assertOk();
        $this->assertSame('sk_neu', $this->credentialsOf('stripe')['secret_key']);
    }

    public function test_the_log_keeps_every_secret_out(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => 'sk_nicht_ins_protokoll', 'webhook_secret' => 'wh_nicht_ins_protokoll', 'enabled' => '1'])->assertOk();
        $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => 'cid_nicht_ins_protokoll', 'secret' => 'sec_nicht_ins_protokoll', 'mode' => 'sandbox', 'enabled' => '0'])->assertOk();

        $stripe = AuditLog::where('action', 'integration.stripe_updated')->firstOrFail();
        $paypal = AuditLog::where('action', 'integration.paypal_updated')->firstOrFail();
        foreach ([$stripe, $paypal] as $log) {
            $this->assertStringNotContainsString('nicht_ins_protokoll', json_encode($log->getAttributes()));
        }
        $this->assertSame('Stripe-Anbindung geändert', $stripe->actionLabel());
        $this->assertSame('PayPal-Anbindung geändert', $paypal->actionLabel());
        $changes = collect($paypal->fieldChanges())->keyBy('field');
        $this->assertSame('Modus', $changes['mode']['label']);
        $this->assertSame('Test (Sandbox)', $changes['mode']['to']);
    }

    public function test_the_limits_come_from_the_settings_and_name_the_next_step(): void
    {
        config(['swayy.payments.secret_max' => 40]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/payment-providers')->assertOk()->getContent();
        foreach (['secret_key', 'webhook_secret', 'client_id', 'secret'] as $field) {
            $this->assertMatchesRegularExpression('/<input[^>]*name="'.$field.'"[^>]*maxlength="40"/', $html, $field);
        }

        $long = str_repeat('k', 41);
        $stripe = $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => $long, 'webhook_secret' => $long, 'enabled' => '1'])->assertStatus(422);
        $this->assertStringContainsString('40', $stripe->json('errors.secret_key.0'));
        $this->assertStringContainsString('Stripe-Dashboard', $stripe->json('errors.secret_key.0'));
        $this->assertStringContainsString('40', $stripe->json('errors.webhook_secret.0'));
        $paypal = $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => $long, 'secret' => $long, 'mode' => 'live', 'enabled' => '1'])->assertStatus(422);
        $this->assertStringContainsString('PayPal', $paypal->json('errors.client_id.0'));
        $this->assertStringContainsString('40', $paypal->json('errors.secret.0'));
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());

        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => str_repeat('k', 40), 'webhook_secret' => str_repeat('w', 40), 'enabled' => '1'])->assertOk();
    }

    public function test_the_limit_stays_within_the_column_and_is_documented(): void
    {
        [$config, $warnings] = $this->swayyConfig(['SWAYY_PAYMENT_SECRET_MAX' => '90000']);
        $this->assertSame(1000, $config['payments']['secret_max']);
        $this->assertStringContainsString('SWAYY_PAYMENT_SECRET_MAX', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_PAYMENT_SECRET_MAX' => '1']);
        $this->assertSame(32, $config['payments']['secret_max']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(255, $config['payments']['secret_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_PAYMENT_SECRET_MAX']);
    }

    public function test_the_largest_allowed_secrets_are_stored_and_read_back(): void
    {
        config(['swayy.payments.secret_max' => 1000]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $key = str_repeat('k', 1000);
        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => $key, 'webhook_secret' => $key, 'enabled' => '1'])->assertOk();
        $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => $key, 'secret' => $key, 'mode' => 'live', 'enabled' => '1'])->assertOk();

        $this->assertSame($key, $this->credentialsOf('stripe')['secret_key']);
        $this->assertSame($key, $this->credentialsOf('stripe')['webhook_secret']);
        $this->assertSame($key, $this->credentialsOf('paypal')['client_id']);
        $this->assertSame($key, $this->credentialsOf('paypal')['secret']);
    }

    public function test_a_caller_without_the_switch_gets_a_connected_new_connection(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => 'sk_ohne_schalter', 'webhook_secret' => 'wh_ohne_schalter'])->assertOk();
        $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'live'])->assertOk();

        $this->assertSame('connected', IntegrationConnection::where('provider', 'stripe')->value('status'));
        $this->assertSame('connected', IntegrationConnection::where('provider', 'paypal')->value('status'));
    }

    /**
     * Ohne Angabe zum Schalter bleibt der Zustand einer bestehenden Anbindung, wie er ist: ausgeschaltet bleibt
     * ausgeschaltet (dieselbe Regel wie bei MailWizz und SMS).
     */
    public function test_a_caller_without_the_switch_keeps_switched_off_providers_off(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk', 'webhook_secret' => 'wh'], 'disconnected');
        $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'live'], 'disconnected');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => '', 'webhook_secret' => ''])
            ->assertOk()->assertJsonPath('message', 'Stripe ausgeschaltet. Die Schlüssel bleiben gespeichert.');
        $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => '', 'secret' => '', 'mode' => 'live'])
            ->assertOk()->assertJsonPath('message', 'PayPal ausgeschaltet. Die Zugangsdaten bleiben gespeichert.');

        $this->assertSame('disconnected', IntegrationConnection::where('provider', 'stripe')->value('status'));
        $this->assertSame('disconnected', IntegrationConnection::where('provider', 'paypal')->value('status'));
    }

    public function test_a_caller_without_the_switch_keeps_switched_on_providers_on(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($setup['tenant']->id, 'stripe', ['secret_key' => 'sk', 'webhook_secret' => 'wh']);
        $this->connect($setup['tenant']->id, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'live']);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => '', 'webhook_secret' => ''])->assertOk();
        $this->actingAs($owner)->putJson('/admin/settings/paypal', ['client_id' => '', 'secret' => '', 'mode' => 'sandbox'])->assertOk();

        $this->assertSame('connected', IntegrationConnection::where('provider', 'stripe')->value('status'));
        $this->assertSame('connected', IntegrationConnection::where('provider', 'paypal')->value('status'));
    }

    public function test_an_invalid_switch_value_asks_to_reload(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/stripe', ['secret_key' => 'sk', 'webhook_secret' => 'wh', 'enabled' => 'vielleicht'])->assertStatus(422);

        $this->assertStringContainsString('neu laden', $response->json('errors.enabled.0'));
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'payment-providers');
        $this->assertSame('admin.settings.payment-providers.show', $item['route']);
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('fragment', $item);
        $this->assertSame(['integrations.manage'], $item['can']);
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->unique()->values();
        $this->assertSame(['stripe', 'paypal'], $targets->all());

        $response = $this->actingAs($owner)->get('/admin/settings/payment-providers');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }
}
