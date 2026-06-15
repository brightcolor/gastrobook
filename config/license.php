<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Self-hosted license enforcement
    |--------------------------------------------------------------------------
    |
    | Set SWAYY_SELF_HOSTED=true in your .env to activate license checking.
    | The hosted SaaS on swayy.de leaves this unset (enforcement is skipped).
    |
    */

    'self_hosted' => (bool) env('SWAYY_SELF_HOSTED', false),

    // Absolute path to the license file, or relative to storage_path().
    'file' => env('SWAYY_LICENSE_FILE', 'license.json'),

    // Revocation check endpoint (GET /v1/revoked/{id} → {"revoked":bool}).
    'revocation_url' => env('SWAYY_REVOCATION_URL', 'https://license.swayy.de/v1/revoked'),

    // How long to cache the revocation result (seconds).
    'revocation_ttl' => (int) env('SWAYY_LICENSE_REVOCATION_TTL', 604800), // 7 days

    // Days after expiry before the admin is hard-locked (booking stays up).
    'grace_days' => (int) env('SWAYY_LICENSE_GRACE_DAYS', 14),

    // Days before expiry when the warning banner appears.
    'warn_days' => (int) env('SWAYY_LICENSE_WARN_DAYS', 30),
];
