<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Signs a license JSON file with the Ed25519 private key.
 *
 * Usage (on the internal license-generation server):
 *   php artisan license:sign \
 *     --id=lic_abc123 \
 *     --licensee="Mustergastro GmbH" \
 *     --email=info@mustergastro.de \
 *     --plan=professional \
 *     --max-tenants=1 \
 *     --max-locations=3 \
 *     --max-tables=100 \
 *     --expires=2027-06-15 \
 *     --secret=<base64-private-key>
 */
class LicenseSign extends Command
{
    protected $signature = 'license:sign
        {--id= : Eindeutige Lizenz-ID (z.B. lic_abc123)}
        {--licensee= : Name des Lizenznehmers}
        {--email= : E-Mail des Lizenznehmers}
        {--plan= : Plan (starter|professional|enterprise)}
        {--max-tenants=1 : Maximale Mandanten}
        {--max-locations=1 : Maximale Standorte}
        {--max-tables=20 : Maximale Tische}
        {--max-users=5 : Maximale Benutzer}
        {--expires= : Ablaufdatum (YYYY-MM-DD, leer = unbegrenzt)}
        {--features=* : Freigeschaltete Features}
        {--secret= : Ed25519 Private Key (base64)}
        {--out= : Ausgabedatei (Standard: stdout)}';

    protected $description = 'Erstellt und signiert eine Swayy-Lizenz.';

    public function handle(): int
    {
        $secretB64 = $this->option('secret');
        if (! $secretB64) {
            $this->components->error('--secret ist erforderlich (base64-kodierter Ed25519 Private Key).');

            return self::FAILURE;
        }

        $secretKey = base64_decode($secretB64, strict: true);
        if ($secretKey === false || strlen($secretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            $this->components->error('Ungültiger Private Key (erwartet '.SODIUM_CRYPTO_SIGN_SECRETKEYBYTES.' Bytes base64).');

            return self::FAILURE;
        }

        $plan = $this->option('plan') ?: 'starter';
        $features = match ($plan) {
            'professional' => ['reservations', 'waitlist', 'floor_plan', 'stammgast', 'branding', 'events'],
            'enterprise' => ['reservations', 'waitlist', 'floor_plan', 'stammgast', 'branding', 'events', 'api', 'white_label'],
            default => ['reservations', 'waitlist'],
        };

        if ($this->option('features')) {
            $features = array_merge($features, $this->option('features'));
            $features = array_unique($features);
        }

        $payload = array_filter([
            'id' => $this->option('id') ?: 'lic_'.bin2hex(random_bytes(8)),
            'licensee' => $this->option('licensee') ?: '',
            'email' => $this->option('email') ?: '',
            'plan' => $plan,
            'max_tenants' => (int) ($this->option('max-tenants') ?? 1),
            'max_locations' => (int) ($this->option('max-locations') ?? 1),
            'max_tables' => (int) ($this->option('max-tables') ?? 20),
            'max_users' => (int) ($this->option('max-users') ?? 5),
            'features' => array_values($features),
            'issued_at' => now()->toDateString(),
            'expires_at' => $this->option('expires') ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        ksort($payload);

        $canonical = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $signature = sodium_crypto_sign_detached($canonical, $secretKey);

        $license = $payload;
        $license['signature'] = base64_encode($signature);

        $json = json_encode($license, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $out = $this->option('out');
        if ($out) {
            file_put_contents($out, $json);
            $this->components->info("Lizenz gespeichert: {$out}");
        } else {
            $this->line($json);
        }

        return self::SUCCESS;
    }
}
