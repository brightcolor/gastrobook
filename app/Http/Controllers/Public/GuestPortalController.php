<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\GuestAuthService;
use App\Services\ReservationLifecycleService;
use Illuminate\Http\Request;

class GuestPortalController extends Controller
{
    public function __construct(private readonly GuestAuthService $auth) {}

    public function request(string $tenantSlug)
    {
        return view('public.portal.request', ['tenant' => $this->tenant($tenantSlug)]);
    }

    public function sendLink(Request $request, string $tenantSlug)
    {
        $tenant = $this->tenant($tenantSlug);
        $request->validate(['email' => ['required', 'email:rfc']]);

        if (! $request->filled('website')) { // honeypot
            $this->auth->sendMagicLink($tenant, $request->input('email'));
        }

        // Neutral response — never reveal whether the email exists
        return view('public.portal.sent', ['tenant' => $tenant]);
    }

    public function login(string $tenantSlug, string $token)
    {
        $tenant = $this->tenant($tenantSlug);
        $row = $this->auth->consume($token, 'login');

        if ($row === null || $row->tenant_id !== $tenant->id) {
            return view('public.portal.link-invalid', ['tenant' => $tenant]);
        }

        $guest = $row->guest;
        if ($guest->email_verified_at === null) {
            $guest->update(['email_verified_at' => now()]);
        }

        session(['guest_portal' => ['guest_id' => $guest->id, 'tenant_id' => $tenant->id]]);

        return redirect()->route('guest.portal.dashboard', $tenant->slug);
    }

    public function dashboard(string $tenantSlug)
    {
        $tenant = $this->tenant($tenantSlug);
        $guest = $this->authedGuest($tenant);
        if ($guest === null) {
            return redirect()->route('guest.portal.request', $tenant->slug);
        }

        $reservations = Reservation::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('guest_id', $guest->id)
            ->with(['services:id,name', 'staffMember:id,name'])
            ->orderByDesc('start_at')
            ->limit(50)
            ->get();

        return view('public.portal.dashboard', [
            'tenant' => $tenant,
            'guest' => $guest,
            'reservations' => $reservations,
        ]);
    }

    public function logout(string $tenantSlug)
    {
        session()->forget('guest_portal');

        return redirect()->route('guest.portal.request', $tenantSlug);
    }

    /**
     * Email confirmation link (also verifies the address). Confirms a held
     * reservation when the location auto-confirms.
     */
    public function verify(string $token)
    {
        $row = $this->auth->consume($token, 'verify');
        if ($row === null) {
            return view('public.portal.link-invalid', ['tenant' => null]);
        }

        $guest = $row->guest;
        if ($guest->email_verified_at === null) {
            $guest->update(['email_verified_at' => now()]);
        }

        $reservation = $row->reservation_id
            ? Reservation::withoutGlobalScope('tenant')->find($row->reservation_id)
            : null;

        if ($reservation !== null && $reservation->status === ReservationStatus::Requested) {
            $settings = $reservation->location()->withoutGlobalScope('tenant')->first()?->effectiveSettings();
            if ($settings && $settings->auto_confirm && ! $settings->request_only) {
                app(ReservationLifecycleService::class)->transition(
                    $reservation, ReservationStatus::Confirmed, null, 'guest', 'email_confirmed'
                );
                $reservation->refresh();
            }
        }

        return view('public.portal.verified', ['reservation' => $reservation]);
    }

    private function authedGuest(Tenant $tenant): ?Guest
    {
        $session = session('guest_portal');
        if (! is_array($session) || ($session['tenant_id'] ?? null) !== $tenant->id) {
            return null;
        }

        return Guest::withoutGlobalScopes()
            ->where('id', $session['guest_id'] ?? 0)
            ->where('tenant_id', $tenant->id)
            ->first();
    }

    private function tenant(string $slug): Tenant
    {
        return Tenant::where('slug', $slug)->where('status', 'active')->firstOrFail();
    }
}
