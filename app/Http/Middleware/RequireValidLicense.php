<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\LicenseService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks access to the admin area when a self-hosted license is invalid
 * (missing, tampered, expired beyond grace period, or revoked).
 *
 * The public-facing booking pages are intentionally NOT protected so that
 * existing reservations stay accessible while the operator renews.
 *
 * On the hosted SaaS (SWAYY_SELF_HOSTED not set) this middleware is a no-op.
 */
class RequireValidLicense
{
    public function __construct(private readonly LicenseService $license) {}

    public function handle(Request $request, Closure $next): Response
    {
        $status = $this->license->check();

        if ($status->isHardLocked()) {
            return response()->view('errors.license_expired', [
                'status' => $status,
            ], 402);
        }

        // Pass the status to views so the banner can be rendered.
        view()->share('licenseStatus', $status);

        return $next($request);
    }
}
