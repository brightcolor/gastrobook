<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Tenant;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request, AuditLogger $audit)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = strtolower($credentials['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('Zu viele Versuche. Bitte warten Sie :seconds Sekunden.', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }

        if (! Auth::attempt($credentials + ['is_active' => true], $request->boolean('remember'))) {
            RateLimiter::hit($throttleKey, 300);
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($throttleKey);
        $request->session()->regenerate();

        $user = $request->user();
        $audit->log('auth.login', $user, null, null, null, $user, $user->current_tenant_id);

        return redirect()->intended(
            $user->isSaasAdmin() && $user->current_tenant_id === null
                ? route('saas.dashboard')
                : route('admin.dashboard')
        );
    }

    public function logoutConfirm(Request $request)
    {
        if ($request->user() === null) {
            return redirect()->route('login');
        }

        return view('auth.logout', ['user' => $request->user()]);
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function switchTenant(Request $request)
    {
        $validated = $request->validate(['tenant_id' => ['required', 'integer']]);
        $user = $request->user();
        $tenant = Tenant::findOrFail($validated['tenant_id']);

        if (! $user->isSaasAdmin() && $user->membershipFor($tenant) === null) {
            abort(403);
        }

        $user->forceFill([
            'current_tenant_id' => $tenant->id,
            'current_location_id' => null,
        ])->save();

        return redirect()->route('admin.dashboard');
    }

    public function switchLocation(Request $request)
    {
        $validated = $request->validate(['location_id' => ['required', 'integer']]);
        $user = $request->user();

        $location = Location::withoutGlobalScope('tenant')->findOrFail($validated['location_id']);
        $tenant = Tenant::findOrFail($location->tenant_id);

        if (! $user->canAccessLocation($tenant, $location)) {
            abort(403);
        }

        $user->forceFill(['current_location_id' => $location->id])->save();

        return back();
    }
}
