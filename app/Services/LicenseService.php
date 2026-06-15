<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Validates the self-hosted Swayy license.
 *
 * The license file (storage/license.json) is signed with Ed25519.
 * The public key is embedded here so it cannot be swapped at runtime.
 * Generate a new keypair with: php artisan license:keygen
 *
 * Validation flow:
 *   1. File exists and is valid JSON
 *   2. Ed25519 signature over canonical payload (sorted keys, no spaces)
 *   3. Expiry check with configurable grace period
 *   4. Revocation check (cached, non-blocking)
 */
class LicenseService
{
    /**
     * Ed25519 public key (base64-encoded, 32 bytes).
     * Replace this with the output of `php artisan license:keygen` before shipping.
     *
     * IMPORTANT: This constant is the trust anchor — never read the public key
     * from config/env, as that would allow key substitution attacks.
     */
    private const PUBLIC_KEY_B64 = 'SWAYY_LICENSE_PUBLIC_KEY_REPLACE_BEFORE_PRODUCTION';

    private const CACHE_KEY = 'swayy.license.status';

    private const CACHE_TTL = 3600; // 1 hour

    public function check(): LicenseStatus
    {
        if (! config('license.self_hosted')) {
            return new LicenseStatus(valid: true, selfHosted: false, plan: 'hosted');
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->validate());
    }

    /** Force re-validation (e.g. after license file change). */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public function planLimit(string $key): ?int
    {
        $status = $this->check();

        if (! $status->selfHosted || ! $status->valid) {
            return null;
        }

        $data = $this->readFile();
        if ($data === null) {
            return null;
        }

        return isset($data[$key]) ? (int) $data[$key] : null;
    }

    // -------------------------------------------------------------------------
    // Private validation pipeline
    // -------------------------------------------------------------------------

    private function validate(): LicenseStatus
    {
        $data = $this->readFile();

        if ($data === null) {
            return new LicenseStatus(
                valid: false,
                selfHosted: true,
                error: 'Keine Lizenzdatei gefunden. Legen Sie storage/license.json ab.',
            );
        }

        // 1. Signature
        if (! $this->verifySignature($data)) {
            return new LicenseStatus(
                valid: false,
                selfHosted: true,
                error: 'Ungültige Lizenzsignatur.',
            );
        }

        $expiresAt = isset($data['expires_at'])
            ? CarbonImmutable::parse($data['expires_at'])->endOfDay()
            : null;

        $now = CarbonImmutable::now();
        $graceDays = (int) config('license.grace_days', 14);

        $expired = $expiresAt !== null && $now->gt($expiresAt);
        $inGrace = $expired && $expiresAt !== null
            && $now->lte($expiresAt->addDays($graceDays));

        // 2. Expiry checks
        if ($expired && ! $inGrace) {
            // Beyond grace period — hard lock
            return new LicenseStatus(
                valid: false,
                selfHosted: true,
                plan: $data['plan'] ?? 'unknown',
                licensee: $data['licensee'] ?? '',
                licenseId: $data['id'] ?? '',
                expiresAt: $expiresAt,
                inGracePeriod: false,
                error: 'Lizenz abgelaufen.',
            );
        }

        if ($inGrace) {
            // Within grace period — access still allowed but marked invalid
            return new LicenseStatus(
                valid: false,
                selfHosted: true,
                plan: $data['plan'] ?? 'unknown',
                licensee: $data['licensee'] ?? '',
                licenseId: $data['id'] ?? '',
                expiresAt: $expiresAt,
                inGracePeriod: true,
                error: 'Lizenz abgelaufen – Kulanzfrist läuft.',
            );
        }

        // 3. Revocation (non-blocking — a network failure does NOT invalidate the license)
        $licenseId = $data['id'] ?? '';
        $revoked = $licenseId ? $this->isRevoked($licenseId) : false;

        if ($revoked) {
            return new LicenseStatus(
                valid: false,
                selfHosted: true,
                plan: $data['plan'] ?? 'unknown',
                licensee: $data['licensee'] ?? '',
                licenseId: $licenseId,
                revoked: true,
                error: 'Lizenz wurde widerrufen.',
            );
        }

        return new LicenseStatus(
            valid: true,
            selfHosted: true,
            plan: $data['plan'] ?? 'unknown',
            licensee: $data['licensee'] ?? '',
            licenseId: $licenseId,
            expiresAt: $expiresAt,
            inGracePeriod: $inGrace,
        );
    }

    /** @return array<string,mixed>|null */
    private function readFile(): ?array
    {
        $path = config('license.file', 'license.json');

        // Allow absolute path or relative to storage_path()
        $absolute = file_exists($path) ? $path : storage_path($path);

        if (! file_exists($absolute)) {
            return null;
        }

        $json = file_get_contents($absolute);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    /** @param array<string,mixed> $data */
    private function verifySignature(array $data): bool
    {
        $publicKeyB64 = self::PUBLIC_KEY_B64;

        if ($publicKeyB64 === 'SWAYY_LICENSE_PUBLIC_KEY_REPLACE_BEFORE_PRODUCTION') {
            // In development without a real key, skip signature verification.
            // NEVER allow this in production — set a real key before shipping.
            if (app()->environment('production')) {
                Log::critical('Swayy license: PUBLIC_KEY_B64 placeholder is still set in production!');

                return false;
            }

            return true; // dev bypass
        }

        if (! isset($data['signature'])) {
            return false;
        }

        $signature = base64_decode($data['signature'], strict: true);
        if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        $publicKey = base64_decode($publicKeyB64, strict: true);
        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return false;
        }

        $payload = $data;
        unset($payload['signature']);
        ksort($payload);

        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        try {
            return sodium_crypto_sign_verify_detached($signature, $canonical, $publicKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    private function isRevoked(string $licenseId): bool
    {
        $cacheKey = 'swayy.license.revoked.'.$licenseId;
        $ttl = (int) config('license.revocation_ttl', 604800);

        return (bool) Cache::remember($cacheKey, $ttl, function () use ($licenseId) {
            try {
                $url = rtrim((string) config('license.revocation_url'), '/');
                $response = Http::timeout(5)->get("{$url}/{$licenseId}");

                if ($response->successful()) {
                    return (bool) ($response->json('revoked') ?? false);
                }
            } catch (\Throwable $e) {
                Log::warning('Swayy license revocation check failed', ['error' => $e->getMessage()]);
            }

            return false; // network failure → assume not revoked
        });
    }
}
