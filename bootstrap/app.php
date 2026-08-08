<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureTrialActive;
use App\Http\Middleware\RequirePermission;
use App\Http\Middleware\RequireValidLicense;
use App\Http\Middleware\ResolveTenantContext;
use App\Support\TrustedHosts;
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
        // at:'*' hiesse: jeder Aufrufer gilt als Proxy. Dann kaeme die Client-IP
        // aus X-Forwarded-For und waere frei waehlbar – saemtliche Rate Limits
        // (Buchung, Slots, API) haengen an dieser IP, ebenso der Auditlog.
        // Voreingestellt sind Loopback und die privaten Netze, in denen der
        // mitgelieferte nginx sitzt. Wer einen Proxy auf einer oeffentlichen
        // Adresse davor hat (Cloudflare, externer Load Balancer), traegt ihn in
        // TRUSTED_PROXIES ein – sonst kommen https-Links und Anmeldung ins
        // Straucheln, weil auch X-Forwarded-Proto an dieser Liste haengt.
        // getenv statt env()/config(): Diese Closure laeuft, bevor die
        // Konfiguration geladen ist.
        $middleware->trustProxies(
            at: array_values(array_filter(array_map('trim', explode(
                ',',
                (string) (getenv('TRUSTED_PROXIES') ?: '127.0.0.1,::1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16')
            )))),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        // Zweite Haelfte desselben Problems: Auch der normale Host-Header ist
        // frei waehlbar. In Produktion binden wir ihn an APP_URL. Lokal (leer
        // oder localhost) bleibt alles wie bisher, damit Entwicklung und Tests
        // unter beliebigen Adressen laufen.
        // Als Closure, nicht direkt: Diese Datei wird ausgewertet, bevor der
        // Konfigurationsdienst existiert – config() waere hier ein Absturz.
        // subdomains: false ist Absicht – TrustedHosts::patterns() bringt das
        // Subdomain-Muster selbst mit. Mit true haengte Laravel ein zweites
        // Muster aus APP_URL an, auch an eine leere Liste; siehe die Warnung
        // in TrustedHosts.
        $middleware->trustHosts(
            at: fn (): array => TrustedHosts::patterns(),
            subdomains: false,
        );

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
