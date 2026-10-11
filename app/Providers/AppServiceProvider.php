<?php

namespace App\Providers;

use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        // SaaS super admins pass every gate; explicit policies still apply
        // their own tenant checks for everyone else.
        Gate::before(function (User $user, string $ability) {
            return $user->isSuperAdmin() ? true : null;
        });

        // Public booking endpoints: strict per-IP limits against spam.
        RateLimiter::for('booking', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perDay(50)->by($request->ip()),
            ];
        });

        RateLimiter::for('booking-slots', function (Request $request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        // API: per token (tenant-bound) with IP fallback.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by($request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip());
        });

        // „Mein Konto“ › Passwort ändern: je Konto, damit eine gestohlene Sitzung das
        // aktuelle Passwort auch über mehrere Adressen nur begrenzt oft probieren kann.
        // Die Grenze steht in swayy.auth.password_change_per_minute und wird bei jeder
        // Anfrage gelesen.
        RateLimiter::for('password-change', function (Request $request) {
            return Limit::perMinute((int) config('swayy.auth.password_change_per_minute'))
                ->by($request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip());
        });
    }
}
