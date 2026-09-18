<?php

declare(strict_types=1);

namespace App\Services\Captcha;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cap CAPTCHA (https://capjs.js.org), self-hosted.
 *
 * The widget in the browser solves a proof-of-work challenge and puts the
 * result into a hidden "cap-token" field. Every protected form has that token
 * confirmed here before the request is allowed to do anything.
 */
class CapVerifier
{
    /** Name of the hidden field the Cap widget adds to the form. */
    public const string TOKEN_FIELD = 'cap-token';

    /**
     * Configured and switched on. Missing credentials count as "off": a
     * half-configured CAPTCHA must not block every public form.
     */
    public function enabled(): bool
    {
        return (bool) config('cap.enabled')
            && config('cap.server_url') !== ''
            && config('cap.site_key') !== ''
            && config('cap.secret_key') !== '';
    }

    /**
     * Whether this particular form is guarded. Unknown names are not: a typo
     * in a middleware parameter would otherwise silently protect nothing
     * while looking guarded, so it is logged loudly.
     */
    public function protects(string $form): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $forms = (array) config('cap.forms', []);

        if (! array_key_exists($form, $forms)) {
            Log::error('Cap: unknown form name "'.$form.'" – nothing is protected under that name.');

            return false;
        }

        return (bool) $forms[$form];
    }

    /**
     * Endpoints and keys the widget needs. Never carries the secret.
     */
    public function widgetConfig(): array
    {
        $server = (string) config('cap.server_url');
        $siteKey = (string) config('cap.site_key');

        return [
            'api_endpoint' => $server.'/'.$siteKey.'/',
            'widget_url' => $server.'/assets/widget.js',
            'wasm_url' => $server.'/assets/cap_wasm_bg.wasm',
            'pako_url' => asset('vendor/cap/pako_inflate.min.js'),
        ];
    }

    /**
     * Asks the Cap server whether the token is good.
     *
     * Cap Standalone exposes a reCAPTCHA-compatible endpoint:
     * POST <server>/<siteKey>/siteverify {"secret": "...", "response": "..."}
     * answering {"success": true} or {"success": false, "error": "..."}.
     *
     * Tokens are single use, so a replayed or expired one also arrives as
     * success=false.
     *
     * @return string|null null when the token is good, otherwise the message
     *                     for the visitor
     */
    public function verify(string $token): ?string
    {
        if (trim($token) === '') {
            return 'Bitte zuerst die Sicherheitsprüfung über dem Absenden-Knopf abschließen.';
        }

        $url = config('cap.server_url').'/'.config('cap.site_key').'/siteverify';

        try {
            $response = Http::timeout((int) config('cap.timeout', 8))
                ->acceptJson()
                ->asJson()
                ->post($url, [
                    'secret' => (string) config('cap.secret_key'),
                    'response' => trim($token),
                ]);
        } catch (\Throwable $e) {
            return $this->unreachable('Cap server at '.$url.' could not be reached: '.$e->getMessage());
        }

        $body = $response->json();

        // The body decides, not the status code: Cap answers 404 for a token
        // that was already used and 403 for a wrong secret – both with a
        // proper {"success": false} body. Read the status first and a real
        // rejection would count as "server unreachable", which fail_open
        // would then wave through.
        if (is_array($body) && array_key_exists('success', $body)) {
            if ($body['success'] === true) {
                return null;
            }

            $reason = (string) ($body['error'] ?? 'no reason given');

            if ($response->status() === 403) {
                Log::error('Cap refused site key or secret for '.$url.': '.$reason);

                return 'Die Sicherheitsprüfung ist nicht richtig eingerichtet. Bitte den Betreiber informieren.';
            }

            Log::info('Cap rejected a token: '.$reason);

            return 'Die Sicherheitsprüfung ist abgelaufen oder wurde bereits verwendet. Bitte erneut lösen und das Formular noch einmal absenden.';
        }

        return $this->unreachable('Cap server at '.$url.' answered HTTP '.$response->status()
            .' without a usable success field.');
    }

    /**
     * The Cap server did not give a usable answer. Rejecting is the default;
     * cap.fail_open lets the request through, and says so in the log every
     * single time so the lost protection cannot go unnoticed.
     */
    private function unreachable(string $technical): ?string
    {
        if (config('cap.fail_open')) {
            Log::error('Cap: request let through UNVERIFIED because cap.fail_open is on. '.$technical);

            return null;
        }

        Log::error('Cap: request rejected, '.$technical);

        return 'Die Sicherheitsprüfung ist gerade nicht erreichbar. Bitte in einigen Minuten noch einmal versuchen.';
    }
}
