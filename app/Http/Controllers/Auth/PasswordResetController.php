<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends Controller
{
    public function showForgot()
    {
        return view('auth.forgot-password');
    }

    public function sendLink(Request $request)
    {
        $request->validate(['email' => ['required', 'email']]);

        $throttleKey = 'pw-reset|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 3)) {
            throw ValidationException::withMessages([
                'email' => __('Zu viele Versuche. Bitte warten Sie :seconds Sekunden.', [
                    'seconds' => RateLimiter::availableIn($throttleKey),
                ]),
            ]);
        }
        RateLimiter::hit($throttleKey, 300);

        // Always show success message to prevent user enumeration
        Password::broker()->sendResetLink(
            ['email' => $request->input('email')],
            function ($user, $token) {
                $url = route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()]);
                Mail::to($user->getEmailForPasswordReset())->queue(new PasswordResetMail($url));
            }
        );

        return back()->with('status', __('Falls ein Konto mit dieser E-Mail-Adresse existiert, haben wir einen Reset-Link gesendet.'));
    }

    public function showReset(Request $request, string $token)
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::min(8)->letters()->numbers()],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __('Ihr Passwort wurde erfolgreich zurückgesetzt. Sie können sich jetzt anmelden.'));
        }

        throw ValidationException::withMessages([
            'email' => __('Dieser Link ist ungültig oder abgelaufen. Bitte fordern Sie einen neuen an.'),
        ]);
    }
}
