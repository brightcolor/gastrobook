<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Concerns\CreatesTenants;
use Tests\Concerns\ReadsSwayyConfig;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use CreatesTenants, ReadsSwayyConfig, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('web')->get('/_test/forbidden', fn () => abort(403, 'Dieser Betrieb ist gesperrt. Schreib uns an info@swayy.de, dann klären wir den Grund.'));
        Route::middleware('web')->get('/_test/forbidden-plain', fn () => abort(403));
        Route::middleware('web')->get('/_test/forbidden-gate', fn () => throw new AuthorizationException);
        Route::middleware('web')->get('/admin/_test/forbidden-plain', fn () => abort(403));
        Route::middleware('web')->get('/admin/_test/forbidden-gate', fn () => throw new AuthorizationException);
        Route::middleware('web')->get('/_test/forbidden-markup', fn () => abort(403, '<script>alert(1)</script> Zugriff verweigert'));
        Route::middleware('web')->get('/_test/missing', fn () => abort(404, 'Tisch 12 mit Zugangscode gibt es nicht'));
        Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('SQLSTATE geheim /var/www/html/app'));
        Route::middleware('web')->get('/_test/boom-abort', fn () => abort(500, 'SQLSTATE geheim /var/www/html/app'));
        Route::middleware('web')->get('/_test/busy', fn () => abort(429));
        Route::middleware('web')->get('/_test/maintenance', fn () => abort(503));
        Route::middleware('web')->get('/_test/maintenance-default', fn () => abort(503, 'Service Unavailable'));
        Route::middleware('web')->get('/_test/maintenance-own', fn () => abort(503, 'Das Rechenzentrum wird gewartet. Schau gleich noch einmal vorbei.'));
        Route::middleware('web')->post('/_test/expired', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    }

    public function test_404_names_what_happened_and_the_way_back(): void
    {
        $this->get('/gibt-es-nicht-'.uniqid())
            ->assertNotFound()
            ->assertSee('Diese Seite gibt es nicht')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('404');
    }

    public function test_404_never_shows_the_message_of_the_exception(): void
    {
        $this->get('/_test/missing')
            ->assertNotFound()
            ->assertSee('Die Adresse ist falsch oder der Eintrag wurde gelöscht.')
            ->assertDontSee('Zugangscode');
    }

    public function test_403_shows_the_reason_from_abort(): void
    {
        $this->get('/_test/forbidden')
            ->assertForbidden()
            ->assertSee('Dieser Betrieb ist gesperrt. Schreib uns an info@swayy.de, dann klären wir den Grund.');
    }

    public function test_403_in_the_admin_without_a_reason_names_the_missing_right_and_the_next_step(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/_test/forbidden-plain')
            ->assertForbidden()
            ->assertSee('Dafür fehlt dir das Recht')
            ->assertSee('Diese Seite ist deiner Rolle nicht freigegeben.')
            ->assertSee('Inhaber oder die Administration');
    }

    public function test_403_hides_the_english_text_of_the_framework(): void
    {
        $this->actingAs(User::factory()->create())->get('/admin/_test/forbidden-gate')
            ->assertForbidden()
            ->assertDontSee('This action is unauthorized')
            ->assertSee('Diese Seite ist deiner Rolle nicht freigegeben.');

        $this->get('/_test/forbidden-gate')
            ->assertForbidden()
            ->assertDontSee('This action is unauthorized');
    }

    public function test_403_on_a_public_link_speaks_to_guests_without_roles(): void
    {
        $this->get('/_test/forbidden-plain')
            ->assertForbidden()
            ->assertSee('Dieser Link funktioniert nicht (mehr)')
            ->assertSee('Öffne den Link noch einmal aus deiner E-Mail oder wende dich an den Betrieb.')
            ->assertDontSee('Rolle')
            ->assertDontSee('Recht')
            ->assertDontSee('Inhaber')
            ->assertDontSee('Abmelden');
    }

    public function test_an_invalid_unsubscribe_link_is_a_public_403(): void
    {
        $this->get('/marketing/abmelden/1')
            ->assertForbidden()
            ->assertSee('Dieser Link funktioniert nicht (mehr)')
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('Rolle')
            ->assertDontSee('Dafür fehlt dir das Recht');
    }

    public function test_a_blocked_business_gets_a_neutral_title_and_no_link_that_loops(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'suspended']);
        $member = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($member)->get('/admin')
            ->assertForbidden()
            ->assertSee('<title>Zugriff nicht möglich – Swayy</title>', false)
            ->assertSee('Dieser Betrieb ist gesperrt.')
            ->assertSee('Frag den Inhaber deines Betriebs oder melde dich mit einem anderen Konto an.')
            ->assertDontSee('Dafür fehlt dir das Recht')
            ->assertDontSee('Rolle')
            ->assertSee('Zur Startseite')
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('Abmelden')
            ->assertSee('href="'.route('logout.confirm').'"', false)
            ->assertDontSee('Zur Übersicht');
        $this->assertStringNotContainsString('href="'.route('admin.dashboard').'"', (string) $response->getContent());
    }

    public function test_an_account_without_a_business_gets_the_reason_and_the_way_out(): void
    {
        $user = User::factory()->create(['current_tenant_id' => null]);

        $this->actingAs($user)->get('/admin')
            ->assertForbidden()
            ->assertSee('Zugriff nicht möglich')
            ->assertSee('Dein Konto gehört zu keinem Betrieb. Frag den Inhaber deines Betriebs nach einer Einladung.')
            ->assertDontSee('Zur Übersicht')
            ->assertSee('href="'.route('logout.confirm').'"', false)
            ->assertSee('href="'.url('/').'"', false);
    }

    public function test_403_escapes_the_reason(): void
    {
        $this->get('/_test/forbidden-markup')
            ->assertForbidden()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt; Zugriff verweigert', false);
    }

    public function test_500_reveals_nothing_internal(): void
    {
        config(['app.debug' => false]);

        $response = $this->get('/_test/boom')->assertStatus(500);

        $response->assertSee('Da ist etwas schiefgegangen')
            ->assertSee('Bitte lade die Seite neu')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('/var/www');
    }

    public function test_500_ignores_a_message_given_to_abort(): void
    {
        config(['app.debug' => false]);

        $this->get('/_test/boom-abort')
            ->assertStatus(500)
            ->assertSee('Da ist etwas schiefgegangen')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('/var/www');
    }

    public function test_500_names_the_support_address_from_the_settings(): void
    {
        config(['app.debug' => false, 'swayy.support.email' => 'hilfe@example.test']);

        $this->get('/_test/boom')
            ->assertStatus(500)
            ->assertSee('hilfe@example.test')
            ->assertSee('href="mailto:hilfe@example.test"', false)
            ->assertDontSee('info@swayy.de');
    }

    public function test_419_offers_to_reload_the_page_the_form_came_from(): void
    {
        $this->from(url('/admin/reservations'))->post('/_test/expired')
            ->assertStatus(419)
            ->assertSee('Die Seite war zu lange offen')
            ->assertSee('Bitte lade die Seite neu und sende das Formular noch einmal.')
            ->assertSee('Seite neu laden')
            ->assertSee('href="'.url('/admin/reservations').'"', false)
            ->assertDontSee('CSRF');
    }

    public function test_419_never_links_to_a_foreign_page(): void
    {
        $this->from('https://fremd.example.test/formular')->post('/_test/expired')
            ->assertStatus(419)
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('fremd.example.test');
    }

    public function test_the_419_view_renders_on_its_own(): void
    {
        $this->view('errors.419')
            ->assertSee('Die Seite war zu lange offen')
            ->assertSee('Bitte lade die Seite neu');
    }

    public function test_429_and_503_say_when_to_try_again(): void
    {
        $this->get('/_test/busy')->assertStatus(429)->assertSee('Zu viele Anfragen');
        $this->get('/_test/maintenance')->assertStatus(503)->assertSee('Swayy wird gerade aktualisiert');
    }

    public function test_429_names_the_waiting_time_from_retry_after(): void
    {
        Route::middleware('web')->get('/_test/busy-42', fn () => abort(429, '', ['Retry-After' => 42]));
        Route::middleware('web')->get('/_test/busy-1', fn () => abort(429, '', ['Retry-After' => 1]));
        Route::middleware('web')->get('/_test/busy-150', fn () => abort(429, '', ['Retry-After' => 150]));

        $this->get('/_test/busy-42')->assertStatus(429)->assertSee('42 Sekunden');
        $this->get('/_test/busy-1')->assertStatus(429)->assertSee('1 Sekunde')->assertDontSee('1 Sekunden');
        $this->get('/_test/busy-150')->assertStatus(429)->assertSee('3 Minuten');
        $this->get('/_test/busy')->assertStatus(429)->assertSee('Bitte warte einen Moment und versuch es dann erneut.');
    }

    public function test_503_shows_its_own_message_but_not_the_default_text_of_the_framework(): void
    {
        $this->get('/_test/maintenance-own')
            ->assertStatus(503)
            ->assertSee('Das Rechenzentrum wird gewartet. Schau gleich noch einmal vorbei.');

        $this->get('/_test/maintenance-default')
            ->assertStatus(503)
            ->assertDontSee('Service Unavailable')
            ->assertSee('Gleich ist alles wieder da.');

        $this->get('/_test/maintenance')
            ->assertStatus(503)
            ->assertSee('Gleich ist alles wieder da.');
    }

    public function test_every_page_is_a_complete_german_page_with_the_status_code(): void
    {
        config(['app.debug' => false]);

        foreach ([
            '/gibt-es-nicht-'.uniqid() => 404,
            '/_test/forbidden-plain' => 403,
            '/_test/boom' => 500,
            '/_test/busy' => 429,
            '/_test/maintenance' => 503,
        ] as $path => $status) {
            $html = (string) $this->get($path)->assertStatus($status)->getContent();

            $this->assertStringContainsString('<html lang="de">', $html, $path);
            $this->assertMatchesRegularExpression('/<title>[^<]+ – Swayy<\/title>/u', $html, $path);
            $this->assertMatchesRegularExpression('/<main[^>]*>.*<h1[^>]*>[^<]+<\/h1>/su', $html, $path);
            $this->assertStringContainsString('Fehlercode '.$status, $html, $path);
        }
    }

    public function test_a_guest_gets_the_way_to_the_start_page_only(): void
    {
        $this->get('/_test/missing')
            ->assertNotFound()
            ->assertSee('Zur Startseite')
            ->assertDontSee('Zur Übersicht');
    }

    public function test_a_signed_in_user_without_a_business_also_gets_the_page(): void
    {
        $user = User::factory()->create(['current_tenant_id' => null]);

        $this->actingAs($user)->get('/_test/missing')
            ->assertNotFound()
            ->assertSee('Diese Seite gibt es nicht')
            ->assertSee('Zur Übersicht')
            ->assertSee('href="'.route('admin.dashboard').'"', false);

        $this->actingAs($user)->get('/_test/forbidden-plain')
            ->assertForbidden()
            ->assertSee('Dieser Link funktioniert nicht (mehr)')
            ->assertSee('Abmelden');
    }

    public function test_403_sends_a_guest_to_the_start_page(): void
    {
        $this->get('/_test/forbidden-plain')
            ->assertForbidden()
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('Zur Übersicht');
    }

    public function test_403_never_names_the_missing_permission(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $response = $this->actingAs($host)->get('/admin/settings/capacity')
            ->assertForbidden()
            ->assertSee('Dafür fehlt dir das Recht')
            ->assertSee('Diese Seite ist deiner Rolle nicht freigegeben.')
            // Der Betrieb ist in Ordnung: Die Übersicht führt nicht erneut in diese Seite.
            ->assertSee('href="'.route('admin.dashboard').'"', false)
            ->assertSee('href="'.url('/').'"', false)
            ->assertSee('href="'.route('logout.confirm').'"', false);

        foreach ((array) config('permissions.permissions') as $permission) {
            $response->assertDontSee($permission);
        }
        $response->assertDontSee('Berechtigung');
    }

    public function test_the_blocked_business_names_the_support_address_from_the_settings(): void
    {
        config(['swayy.support.email' => 'hilfe@example.test']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'suspended']);
        $member = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($member)->get('/admin')
            ->assertForbidden()
            ->assertSee('Dieser Betrieb ist gesperrt. Schreib uns an hilfe@example.test, dann klären wir den Grund.')
            ->assertDontSee('info@swayy.de');
    }

    public function test_a_blocked_account_gets_a_german_402_page_with_cause_and_next_step(): void
    {
        config(['swayy.support.email' => 'hilfe@example.test']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'suspended']);
        $support = User::factory()->create(['saas_role' => 'super_admin', 'current_tenant_id' => $setup['tenant']->id]);
        $this->clearTenantContext();

        $response = $this->actingAs($support)->get('/admin')
            ->assertStatus(402)
            ->assertSee('<title>Dieser Betrieb ist gesperrt – Swayy</title>', false)
            ->assertSee('Dieser Betrieb ist gesperrt. Schreib uns an hilfe@example.test, dann klären wir den Grund.')
            ->assertSee('Frag den Inhaber deines Betriebs oder melde dich mit einem anderen Konto an.')
            ->assertSee('Fehlercode 402')
            ->assertSee('href="'.route('logout.confirm').'"', false)
            ->assertSee('href="'.url('/').'"', false)
            ->assertDontSee('Payment Required')
            ->assertDontSee('Wenden Sie')
            ->assertDontSee('info@swayy.de')
            ->assertDontSee('Zur Übersicht');
        $this->assertStringNotContainsString('href="'.route('admin.dashboard').'"', (string) $response->getContent());
    }

    public function test_a_402_without_a_reason_still_names_the_cause_and_the_support_address(): void
    {
        config(['swayy.support.email' => 'hilfe@example.test']);
        Route::middleware('web')->get('/_test/payment', fn () => abort(402));

        $this->get('/_test/payment')
            ->assertStatus(402)
            ->assertSee('Der Zugang dieses Betriebs ist gerade gesperrt. Schreib uns an hilfe@example.test, dann klären wir den Grund.')
            ->assertSee('Wende dich an den Betrieb.')
            ->assertDontSee('Payment Required');
    }

    public function test_the_admin_answers_a_blocked_account_with_json_in_the_du_form(): void
    {
        config(['swayy.support.email' => 'hilfe@example.test']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'suspended']);
        $support = User::factory()->create(['saas_role' => 'super_admin', 'current_tenant_id' => $setup['tenant']->id]);
        $this->clearTenantContext();

        $this->actingAs($support)->getJson('/admin')
            ->assertStatus(402)
            ->assertExactJson(['message' => 'Dieser Betrieb ist gesperrt. Schreib uns an hilfe@example.test, dann klären wir den Grund.']);
    }

    public function test_the_admin_keeps_answering_fetch_requests_with_json(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->getJson('/admin/settings/capacity')
            ->assertForbidden()
            ->assertJsonStructure(['message']);
    }

    public function test_the_402_page_offers_platform_administrators_the_way_back(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'suspended']);
        $platform = User::factory()->create(['saas_role' => 'support_admin', 'current_tenant_id' => $setup['tenant']->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($platform)->get('/admin')
            ->assertStatus(402)
            ->assertSee('Zur Plattform')
            ->assertSee('href="'.url('/saas').'"', false)
            ->assertSee('Als Plattform-Administrator änderst du den Zustand des Betriebs in der Plattform unter „Betriebe“.')
            // Die übrigen Wege bleiben, und der Knopf „Zur Startseite“ tritt hinter die Plattform zurück.
            ->assertSee('href="'.route('logout.confirm').'"', false);

        // Wer zum Betrieb gehört, sieht die Sperre als 403 und hat keinen Weg in die Plattform.
        $this->actingAs($owner)->get('/admin')
            ->assertForbidden()
            ->assertDontSee('Zur Plattform')
            ->assertDontSee('href="'.url('/saas').'"', false)
            ->assertDontSee('Plattform-Administrator');
    }

    public function test_the_remaining_pages_name_the_support_address_of_the_settings(): void
    {
        config(['swayy.support.email' => 'hilfe@example.test']);

        $locked = view('provisioning.locked', ['tenant' => new Tenant(['name' => 'Beispielbetrieb', 'status' => 'cancelled']), 'label' => 'gekündigt', 'panelUrl' => ''])->render();
        $this->assertStringContainsString('schreib uns an hilfe@example.test, solange die Daten noch da sind.', $locked);
        $this->assertStringContainsString('href="mailto:hilfe@example.test"', $locked);
        $this->assertStringContainsString('Fragen an hilfe@example.test', $locked);

        $already = view('admin.trial.confirm-already')->render();
        $this->assertStringContainsString('href="mailto:hilfe@example.test"', $already);

        $confirmed = view('admin.trial.confirmed', ['billingRequest' => (object) ['contact_name' => 'Frau Beispiel']])->render();
        $this->assertStringContainsString('href="mailto:hilfe@example.test"', $confirmed);

        foreach ([$locked, $already, $confirmed] as $html) {
            $this->assertStringNotContainsString('info@swayy.de', $html);
        }
    }

    public function test_the_billing_way_back_names_the_support_address_of_the_settings(): void
    {
        config(['swayy.support.email' => 'hilfe@example.test']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/tarif')
            ->assertRedirect(route('admin.dashboard'))
            ->assertSessionHas('error', 'Tarif und Rechnungen für diesen Betrieb führt der Kundenbereich noch nicht. Schreib uns an hilfe@example.test, wenn du deinen Tarif ändern möchtest.');
    }

    public function test_the_support_address_is_a_documented_setting_with_a_default(): void
    {
        [$config, $warnings] = $this->swayyConfig([]);
        $this->assertSame('info@swayy.de', $config['support']['email']);
        $this->assertSame('', $warnings);

        [$config] = $this->swayyConfig(['SWAYY_SUPPORT_EMAIL' => 'hilfe@example.test']);
        $this->assertSame('hilfe@example.test', $config['support']['email']);

        [$config, $warnings] = $this->swayyConfig(['SWAYY_SUPPORT_EMAIL' => 'keine adresse']);
        $this->assertSame('info@swayy.de', $config['support']['email']);
        $this->assertStringContainsString('SWAYY_SUPPORT_EMAIL', $warnings);

        $this->assertDocumentedInEnvExample(['SWAYY_SUPPORT_EMAIL']);
    }
}
