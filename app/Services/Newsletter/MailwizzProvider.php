<?php

namespace App\Services\Newsletter;

use App\Models\Guest;
use App\Support\OutboundUrlBlocked;
use App\Support\OutboundUrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

/**
 * MailWizz EMS adapter (API v2, single X-API-KEY auth).
 *
 * Credentials: api_url (e.g. https://news.example.com/api), api_key, list_uid.
 * Double-opt-in is controlled by the MailWizz list settings — when the list
 * is configured for DOI, MailWizz sends the confirmation mail itself.
 *
 * Die API-Adresse traegt der Betrieb selbst ein. Jede Anfrage laeuft deshalb
 * ueber dieselbe Zielpruefung wie die Webhooks: vor jedem Aufruf neu
 * aufgeloest, auf die gepruefte Adresse festgenagelt und ohne Weiterleitungen.
 * Eine abgelehnte Adresse wirft OutboundUrlBlocked, bevor eine Verbindung
 * entsteht.
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

        $fields = array_filter([
            'EMAIL' => $guest->email,
            'FNAME' => $guest->first_name,
            'LNAME' => $guest->last_name,
        ]);

        $url = $this->endpoint('/subscribers');
        $response = $this->request($url)->asForm()->post($url, $fields);

        // 409/422 = already subscribed → update instead (idempotent behaviour)
        if ($response->status() === 409 || $response->status() === 422) {
            $url = $this->endpoint('/subscribers/search-by-email-and-update');
            $response = $this->request($url)->asForm()->put($url, $fields);
        }

        return $response->successful();
    }

    public function unsubscribe(Guest $guest): bool
    {
        if (! $guest->email) {
            return false;
        }

        $url = $this->endpoint('/subscribers/search-by-email-and-unsubscribe');

        return $this->request($url)->asForm()->put($url, [
            'EMAIL' => $guest->email,
        ])->successful();
    }

    /**
     * Fragt die Liste ab. Die Antwort geht unveraendert zurueck, damit die
     * Einstellungsseite sagen kann, woran es liegt: Weiterleitung, API-Key
     * oder Liste.
     *
     * @throws OutboundUrlBlocked
     */
    public function testConnection(): Response
    {
        $url = $this->endpoint('');

        return $this->request($url)->get($url);
    }

    /**
     * @throws OutboundUrlBlocked
     */
    private function request(string $url): PendingRequest
    {
        return OutboundUrlGuard::client($url)
            ->withHeaders(['X-API-KEY' => $this->apiKey])
            ->timeout((int) config('swayy.newsletter.timeout'));
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->apiUrl, '/').'/lists/'.$this->listUid.$path;
    }
}
