<?php

declare(strict_types=1);

namespace Tests\Feature;

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

    public function test_403_without_a_reason_names_the_next_step(): void
    {
        $this->get('/_test/forbidden-plain')
            ->assertForbidden()
            ->assertSee('Dafür fehlt dir das Recht')
            ->assertSee('Diese Seite ist deiner Rolle nicht freigegeben.')
            ->assertSee('Inhaber oder die Administration');
    }

    public function test_403_hides_the_english_text_of_the_framework(): void
    {
        $this->get('/_test/forbidden-gate')
            ->assertForbidden()
            ->assertDontSee('This action is unauthorized')
            ->assertSee('Diese Seite ist deiner Rolle nicht freigegeben.');
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
            ->assertSee('Zur Übersicht');
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
            ->assertSee('Diese Seite ist deiner Rolle nicht freigegeben.');

        foreach ((array) config('permissions.permissions') as $permission) {
            $response->assertDontSee($permission);
        }
        $response->assertDontSee('Berechtigung');
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
