<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\GuestLinkMail;
use App\Mail\Layout\MailFrameFactory;
use App\Models\Guest;
use App\Models\GuestAuthToken;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Support\Duration;
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

        $minutes = (int) config('swayy.guest_auth.login_minutes', 60);
        $token = $this->issue($guest, 'login', null, $minutes);
        $url = route('guest.portal.login', ['tenantSlug' => $tenant->slug, 'token' => $token]);

        // Portal login is tenant-wide → use the tenant's first location's tone.
        $location = Location::where('tenant_id', $tenant->id)->first();
        $du = $location?->effectiveSettings()->du() ?? false;
        $sender = app(GuestMailSender::class);

        // Send synchronously (like the password-reset mail): a magic link is
        // useless if it sits in a stuck queue when Redis/worker is down.
        $name = $guest->first_name ?: $guest->last_name;
        Mail::to($guest->email)->send(new GuestLinkMail(
            $du ? __('Dein Anmeldelink für :name', ['name' => $tenant->name])
                : __('Ihr Anmeldelink für :name', ['name' => $tenant->name]),
            $du ? __("Hallo :guest,\n\nhier ist dein Anmeldelink für dein Kundenkonto bei :name.", ['guest' => $name, 'name' => $tenant->name])
                : __("Hallo :guest,\n\nhier ist Ihr Anmeldelink für Ihr Kundenkonto bei :name.", ['guest' => $name, 'name' => $tenant->name]),
            $url,
            __('Jetzt anmelden'),
            $sender->fromName($tenant, $location),
            $sender->replyTo($tenant, $location),
            app(MailFrameFactory::class)->forGuest('guest_login', $tenant, $location),
            $du ? __('Der Link gilt :dauer. Hast du ihn nicht angefordert, kannst du diese Mail löschen.', ['dauer' => Duration::minutes($minutes)])
                : __('Der Link gilt :dauer. Haben Sie ihn nicht angefordert, können Sie diese Mail löschen.', ['dauer' => Duration::minutes($minutes)]),
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

        $minutes = (int) config('swayy.guest_auth.verify_minutes', 1440);
        $token = $this->issue($guest, 'verify', $reservation->id, $minutes);
        $url = route('guest.verify', ['token' => $token]);

        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $tenant = $location?->tenant()->withoutGlobalScopes()->first();
        $du = $location?->effectiveSettings()->du() ?? false;
        $sender = app(GuestMailSender::class);

        // Synchronous: the guest is waiting on this link to finish booking.
        $name = $guest->first_name ?: $guest->last_name;
        Mail::to($guest->email)->send(new GuestLinkMail(
            $du ? __('Bitte bestätige deine E-Mail-Adresse')
                : __('Bitte bestätigen Sie Ihre E-Mail-Adresse'),
            $du ? __("Hallo :guest,\n\nein Klick noch, dann ist deine Buchung :code bei uns.", ['guest' => $name, 'code' => $reservation->code])
                : __("Hallo :guest,\n\nein Klick noch, dann ist Ihre Buchung :code bei uns.", ['guest' => $name, 'code' => $reservation->code]),
            $url,
            __('E-Mail-Adresse bestätigen'),
            $sender->fromName($tenant, $location),
            $sender->replyTo($tenant, $location),
            app(MailFrameFactory::class)->forGuest('guest_verify', $tenant, $location),
            $du ? __('Der Link gilt :dauer. Hast du nicht bei uns reserviert, kannst du diese Mail löschen.', ['dauer' => Duration::minutes($minutes)])
                : __('Der Link gilt :dauer. Haben Sie nicht bei uns reserviert, können Sie diese Mail löschen.', ['dauer' => Duration::minutes($minutes)]),
        ));
    }
}
