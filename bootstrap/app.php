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
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
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
