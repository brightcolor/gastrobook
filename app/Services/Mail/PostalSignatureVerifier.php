<?php

declare(strict_types=1);

namespace App\Services\Mail;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use OpenSSLAsymmetricKey;

/**
 * Prüft die Signatur eines eingehenden Postal-Aufrufs. Postal signiert den
 * Rohbody mit dem Schlüssel des Servers und schickt zwei Signaturen:
 * X-Postal-Signature-256 (SHA-256) und X-Postal-Signature (SHA-1). Geprüft
 * wird die zum eingestellten Verfahren passende.
 */
final class PostalSignatureVerifier
{
    /**
     * Kopfzeile, in der die Signatur zum eingestellten Verfahren steht.
     */
    public function headerName(): string
    {
        $header = trim((string) config('swayy.guest_mail_relay.inbound_signature_header'));
        if ($header !== '') {
            return $header;
        }

        return $this->algorithm() === OPENSSL_ALGO_SHA1 ? 'X-Postal-Signature' : 'X-Postal-Signature-256';
    }

    /**
     * Prüft Signatur und optionales Geheimnis einer Anfrage von Postal.
     * null, wenn alles stimmt; sonst die Meldung für die Antwort mit 401.
     */
    public function rejection(Request $request): ?string
    {
        if (! $this->verify($request->getContent(), (string) $request->header($this->headerName()))) {
            return 'Signatur der Anfrage fehlt oder stimmt nicht. Die Nachricht wurde nicht verarbeitet. Bitte den öffentlichen Schlüssel von Postal in SWAYY_GUEST_MAIL_RELAY_PUBLIC_KEY prüfen.';
        }

        $shared = trim((string) config('swayy.guest_mail_relay.inbound_shared_secret'));
        if ($shared !== '' && ! hash_equals($shared, (string) $request->header('X-Postal-Shared-Secret'))) {
            return 'Zugangskennung fehlt oder stimmt nicht.';
        }

        return null;
    }

    public function verify(string $payload, string $signatureHeader): bool
    {
        $publicKey = $this->publicKey();
        $signature = base64_decode(trim($signatureHeader), true);
        if ($publicKey === null || $signature === false || $signature === '') {
            return false;
        }

        return openssl_verify($payload, $signature, $publicKey, $this->algorithm()) === 1;
    }

    private function algorithm(): int
    {
        return config('swayy.guest_mail_relay.inbound_signature_algo', 'sha256') === 'sha1'
            ? OPENSSL_ALGO_SHA1
            : OPENSSL_ALGO_SHA256;
    }

    /**
     * Der eingestellte Schlüssel. Postal gibt ihn als DKIM-Eintrag aus
     * („v=DKIM1; …; p=<base64>"); angenommen werden dieser Eintrag, der nackte
     * p=-Wert und ein PEM-Block. null, wenn nichts eingestellt ist oder sich
     * der Schlüssel nicht lesen lässt.
     */
    private function publicKey(): ?OpenSSLAsymmetricKey
    {
        $raw = trim((string) config('swayy.guest_mail_relay.inbound_public_key'));
        if ($raw === '') {
            return null;
        }

        if (! str_contains($raw, '-----BEGIN')) {
            if (preg_match('/(?:^|;)\s*p=([A-Za-z0-9+\/=\s]+)/', $raw, $m)) {
                $raw = $m[1];
            }
            // Nur base64-Zeichen behalten: Postal beendet den DKIM-Eintrag mit
            // „;", beim Kopieren kommen gern Anführungszeichen oder Umbrüche mit.
            $base64 = (string) preg_replace('/[^A-Za-z0-9+\/=]/', '', $raw);
            $raw = "-----BEGIN PUBLIC KEY-----\n".chunk_split($base64, 64, "\n")."-----END PUBLIC KEY-----\n";
        }

        $key = openssl_pkey_get_public($raw);
        if ($key === false) {
            Log::warning('[swayy] SWAYY_GUEST_MAIL_RELAY_PUBLIC_KEY lässt sich nicht als öffentlicher Schlüssel lesen. Eingehende Gästeantworten werden abgewiesen. Bitte den Wert hinter p= aus „postal default-dkim-record" eintragen.');

            return null;
        }

        return $key;
    }
}
