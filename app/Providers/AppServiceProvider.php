<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Mail\MailDeliveryLog;
use App\Support\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mime\Header\IdentificationHeader;

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

        // Versandprotokoll: Der Mailserver hat die Mail angenommen. Zugeordnet
        // über die Message-ID, die MailDeliveryLog der Mail mitgegeben hat.
        Event::listen(MessageSent::class, function (MessageSent $event) {
            $header = $event->message->getHeaders()->get('Message-ID');
            if ($header instanceof IdentificationHeader) {
                app(MailDeliveryLog::class)->markSent($header->getId());
            }
        });

        // Zustellstatus auf der Buchungsseite: Die Seite fragt alle paar
        // Sekunden nach. Doppelt so viel Luft, wie der eingestellte Abstand braucht.
        RateLimiter::for('mail-status', function (Request $request) {
            $poll = max(1, (int) config('swayy.mail_delivery.poll_seconds', 3));

            return Limit::perMinute(max(30, (int) ceil(60 / $poll) * 2))->by($request->ip());
        });

        // Public booking endpoints: strict per-IP limits against spam.
        RateLimiter::for('booking', function (Request $request) {
            return [
                Limit::perMinute(10)->by($request->ip()),
                Limit::perDay(50)->by($request->ip()),
            ];
        });

        // Tisch beim Buchen halten: je Adresse und Minute (swayy.booking.hold_per_minute). So hält niemand im
        // Sekundentakt alle Tische eines Betriebs fest.
        RateLimiter::for('booking-hold', function (Request $request) {
            return Limit::perMinute((int) config('swayy.booking.hold_per_minute'))->by($request->ip());
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
