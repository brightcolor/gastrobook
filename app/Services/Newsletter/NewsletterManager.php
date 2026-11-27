<?php

namespace App\Services\Newsletter;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Support\NewsletterSignup;
use Illuminate\Support\Facades\Crypt;

class NewsletterManager
{
    /** Anbieter, fuer die es einen Adapter gibt. */
    private const PROVIDERS = ['mailwizz'];

    /**
     * Resolve the configured newsletter provider for a tenant, or null when
     * no integration is connected.
     */
    public function providerFor(Tenant $tenant): ?NewsletterProvider
    {
        $connection = $this->connectionFor($tenant);

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
                NewsletterSignup::extraFields($tenant),
            ),
            default => null,
        };
    }

    /**
     * Muss die Seite nach einer Buchung auf eine Bestätigungsmail des Newsletter-Systems hinweisen? Nur mit Häkchen,
     * verbundener Anbindung und Double-Opt-in.
     */
    public function announcesConfirmationMail(?Tenant $tenant, bool $ticked): bool
    {
        return $ticked && $tenant !== null && $tenant->newsletter_double_opt_in && $this->connectionFor($tenant) !== null;
    }

    /**
     * Haelt die Anbindung an, wenn die Zielpruefung ihre Adresse ablehnt.
     *
     * Jeder weitere Versuch traefe dieselbe Pruefung. Die Anbindung steht
     * deshalb auf "Fehler", bis jemand die Einstellungen neu speichert - dabei
     * wird die Adresse erneut geprueft. Der Grund erscheint auf der
     * Einstellungsseite.
     */
    public function suspend(Tenant $tenant, string $reason): void
    {
        $connection = $this->connectionFor($tenant);
        if ($connection === null) {
            return;
        }

        $connection->update([
            'status' => 'error',
            'settings' => array_merge($connection->settings ?? [], ['last_error' => $reason]),
        ]);
    }

    private function connectionFor(Tenant $tenant): ?IntegrationConnection
    {
        return IntegrationConnection::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereNull('location_id')
            ->where('status', 'connected')
            ->whereIn('provider', self::PROVIDERS)
            ->first();
    }
}
