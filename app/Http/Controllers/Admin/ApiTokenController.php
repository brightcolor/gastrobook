<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;

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
        $tenantAbility = 'tenant:'.$this->context->tenantId();

        $tokens = $request->user()->tokens()
            ->get()
            ->filter(fn ($t) => in_array($tenantAbility, $t->abilities ?? [], true));

        return view('admin.api-tokens.index', [
            'tokens' => $tokens,
            'scopes' => self::SCOPES,
            'apiEnabled' => $this->context->tenant()->hasFeature('api_enabled'),
        ]);
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
        $token = $request->user()->tokens()->findOrFail($tokenId);
        $this->audit->log('api_token.deleted', null, ['name' => $token->name]);
        $token->delete();

        return back()->with('success', __('Token gelöscht.'));
    }
}
