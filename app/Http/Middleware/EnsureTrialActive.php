<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Provisioning\AccountState;
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

        // Ein Betrieb mit Vertrag im Kundenpanel: Zustand und Probe regelt das
        // Panel. Wartend und aktiv arbeiten, gesperrt und gekuendigt sehen eine
        // Seite mit Grund und Weg in den Kundenbereich.
        if ($tenant->hasContract()) {
            if (AccountState::isOpen($tenant)) {
                return $next($request);
            }

            return response()->view('provisioning.locked', [
                'tenant' => $tenant,
                'label' => AccountState::label($tenant),
                'panelUrl' => (string) config('provisioning.panel_base_url'),
            ], 403);
        }

        // Ein bezahlter Tarif (ohne Testfrist) unterliegt nie der Trial-Sperre.
        // Ein Tarifwechsel liess `trial_ends_at` frueher stehen; lief diese
        // alte Frist ab, sperrte sie den zahlenden Betrieb aus ("Tarif wird
        // nicht gespeichert"). Darum: auf einem bezahlten Tarif niemals auf
        // trial_expired kippen - und einen so faelschlich gesperrten Betrieb
        // wieder aktiv schalten.
        $onPaidPlan = $tenant->plan !== null && ! $tenant->plan->grantsTrial();

        if ($onPaidPlan) {
            if ($tenant->status === 'trial_expired') {
                $tenant->update(['status' => 'active']);
                $tenant->refresh();
            }
        } elseif ($tenant->status === 'active'
            && $tenant->trial_ends_at !== null
            && $tenant->trial_ends_at->isPast()
        ) {
            // Testtarif, dessen Frist still abgelaufen ist.
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
            abort(402, __('Dieser Betrieb ist gesperrt. Schreib uns an :email, dann klären wir den Grund.', ['email' => config('swayy.support.email')]));
        }

        return $next($request);
    }
}
