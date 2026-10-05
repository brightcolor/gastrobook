<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Fehlerantworten der Verwaltung fuer Seiten, die JSON erwarten.
 *
 * Die Seiten der Verwaltung schicken ihre Formulare per fetch und zeigen die
 * Meldung aus der Antwort. Ausserhalb von api/* antwortete Laravel auf einen
 * Pruefungsfehler mit einer Weiterleitung, der fetch still folgte; die Seite
 * meldete dann "Gespeichert", und die eigentliche Meldung ging verloren.
 *
 * Fragt eine Anfrage an admin/* nach JSON, kommt jeder Fehler als JSON mit
 * einer deutschen Meldung, die Grund und naechsten Schritt nennt. Eigene
 * Meldungen aus abort() kommen unveraendert an. Die Standardtexte des
 * Frameworks ("CSRF token mismatch.", "No query results for model ...")
 * ersetzt diese Klasse, und Einzelheiten unerwarteter Fehler bleiben im Log.
 */
final class AdminJsonErrors
{
    /**
     * Status, fuer die nur das Framework Texte liefert, und zwar englische
     * oder leere. Die Antwort traegt dann immer den Text aus forStatus().
     */
    private const FRAMEWORK_STATUSES = [401, 404, 405, 413, 419, 429];

    /** Fuer alle uebrigen Status; :status steht fuer den Statuscode. */
    private const REJECTED = 'Die Anfrage wurde abgelehnt (Status :status). Bitte die Seite neu laden und es noch einmal versuchen.';

    public static function applies(Request $request): bool
    {
        return $request->is('admin', 'admin/*') && $request->expectsJson();
    }

    /**
     * Antwort fuer den Exception-Handler; null laesst Laravel antworten.
     *
     * Pruefungsfehler rendert Laravel selbst als JSON mit "message" und
     * "errors", sobald applies() zutrifft (shouldRenderJsonWhen).
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! self::applies($request) || $e instanceof ValidationException || $e instanceof HttpResponseException) {
            return null;
        }

        if ($e instanceof AuthenticationException) {
            return response()->json(['message' => self::forStatus(401)], 401);
        }

        if ($e instanceof HttpExceptionInterface) {
            return response()->json(['message' => self::messageFor($e)], $e->getStatusCode(), $e->getHeaders());
        }

        // Unerwarteter Fehler. Mit APP_DEBUG zeigt Laravel die Einzelheiten,
        // sonst stehen sie nur im Log.
        if (config('app.debug')) {
            return null;
        }

        return response()->json(['message' => self::forStatus(500)], 500);
    }

    /**
     * Meldung fuer einen Status, wenn der Server keine eigene liefert.
     *
     * @param  array<string, mixed>  $headers  Antwort-Header, fuer Retry-After
     */
    public static function forStatus(int $status, array $headers = []): string
    {
        $retryAfter = $headers['Retry-After'] ?? null;

        return match (true) {
            $status === 401 => __('Die Anmeldung ist abgelaufen. Bitte neu anmelden und es dann noch einmal versuchen.'),
            $status === 403 => __('Für diese Aktion fehlt die Berechtigung. Bitte einen Administrator des Betriebs fragen.'),
            $status === 404 => __('Der Eintrag wurde nicht gefunden. Vielleicht wurde er inzwischen gelöscht. Bitte die Seite neu laden.'),
            $status === 413 => __('Die Datei ist zu groß. Bitte eine kleinere Datei wählen.'),
            $status === 419 => __('Die Sitzung ist abgelaufen. Bitte die Seite neu laden und es dann noch einmal versuchen.'),
            $status === 422 => __('Die Eingaben wurden abgelehnt. Bitte sie prüfen und es noch einmal versuchen.'),
            $status === 429 && is_numeric($retryAfter) => __('Zu viele Anfragen in kurzer Zeit. Bitte in :seconds Sekunden noch einmal versuchen.', ['seconds' => (int) $retryAfter]),
            $status === 429 => __('Zu viele Anfragen in kurzer Zeit. Bitte kurz warten und es dann noch einmal versuchen.'),
            in_array($status, [502, 503, 504], true) => __('Der Server antwortet gerade nicht, etwa wegen Wartungsarbeiten. Bitte kurz warten und es noch einmal versuchen.'),
            $status >= 500 => __('Auf dem Server ist ein Fehler aufgetreten. Bitte die Seite neu laden und es noch einmal versuchen. Tritt der Fehler wieder auf, bitte den Betreiber informieren und die Uhrzeit nennen.'),
            default => __(self::REJECTED, ['status' => $status]),
        };
    }

    /**
     * Texte fuer das Skript einer Seite. Es braucht sie, wenn gar keine
     * JSON-Antwort kommt: Fehlerseite eines Proxys, abgebrochene Verbindung,
     * Weiterleitung. Unter "default" steht :status fuer den Statuscode.
     *
     * @return array<int|string, string>
     */
    public static function forClient(): array
    {
        $messages = [];
        foreach ([401, 403, 404, 413, 419, 422, 429, 500, 502, 503, 504] as $status) {
            $messages[$status] = self::forStatus($status);
        }

        return $messages + [
            'default' => __(self::REJECTED),
            'network' => __('Keine Verbindung zum Server. Bitte die Internetverbindung prüfen und es noch einmal versuchen.'),
            'unclear' => __('Die Antwort des Servers war unerwartet. Bitte die Seite neu laden und prüfen, ob die Änderung gespeichert ist.'),
        ];
    }

    /**
     * Eine eigene Meldung aus abort() steht ohne vorherige Ausnahme da. Hat
     * das Framework eine Ausnahme umgewandelt (Modell nicht gefunden, Gate),
     * traegt sie dessen englischen Standardtext.
     */
    private static function messageFor(HttpExceptionInterface $e): string
    {
        $status = $e->getStatusCode();
        $own = $e instanceof Throwable && $e->getPrevious() === null ? trim($e->getMessage()) : '';

        if ($own === '' || $status >= 500 || in_array($status, self::FRAMEWORK_STATUSES, true)) {
            return self::forStatus($status, $e->getHeaders());
        }

        return $own;
    }
}
