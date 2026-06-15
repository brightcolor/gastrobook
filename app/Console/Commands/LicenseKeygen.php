<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Generates a fresh Ed25519 keypair for license signing.
 *
 * Run this ONCE on the Swayy license-generation server, then:
 *   1. Copy the public key into LicenseService::PUBLIC_KEY_B64
 *   2. Store the private key securely (password manager / vault)
 *   3. Use the private key with LicenseSigner to sign license files
 */
class LicenseKeygen extends Command
{
    protected $signature = 'license:keygen';

    protected $description = 'Generates an Ed25519 keypair for Swayy license signing.';

    public function handle(): int
    {
        $keypair = sodium_crypto_sign_keypair();
        $publicKey = sodium_crypto_sign_publickey($keypair);
        $secretKey = sodium_crypto_sign_secretkey($keypair);

        $pubB64 = base64_encode($publicKey);
        $secB64 = base64_encode($secretKey);

        $this->components->info('Ed25519 keypair generated.');
        $this->newLine();

        $this->line('<fg=green>PUBLIC KEY (embed in LicenseService::PUBLIC_KEY_B64):</>');
        $this->line($pubB64);
        $this->newLine();

        $this->line('<fg=yellow>PRIVATE KEY (keep secret — never commit):</>');
        $this->line($secB64);
        $this->newLine();

        $this->components->warn('Store the private key in a secrets vault. It cannot be recovered.');
        $this->components->warn('Replace the PUBLIC_KEY_B64 placeholder in LicenseService before shipping.');

        return self::SUCCESS;
    }
}
