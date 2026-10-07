<?php

declare(strict_types=1);

namespace App\Services\Mail;

use App\Models\Reservation;

/**
 * Baut und prüft die Swayy-Antwortadresse einer Buchung:
 * <slug>+<code>.<hmac>@<domain>. Der HMAC aus dem App-Schlüssel sorgt dafür,
 * dass sich einer Buchung keine fremde Mail über eine erratene Adresse
 * unterschieben lässt. Zustandslos, ohne eigene Spalte.
 */
final class GuestReplyAddress
{
    public static function isConfigured(): bool
    {
        return trim((string) config('swayy.guest_mail_relay.domain')) !== '';
    }

    public static function hmac(int $tenantId, string $code): string
    {
        $len = (int) config('swayy.guest_mail_relay.hmac_length', 8);
        $raw = hash_hmac('sha256', $tenantId.':'.$code, (string) config('app.key'), true);

        return substr(bin2hex($raw), 0, $len);
    }

    public static function forReservation(Reservation $reservation): string
    {
        $sepTag = (string) config('swayy.guest_mail_relay.separator_tag', '+');
        $sepHmac = (string) config('swayy.guest_mail_relay.separator_hmac', '.');
        $domain = (string) config('swayy.guest_mail_relay.domain');

        return (string) $reservation->tenant->slug
            .$sepTag.$reservation->code
            .$sepHmac.self::hmac((int) $reservation->tenant_id, (string) $reservation->code)
            .'@'.$domain;
    }

    /**
     * Zerlegt die Empfängeradresse. null, wenn die Struktur nicht passt.
     *
     * @return array{slug:string,code:string,hmac:string}|null
     */
    public static function parse(string $recipient): ?array
    {
        $sepTag = (string) config('swayy.guest_mail_relay.separator_tag', '+');
        $sepHmac = (string) config('swayy.guest_mail_relay.separator_hmac', '.');

        $local = strstr($recipient, '@', true);
        if ($local === false || ! str_contains($local, $sepTag)) {
            return null;
        }

        [$slug, $tag] = explode($sepTag, $local, 2);
        $pos = strrpos($tag, $sepHmac);
        if ($slug === '' || $pos === false || $pos === 0) {
            return null;
        }

        return [
            'slug' => $slug,
            'code' => substr($tag, 0, $pos),
            'hmac' => substr($tag, $pos + strlen($sepHmac)),
        ];
    }
}
