<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware: permission:reservations.view
 * Checks the permission against the active tenant and location.
 */
class RequirePermission
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();
        $tenant = $this->context->tenant();

        if ($user === null || $tenant === null) {
            abort(403);
        }

        if (! $user->canInTenant($permission, $tenant, $this->context->location())) {
            abort(403, 'Fehlende Berechtigung: '.$permission);
        }

        return $next($request);
    }
}
