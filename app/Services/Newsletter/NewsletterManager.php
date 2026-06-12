<?php

namespace App\Services\Newsletter;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use Illuminate\Support\Facades\Crypt;

class NewsletterManager
{
    /**
     * Resolve the configured newsletter provider for a tenant, or null when
     * no integration is connected.
     */
    public function providerFor(Tenant $tenant): ?NewsletterProvider
    {
        $connection = IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->where('status', 'connected')
            ->whereIn('provider', ['mailwizz'])
            ->first();

        if ($connection === null || ! $connection->credentials_encrypted) {
            return null;
        }

        $credentials = json_decode(Crypt::decryptString($connection->credentials_encrypted), true);
        if (! is_array($credentials)) {
            return null;
        }

        return match ($connection->provider) {
            'mailwizz' => new MailwizzProvider(
                $credentials['api_url'] ?? '',
                $credentials['api_key'] ?? '',
                $credentials['list_uid'] ?? '',
            ),
            default => null,
        };
    }
}
