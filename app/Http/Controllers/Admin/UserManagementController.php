<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserManagementController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index()
    {
        $tenant = $this->context->tenant();

        return view('admin.users.index', [
            'tenant' => $tenant,
            'memberships' => $tenant->memberships()->with('user')->get(),
            'invitations' => Invitation::whereNull('accepted_at')->where('expires_at', '>', now())->get(),
            'roles' => array_keys(config('permissions.roles')),
            'locations' => $tenant->locations()->get(),
        ]);
    }

    public function invite(Request $request)
    {
        $tenant = $this->context->tenant();

        if (! $this->limits->canAdd($tenant, 'max_users')) {
            return back()->withErrors(['email' => __('Benutzer-Limit Ihres Tarifs erreicht.')]);
        }

        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
            'role' => ['required', 'in:'.implode(',', array_keys(config('permissions.roles')))],
            'all_locations' => ['nullable', 'boolean'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
        ]);

        // Existing user → direct membership, otherwise invitation token
        $user = User::where('email', strtolower($validated['email']))->first();
        if ($user !== null) {
            TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $validated['role'], 'all_locations' => $request->boolean('all_locations', true)]
            );
            if (! $request->boolean('all_locations', true)) {
                $locationIds = collect($validated['location_ids'] ?? [])
                    ->filter(fn ($id) => $tenant->locations()->where('id', $id)->exists());
                foreach ($locationIds as $locationId) {
                    DB::table('location_user')->insertOrIgnore([
                        'location_id' => $locationId,
                        'user_id' => $user->id,
                        'tenant_id' => $tenant->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
            $this->audit->log('user.added', $user, null, ['role' => $validated['role']]);

            return back()->with('success', __('Benutzer hinzugefügt.'));
        }

        $invitation = Invitation::create([
            'tenant_id' => $tenant->id,
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'all_locations' => $request->boolean('all_locations', true),
            'location_ids' => $validated['location_ids'] ?? null,
            'invited_by' => $request->user()->id,
        ]);

        $this->audit->log('user.invited', $invitation, null, ['email' => $invitation->email, 'role' => $invitation->role]);

        return back()->with('success', __('Einladung erstellt. Link: :link', [
            'link' => route('invitation.accept', ['token' => $invitation->token]),
        ]));
    }

    public function updateRole(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);

        $validated = $request->validate([
            'role' => ['required', 'in:'.implode(',', array_keys(config('permissions.roles')))],
        ]);

        // The last owner cannot be demoted
        if ($membership->role === 'tenant_owner' && $validated['role'] !== 'tenant_owner') {
            $owners = $tenant->memberships()->where('role', 'tenant_owner')->count();
            if ($owners <= 1) {
                return back()->withErrors(['role' => __('Der letzte Inhaber kann nicht herabgestuft werden.')]);
            }
        }

        $old = $membership->role;
        $membership->update(['role' => $validated['role']]);

        $this->audit->log('user.role_changed', $membership, ['role' => $old], ['role' => $validated['role']]);

        return back()->with('success', __('Rolle geändert.'));
    }

    public function remove(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        abort_if($membership->user_id === $request->user()->id, 422, 'Sie können sich nicht selbst entfernen.');

        if ($membership->role === 'tenant_owner'
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1) {
            return back()->withErrors(['role' => __('Der letzte Inhaber kann nicht entfernt werden.')]);
        }

        $this->audit->log('user.removed', $membership, ['user_id' => $membership->user_id, 'role' => $membership->role]);
        $membership->delete();

        return back()->with('success', __('Benutzer entfernt.'));
    }

    public function deleteUser(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        abort_if($membership->user_id === $request->user()->id, 422);

        if ($membership->role === 'tenant_owner'
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1) {
            return back()->withErrors(['delete' => __('Der letzte Inhaber kann nicht gelöscht werden.')]);
        }

        $user = User::findOrFail($membership->user_id);
        $otherTenants = $user->tenants()->where('tenants.id', '!=', $tenant->id)->exists();

        if ($otherTenants) {
            $this->audit->log('user.removed', $membership, ['user_id' => $user->id]);
            $membership->delete();

            return back()->with('success', __('Benutzer aus diesem Betrieb entfernt (Konto bleibt bestehen – in anderen Betrieben aktiv).'));
        }

        $this->audit->log('user.deleted', $user, ['email' => $user->email, 'name' => $user->name]);
        $user->delete();

        return back()->with('success', __('Benutzerkonto vollständig gelöscht.'));
    }
}
