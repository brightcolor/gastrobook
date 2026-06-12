<?php

namespace App\Http\Middleware;

use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API tokens are tenant-bound: every token carries a "tenant:<id>" ability.
 * The middleware resolves that tenant, verifies the user is (still) a member
 * and sets the tenant context for all scoped queries.
 */
class ResolveApiTenant
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if ($user === null || $token === null) {
            abort(401);
        }

        $tenantId = null;
        foreach ($token->abilities ?? [] as $ability) {
            if (str_starts_with($ability, 'tenant:')) {
                $tenantId = (int) substr($ability, 7);
                break;
            }
        }

        if ($tenantId === null) {
            abort(403, 'Token ist keinem Mandanten zugeordnet.');
        }

        $tenant = Tenant::find($tenantId);
        if ($tenant === null || ! $tenant->isActive()) {
            abort(403, 'Mandant inaktiv.');
        }

        if (! $user->isSaasAdmin() && $user->membershipFor($tenant) === null) {
            abort(403, 'Kein Zugriff auf diesen Mandanten.');
        }

        if (! $tenant->hasFeature('api_enabled')) {
            abort(403, 'API ist im aktuellen Tarif nicht enthalten.');
        }

        $this->context->setTenant($tenant);

        return $next($request);
    }
}
