<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\GuestLinkMail;
use App\Models\Guest;
use App\Models\GuestAuthToken;
use App\Models\Reservation;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class GuestAuthService
{
    /**
     * Create a single-use token for a guest and return the plaintext value.
     */
    public function issue(Guest $guest, string $purpose, ?int $reservationId = null, int $ttlMinutes = 60): string
    {
        $token = Str::random(48);

        GuestAuthToken::create([
            'tenant_id' => $guest->tenant_id,
            'guest_id' => $guest->id,
            'reservation_id' => $reservationId,
            'token' => $token,
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes($ttlMinutes),
        ]);

        return $token;
    }

    /**
     * Validate and burn a token. Returns the row (with guest) or null.
     */
    public function consume(string $token, string $purpose): ?GuestAuthToken
    {
        $row = GuestAuthToken::withoutGlobalScopes()
            ->where('token', $token)
            ->where('purpose', $purpose)
            ->first();

        if ($row === null || ! $row->isUsable()) {
            return null;
        }

        $row->update(['used_at' => now()]);

        return $row;
    }

    /**
     * Send a passwordless login link for the portal. Neutral by design:
     * does nothing observable when no matching guest exists (no enumeration).
     */
    public function sendMagicLink(Tenant $tenant, string $email): void
    {
        $guest = Guest::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('anonymized', false)
            ->whereRaw('lower(email) = ?', [strtolower(trim($email))])
            ->first();

        if ($guest === null || ! $guest->email) {
            return;
        }

        $token = $this->issue($guest, 'login', null, 60);
        $url = route('guest.portal.login', ['tenantSlug' => $tenant->slug, 'token' => $token]);

        Mail::to($guest->email)->queue(new GuestLinkMail(
            __('Ihr Anmeldelink für :name', ['name' => $tenant->name]),
            __('Hier ist Ihr Anmeldelink für Ihr Kundenkonto bei :name.', ['name' => $tenant->name]),
            $url,
            __('Jetzt anmelden'),
        ));
    }

    /**
     * Send an email-confirmation link tied to a specific reservation.
     */
    public function sendVerification(Guest $guest, Reservation $reservation): void
    {
        if (! $guest->email) {
            return;
        }

        $token = $this->issue($guest, 'verify', $reservation->id, 1440);
        $url = route('guest.verify', ['token' => $token]);

        Mail::to($guest->email)->queue(new GuestLinkMail(
            __('Bitte bestätigen Sie Ihre E-Mail-Adresse'),
            __('Bitte bestätigen Sie Ihre E-Mail-Adresse, um Ihre Buchung :code abzuschließen.', ['code' => $reservation->code]),
            $url,
            __('E-Mail bestätigen'),
        ));
    }
}
