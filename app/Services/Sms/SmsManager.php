<?php

declare(strict_types=1);

namespace App\Services\Sms;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

class SmsManager
{
    /**
     * Resolve the configured SMS provider for a tenant, or null when no
     * integration is connected.
     */
    public function providerFor(Tenant $tenant): ?SmsProvider
    {
        $connection = IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->where('status', 'connected')
            ->whereIn('provider', ['sevenio'])
            ->first();

        if ($connection === null || ! $connection->credentials_encrypted) {
            return null;
        }

        $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true);
        if (! is_array($credentials)) {
            return null;
        }

        return match ($connection->provider) {
            'sevenio' => new SevenIoProvider(
                $credentials['api_key'] ?? '',
                $credentials['sender_id'] ?? '',
            ),
            default => null,
        };
    }

    public function isConfigured(Tenant $tenant): bool
    {
        return $this->providerFor($tenant) !== null;
    }

    /**
     * Normalize a free-form phone number to international digits for SMS.
     * German-market default: leading 0 becomes the country code.
     *
     * @param  string  $defaultCountry  Country calling code without "+" (e.g. "49")
     */
    public function normalizePhone(?string $phone, string $defaultCountry = '49'): ?string
    {
        if ($phone === null) {
            return null;
        }

        $trimmed = trim($phone);
        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed) ?? '';

        if ($digits === '') {
            return null;
        }

        if ($hasPlus) {
            return $digits;
        }

        // 00 49 ... international prefix
        if (str_starts_with($digits, '00')) {
            return substr($digits, 2);
        }

        // National format: 0170... -> 49170...
        if (str_starts_with($digits, '0')) {
            return $defaultCountry.substr($digits, 1);
        }

        // Already looks like country code + number
        return $digits;
    }
}
