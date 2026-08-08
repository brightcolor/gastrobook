<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Erlaubte Host-Header, abgeleitet aus APP_URL.
 *
 * Hintergrund: Der Host-Header ist frei waehlbar. Ohne Bindung landet ein
 * gefaelschter Host in generierten Links – etwa im Passwort-Reset-Link, der
 * dann mit gueltigem Token auf eine fremde Domain zeigt.
 *
 * Eigene Klasse statt einer Closure in bootstrap/app.php, weil genau diese
 * Ableitung schon einmal die Produktion lahmgelegt hat (siehe unten) und
 * pruefbar sein muss.
 */
final class TrustedHosts
{
    /**
     * Hosts, unter denen die App laufen darf – als Regex-Muster fuer Symfony.
     *
     * Leeres Ergebnis bedeutet: keine Einschraenkung. Das ist der Fall lokal,
     * in Tests und immer dann, wenn APP_URL nicht auf eine echte Domain zeigt.
     *
     * ACHTUNG, teuer gelernt: Diese Liste muss das Subdomain-Muster selbst
     * enthalten. Laravels `trustHosts(subdomains: true)` haengt sonst
     * zusaetzlich ein Muster aus APP_URL an – auch an eine leere Liste. Steht
     * dort dann `http://localhost:8081` waehrend die Seite unter einer echten
     * Domain laeuft, ist die Liste nicht mehr leer, kein Aufruf passt mehr und
     * jede Anfrage endet in 400 Bad Request. Genau so passiert am 08.08.2026.
     *
     * @return array<int, string>
     */
    public static function patterns(?string $appUrl = null): array
    {
        $host = parse_url((string) ($appUrl ?? config('app.url')), PHP_URL_HOST);

        if (! is_string($host) || in_array($host, ['', 'localhost', '127.0.0.1', '::1'], true)) {
            return [];
        }

        // Eine reine IP hat keine Subdomains.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return ['^'.preg_quote($host, '#').'$'];
        }

        return ['^(.+\.)?'.preg_quote($host, '#').'$'];
    }
}
