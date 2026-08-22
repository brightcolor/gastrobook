<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;

class ApiTokenController extends Controller
{
    private const SCOPES = [
        'reservations:read', 'reservations:write',
        'guests:read', 'guests:write',
        'availability:read', 'waitlist:write',
        'events:read', 'events:write',
        'webhooks:manage', 'reports:read',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        return view('admin.api-tokens.index', [
            'tokens' => $this->tenantTokens(),
            'scopes' => self::SCOPES,
            'apiEnabled' => $this->context->tenant()->hasFeature('api_enabled'),
            'currentUserId' => $request->user()->id,
        ]);
    }

    /**
     * Alle Token DIESES Betriebs, nicht nur die eigenen.
     *
     * Vorher sah jeder nur seine eigenen. Damit gab es keinen Weg, einen
     * einmal ausgegebenen Token eines Kollegen zu entziehen - ausser dessen
     * Mitgliedschaft ganz zu loeschen. Wer api_tokens.manage hat, muss den
     * Bestand des Betriebs sehen und widerrufen koennen.
     *
     * @return Collection<int, PersonalAccessToken>
     */
    private function tenantTokens(): Collection
    {
        $tenantAbility = 'tenant:'.$this->context->tenantId();
        $mitglieder = TenantUser::where('tenant_id', $this->context->tenantId())->pluck('user_id');

        return PersonalAccessToken::where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $mitglieder)
            ->with('tokenable:id,name,email')
            ->get()
            ->filter(fn ($t) => in_array($tenantAbility, $t->abilities ?? [], true))
            ->values();
    }

    public function store(Request $request)
    {
        abort_unless($this->context->tenant()->hasFeature('api_enabled'), 403, 'API nicht im Tarif enthalten.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['in:'.implode(',', self::SCOPES)],
        ]);

        // Tenant binding lives in the token abilities
        $abilities = array_merge(['tenant:'.$this->context->tenantId()], $validated['scopes']);
        $token = $request->user()->createToken($validated['name'], $abilities);

        $this->audit->log('api_token.created', null, null, ['name' => $validated['name'], 'scopes' => $validated['scopes']]);

        return back()->with('new_token', $token->plainTextToken);
    }

    public function destroy(Request $request, int $tokenId)
    {
        $token = $this->tenantTokens()->firstWhere('id', $tokenId);
        abort_if($token === null, 404);

        $this->audit->log('api_token.deleted', null, [
            'name' => $token->name,
            'owner_id' => $token->tokenable_id,
        ]);
        $token->delete();

        return back()->with('success', __('Token gelöscht.'));
    }
}
