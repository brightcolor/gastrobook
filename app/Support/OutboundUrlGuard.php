<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * SSRF guard for user-configured outbound URLs (webhook endpoints, the
 * MailWizz API address).
 *
 * Tenant admins can register arbitrary URLs the server later calls. Without a
 * guard, a URL pointing at a loopback, private or link-local address would let
 * the server reach cloud metadata services or internal admin panels. This
 * rejects any URL whose host resolves to an address outside the globally
 * routed range. Resolving at call time (not just at save time) also
 * mitigates DNS-rebinding; client() pins the request to the checked addresses.
 *
 * Operators of a self-hosted installation can open specific internal networks
 * via swayy.outbound.allowed_networks (SWAYY_OUTBOUND_ALLOWED_NETWORKS).
 */
class OutboundUrlGuard
{
    /**
     * Ersatz fuer die Namensaufloesung, nur fuer Tests.
     *
     * @var (Closure(string): list<string>)|null
     */
    private static ?Closure $resolver = null;

    /**
     * True when the URL is https, has a resolvable host, and every resolved IP
     * is allowed.
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
        try {
            return self::check($url);
        } catch (OutboundUrlBlocked) {
            return null;
        }
    }

    /**
     * Wie publicIpsFor(), nur mit Grund: Die Ausnahme sagt, warum die Adresse
     * abgelehnt wurde, und traegt eine Meldung fuer die Person, die sie
     * eingetragen hat.
     *
     * @return list<string>
     *
     * @throws OutboundUrlBlocked
     */
    public static function check(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? self::hostOf($parts) : '';

        // Nur Zeichen, die in einem DNS-Namen oder einer IP vorkommen. PHP und
        // der HTTP-Client sollen denselben Host sehen; ein Prozentzeichen etwa
        // dekodiert curl je nach Version selbst - dann loest die Pruefung einen
        // anderen Namen auf, als die Anfrage spaeter ansteuert.
        if ($host === '' || ! self::isWellFormedHost($host)) {
            throw OutboundUrlBlocked::because(OutboundUrlBlocked::INVALID);
        }

        if (($parts['scheme'] ?? null) !== 'https') {
            throw OutboundUrlBlocked::because(OutboundUrlBlocked::SCHEME);
        }

        // Reject URLs that embed credentials – not expected for these targets
        // and a common confused-deputy trick.
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw OutboundUrlBlocked::because(OutboundUrlBlocked::CREDENTIALS);
        }

        $ips = self::resolve($host);
        if ($ips === []) {
            // unresolvable host → refuse rather than let the HTTP client try
            throw OutboundUrlBlocked::because(OutboundUrlBlocked::UNRESOLVABLE);
        }

        foreach ($ips as $ip) {
            if (! self::isAllowedIp($ip)) {
                throw OutboundUrlBlocked::because(OutboundUrlBlocked::INTERNAL);
            }
        }

        return $ips;
    }

    /**
     * HTTP-Client fuer genau diese Adresse: geprueft, auf die geprueften
     * Adressen festgenagelt und ohne Weiterleitungen.
     *
     * Eine Weiterleitung fuehrte sonst zu einem Ziel, das niemand geprueft hat.
     * Gedacht fuer einen einzelnen Aufruf: Jede weitere Anfrage holt sich einen
     * eigenen Client und wird damit erneut geprueft.
     *
     * @throws OutboundUrlBlocked
     */
    public static function client(string $url): PendingRequest
    {
        $pin = self::resolveOption($url, self::check($url));

        return Http::withoutRedirecting()
            ->when($pin !== [], fn (PendingRequest $client) => $client->withOptions(['curl' => [CURLOPT_RESOLVE => $pin]]));
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
        $host = is_array($parts) ? self::hostOf($parts) : '';

        if ($host === '' || $ips === [] || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [];
        }

        return [$host.':'.($parts['port'] ?? 443).':'.implode(',', $ips)];
    }

    /**
     * Namensaufloesung fuer Tests ersetzen; null stellt die echte wieder her.
     *
     * @param  (callable(string): list<string>)|null  $resolver
     */
    public static function resolveUsing(?callable $resolver): void
    {
        self::$resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /**
     * Host ohne die eckigen Klammern einer IPv6-Adresse.
     *
     * @param  array<string, int|string>  $parts
     */
    private static function hostOf(array $parts): string
    {
        $host = (string) ($parts['host'] ?? '');

        if (str_starts_with($host, '[') && str_ends_with($host, ']')
            && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * Eine IP oder Labels aus Buchstaben, Ziffern, Binde- und Unterstrich,
     * getrennt durch einzelne Punkte. Ohne Punkt am Ende: Den behandeln
     * Pruefung und curl nicht zwingend gleich, und der Festnagel-Ausdruck
     * muss genau den Namen treffen, den curl aufloest.
     */
    private static function isWellFormedHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || preg_match('/\A[a-z0-9_-]+(?:\.[a-z0-9_-]+)*\z/i', $host) === 1;
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

        if (self::$resolver !== null) {
            $ips = array_filter(
                (self::$resolver)($host),
                fn (mixed $ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false
            );

            return array_values(array_unique($ips));
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

    /**
     * Erlaubt ist, was global geroutet wird - oder was der Betreiber
     * ausdruecklich freigegeben hat.
     *
     * FILTER_FLAG_GLOBAL_RANGE folgt dem IANA-Verzeichnis der Adressen fuer
     * besondere Zwecke und deckt damit mehr ab als "privat" und "reserviert"
     * allein.
     */
    private static function isAllowedIp(string $ip): bool
    {
        $global = filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE | FILTER_FLAG_GLOBAL_RANGE
        ) !== false;

        if ($global) {
            return true;
        }

        $networks = array_values(array_filter(
            (array) config('swayy.outbound.allowed_networks', []),
            'is_string'
        ));

        return $networks !== [] && IpUtils::checkIp($ip, $networks);
    }
}
