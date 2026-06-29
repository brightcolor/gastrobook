<?php

declare(strict_types=1);

namespace App\Http\Controllers\Saas;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class SaasUserController extends Controller
{
    /** Platform-level roles selectable here (null = normal tenant user). */
    private const SAAS_ROLES = ['super_admin', 'support_admin', 'billing_admin', 'readonly_admin'];

    public function __construct(private readonly AuditLogger $audit) {}

    public function index(Request $request)
    {
        abort_unless($request->user()?->isSaasAdmin(), 403);

        $users = User::query()
            ->withCount('tenantMemberships')
            ->when($request->input('q'), fn ($q, $term) => $q
                ->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")))
            ->orderByRaw('saas_role is null') // platform admins first
            ->orderBy('name')
            ->paginate(50)
            ->withQueryString();

        return view('saas.users.index', [
            'users' => $users,
            'saasRoles' => self::SAAS_ROLES,
            'canManage' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:200', 'unique:users,email'],
            'password' => ['required', 'string', 'min:10'],
            'saas_role' => ['nullable', Rule::in(self::SAAS_ROLES)],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => strtolower($validated['email']),
            'password' => Hash::make($validated['password']),
            'saas_role' => $validated['saas_role'] ?: null,
            'is_active' => true,
        ]);

        $this->audit->log('saas.user_created', $user, null, [
            'email' => $user->email, 'saas_role' => $user->saas_role,
        ], null, $request->user());

        return back()->with('success', __('Benutzer ":name" angelegt.', ['name' => $user->name]));
    }

    public function updateRole(Request $request, User $user)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $validated = $request->validate([
            'saas_role' => ['nullable', Rule::in(self::SAAS_ROLES)],
        ]);
        $newRole = $validated['saas_role'] ?: null;

        // Never strip the last super admin (would lock everyone out of SaaS admin).
        if ($user->saas_role === 'super_admin' && $newRole !== 'super_admin'
            && User::where('saas_role', 'super_admin')->count() <= 1) {
            return back()->withErrors(['saas_role' => __('Der letzte Super-Admin kann nicht herabgestuft werden.')]);
        }

        $old = $user->saas_role;
        $user->update(['saas_role' => $newRole]);

        $this->audit->log('saas.user_role_changed', $user, ['saas_role' => $old], ['saas_role' => $newRole], null, $request->user());

        return back()->with('success', __('Plattform-Rolle aktualisiert.'));
    }

    public function destroy(Request $request, User $user)
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);
        abort_if($user->id === $request->user()->id, 422, 'Sie können sich nicht selbst löschen.');

        if ($user->saas_role === 'super_admin'
            && User::where('saas_role', 'super_admin')->count() <= 1) {
            return back()->withErrors(['delete' => __('Der letzte Super-Admin kann nicht gelöscht werden.')]);
        }

        $this->audit->log('saas.user_deleted', $user, ['email' => $user->email], null, null, $request->user());
        $user->delete();

        return back()->with('success', __('Benutzer gelöscht.'));
    }
}
