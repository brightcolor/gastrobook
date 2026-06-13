<?php

namespace App\Services\Payments;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

class PaymentProviderManager
{
    private const SUPPORTED = ['stripe', 'paypal'];

    /**
     * All connected payment providers for a tenant, keyed by provider name.
     * A tenant can offer several at once (guest chooses at checkout).
     *
     * @return array<string, PaymentProvider>
     */
    public function available(Tenant $tenant): array
    {
        if (! $tenant->hasFeature('deposits_enabled')) {
            return [];
        }

        $connections = IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->where('status', 'connected')
            ->whereIn('provider', self::SUPPORTED)
            ->get();

        $providers = [];
        foreach ($connections as $connection) {
            $provider = $this->build($connection->provider, $connection->credentials_encrypted);
            if ($provider !== null) {
                $providers[$connection->provider] = $provider;
            }
        }

        return $providers;
    }

    /**
     * A specific connected provider, or null when not configured.
     */
    public function provider(Tenant $tenant, string $key): ?PaymentProvider
    {
        return $this->available($tenant)[$key] ?? null;
    }

    /**
     * First available provider (back-compat / single-provider shortcut).
     */
    public function providerFor(Tenant $tenant): ?PaymentProvider
    {
        return array_values($this->available($tenant))[0] ?? null;
    }

    public function isConfigured(Tenant $tenant): bool
    {
        return $this->available($tenant) !== [];
    }

    private function build(string $provider, ?string $encrypted): ?PaymentProvider
    {
        if (! $encrypted) {
            return null;
        }

        $c = json_decode(Crypt::decryptString($encrypted), true);
        if (! is_array($c)) {
            return null;
        }

        return match ($provider) {
            'stripe' => new StripeProvider($c['secret_key'] ?? '', $c['webhook_secret'] ?? ''),
            'paypal' => new PayPalProvider($c['client_id'] ?? '', $c['secret'] ?? '', $c['mode'] ?? 'live'),
            default => null,
        };
    }
}
