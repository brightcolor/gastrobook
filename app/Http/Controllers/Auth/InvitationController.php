<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Location;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InvitationController extends Controller
{
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
            'password' => ['required', 'string', 'min:10', 'confirmed'],
        ]);

        // Wichtig: Existiert zu dieser Adresse bereits ein Konto, darf dieser
        // Link NICHT anmelden. Das eingegebene Passwort gehoert dann nicht zum
        // Konto, und wer den Link in die Finger bekommt (er steht dem Einladenden
        // im Klartext in der Benutzerliste), waere sonst als fremde Person
        // angemeldet. Stattdessen: Mitgliedschaft anlegen, regulaer anmelden lassen.
        $existing = User::where('email', $invitation->email)->first();
        if ($existing !== null) {
            $this->attachMembership($invitation, $existing);

            return redirect()->route('login')->with('success', __(
                'Du wurdest zum Betrieb hinzugefügt. Bitte melde dich mit deinem bestehenden Passwort an.'
            ));
        }

        DB::transaction(function () use ($invitation, $validated) {
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
        });

        return redirect()->route('admin.dashboard');
    }

    /**
     * Mitgliedschaft (und ggf. Standortfreigaben) fuer ein bereits bestehendes
     * Konto anlegen, ohne es anzumelden.
     */
    private function attachMembership(Invitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user) {
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
        });
    }
}
