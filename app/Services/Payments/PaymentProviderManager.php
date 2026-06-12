<?php

namespace App\Services\Payments;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

class PaymentProviderManager
{
    public function providerFor(Tenant $tenant): ?PaymentProvider
    {
        if (! $tenant->hasFeature('deposits_enabled')) {
            return null;
        }

        $connection = IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->where('status', 'connected')
            ->whereIn('provider', ['stripe'])
            ->first();

        if ($connection === null || ! $connection->credentials_encrypted) {
            return null;
        }

        $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true);
        if (! is_array($credentials)) {
            return null;
        }

        return match ($connection->provider) {
            'stripe' => new StripeProvider(
                $credentials['secret_key'] ?? '',
                $credentials['webhook_secret'] ?? '',
            ),
            default => null,
        };
    }

    public function isConfigured(Tenant $tenant): bool
    {
        return $this->providerFor($tenant) !== null;
    }
}
