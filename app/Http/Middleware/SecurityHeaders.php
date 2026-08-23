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

        if ($this->isEmbeddable($request)) {
            // Ausdruecklich offen. Ohne diese Zeile setzt kein Header die
            // Einbettung frei - sie ist die Erlaubnis, nicht ihr Fehlen.
            $response->headers->set('Content-Security-Policy', 'frame-ancestors *', false);

            return $response;
        }

        // frame-ancestors ist die geltende Regel, X-Frame-Options der
        // Rueckfall fuer aeltere Browser. Beide setzen, keiner allein.
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'", false);
        $response->headers->set('X-Frame-Options', 'DENY', false);

        return $response;
    }

    private function isEmbeddable(Request $request): bool
    {
        return $request->isMethod('GET') && $request->is(...self::EMBEDDABLE);
    }
}
