<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks admin access when the trial has expired or the account is suspended.
 * Redirects to the trial-expired screen where the customer can request billing.
 *
 * Runs after ResolveTenantContext so TenantContext is populated.
 */
class EnsureTrialActive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->tenant();

        if (! $tenant) {
            return $next($request);
        }

        // Transition trialing tenants whose trial has silently elapsed
        if ($tenant->status === 'active'
            && $tenant->trial_ends_at !== null
            && $tenant->trial_ends_at->isPast()
        ) {
            $tenant->update(['status' => 'trial_expired']);
            $tenant->refresh();
        }

        if ($tenant->status === 'trial_expired' || $tenant->status === 'pending_billing') {
            // Allow the billing-request routes themselves through (form + confirm + success)
            if ($request->routeIs('admin.trial.*', 'billing.confirm')) {
                return $next($request);
            }

            return redirect()->route('admin.trial.expired');
        }

        if ($tenant->status === 'suspended') {
            abort(402, 'Konto gesperrt. Bitte wenden Sie sich an info@swayy.de.');
        }

        return $next($request);
    }
}
