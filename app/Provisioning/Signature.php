<?php

declare(strict_types=1);

namespace App\Provisioning;

/**
 * Die Signaturen der Bereitstellungs-Schnittstelle, Version 1.
 *
 * DIESELBE REGEL WIE IM PANEL (modules/Saas/Signature.php) und in vip-invites
 * (app/services/provisioning_auth.py). Die Pruefwerte in
 * tests/Unit/ProvisioningSignatureTest.php stammen von dort; eine Abweichung
 * wird rot, bevor ein Aufruf auf einem Server scheitert.
 *
 * REIN: Alles kommt als Argument, auch die Zeit.
 */
final class Signature
{
    /** Die Schreibweise vor dem Hexwert, wie sie im Kopf steht. */
    public const SCHEME = 'v1=';

    /** HMAC-SHA256 ueber Zeitstempel, Methode, Pfad mit Query und SHA-256 des Koerpers, je eine Zeile. */
    public static function request(string $key, string $timestamp, string $method, string $target, string $body): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, strtoupper($method), $target, hash('sha256', $body)]), $key);
    }

    /** Der Rueckweg ins Panel: Zeitstempel, Einmalwert, Dienst und Vertrag, je eine Zeile. */
    public static function back(string $key, string $timestamp, string $nonce, string $service, string $contract): string
    {
        return hash_hmac('sha256', implode("\n", [$timestamp, $nonce, $service, $contract]), $key);
    }

    /**
     * Der signierte Link „Tarif und Rechnungen“ ins Panel.
     *
     * DER ERSTE SCHLUESSEL SIGNIERT. Beim Wechsel steht der neue an zweiter
     * Stelle, bis das Panel ihn kennt; erst dann kommt er nach vorn.
     */
    public static function backUrl(string $panelBaseUrl, string $key, string $service, string $contract, int $now, string $nonce): string
    {
        $stempel = (string) $now;

        return rtrim($panelBaseUrl, '/').'/saas/zurueck?'.http_build_query([
            'service' => $service,
            'contract' => $contract,
            'ts' => $stempel,
            'nonce' => $nonce,
            'sig' => self::back($key, $stempel, $nonce, $service, $contract),
        ], '', '&', PHP_QUERY_RFC3986);
    }
}
