<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\VerifyCapToken;
use App\Mail\ContactRequestMail;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Cap CAPTCHA on the public forms.
 *
 * The contact form stands in for all of them in the behaviour tests – it is
 * the shortest one that reaches a visible side effect (a mail). Which forms
 * are guarded at all is pinned down by the completeness test at the bottom.
 */
class CapCaptchaTest extends TestCase
{
    use RefreshDatabase;

    private const VERIFY_URL = 'https://cap.test/testkey/siteverify';

    /** Fully configured and switched on. */
    private function capOn(array $overrides = []): void
    {
        config(array_merge([
            'cap.enabled' => true,
            'cap.server_url' => 'https://cap.test',
            'cap.site_key' => 'testkey',
            'cap.secret_key' => 'sk-test',
            'cap.fail_open' => false,
            'cap.forms.contact' => true,
        ], $overrides));
    }

    private function contactPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Beispielperson',
            'email' => 'beispiel@example.test',
            'message' => 'Eine Anfrage.',
        ], $overrides);
    }

    // ── Gegenprobe: ohne Cap bleibt alles wie vorher ──────────────────────

    public function test_contact_form_goes_through_when_cap_is_off(): void
    {
        Mail::fake();
        config(['cap.enabled' => false]);

        $this->post('/kontakt', $this->contactPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ContactRequestMail::class);
    }

    public function test_half_configured_cap_does_not_block_the_form(): void
    {
        Mail::fake();
        // Enabled, but the secret is missing – that must not lock every
        // visitor out of the form.
        $this->capOn(['cap.secret_key' => '']);

        $this->post('/kontakt', $this->contactPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ContactRequestMail::class);
    }

    // ── Mit Cap: der Token entscheidet ───────────────────────────────────

    public function test_missing_token_is_rejected_and_sends_no_mail(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);
        $this->capOn();

        $this->post('/kontakt', $this->contactPayload())
            ->assertSessionHasErrors('cap-token');

        Mail::assertNothingSent();
        // Without a token the server must not even ask Cap.
        Http::assertNothingSent();
    }

    public function test_valid_token_lets_the_form_through(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response(['success' => true])]);
        $this->capOn();

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'solved-token']))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ContactRequestMail::class);
        Http::assertSent(fn ($request) => $request->url() === self::VERIFY_URL
            && $request['secret'] === 'sk-test'
            && $request['response'] === 'solved-token');
    }

    public function test_token_rejected_by_cap_is_rejected_here_too(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response(['success' => false, 'error' => 'expired'])]);
        $this->capOn();

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'stale-token']))
            ->assertSessionHasErrors('cap-token');

        Mail::assertNothingSent();
    }

    /**
     * Cap does not answer HTTP 200 when it rejects. Measured against the live
     * server on 18.09.2026: a spent token gives 404, a wrong secret 403 – both
     * with a proper {"success": false} body.
     */
    public function test_spent_token_is_rejected_although_cap_answers_404(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response(['success' => false, 'error' => 'Token not found'], 404)]);
        $this->capOn();

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'spent-token']))
            ->assertSessionHasErrors('cap-token');

        Mail::assertNothingSent();
    }

    /**
     * The one that matters: fail_open is the escape hatch for an unreachable
     * server. A token Cap actively rejected must stay rejected, or a bot gets
     * in by sending nonsense and collecting the 404.
     */
    public function test_fail_open_does_not_wave_through_a_token_cap_rejected(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response(['success' => false, 'error' => 'Token not found'], 404)]);
        $this->capOn(['cap.fail_open' => true]);

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'spent-token']))
            ->assertSessionHasErrors('cap-token');

        Mail::assertNothingSent();
    }

    public function test_wrong_secret_says_so_instead_of_blaming_the_visitor(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response(['success' => false, 'error' => 'Invalid site key or secret'], 403)]);
        $this->capOn();

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'solved-token']))
            ->assertSessionHasErrors(['cap-token' => 'Die Sicherheitsprüfung ist nicht richtig eingerichtet. Bitte den Betreiber informieren.']);

        Mail::assertNothingSent();
    }

    // ── Cap-Server antwortet nicht ───────────────────────────────────────

    public function test_unreachable_cap_server_rejects_the_request(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response('gateway down', 502)]);
        $this->capOn();

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'solved-token']))
            ->assertSessionHasErrors('cap-token');

        Mail::assertNothingSent();
    }

    public function test_fail_open_lets_the_request_through_when_cap_is_unreachable(): void
    {
        Mail::fake();
        Http::fake([self::VERIFY_URL => Http::response('gateway down', 502)]);
        $this->capOn(['cap.fail_open' => true]);

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'solved-token']))
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ContactRequestMail::class);
    }

    public function test_answer_without_success_field_is_treated_as_unreachable(): void
    {
        Mail::fake();
        // An HTML error page from a proxy, not Cap's JSON – must not pass as
        // a solved challenge.
        Http::fake([self::VERIFY_URL => Http::response('<html>Bad Gateway</html>', 200)]);
        $this->capOn();

        $this->post('/kontakt', $this->contactPayload(['cap-token' => 'solved-token']))
            ->assertSessionHasErrors('cap-token');

        Mail::assertNothingSent();
    }

    public function test_switching_off_one_form_leaves_it_unguarded(): void
    {
        Mail::fake();
        $this->capOn(['cap.forms.contact' => false]);

        $this->post('/kontakt', $this->contactPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        Mail::assertSent(ContactRequestMail::class);
    }

    // ── Vollstaendigkeit: kein oeffentliches Formular ohne Cap ────────────

    /**
     * Routes that write, are reachable without a login, and deliberately carry
     * no CAPTCHA – with the reason. A new public form that is not listed here
     * and has no cap middleware turns this test red.
     */
    private const UNGUARDED_ON_PURPOSE = [
        // Reached only through a one-time token in the URL that the visitor
        // received by mail. A bot cannot find these addresses.
        'POST event-booking/{code}/{token}/cancel',
        'POST feedback/{token}',
        'POST invitation/{token}',
        'POST passwort-reset',
        'POST reservation/{code}/cancel/{token}',
        'POST reservation/{code}/reschedule/{token}',
        'POST waitlist/{entry}/{token}',

        // Ends a session, costs nothing and sends nothing.
        'POST konto/{tenantSlug}/logout',

        // Machine to machine, authenticated by signature instead.
        'POST webhooks/gocardless',
        'POST webhooks/stripe',

        // Laravel's own temporary-upload route, only registered while the
        // local disk serves signed URLs.
        'PUT storage/{path}',
    ];

    /**
     * The form name a route is guarded with, or null. Aliases stay unresolved
     * in gatherMiddleware(), so both spellings have to be recognised.
     */
    private function capFormOf(array $middleware): ?string
    {
        foreach ($middleware as $entry) {
            foreach (['cap:', VerifyCapToken::class.':'] as $prefix) {
                if (str_starts_with($entry, $prefix)) {
                    return substr($entry, strlen($prefix));
                }
            }
        }

        return null;
    }

    public function test_every_public_writing_route_is_guarded_or_listed(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $methods = array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']);

            if ($methods === []) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            // Behind a login, or part of the token-authenticated API.
            if (in_array('auth', $middleware, true)
                || in_array(Authenticate::class, $middleware, true)
                || str_starts_with($route->uri(), 'api/')) {
                continue;
            }

            if ($this->capFormOf($middleware) !== null) {
                continue;
            }

            $key = reset($methods).' '.$route->uri();

            if (! in_array($key, self::UNGUARDED_ON_PURPOSE, true)) {
                $unguarded[] = $key;
            }
        }

        $this->assertSame([], $unguarded, 'Public writing routes without a Cap guard: '
            .implode(', ', $unguarded)
            .'. Add cap:<form> plus <x-cap-widget form="<form>" /> on the page, or list the route in UNGUARDED_ON_PURPOSE with a reason.');
    }

    public function test_guard_and_widget_name_the_same_forms(): void
    {
        $guarded = [];

        foreach (Route::getRoutes() as $route) {
            $form = $this->capFormOf($route->gatherMiddleware());

            if ($form !== null) {
                $guarded[] = $form;
            }
        }

        $guarded = array_values(array_unique($guarded));
        sort($guarded);

        $rendered = [];
        $views = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views')));

        foreach ($views as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                preg_match_all('/<x-cap-widget\s+form="([^"]+)"/', (string) file_get_contents($file->getPathname()), $hits);
                $rendered = array_merge($rendered, $hits[1]);
            }
        }

        $rendered = array_values(array_unique($rendered));
        sort($rendered);

        // A guard with no widget leaves visitors stuck; a widget with no guard
        // makes them solve a challenge nobody checks.
        $this->assertSame($guarded, $rendered);

        // And every name has to exist in the config, otherwise it protects
        // nothing at all.
        foreach ($guarded as $form) {
            $this->assertArrayHasKey($form, config('cap.forms'), 'Unknown Cap form name: '.$form);
        }
    }

    // ── Das Widget landet wirklich auf der Seite ─────────────────────────

    /** @return array<string, array{string, string}> */
    public static function guardedPages(): array
    {
        return [
            'Kontakt' => ['/kontakt', 'contact'],
            'Anmeldung' => ['/login', 'login'],
            'Registrierung' => ['/register', 'register'],
            'Passwort vergessen' => ['/passwort-vergessen', 'password'],
        ];
    }

    #[DataProvider('guardedPages')]
    public function test_page_carries_the_widget_and_our_own_asset_urls(string $path, string $form): void
    {
        $this->capOn(['cap.forms.'.$form => true]);

        $html = $this->get($path)->assertOk()->getContent();

        $this->assertStringContainsString('<cap-widget', $html);
        $this->assertStringContainsString('data-cap-api-endpoint="https://cap.test/testkey/"', $html);

        // Widget and WebAssembly from our own Cap server, pako from this app –
        // without these two globals the widget pulls both from a public CDN.
        $this->assertStringContainsString('https://cap.test/assets/widget.js', $html);
        $this->assertStringContainsString("window.CAP_CUSTOM_WASM_URL = 'https:\/\/cap.test\/assets\/cap_wasm_bg.wasm'", $html);
        $this->assertStringContainsString('window.CAP_PAKO_URL', $html);
        $this->assertStringContainsString('vendor\/cap\/pako_inflate.min.js', $html);
        $this->assertStringNotContainsString('cdn.jsdelivr.net', $html);

        // The secret never belongs in a page.
        $this->assertStringNotContainsString('sk-test', $html);
    }

    #[DataProvider('guardedPages')]
    public function test_page_stays_clean_when_that_form_is_unguarded(string $path, string $form): void
    {
        $this->capOn(['cap.forms.'.$form => false]);

        $html = $this->get($path)->assertOk()->getContent();

        $this->assertStringNotContainsString('cap-widget', $html);
    }
}
