<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TenantSignupService;
use App\Support\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RegistrationController extends Controller
{
    public function __construct(private readonly TenantSignupService $signup) {}

    public function show()
    {
        // Neue Betriebe bestellen im Kundenpanel, sobald seine Bestellseite eingetragen ist.
        $bestellung = (string) config('provisioning.panel_order_url');
        if ($bestellung !== '') {
            return redirect()->away($bestellung);
        }

        return view('auth.register');
    }

    public function store(Request $request)
    {
        $bestellung = (string) config('provisioning.panel_order_url');
        if ($bestellung !== '') {
            return redirect()->away($bestellung);
        }

        if ($request->filled('website')) {
            abort(422); // honeypot
        }

        $validated = $request->validate([
            'restaurant_name' => ['required', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', PasswordPolicy::rule()],
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
