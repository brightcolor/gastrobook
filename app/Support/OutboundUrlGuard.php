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
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = $parts['host'];

        // Reject URLs that embed credentials – not expected for webhooks and a
        // common confused-deputy trick.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            return false; // unresolvable host → refuse rather than let the HTTP client try
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
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
