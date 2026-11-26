<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Location;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\PlanLimitService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InvitationController extends Controller
{
    public function __construct(private readonly PlanLimitService $limits) {}

    public function show(string $token)
    {
        $invitation = Invitation::withoutGlobalScope('tenant')
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        return view('auth.invitation', ['invitation' => $invitation]);
    }

    public function accept(Request $request, string $token)
    {
        $invitation = Invitation::withoutGlobalScope('tenant')
            ->where('token', $token)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', PasswordPolicy::rule(), 'confirmed'],
        ]);

        // Wichtig: Existiert zu dieser Adresse bereits ein Konto, darf dieser
        // Link NICHT anmelden. Das eingegebene Passwort gehoert dann nicht zum
        // Konto, und wer den Link in die Finger bekommt (er steht dem Einladenden
        // im Klartext in der Benutzerliste), waere sonst als fremde Person
        // angemeldet. Stattdessen: Mitgliedschaft anlegen, regulaer anmelden lassen.
        $tenant = Tenant::find($invitation->tenant_id);
        abort_if($tenant === null, 404);

        $existing = User::where('email', $invitation->email)->first();
        if ($existing !== null) {
            if (! $this->attachMembership($invitation, $existing, $tenant)) {
                return $this->limitReached();
            }

            return redirect()->route('login')->with('success', __(
                'Du wurdest zum Betrieb hinzugefügt. Bitte melde dich mit deinem bestehenden Passwort an.'
            ));
        }

        // Das Konto entsteht nur zusammen mit einem freien Zugang des Tarifs.
        // Offene Einladungen zaehlen nicht gegen die Grenze; erst das Annehmen
        // belegt einen Zugang, unter Sperre, damit zwei gleichzeitig
        // angenommene Einladungen nicht beide den letzten bekommen.
        $joined = $this->limits->createWithinLimit($tenant, 'max_users', function () use ($invitation, $validated) {
            $user = User::firstOrCreate(
                ['email' => $invitation->email],
                ['name' => $validated['name'], 'password' => Hash::make($validated['password'])]
            );

            TenantUser::firstOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'all_locations' => $invitation->all_locations]
            );

            if (! $invitation->all_locations) {
                // Nur Standorte des einladenden Betriebs – die IDs stammen aus
                // dem Einladungsformular und sind dort ungeprueft gespeichert.
                $erlaubt = Location::withoutGlobalScope('tenant')
                    ->where('tenant_id', $invitation->tenant_id)
                    ->whereIn('id', $invitation->location_ids ?? [])
                    ->pluck('id');

                foreach ($erlaubt as $locationId) {
                    DB::table('location_user')->insertOrIgnore([
                        'location_id' => $locationId,
                        'user_id' => $user->id,
                        'tenant_id' => $invitation->tenant_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $invitation->update(['accepted_at' => now()]);
            $user->forceFill(['current_tenant_id' => $invitation->tenant_id])->save();

            Auth::login($user);

            return true;
        });

        if ($joined === null) {
            return $this->limitReached();
        }

        return redirect()->route('admin.dashboard');
    }

    /**
     * Antwort, wenn im Team kein Zugang mehr frei ist. Die Einladung bleibt
     * offen und lässt sich annehmen, sobald wieder ein Zugang frei ist.
     */
    private function limitReached()
    {
        return back()->withInput(request()->except('password', 'password_confirmation'))->withErrors(['name' => __(
            'Im Team sind alle Zugänge des Tarifs vergeben. Bitte die Person, die dich eingeladen hat, einen Zugang freizugeben oder den Tarif zu wechseln. Danach funktioniert dieser Link wieder.'
        )]);
    }

    /**
     * Mitgliedschaft (und ggf. Standortfreigaben) fuer ein bereits bestehendes
     * Konto anlegen, ohne es anzumelden.
     *
     * @return bool false, wenn der Tarif keinen weiteren Zugang zulaesst
     */
    private function attachMembership(Invitation $invitation, User $user, Tenant $tenant): bool
    {
        $attach = function () use ($invitation, $user) {
            TenantUser::firstOrCreate(
                ['tenant_id' => $invitation->tenant_id, 'user_id' => $user->id],
                ['role' => $invitation->role, 'all_locations' => $invitation->all_locations]
            );

            if (! $invitation->all_locations) {
                $erlaubt = Location::withoutGlobalScope('tenant')
                    ->where('tenant_id', $invitation->tenant_id)
                    ->whereIn('id', $invitation->location_ids ?? [])
                    ->pluck('id');

                foreach ($erlaubt as $locationId) {
                    DB::table('location_user')->insertOrIgnore([
                        'location_id' => $locationId,
                        'user_id' => $user->id,
                        'tenant_id' => $invitation->tenant_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            $invitation->update(['accepted_at' => now()]);

            return true;
        };

        // Wer schon zum Team gehoert, belegt keinen weiteren Zugang.
        if (TenantUser::where('tenant_id', $tenant->id)->where('user_id', $user->id)->exists()) {
            return DB::transaction($attach);
        }

        return $this->limits->createWithinLimit($tenant, 'max_users', $attach) ?? false;
    }
}
