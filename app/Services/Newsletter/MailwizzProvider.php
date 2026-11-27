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
    /**
     * @param  array<string, string>  $extraFields  Felder, die eine neue Anmeldung zusätzlich bekommt (NewsletterSignup)
     */
    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $listUid,
        private readonly array $extraFields = [],
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

        // Neue Anmeldung, auch nach einer früheren Abmeldung: MailWizz legt die Adresse (wieder) an und schickt bei
        // Double-Opt-in die Bestätigungsmail. Herkunft und Veranstaltung gehen nur hier mit.
        $url = $this->endpoint('/subscribers');
        $response = $this->request($url)->asForm()->post($url, $fields + $this->extraFields);
        if ($response->successful()) {
            return true;
        }
        if ($response->status() !== 409 && $response->status() !== 422) {
            return false;
        }

        // Die Adresse steht schon auf der Liste: nur die Namen nachziehen. MailWizz ändert beim Aktualisieren nur
        // gesendete Felder, die erste Herkunft bleibt also erhalten.
        $subscriberUid = $this->findSubscriber($guest->email)['uid'];
        if ($subscriberUid === null) {
            return false;
        }
        $url = $this->endpoint('/subscribers/'.$subscriberUid);

        return $this->request($url)->asForm()->put($url, $fields)->successful();
    }

    public function unsubscribe(Guest $guest): bool
    {
        if (! $guest->email) {
            return false;
        }

        ['uid' => $subscriberUid, 'status' => $status] = $this->findSubscriber($guest->email);
        if ($subscriberUid === null) {
            // 404 heißt „Adresse unbekannt“ oder „Liste unbekannt“. Erledigt ist die Abmeldung nur, wenn es die Liste
            // gibt; die Fehlertexte von MailWizz sind übersetzt und taugen nicht zum Unterscheiden.
            return $status === 404 && $this->testConnection()->successful();
        }

        $url = $this->endpoint('/subscribers/'.$subscriberUid.'/unsubscribe');

        return $this->request($url)->asForm()->put($url)->successful();
    }

    /**
     * Sucht eine Adresse in der Liste.
     *
     * @return array{uid: ?string, status: int}
     *
     * @throws OutboundUrlBlocked
     */
    private function findSubscriber(string $email): array
    {
        $url = $this->endpoint('/subscribers/search-by-email');
        $response = $this->request($url)->get($url, ['EMAIL' => $email]);
        $uid = $response->successful() ? $response->json('data.subscriber_uid') : null;

        // Die Kennung landet in der Adresse der nächsten Anfrage, deshalb nur Buchstaben und Ziffern.
        return [
            'uid' => is_string($uid) && preg_match('/^[A-Za-z0-9]+$/', $uid) === 1 ? $uid : null,
            'status' => $response->status(),
        ];
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
