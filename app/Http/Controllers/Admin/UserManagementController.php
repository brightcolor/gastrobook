<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Einstellungen › Betrieb › Team & Rollen: Mitglieder, Rollen,
 * Standortfreigaben und offene Einladungen.
 */
class UserManagementController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();
        $locations = $tenant->locations()->orderBy('name')->get();

        return view('admin.users.index', [
            'tenant' => $tenant,
            // Die zugeteilten Standorte gleich mitladen: Die Ansicht fragte sie
            // je Mitglied UND je Standort einzeln ab - bei elf Mitgliedern und
            // zehn Standorten waren das hundert zusaetzliche Abfragen fuer eine
            // einzige Seite.
            'memberships' => $tenant->memberships()->with('user.allowedLocations:id,name')->get(),
            'invitations' => Invitation::whereNull('accepted_at')->where('expires_at', '>', now())->orderBy('expires_at')->get(),
            'roleOptions' => $this->labels($this->assignableRoles($request, $tenant)),
            'allRoles' => array_keys((array) config('permissions.roles')),
            'locations' => $locations,
            'hasSeveralLocations' => $locations->count() > 1,
            'canManageRoles' => $request->user()->canInTenant('users.roles.manage', $tenant),
            'tz' => $this->context->location()?->timezone ?? config('swayy.locations.default_timezone'),
        ]);
    }

    public function invite(Request $request)
    {
        $tenant = $this->context->tenant();

        if (! $this->limits->canAdd($tenant, 'max_users')) {
            $limit = (int) $tenant->limit('max_users');

            return $this->failed($request, 'email', trans_choice(
                'Dein Tarif umfasst einen Zugang, und der ist vergeben.|Dein Tarif umfasst :count Zugänge, und alle sind vergeben.',
                $limit,
                ['count' => $limit],
            ).' '.($request->user()->canInTenant('billing.manage', $tenant)
                ? __('Für weitere Personen den Tarif unter „Abo & Rechnungen“ wechseln oder einen Zugang entfernen.')
                : __('Für weitere Personen bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.')));
        }

        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
            'role' => ['required', 'in:'.implode(',', $this->assignableRoles($request, $tenant))],
            'all_locations' => ['nullable', 'boolean'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
        ], [
            'email.required' => __('Bitte die E-Mail-Adresse der Person eintragen.'),
            'email.email' => __('Die E-Mail-Adresse ist unvollständig. Bitte in der Form name@beispiel.de eintragen.'),
            'role.required' => __('Bitte eine Rolle wählen.'),
            'role.in' => __('Diese Rolle kannst du nicht vergeben. Bitte eine Rolle aus der Liste wählen.'),
        ]);

        $allLocations = $request->boolean('all_locations', true);
        if (! $allLocations && empty($validated['location_ids'])) {
            return $this->failed($request, 'location_ids', __('Bitte mindestens einen Standort auswählen oder „Alle Standorte“ anhaken.'));
        }

        // Existing user → direct membership, otherwise invitation token
        $user = User::where('email', strtolower($validated['email']))->first();
        if ($user !== null) {
            // Niemand aendert ueber diese Route seine eigene Freigabe. Sonst
            // reicht users.invite, um sich selbst in einen bisher gesperrten
            // Standort einzutragen - jede andere Aenderung an einer
            // Mitgliedschaft liegt hinter users.roles.manage.
            abort_if($user->id === $request->user()->id, 403, __('Deine eigene Rolle und deine Standorte ändert eine andere Person mit Rechten für das Team. Bitte sie darum.'));

            $membership = TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $validated['role'], 'all_locations' => $allLocations]
            );

            // Nur fuer eine NEUE Mitgliedschaft. Eine bestehende zu erweitern
            // ist eine Aenderung an fremden Rechten und gehoert nicht zum
            // Einladen - firstOrCreate laesst die Rolle aus demselben Grund
            // unangetastet.
            if ($membership->wasRecentlyCreated && ! $allLocations) {
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

            if (! $membership->wasRecentlyCreated) {
                return $this->failed($request, 'email', __('Diese Person gehört schon zum Team. Rolle und Standorte änderst du direkt in der Liste.'));
            }

            $this->audit->log('user.added', $user, null, ['role' => $validated['role']]);

            return $this->saved($request, __(':name gehört jetzt zum Team.', ['name' => $user->name]), reload: true);
        }

        $invitation = Invitation::create([
            'tenant_id' => $tenant->id,
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'all_locations' => $allLocations,
            'location_ids' => $validated['location_ids'] ?? null,
            'invited_by' => $request->user()->id,
        ]);

        $this->audit->log('user.invited', $invitation, null, ['email' => $invitation->email, 'role' => $invitation->role]);

        return $this->saved($request, __('Einladung für :email angelegt. Den Link findest du unter „Offene Einladungen“ zum Kopieren.', [
            'email' => $invitation->email,
        ]), reload: true);
    }

    /**
     * Welche Rollen darf dieser Benutzer vergeben?
     *
     * Die Inhaberrolle schliesst Abrechnung und "Betrieb loeschen" ein. Ohne
     * diese Grenze koennte jemand mit users.invite (z. B. die Betriebsleitung,
     * die users.roles.manage ausdruecklich NICHT hat) sich ueber eine zweite
     * Mailadresse selbst zum Inhaber machen.
     *
     * @return array<int, string>
     */
    private function assignableRoles(Request $request, Tenant $tenant): array
    {
        $alle = array_keys(config('permissions.roles'));
        $user = $request->user();

        if ($user?->isSaasAdmin() || $user?->membershipFor($tenant)?->role === 'tenant_owner') {
            return $alle;
        }

        return array_values(array_diff($alle, ['tenant_owner']));
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<string, string> Rolle => Bezeichnung
     */
    private function labels(array $roles): array
    {
        return collect($roles)->mapWithKeys(fn (string $role) => [$role => __("roles.{$role}.label")])->all();
    }

    public function updateRole(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);

        $validated = $request->validate([
            'role' => ['required', 'in:'.implode(',', $this->assignableRoles($request, $tenant))],
        ], [
            'role.in' => __('Diese Rolle kannst du nicht vergeben. Bitte eine Rolle aus der Liste wählen.'),
        ]);

        // The last owner cannot be demoted
        if ($membership->role === 'tenant_owner' && $validated['role'] !== 'tenant_owner') {
            $owners = $tenant->memberships()->where('role', 'tenant_owner')->count();
            if ($owners <= 1) {
                return $this->failed($request, 'role', __('Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du diese Rolle ändern.'));
            }
        }

        $old = $membership->role;
        $membership->update(['role' => $validated['role']]);

        $this->audit->log('user.role_changed', $membership, ['role' => $old], ['role' => $validated['role']]);

        return $this->saved($request, __('Rolle von :name ist jetzt „:role“.', [
            'name' => $membership->user?->name,
            'role' => __("roles.{$validated['role']}.label"),
        ]));
    }

    /**
     * Standortfreigaben einer bestehenden Mitgliedschaft aendern.
     *
     * Frueher lief das ueber "Benutzer einladen" mit - und weil dort nur
     * `users.invite` verlangt wird, konnte sich damit jeder selbst einen
     * gesperrten Standort freischalten. Das Erweitern ist eine Aenderung an
     * fremden Rechten und gehoert darum hierher, hinter dasselbe Recht wie die
     * Rolle. Ohne diesen Weg gaebe es gar keinen mehr: Ein Mitglied, das
     * zusaetzlich an einem zweiten Standort arbeitet, muesste sonst entfernt
     * und neu eingeladen werden.
     */
    public function updateLocations(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        // Auch hier nicht die eigene Freigabe.
        abort_if($membership->user_id === $request->user()->id, 403, __('Deine eigene Rolle und deine Standorte ändert eine andere Person mit Rechten für das Team. Bitte sie darum.'));

        $validated = $request->validate([
            'all_locations' => ['nullable', 'boolean'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
        ]);

        $alle = $request->boolean('all_locations');
        $locationIds = $alle
            ? collect()
            : collect($validated['location_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $tenant->locations()->where('id', $id)->exists())
                ->values();

        // Eine Mitgliedschaft ohne "alle Standorte" und ohne einen einzigen
        // freigegebenen ist der Zustand, in dem gar kein Standort aufgeloest
        // werden kann - genau daran fiel frueher die Standortschranke auf.
        if (! $alle && $locationIds->isEmpty()) {
            return $this->failed($request, 'location_ids', __('Bitte mindestens einen Standort auswählen oder „Alle Standorte“ anhaken.'));
        }

        $vorher = [
            'all_locations' => (bool) $membership->all_locations,
            'location_ids' => DB::table('location_user')
                ->where('user_id', $membership->user_id)
                ->where('tenant_id', $tenant->id)
                ->pluck('location_id')->all(),
        ];

        DB::transaction(function () use ($membership, $tenant, $alle, $locationIds) {
            $membership->update(['all_locations' => $alle]);

            DB::table('location_user')
                ->where('user_id', $membership->user_id)
                ->where('tenant_id', $tenant->id)
                ->delete();

            foreach ($locationIds as $locationId) {
                DB::table('location_user')->insert([
                    'location_id' => $locationId,
                    'user_id' => $membership->user_id,
                    'tenant_id' => $tenant->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->audit->log('user.locations_changed', $membership, $vorher, [
            'all_locations' => $alle,
            'location_ids' => $locationIds->all(),
        ]);

        return $this->saved($request, __('Standorte von :name gespeichert.', ['name' => $membership->user?->name]), reload: true);
    }

    public function remove(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        abort_if($membership->user_id === $request->user()->id, 422, __('Du kannst dich nicht selbst entfernen. Bitte eine andere Person mit Rechten für das Team fragen.'));

        if ($membership->role === 'tenant_owner'
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1) {
            return $this->failed($request, 'role', __('Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du diese Person entfernen.'));
        }

        $name = $membership->user?->name;
        $this->audit->log('user.removed', $membership, ['user_id' => $membership->user_id, 'role' => $membership->role]);
        $membership->delete();

        return $this->saved($request, __(':name ist aus dem Team entfernt.', ['name' => $name]), reload: true);
    }

    public function deleteUser(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        abort_if($membership->user_id === $request->user()->id, 422, __('Dein eigenes Konto löschst du unter „Mein Konto“.'));

        if ($membership->role === 'tenant_owner'
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1) {
            return $this->failed($request, 'delete', __('Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du dieses Konto löschen.'));
        }

        $user = User::findOrFail($membership->user_id);
        $otherTenants = $user->tenants()->where('tenants.id', '!=', $tenant->id)->exists();

        if ($otherTenants) {
            $this->audit->log('user.removed', $membership, ['user_id' => $user->id]);
            $membership->delete();

            return $this->saved($request, __(':name ist aus dem Team entfernt. Das Konto bleibt, weil die Person zu einem weiteren Betrieb gehört.', ['name' => $user->name]), reload: true);
        }

        $this->audit->log('user.deleted', $user, ['email' => $user->email, 'name' => $user->name]);
        $user->delete();

        return $this->saved($request, __('Das Konto von :name ist gelöscht.', ['name' => $user->name]), reload: true);
    }
}
