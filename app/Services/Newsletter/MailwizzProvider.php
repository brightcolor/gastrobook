<?php

namespace App\Services\Newsletter;

use App\Models\Guest;
use Illuminate\Support\Facades\Http;

/**
 * MailWizz EMS adapter (API v2, single X-API-KEY auth).
 *
 * Credentials: api_url (e.g. https://news.example.com/api), api_key, list_uid.
 * Double-opt-in is controlled by the MailWizz list settings — when the list
 * is configured for DOI, MailWizz sends the confirmation mail itself.
 */
class MailwizzProvider implements NewsletterProvider
{
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $listUid,
    ) {}

    public function subscribe(Guest $guest): bool
    {
        if (! $guest->email) {
            return false;
        }

        $response = Http::asForm()
            ->withHeaders(['X-API-KEY' => $this->apiKey])
            ->timeout(10)
            ->post($this->endpoint('/subscribers'), array_filter([
                'EMAIL' => $guest->email,
                'FNAME' => $guest->first_name,
                'LNAME' => $guest->last_name,
            ]));

        // 409/422 = already subscribed → update instead (idempotent behaviour)
        if ($response->status() === 409 || $response->status() === 422) {
            $response = Http::asForm()
                ->withHeaders(['X-API-KEY' => $this->apiKey])
                ->timeout(10)
                ->put($this->endpoint('/subscribers/search-by-email-and-update'), array_filter([
                    'EMAIL' => $guest->email,
                    'FNAME' => $guest->first_name,
                    'LNAME' => $guest->last_name,
                ]));
        }

        return $response->successful();
    }

    public function unsubscribe(Guest $guest): bool
    {
        if (! $guest->email) {
            return false;
        }

        $response = Http::asForm()
            ->withHeaders(['X-API-KEY' => $this->apiKey])
            ->timeout(10)
            ->put($this->endpoint('/subscribers/search-by-email-and-unsubscribe'), [
                'EMAIL' => $guest->email,
            ]);

        return $response->successful();
    }

    public function testConnection(): bool
    {
        return Http::withHeaders(['X-API-KEY' => $this->apiKey])
            ->timeout(10)
            ->get($this->endpoint(''))
            ->successful();
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->apiUrl, '/').'/lists/'.$this->listUid.$path;
    }
}
