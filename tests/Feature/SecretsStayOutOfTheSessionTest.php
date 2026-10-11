<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SessionSecrets;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

/**
 * Nach einer abgelehnten Eingabe füllt Laravel das Formular aus der Sitzung
 * wieder auf (_old_input). Die Sitzung liegt unverschlüsselt im Speicher
 * (Redis, Datenbank): Schlüssel und Zugangsdaten gehören nicht hinein, auch
 * wenn die Felder keinen value tragen.
 */
class SecretsStayOutOfTheSessionTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    private function owner(): object
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        return $owner;
    }

    public function test_a_rejected_sms_save_keeps_the_api_key_out_of_the_session(): void
    {
        $key = str_repeat('s', (int) config('swayy.sms.api_key_max') + 1);

        $this->actingAs($this->owner())->from('/admin/settings/sms')
            ->put('/admin/settings/sms', ['api_key' => $key, 'sender_id' => 'Trattoria', 'enabled' => '1'])
            ->assertRedirect('/admin/settings/sms')
            ->assertSessionHasErrors('api_key')
            ->assertSessionMissing('_old_input.api_key')
            ->assertSessionHas('_old_input.sender_id', 'Trattoria');
    }

    public function test_a_rejected_stripe_save_keeps_both_keys_out_of_the_session(): void
    {
        $tooLong = str_repeat('k', (int) config('swayy.payments.secret_max') + 1);

        $this->actingAs($this->owner())->from('/admin/settings/payment-providers')
            ->put('/admin/settings/stripe', ['secret_key' => $tooLong, 'webhook_secret' => 'whsec_geheim', 'enabled' => '1'])
            ->assertRedirect('/admin/settings/payment-providers')
            ->assertSessionHasErrors('secret_key')
            ->assertSessionMissing('_old_input.secret_key')
            ->assertSessionMissing('_old_input.webhook_secret')
            ->assertSessionHas('_old_input.enabled', '1');
    }

    public function test_a_rejected_paypal_save_keeps_both_keys_out_of_the_session(): void
    {
        $this->actingAs($this->owner())->from('/admin/settings/payment-providers')
            ->put('/admin/settings/paypal', ['client_id' => 'client-geheim', 'secret' => 'secret-geheim', 'mode' => 'unbekannt', 'enabled' => '1'])
            ->assertRedirect('/admin/settings/payment-providers')
            ->assertSessionHasErrors('mode')
            ->assertSessionMissing('_old_input.client_id')
            ->assertSessionMissing('_old_input.secret')
            ->assertSessionHas('_old_input.enabled', '1');
    }

    public function test_a_rejected_mailwizz_save_keeps_the_api_key_out_of_the_session(): void
    {
        $key = str_repeat('m', (int) config('swayy.newsletter.api_key_max') + 1);

        $this->actingAs($this->owner())->from('/admin/settings/newsletter')
            ->put('/admin/settings/mailwizz', ['api_url' => 'https://news.example.test/api', 'api_key' => $key, 'list_uid' => 'ab12cd', 'enabled' => '1'])
            ->assertRedirect('/admin/settings/newsletter')
            ->assertSessionHasErrors('api_key')
            ->assertSessionMissing('_old_input.api_key')
            ->assertSessionHas('_old_input.list_uid', 'ab12cd');
    }

    public function test_the_defaults_of_the_framework_stay_in_place(): void
    {
        $this->from('/register')->post('/register', [
            'restaurant_name' => 'Trattoria Test', 'name' => 'Test Person', 'email' => 'person@example.test',
            'password' => 'kurz', 'password_confirmation' => 'kurz', 'privacy_accepted' => '1',
        ])->assertSessionHasErrors('password')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionHas('_old_input.email', 'person@example.test');
    }

    public function test_the_list_comes_from_the_settings(): void
    {
        $this->app->forgetInstance(ExceptionHandler::class);
        config(['swayy.security.dont_flash' => ['list_uid']]);

        $this->actingAs($this->owner())->from('/admin/settings/newsletter')
            ->put('/admin/settings/mailwizz', ['api_url' => 'https://news.example.test/api', 'api_key' => str_repeat('m', 1000), 'list_uid' => 'ab12cd', 'enabled' => '1'])
            ->assertSessionHasErrors('api_key')
            ->assertSessionMissing('_old_input.list_uid')
            ->assertSessionHas('_old_input.api_url', 'https://news.example.test/api');
    }

    public function test_an_empty_or_mistyped_setting_keeps_the_credentials_out(): void
    {
        foreach ([[], ['bad name!']] as $setting) {
            $this->app->forgetInstance(ExceptionHandler::class);
            config(['swayy.security.dont_flash' => $setting]);

            $this->actingAs($this->owner())->from('/admin/settings/newsletter')
                ->put('/admin/settings/mailwizz', ['api_url' => 'https://news.example.test/api', 'api_key' => str_repeat('m', 1000), 'list_uid' => 'ab12cd', 'enabled' => '1'])
                ->assertSessionHasErrors('api_key')
                ->assertSessionMissing('_old_input.api_key')
                ->assertSessionHas('_old_input.list_uid', 'ab12cd');
        }

        $this->assertSame(SessionSecrets::ALWAYS, array_slice(SessionSecrets::fields(), 0, count(SessionSecrets::ALWAYS)));
    }

    public function test_the_setting_has_defaults_and_is_documented(): void
    {
        [$config, $warnings] = $this->swayyConfig([]);
        foreach (['api_key', 'secret_key', 'webhook_secret', 'client_id', 'secret'] as $name) {
            $this->assertContains($name, $config['security']['dont_flash']);
        }
        $this->assertSame('', $warnings);

        [$config, $warnings] = $this->swayyConfig(['SWAYY_DONT_FLASH' => 'api_key, iban ,bad name!']);
        $this->assertSame(['api_key', 'iban'], $config['security']['dont_flash']);
        $this->assertStringContainsString('SWAYY_DONT_FLASH', $warnings);
        $this->assertStringContainsString('bad name!', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_DONT_FLASH' => '-']);
        $this->assertSame([], $config['security']['dont_flash']);

        $this->assertDocumentedInEnvExample(['SWAYY_DONT_FLASH']);
    }
}
