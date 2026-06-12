<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
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
                foreach ($invitation->location_ids ?? [] as $locationId) {
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
}
