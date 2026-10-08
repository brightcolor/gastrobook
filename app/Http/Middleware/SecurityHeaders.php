<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sicherheitsheader - aus der Anwendung, nicht aus dem Reverse Proxy.
 *
 * Vorher setzte sie nur der vorgelagerte nginx auf swayy.de. Wer die Anwendung
 * nach README selbst betreibt (docker compose up), bekam damit GAR KEINEN
 * Klickschutz - und im Adminbereich liegen Stornierung und Erstattung. Schutz,
 * der nur an einer Stelle der Auslieferungskette existiert, faehrt nicht mit.
 *
 * Und er war pauschal: `X-Frame-Options: SAMEORIGIN` auf allem hat die
 * Einbett-Widgets (embed.js, popup.js) bei jedem Kunden lahmgelegt - die bauen
 * genau das iframe auf, das der Header verbietet. Darum hier zweigeteilt:
 *
 *  - Adminbereich, Plattformbereich, Anmeldung: Einbettung verboten.
 *  - Oeffentliche Buchungsseiten: Einbettung ausdruecklich erlaubt, das ist
 *    ihr Zweck.
 */
class SecurityHeaders
{
    /**
     * Pfade, die eingebettet werden duerfen und muessen.
     *
     * Bewusst eng: Alles, was der Gast im iframe sieht, plus die Ausgabe der
     * Widget-Skripte selbst. Der Verwaltungslink einer Buchung gehoert NICHT
     * dazu - er traegt den Zugriffstoken in der Adresse.
     */
    private const EMBEDDABLE = [
        'book/*',
        'r/*',
        'embed/*',
        'widget/*',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin', false);
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=()', false);

        // Nur ueber HTTPS, und nur dann sinnvoll: Der Browser merkt sich die
        // Vorgabe fuer diese Adresse. Ueber eine unverschluesselte Verbindung
        // gesendet, ignoriert er sie ohnehin - und eine oertliche Installation
        // laeuft oft bewusst ohne TLS.
        //
        // Ohne Subdomains und ohne preload: Beides ist eine Einbahnstrasse,
        // die auch Nachbarn dieser Domain betrifft. Das gehoert entschieden,
        // nicht mitgeliefert.
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000', false);
        }

        $base = $this->withCaptchaSources(trim((string) config('swayy.security.csp_base')));
        $base = $base === '' ? '' : $base.'; ';

        if ($this->isEmbeddable($request)) {
            // Ausdruecklich offen fuer fremde Seiten - das ist der Zweck des
            // Widgets. Die uebrige Richtlinie (self, kein object, kein wildcard)
            // gilt trotzdem.
            $response->headers->set('Content-Security-Policy', $base.'frame-ancestors *', false);

            return $response;
        }

        // frame-ancestors ist die geltende Regel, X-Frame-Options der
        // Rueckfall fuer aeltere Browser. Beide setzen, keiner allein.
        $response->headers->set('Content-Security-Policy', $base."frame-ancestors 'none'", false);
        $response->headers->set('X-Frame-Options', 'DENY', false);

        return $response;
    }

    private function isEmbeddable(Request $request): bool
    {
        return $request->isMethod('GET') && $request->is(...self::EMBEDDABLE);
    }

    /**
     * Erweitert die Richtlinie um die Quellen des Cap-Captchas, wenn es aktiv
     * ist. Das Widget laedt Skript und WebAssembly vom Cap-Server, ruft dessen
     * API und rechnet den Proof-of-Work in einem Worker. Ohne diese Quellen
     * blockt die eigene CSP das Captcha - und ohne geloestes Captcha laesst
     * sich kein oeffentliches Formular (Reservierung, Anmeldung) absenden.
     *
     * 'unsafe-eval' braucht die Browserpruefung von Cap ("instrumentation"):
     * Sie laeuft in einem srcdoc-iframe, das diese CSP erbt, und ruft eval
     * und new Function auf. Ohne die Quelle bricht das Skript ab, das Widget
     * wartet 20 Sekunden ("instr_timeout") und liefert kein Token. Der Zusatz
     * kostet wenig, weil script-src ohnehin 'unsafe-inline' erlaubt.
     *
     * Der Server kommt aus der Einstellung (cap.server_url), steht also nicht
     * fest im Code.
     */
    private function withCaptchaSources(string $policy): string
    {
        if (! config('cap.enabled')) {
            return $policy;
        }

        $origin = $this->originOf((string) config('cap.server_url'));
        if ($origin === null) {
            return $policy;
        }

        $directives = [];
        foreach (array_filter(array_map('trim', explode(';', $policy))) as $part) {
            [$name, $sources] = array_pad(preg_split('/\s+/', $part, 2), 2, '');
            $directives[$name] = $sources === '' ? [] : preg_split('/\s+/', $sources);
        }

        $add = function (string $directive, array $sources, array $seed = []) use (&$directives) {
            if (! isset($directives[$directive])) {
                $directives[$directive] = $seed;
            }
            foreach ($sources as $source) {
                if (! in_array($source, $directives[$directive], true)) {
                    $directives[$directive][] = $source;
                }
            }
        };

        $add('script-src', ["'unsafe-eval'", "'wasm-unsafe-eval'", $origin], ["'self'"]);
        $add('connect-src', [$origin], ["'self'"]);
        $add('worker-src', ["'self'", 'blob:']);

        return implode('; ', array_map(
            fn (string $name, array $sources) => trim($name.' '.implode(' ', $sources)),
            array_keys($directives),
            $directives,
        ));
    }

    /**
     * Schema://Host[:Port] einer URL, oder null, wenn unbrauchbar.
     */
    private function originOf(string $url): ?string
    {
        $parts = parse_url($url);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
