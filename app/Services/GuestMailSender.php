<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\TemplatedMail;
use App\Models\Location;
use App\Models\Tenant;

/**
 * Absendername und Antwortadresse fuer Mails eines Betriebs.
 *
 * Der Absender bleibt MAIL_FROM_ADDRESS (SPF/DKIM). Antwortet ein Gast,
 * landet die Mail ueber Reply-To beim Betrieb. Eigene Angaben des Betriebs
 * gehen vor, danach die Ersatzlisten aus config/swayy.php (guest_mail).
 */
class GuestMailSender
{
    public function fromName(?Tenant $tenant, ?Location $location): ?string
    {
        $eigener = trim((string) $tenant?->mail_from_name);
        if ($eigener !== '') {
            return $eigener;
        }

        foreach ((array) config('swayy.guest_mail.from_name_fallbacks', []) as $quelle) {
            $wert = match ($quelle) {
                'location_name' => $location?->name,
                'tenant_name' => $tenant?->name,
                default => null,
            };
            if (is_string($wert) && trim($wert) !== '') {
                return trim($wert);
            }
        }

        return null;
    }

    public function replyTo(?Tenant $tenant, ?Location $location): ?string
    {
        $eigene = trim((string) $tenant?->mail_reply_to);
        if ($eigene !== '') {
            return $eigene;
        }

        foreach ((array) config('swayy.guest_mail.reply_to_fallbacks', []) as $quelle) {
            $wert = match ($quelle) {
                'location_email' => $location?->email,
                'owner_notification_email' => $location?->effectiveSettings()->owner_notification_email,
                default => null,
            };
            if (is_string($wert) && filter_var(trim($wert), FILTER_VALIDATE_EMAIL)) {
                return trim($wert);
            }
        }

        return null;
    }

    /**
     * Mail an einen Gast: Name des Betriebs, Antworten an den Betrieb.
     */
    public function toGuest(string $subject, string $body, ?Tenant $tenant, ?Location $location): TemplatedMail
    {
        return new TemplatedMail(
            $subject,
            $body,
            $this->fromName($tenant, $location),
            $this->replyTo($tenant, $location),
        );
    }

    /**
     * Mail an den Betrieb. Mit $guestEmail gehen Antworten direkt an den
     * Gast, sofern die Einstellung owner_reply_to_guest das erlaubt.
     */
    public function toOperator(string $subject, string $body, ?Tenant $tenant, ?Location $location, ?string $guestEmail = null): TemplatedMail
    {
        $antwort = $guestEmail !== null
            && config('swayy.guest_mail.owner_reply_to_guest', true)
            && filter_var($guestEmail, FILTER_VALIDATE_EMAIL)
                ? $guestEmail
                : null;

        return new TemplatedMail($subject, $body, $this->fromName($tenant, $location), $antwort);
    }
}
