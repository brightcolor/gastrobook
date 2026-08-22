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
    /**
     * Welches Recht hinter jedem Token-Umfang steht.
     *
     * Die API prueft sonst nur die Umfaenge des Tokens, nie die Rolle im
     * Betrieb. Ein einmal ausgestellter Token behielt damit die Rechte von
     * damals: Wer vom Inhaber auf "nur lesen" heruntergestuft wurde, konnte
     * ueber die API weiter Reservierungen anlegen, die komplette Gaesteliste
     * auslesen und Webhook-Ziele auf eine beliebige fremde Adresse setzen. In
     * der Oberflaeche wirkte die Herabstufung sofort, hier gar nicht.
     *
     * @var array<string, string>
     */
    private const SCOPE_PERMISSIONS = [
        'reservations:read' => 'reservations.view',
        'reservations:write' => 'reservations.create',
        'guests:read' => 'guests.view',
        'guests:write' => 'guests.update',
        'availability:read' => 'reservations.view',
        'waitlist:write' => 'waitlist.manage',
        'events:read' => 'reservations.view',
        'events:write' => 'events.manage',
        'webhooks:manage' => 'webhooks.manage',
        'reports:read' => 'reports.view',
    ];

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

        // Die Umfaenge des Tokens auf das eindampfen, was die HEUTIGE Rolle
        // hergibt. tokenCan() in den Controllern fragt danach - eine
        // Herabstufung wirkt damit auch hier.
        if (! $user->isSaasAdmin()) {
            $token->abilities = array_values(array_filter(
                $token->abilities ?? [],
                fn (string $ability) => ! isset(self::SCOPE_PERMISSIONS[$ability])
                    || $user->canInTenant(self::SCOPE_PERMISSIONS[$ability], $tenant)
            ));
        }

        return $next($request);
    }
}
