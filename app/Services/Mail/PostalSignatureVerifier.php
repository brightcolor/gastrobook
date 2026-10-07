<?php

declare(strict_types=1);

namespace App\Services\Mail;

/**
 * Prüft die Signatur eines eingehenden Postal-Webhooks gegen den
 * konfigurierten Public Key des Mailservers. Postal signiert den Rohbody mit
 * RSA; geprüft wird mit openssl_verify. Ohne Key oder ohne Signatur: false.
 */
final class PostalSignatureVerifier
{
    public function verify(string $payload, string $signatureHeader): bool
    {
        $publicKey = trim((string) config('swayy.guest_mail_relay.inbound_public_key'));
        $signature = base64_decode(trim($signatureHeader), true);
        if ($publicKey === '' || $signature === false || $signature === '') {
            return false;
        }

        $algo = config('swayy.guest_mail_relay.inbound_signature_algo', 'sha256') === 'sha1'
            ? OPENSSL_ALGO_SHA1
            : OPENSSL_ALGO_SHA256;

        return openssl_verify($payload, $signature, $publicKey, $algo) === 1;
    }
}
