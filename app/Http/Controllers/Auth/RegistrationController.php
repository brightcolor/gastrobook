<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TenantSignupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;

class RegistrationController extends Controller
{
    public function __construct(private readonly TenantSignupService $signup) {}

    public function show()
    {
        return view('auth.register');
    }

    public function store(Request $request)
    {
        if ($request->filled('website')) {
            abort(422); // honeypot
        }

        $validated = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(10)],
            'privacy_accepted' => ['accepted'],
        ], [
            'email.unique' => __('Diese E-Mail-Adresse ist bereits registriert. Bitte melden Sie sich an.'),
        ]);

        [, $user] = $this->signup->signup([
            'restaurant_name' => $validated['restaurant_name'],
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('admin.onboarding.show');
    }
}
