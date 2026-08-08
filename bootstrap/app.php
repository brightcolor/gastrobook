<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTrialActive;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireValidLicense;
use App\Http\Middleware\ResolveTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
            'permission' => RequirePermission::class,
            'license' => RequireValidLicense::class,
            'trial' => EnsureTrialActive::class,
        ]);

        // Already-logged-in visitors hitting a "guest" page (e.g. /login) should
        // land in the app, not on the public marketing homepage – otherwise a
        // stale session silently bounces them to the front page.
        $middleware->redirectUsersTo(function (Request $request) {
            $user = $request->user();

            return $user && $user->isSaasAdmin() && $user->current_tenant_id === null
                ? route('saas.dashboard')
                : route('admin.dashboard');
        });

        // Behind the bundled nginx and/or an external reverse proxy (TLS
        // termination): honour X-Forwarded-* so generated URLs use the correct
        // scheme/host (https links in mails, payment returns, magic links).
        // X-Forwarded-HOST steht bewusst NICHT in der Liste: Mit at:'*' gilt jeder
        // Aufrufer als Proxy, ein gefaelschter Host-Header landete sonst in
        // generierten Links – etwa im Passwort-Reset-Link, der dann auf eine
        // fremde Domain zeigt (mit gueltigem Token). Fuer https-Links reicht
        // X-Forwarded-Proto.
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Zweite Haelfte desselben Problems: Auch der normale Host-Header ist
        // frei waehlbar. In Produktion binden wir ihn an APP_URL. Lokal (leer
        // oder localhost) bleibt alles wie bisher, damit Entwicklung und Tests
        // unter beliebigen Adressen laufen.
        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if (is_string($appHost) && ! in_array($appHost, ['', 'localhost', '127.0.0.1'], true)) {
            $middleware->trustHosts(at: [$appHost], subdomains: true);
        }

        // Signed provider webhooks authenticate via signature, not session
        $middleware->validateCsrfTokens(except: [
            'webhooks/stripe',
            'webhooks/gocardless',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
