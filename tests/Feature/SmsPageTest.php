<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class SmsPageTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function connect(int $tenantId, string $status = 'connected', array $credentials = ['api_key' => 'geheimer-sms-schluessel', 'sender_id' => 'Trattoria']): IntegrationConnection
    {
        return IntegrationConnection::create([
            'tenant_id' => $tenantId, 'location_id' => null, 'provider' => 'sevenio', 'status' => $status,
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

    public function test_the_page_shows_the_state_and_never_the_key(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => 'sevenio', 'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'geheimer-sms-schluessel', 'sender_id' => 'Trattoria'])),
        ]);
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/sms')->assertOk();

        $response->assertSee('Einstellungen › Gästekommunikation')
            ->assertSee('id="seven"', false)
            ->assertSee('Verbunden')
            ->assertSee('value="Trattoria"', false)
            ->assertDontSee('geheimer-sms-schluessel');
        $this->assertMatchesRegularExpression('/data-nav-key="sms"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_empty_key_keeps_the_stored_one(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => 'sevenio', 'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['api_key' => 'alter-schluessel', 'sender_id' => 'Alt'])),
        ]);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => 'Neu', 'enabled' => '1'])->assertOk();

        $connection = IntegrationConnection::where('provider', 'sevenio')->first();
        $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true);
        $this->assertSame('alter-schluessel', $credentials['api_key']);
        $this->assertSame('Neu', $credentials['sender_id']);
    }

    public function test_a_missing_key_names_where_to_find_it(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => '', 'enabled' => '1'])->assertStatus(422);

        $this->assertStringContainsString('app.seven.io', $response->json('errors.api_key.0'));
    }

    public function test_a_too_long_sender_id_names_the_limit(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'k', 'sender_id' => str_repeat('A', 17), 'enabled' => '1'])->assertStatus(422);

        $this->assertStringContainsString('16', $response->json('errors.sender_id.0'));
    }

    public function test_the_limits_come_from_the_settings_and_name_the_next_step(): void
    {
        config(['swayy.sms.sender_id_max' => 8, 'swayy.sms.sender_id_text_max' => 6, 'swayy.sms.api_key_max' => 40]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="sender_id"[^>]*maxlength="8"/', $html);
        $this->assertMatchesRegularExpression('/name="api_key"[^>]*maxlength="40"/', $html);

        $response = $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => str_repeat('k', 41), 'sender_id' => str_repeat('A', 9), 'enabled' => '1'])
            ->assertStatus(422);
        $this->assertSame('Die Absenderkennung mit Buchstaben darf höchstens 6 Zeichen haben. Bitte kürzen oder eine Rufnummer mit bis zu 8 Ziffern eintragen.', $response->json('errors.sender_id.0'));
        $this->assertSame('Der API-Key ist zu lang. Bitte ihn aus app.seven.io kopieren und ohne Leerzeichen einfügen.', $response->json('errors.api_key.0'));
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());
    }

    public function test_the_default_limits_read_as_the_provider_states_them(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'k', 'sender_id' => str_repeat('A', 17), 'enabled' => '1'])->assertStatus(422);

        $this->assertSame('Die Absenderkennung mit Buchstaben darf höchstens 11 Zeichen haben. Bitte kürzen oder eine Rufnummer mit bis zu 16 Ziffern eintragen.', $response->json('errors.sender_id.0'));
    }

    public function test_a_sender_id_with_letters_follows_the_text_limit_and_a_phone_number_the_longer_one(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        config(['swayy.sms.sender_id_max' => 12, 'swayy.sms.sender_id_text_max' => 6]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $save = fn (string $senderId) => $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'k', 'sender_id' => $senderId, 'enabled' => '1']);

        $rejected = $save('Salonxx')->assertStatus(422);
        $this->assertSame('Die Absenderkennung mit Buchstaben darf höchstens 6 Zeichen haben. Bitte kürzen oder eine Rufnummer mit bis zu 12 Ziffern eintragen.', $rejected->json('errors.sender_id.0'));
        $this->assertSame(0, IntegrationConnection::withoutGlobalScopes()->count());

        $save('Salonx')->assertOk();
        $save('123456789012')->assertOk();
        $save('+123456789012')->assertOk();
        $save('Sal0n12')->assertStatus(422)->assertJsonValidationErrors('sender_id');
        $save('1234567890123')->assertStatus(422)->assertJsonPath(
            'errors.sender_id.0',
            'Die Rufnummer als Absenderkennung darf höchstens 12 Ziffern haben. Bitte kürzen.',
        );
        $save('+1234567890123')->assertStatus(422)->assertJsonValidationErrors('sender_id');
    }

    public function test_the_default_sender_id_limits_accept_a_name_of_eleven_letters_and_a_number_of_sixteen_digits(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $save = fn (string $senderId) => $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'k', 'sender_id' => $senderId, 'enabled' => '1']);

        $save(str_repeat('A', 11))->assertOk();
        $save(str_repeat('A', 12))->assertStatus(422);
        $save(str_repeat('4', 16))->assertOk();
        $save(str_repeat('4', 17))->assertStatus(422);
    }

    public function test_the_key_never_comes_back_into_the_page_even_after_a_rejected_classic_save(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->from('/admin/settings/sms')
            ->put('/admin/settings/sms', ['api_key' => 'gerade-getippter-schluessel', 'sender_id' => str_repeat('A', 17), 'enabled' => '1'])
            ->assertRedirect('/admin/settings/sms')
            ->assertSessionHasErrors('sender_id');

        $html = (string) $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->getContent();
        $this->assertStringNotContainsString('gerade-getippter-schluessel', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*type="password"[^>]*name="api_key"[^>]*autocomplete="new-password"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="api_key"[^>]*value=/', $html);
    }

    public function test_a_stored_key_changes_the_hint_and_does_not_need_to_be_filled_in_again(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->getContent();
        $this->assertStringNotContainsString('Gespeichert. Leer lassen, um ihn zu behalten.', $html);
        $this->assertMatchesRegularExpression('/<input[^>]*name="api_key"[^>]*required/', $html);

        $this->connect($setup['tenant']->id);

        $html = (string) $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()
            ->assertSee('Gespeichert. Leer lassen, um ihn zu behalten.')
            ->getContent();
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="api_key"[^>]*required/', $html);
    }

    public function test_switching_off_keeps_the_credentials_and_skips_the_connection_test(): void
    {
        Http::fake();
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => 'Trattoria', 'enabled' => '0'])
            ->assertOk()
            ->assertJsonPath('message', 'SMS-Versand ausgeschaltet. Der API-Key bleibt gespeichert.')
            ->assertJsonPath('reload', true);

        $this->assertSame('disconnected', $connection->fresh()->status);
        $this->assertSame('geheimer-sms-schluessel', $this->credentialsOf($connection)['api_key']);
        Http::assertNothingSent();

        $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->assertSee('Ausgeschaltet');
    }

    public function test_switching_on_tests_the_connection_and_says_so(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'neuer-schluessel', 'sender_id' => 'Salon Anna', 'enabled' => '1'])
            ->assertOk()
            ->assertJsonPath('message', 'SMS-Versand gespeichert. Die Verbindung zu seven.io funktioniert.')
            ->assertJsonPath('reload', true);

        $connection = IntegrationConnection::where('provider', 'sevenio')->firstOrFail();
        $this->assertSame('connected', $connection->status);
        Http::assertSent(fn ($request) => $request->hasHeader('X-Api-Key', 'neuer-schluessel'));
    }

    public function test_a_failed_connection_test_says_what_was_saved_and_what_to_do(): void
    {
        Http::fake(['*' => Http::response('Unauthorized', 401)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'falscher-key', 'sender_id' => '', 'enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.api_key.0', 'Gespeichert, aber seven.io hat den Verbindungstest abgelehnt. Bitte den API-Key unter app.seven.io → Einstellungen → API prüfen und neu eintragen.');

        $connection = IntegrationConnection::where('provider', 'sevenio')->firstOrFail();
        $this->assertSame('error', $connection->status);

        $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->assertSee('Fehler beim letzten Test');
    }

    public function test_a_sender_id_sent_empty_is_cleared_and_one_not_sent_stays(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['enabled' => '1'])->assertOk();
        $this->assertSame('Trattoria', $this->credentialsOf($connection)['sender_id']);

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => '', 'enabled' => '1'])->assertOk();
        $this->assertSame('', $this->credentialsOf($connection)['sender_id']);
        $this->assertSame('geheimer-sms-schluessel', $this->credentialsOf($connection)['api_key']);
    }

    public function test_the_page_shows_every_state_in_words(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->assertSee('Nicht eingerichtet');

        $connection = $this->connect($setup['tenant']->id, 'disconnected');
        $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->assertSee('Ausgeschaltet');

        $connection->update(['status' => 'error']);
        $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->assertSee('Fehler beim letzten Test');

        $connection->update(['status' => 'connected']);
        $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->assertSee('Verbunden');
    }

    public function test_the_connection_of_another_business_stays_out(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->connect($other['tenant']->id, 'connected', ['api_key' => 'fremder-schluessel', 'sender_id' => 'Fremd']);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/sms')
            ->assertOk()
            ->assertSee('Nicht eingerichtet')
            ->assertDontSee('Fremd')
            ->assertDontSee('fremder-schluessel');
    }

    public function test_unreadable_credentials_do_not_break_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null, 'provider' => 'sevenio', 'status' => 'connected',
            'credentials_encrypted' => 'kein-gueltiger-wert',
        ]);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/sms')->assertOk()->assertSee('Nicht eingerichtet');

        $response = $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => '', 'enabled' => '1'])->assertStatus(422);
        $this->assertStringContainsString('app.seven.io', $response->json('errors.api_key.0'));
    }

    public function test_the_log_keeps_the_key_out(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'nicht-ins-protokoll', 'sender_id' => 'Salon Anna', 'enabled' => '1'])->assertOk();

        $log = AuditLog::where('action', 'integration.sms_updated')->firstOrFail();
        $this->assertSame('SMS-Anbindung geändert', $log->actionLabel());
        $this->assertStringNotContainsString('nicht-ins-protokoll', json_encode($log->getAttributes()));
        $changes = collect($log->fieldChanges())->keyBy('field');
        $this->assertSame('Absenderkennung', $changes['sender_id']['label']);
        $this->assertSame('Salon Anna', $changes['sender_id']['to']);
    }

    public function test_the_page_points_to_the_place_of_the_key_and_to_the_reminders(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/sms')
            ->assertOk()
            ->assertSee('app.seven.io')
            ->assertSee('href="'.route('admin.settings.notifications.show').'#erinnerung"', false)
            ->assertSee('Speichern und Verbindung testen')
            ->assertDontSee('data-savebar', false);
    }

    public function test_roles_without_integration_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/sms')->assertForbidden();
        $this->actingAs($manager)->putJson('/admin/settings/sms', ['api_key' => 'k', 'enabled' => '1'])->assertForbidden();
    }

    public function test_the_old_combined_page_keeps_saving_through_the_same_route(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $page = $this->actingAs($owner)->get('/admin/settings')->assertOk();
        $page->assertSee('action="'.route('admin.settings.sms').'"', false);

        $this->actingAs($owner)->put('/admin/settings/sms', ['api_key' => 'alter-weg', 'sender_id' => 'Alt', 'enabled' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $connection = IntegrationConnection::where('provider', 'sevenio')->firstOrFail();
        $this->assertSame('connected', $connection->status);
        $this->assertSame('alter-weg', $this->credentialsOf($connection)['api_key']);
    }

    /**
     * Die neue Seite kann den Versand ausschalten. Speichert jemand danach das
     * Formular der alten Sammelseite, darf es ihn nicht wieder einschalten:
     * Das Kästchen steht dort unmarkiert, und eine 0 davor sorgt dafür, dass
     * der Schalter in der Anfrage ankommt.
     */
    public function test_the_old_combined_page_keeps_a_switched_off_connection_off(): void
    {
        Http::fake();
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id, 'disconnected');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<input type="hidden" name="enabled" value="0">\s*<input type="checkbox" name="enabled" value="1"\s*>\s*SMS-Versand aktiv/', $html);

        // Das Formular der alten Seite mit unmarkiertem Kästchen sendet nur die 0.
        $this->actingAs($owner)->put('/admin/settings/sms', ['api_key' => '', 'sender_id' => 'Trattoria', 'enabled' => '0'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('disconnected', $connection->fresh()->status);
        Http::assertNothingSent();
    }

    /**
     * Nach einem fehlgeschlagenen Verbindungstest steht der Versand auf „error“, bleibt aber eingeschaltet. Die alte
     * Seite zeigt das Kästchen dann angehakt; sonst schaltete das Speichern des Formulars mit der 0 davor den Versand aus.
     */
    public function test_the_old_combined_page_keeps_sms_on_after_an_error(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id, 'error');
        $this->clearTenantContext();
        $ticked = '/<input type="hidden" name="enabled" value="0">\s*<input type="checkbox" name="enabled" value="1"\s+checked\s*>\s*SMS-Versand aktiv/';

        $html = (string) $this->actingAs($owner)->get('/admin/settings')->assertOk()->getContent();
        $this->assertMatchesRegularExpression($ticked, $html);

        // Das angehakte Kästchen der alten Seite sendet die 0 und danach die 1.
        $this->actingAs($owner)->put('/admin/settings/sms', ['api_key' => '', 'sender_id' => 'Trattoria', 'enabled' => '1'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertNotSame('disconnected', $connection->fresh()->status);
        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_the_old_combined_page_offers_a_new_connection_switched_on(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input type="hidden" name="enabled" value="0">\s*<input type="checkbox" name="enabled" value="1"\s+checked\s*>\s*SMS-Versand aktiv/', $html);
    }

    public function test_a_caller_without_the_switch_gets_a_connected_new_connection(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => 'ohne-schalter'])->assertOk();

        $this->assertSame('connected', IntegrationConnection::where('provider', 'sevenio')->firstOrFail()->status);
    }

    /**
     * Eine Anbindung, die der Betrieb ausgeschaltet hat, bleibt aus, wenn ein Aufrufer den Schalter nicht sendet
     * (dieselbe Regel wie bei MailWizz, Stripe und PayPal).
     */
    public function test_a_caller_without_the_switch_keeps_a_switched_off_connection_off(): void
    {
        Http::fake();
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id, 'disconnected');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => 'Trattoria'])
            ->assertOk()
            ->assertJsonPath('message', 'SMS-Versand ausgeschaltet. Der API-Key bleibt gespeichert.');

        $this->assertSame('disconnected', $connection->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_caller_without_the_switch_keeps_a_connection_after_an_error_on(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $connection = $this->connect($setup['tenant']->id, 'error');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => '', 'sender_id' => 'Trattoria'])->assertOk();

        $this->assertSame('connected', $connection->fresh()->status);
    }

    public function test_the_old_combined_page_offers_the_configured_length(): void
    {
        config(['swayy.sms.sender_id_max' => 9]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/name="sender_id"[^>]*maxlength="9"/', $html);
    }

    public function test_the_limits_stay_within_what_the_provider_allows_and_are_documented(): void
    {
        [$config, $warnings] = $this->swayyConfig(['SWAYY_SMS_SENDER_ID_MAX' => '40', 'SWAYY_SMS_SENDER_ID_TEXT_MAX' => '40', 'SWAYY_SMS_API_KEY_MAX' => '90000']);
        $this->assertSame(16, $config['sms']['sender_id_max']);
        $this->assertSame(16, $config['sms']['sender_id_text_max']);
        $this->assertSame(1000, $config['sms']['api_key_max']);
        $this->assertStringContainsString('SWAYY_SMS_SENDER_ID_MAX', $warnings);
        $this->assertStringContainsString('SWAYY_SMS_SENDER_ID_TEXT_MAX', $warnings);
        $this->assertStringContainsString('SWAYY_SMS_API_KEY_MAX', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_SMS_SENDER_ID_MAX' => '1', 'SWAYY_SMS_SENDER_ID_TEXT_MAX' => '1', 'SWAYY_SMS_API_KEY_MAX' => '1']);
        $this->assertSame(3, $config['sms']['sender_id_max']);
        $this->assertSame(3, $config['sms']['sender_id_text_max']);
        $this->assertSame(32, $config['sms']['api_key_max']);

        [$config] = $this->swayyConfig([]);
        $this->assertSame(16, $config['sms']['sender_id_max']);
        $this->assertSame(11, $config['sms']['sender_id_text_max']);
        $this->assertSame(255, $config['sms']['api_key_max']);

        $this->assertDocumentedInEnvExample(['SWAYY_SMS_SENDER_ID_MAX', 'SWAYY_SMS_SENDER_ID_TEXT_MAX', 'SWAYY_SMS_API_KEY_MAX']);
    }

    public function test_the_largest_allowed_values_are_stored_and_read_back(): void
    {
        Http::fake(['*' => Http::response('12.50', 200)]);
        config(['swayy.sms.sender_id_max' => 16, 'swayy.sms.api_key_max' => 1000]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $key = str_repeat('k', 1000);
        $sender = str_repeat('1', 16);
        $this->actingAs($owner)->putJson('/admin/settings/sms', ['api_key' => $key, 'sender_id' => $sender, 'enabled' => '1'])->assertOk();

        $credentials = $this->credentialsOf(IntegrationConnection::where('provider', 'sevenio')->firstOrFail());
        $this->assertSame($key, $credentials['api_key']);
        $this->assertSame($sender, $credentials['sender_id']);
    }

    public function test_the_search_terms_lead_to_sections_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $item = collect(config('navigation.settings'))->pluck('items')->flatten(1)->firstWhere('key', 'sms');
        $this->assertArrayNotHasKey('active', $item);
        $this->assertArrayNotHasKey('fragment', $item);
        $targets = collect($item['keywords'])->filter(fn ($value, $key) => is_string($key))->values()->unique();
        $this->assertGreaterThanOrEqual(1, $targets->count());

        $response = $this->actingAs($owner)->get('/admin/settings/sms');
        foreach ($targets as $target) {
            $response->assertSee('id="'.$target.'"', false);
        }
    }
}
