<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Captcha\CapVerifier;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cap CAPTCHA guard for the public forms.
 *
 * Applied per route with the form name from config/cap.php, e.g.
 * ->middleware('cap:booking'). The matching page renders the widget with
 * <x-cap-widget form="booking" />; both must name the same form, otherwise
 * visitors get a widget nobody checks, or a check with no widget to solve.
 *
 * Throwing ValidationException keeps both callers working: a browser form
 * goes back to the page with the message, a fetch request gets 422 JSON.
 */
class VerifyCapToken
{
    public function __construct(private readonly CapVerifier $cap) {}

    public function handle(Request $request, Closure $next, string $form): Response
    {
        if (! $this->cap->protects($form)) {
            return $next($request);
        }

        $message = $this->cap->verify((string) $request->input(CapVerifier::TOKEN_FIELD, ''));

        if ($message !== null) {
            throw ValidationException::withMessages([CapVerifier::TOKEN_FIELD => $message]);
        }

        return $next($request);
    }
}
