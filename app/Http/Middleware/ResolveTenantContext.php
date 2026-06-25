<?php

namespace App\Http\Middleware;

use App\Models\Location;
use App\Models\Tenant;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active tenant (and optional location) for the admin area
 * from the authenticated user's membership. Aborts with 403 when the user
 * tries to activate a tenant they do not belong to.
 */
class ResolveTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user === null) {
            return $next($request);
        }

        $user->loadMissing('tenantMemberships');

        $tenant = null;

        if ($user->current_tenant_id !== null) {
            $tenant = Tenant::find($user->current_tenant_id);
        }

        // Membership check — SaaS admins may enter any tenant (audited via impersonation flow).
        if ($tenant !== null && ! $user->isSaasAdmin() && $user->membershipFor($tenant) === null) {
            $tenant = null;
        }

        if ($tenant === null && ! $user->isSaasAdmin()) {
            $membership = $user->tenantMemberships->first();
            if ($membership !== null) {
                $tenant = Tenant::find($membership->tenant_id);
                $user->forceFill(['current_tenant_id' => $tenant?->id])->save();
            }
        }

        if ($tenant === null) {
            if ($user->isSaasAdmin()) {
                return $next($request); // SaaS admin area works without tenant context
            }
            abort(403, 'Kein Zugriff auf einen Mandanten.');
        }

        // trial_expired and pending_billing are handled by EnsureTrialActive;
        // only hard-block statuses (suspended, cancelled) are rejected here.
        $hardBlocked = in_array($tenant->status, ['suspended', 'cancelled'], true);
        if ($hardBlocked && ! $user->isSaasAdmin()) {
            abort(403, 'Dieser Mandant ist gesperrt.');
        }

        $this->context->setTenant($tenant);

        // Active location (validated against tenant + per-user restrictions)
        $location = null;
        if ($user->current_location_id !== null) {
            $location = Location::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->find($user->current_location_id);
        }
        if ($location === null) {
            $location = Location::withoutGlobalScope('tenant')
                ->where('tenant_id', $tenant->id)
                ->where('is_active', true)
                ->orderBy('id')
                ->first();
        }
        if ($location !== null && ! $user->canAccessLocation($tenant, $location)) {
            $location = $tenant->locations()
                ->get()
                ->first(fn ($l) => $user->canAccessLocation($tenant, $l));
        }

        $this->context->setLocation($location);

        return $next($request);
    }
}
