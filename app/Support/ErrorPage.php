<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Bausteine der Fehlerseiten (resources/views/errors).
 *
 * Eine Fehlerseite erscheint auch dann, wenn etwas Grundlegendes nicht
 * läuft: vor der Anmeldung, ohne Betrieb, bei einem Datenbankfehler. Jeder
 * Baustein greift deshalb vorsichtig zu und fällt auf einen ruhigen Wert
 * zurück.
 */
final class ErrorPage
{
    /**
     * Der Text, den der Aufrufer von abort() mitgegeben hat, oder ein leerer
     * Text. Eigene Meldungen sind für Menschen geschrieben. Texte des
     * Frameworks (englisch, oder aus einer umgewandelten Ausnahme wie einer
     * verweigerten Berechtigung) und Meldungen mit einem Namen aus
     * config/permissions.php ergeben den leeren Text; so erfährt niemand,
     * welche Berechtigung im Einzelnen fehlt.
     */
    public static function reason(mixed $exception): string
    {
        if (! $exception instanceof HttpExceptionInterface || ! $exception instanceof Throwable || $exception->getPrevious() !== null) {
            return '';
        }

        $message = trim($exception->getMessage());
        $frameworkText = Response::$statusTexts[$exception->getStatusCode()] ?? '';
        if ($message === '' || strcasecmp($message, $frameworkText) === 0) {
            return '';
        }

        foreach ((array) config('permissions.permissions', []) as $permission) {
            if (is_string($permission) && $permission !== '' && str_contains($message, $permission)) {
                return '';
            }
        }

        return $message;
    }

    /**
     * Wartezeit aus dem Header Retry-After („42 Sekunden“, „3 Minuten“),
     * aufgerundet; null, wenn der Header fehlt.
     */
    public static function waitTime(mixed $exception): ?string
    {
        if (! $exception instanceof HttpExceptionInterface) {
            return null;
        }

        $value = null;
        foreach ($exception->getHeaders() as $name => $header) {
            if (strcasecmp((string) $name, 'Retry-After') === 0) {
                $value = is_array($header) ? ($header[0] ?? null) : $header;

                break;
            }
        }

        if (is_numeric($value)) {
            $seconds = (int) ceil((float) $value);
        } elseif (is_string($value) && ($timestamp = strtotime($value)) !== false) {
            $seconds = $timestamp - now()->getTimestamp();
        } else {
            return null;
        }

        if ($seconds < 1) {
            return null;
        }

        return $seconds < 60
            ? trans_choice('{1} :count Sekunde|[2,*] :count Sekunden', $seconds)
            : trans_choice('{1} :count Minute|[2,*] :count Minuten', (int) ceil($seconds / 60));
    }

    /**
     * Die Adresse für „Seite neu laden“ nach einem abgelaufenen Formular: die
     * Seite, von der das Formular kam. Eine fremde Herkunft führt zur
     * Startseite, damit die Fehlerseite nie auf eine fremde Adresse verweist.
     */
    public static function reloadUrl(): string
    {
        try {
            $previous = url()->previous();
        } catch (Throwable) {
            return url('/');
        }

        return parse_url($previous, PHP_URL_HOST) === request()->getHost() ? $previous : url('/');
    }

    public static function signedIn(): bool
    {
        try {
            return auth()->check();
        } catch (Throwable) {
            return false;
        }
    }

    public static function supportEmail(): string
    {
        return (string) config('swayy.support.email');
    }
}
