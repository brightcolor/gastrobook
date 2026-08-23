<?php

declare(strict_types=1);

namespace App\Support;

/**
 * SSRF guard for user-configured outbound URLs (e.g. webhook endpoints).
 *
 * Tenant admins can register arbitrary URLs the server later calls. Without a
 * guard, a URL like https://169.254.169.254/ or https://localhost/ would let
 * the server reach cloud metadata services or internal admin panels. This
 * rejects any URL whose host resolves to a private, loopback, link-local or
 * otherwise reserved IP. Resolving at call time (not just at save time) also
 * mitigates DNS-rebinding.
 */
class OutboundUrlGuard
{
    /**
     * True when the URL is https, has a resolvable host, and every resolved IP
     * is a public address.
     */
    public static function isAllowed(string $url): bool
    {
        return self::publicIpsFor($url) !== null;
    }

    /**
     * Die geprueften Adressen - oder null, wenn die URL nicht erlaubt ist.
     *
     * Der Aufrufer soll die Anfrage auf GENAU diese Adressen festnageln. Sonst
     * loest der HTTP-Client den Namen ein zweites Mal auf, und zwischen beiden
     * Aufloesungen kann eine Angreiferdomain mit kurzer Lebensdauer auf eine
     * interne Adresse umschwenken (DNS-Rebinding). Die Pruefung hier waere dann
     * nur noch Zierde.
     *
     * @return list<string>|null
     */
    public static function publicIpsFor(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return null;
        }

        $host = $parts['host'];

        // Reject URLs that embed credentials – not expected for webhooks and a
        // common confused-deputy trick.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            return null; // unresolvable host → refuse rather than let the HTTP client try
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return null;
            }
        }

        return $ips;
    }

    /**
     * Der curl-Ausdruck, der Namen auf die geprueften Adressen festnagelt.
     *
     * Leer, wenn im Host schon eine IP steht - dann gibt es nichts aufzuloesen.
     *
     * @param  list<string>  $ips
     * @return list<string>
     */
    public static function resolveOption(string $url, array $ips): array
    {
        $parts = parse_url($url);
        $host = $parts['host'] ?? '';

        if ($host === '' || $ips === [] || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return [$host.':'.($parts['port'] ?? 443).':'.implode(',', $ips)];
    }

    /**
     * @return list<string>
     */
    private static function resolve(string $host): array
    {
        // Host is already a literal IP.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = $v4;
        }

        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
