<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Rules\AllowedOutboundUrl;
use App\Services\AuditLogger;
use App\Services\Newsletter\MailwizzProvider;
use App\Support\OutboundUrlBlocked;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;
use Throwable;

/**
 * Einstellungen › Verbindungen › Newsletter: Zugang zu MailWizz. Gilt für alle
 * Standorte. Der API-Key liegt verschlüsselt in der Verbindung und erscheint
 * nie wieder in einer Seite; ein leeres Feld behält den gespeicherten Wert.
 * Beim Speichern prüft ein Aufruf der Liste den Zugang. Die API-Adresse trägt
 * der Betrieb selbst ein und der Server ruft sie auf, deshalb gilt dieselbe
 * Zielprüfung wie bei Webhooks (AllowedOutboundUrl, MailwizzProvider).
 */
class NewsletterController extends Controller
{
    use RespondsToSettingsForms;

    private const PROVIDER = 'mailwizz';

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        abort_if($this->context->tenant() === null, 404);

        $connection = $this->connection();
        $credentials = $this->credentials($connection);
        $hasKey = ($credentials['api_key'] ?? '') !== '';
        // Ohne lesbaren Key gilt die Anbindung als nicht eingerichtet, was auch in der Verbindung steht.
        $status = $hasKey ? $connection?->status : null;

        return view('admin.settings.newsletter', [
            // Nur Adresse und Liste verlassen die Zugangsdaten; der Key bleibt als Ja/Nein.
            'apiUrl' => (string) ($credentials['api_url'] ?? ''),
            'listUid' => (string) ($credentials['list_uid'] ?? ''),
            'hasKey' => $hasKey,
            // Der gespeicherte Grund gilt nur, solange die Anbindung auf Fehler steht.
            'lastError' => $status === 'error' ? (string) ($connection->settings['last_error'] ?? '') : '',
            'statusLabel' => $this->statusLabel($status),
            'statusState' => match ($status) {
                'connected' => 'ok',
                'error' => 'error',
                default => 'off',
            },
            // Eine neue Anbindung startet eingeschaltet; nur eine ausdrücklich ausgeschaltete bleibt es.
            'enabled' => $connection === null || $connection->status !== 'disconnected',
            'limits' => (array) config('swayy.newsletter'),
        ]);
    }

    /**
     * MailWizz-Anbindung (für alle Standorte). Die Zugangsdaten werden
     * verschlüsselt gespeichert; der API-Key erscheint nie wieder.
     */
    public function update(Request $request): mixed
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $urlMax = (int) config('swayy.newsletter.api_url_max');
        $keyMax = (int) config('swayy.newsletter.api_key_max');
        $uidMax = (int) config('swayy.newsletter.list_uid_max');

        $validated = $request->validate([
            // Der Server ruft diese Adresse selbst auf, also dieselbe Zielprüfung wie bei Webhook-Endpunkten.
            // Jede Anfrage prüft später noch einmal (MailwizzProvider).
            'api_url' => ['bail', 'required', 'string', 'max:'.$urlMax, 'url', new AllowedOutboundUrl],
            'api_key' => ['nullable', 'string', 'max:'.$keyMax],
            'list_uid' => ['required', 'string', 'max:'.$uidMax],
            // Die neue Seite und die alte Sammelseite senden eine 0 vor dem Kästchen. Fehlt der Schalter, bleibt der
            // Zustand einer bestehenden Anbindung, wie er ist (siehe $enabled unten).
            'enabled' => ['nullable', 'boolean'],
        ], [
            'api_url.required' => __('Die API-URL fehlt. Bitte die Adresse deiner MailWizz-API eintragen, etwa https://news.example.com/api (in MailWizz unter Account → API).'),
            'api_url.string' => __('Die API-URL muss ein Text sein. Bitte sie aus MailWizz kopieren und neu einfügen.'),
            'api_url.max' => __('Die Adresse ist zu lang (höchstens :max Zeichen). Bitte die API-URL aus MailWizz unverändert einfügen.', ['max' => $urlMax]),
            'api_url.url' => __('Die API-URL ist unvollständig. Bitte die Adresse mit https:// am Anfang eintragen, etwa https://news.example.com/api.'),
            'api_key.string' => __('Der API-Key muss ein Text sein. Bitte ihn aus MailWizz kopieren und neu einfügen.'),
            'api_key.max' => __('Der API-Key ist zu lang (höchstens :max Zeichen). Bitte ihn aus MailWizz kopieren und unverändert einfügen.', ['max' => $keyMax]),
            'list_uid.required' => __('Die Listen-UID fehlt. Bitte sie in MailWizz in der Listenübersicht unter „Unique ID“ ablesen und eintragen.'),
            'list_uid.string' => __('Die Listen-UID muss ein Text sein. Bitte sie aus MailWizz kopieren und neu einfügen.'),
            'list_uid.max' => __('Die Listen-UID ist zu lang (höchstens :max Zeichen). Bitte sie in MailWizz in der Listenübersicht unter „Unique ID“ kopieren.', ['max' => $uidMax]),
            'enabled.boolean' => __('Der Schalter für die Übertragung hat einen ungültigen Wert. Bitte die Seite neu laden und noch einmal speichern.'),
        ]);

        $connection = $this->connection() ?? new IntegrationConnection([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => self::PROVIDER,
        ]);

        $credentials = $this->credentials($connection);
        $credentials['api_url'] = $validated['api_url'];
        $credentials['list_uid'] = $validated['list_uid'];
        if (! empty($validated['api_key'])) {
            $credentials['api_key'] = $validated['api_key'];
        }

        if (empty($credentials['api_key'])) {
            return $this->failed($request, 'api_key', __('Der API-Key fehlt. Bitte den API-Key aus MailWizz eintragen (in MailWizz unter Account → API).'));
        }

        // Ohne Angabe zum Schalter bleibt der Zustand einer bestehenden Anbindung: ausgeschaltet bleibt ausgeschaltet.
        // Eine neue Anbindung startet eingeschaltet.
        $enabled = $request->filled('enabled')
            ? $request->boolean('enabled')
            : ! $connection->exists || $connection->status !== 'disconnected';

        $connection->credentials_encrypted = Crypt::encryptString((string) json_encode($credentials));
        $connection->status = $enabled ? 'connected' : 'disconnected';
        // Ein alter Fehlergrund gehört zur alten Eingabe.
        $settings = Arr::except($connection->settings ?? [], 'last_error');
        $connection->settings = $settings === [] ? null : $settings;
        $connection->save();

        $this->audit->log('integration.mailwizz_updated', $connection, null, [
            'api_url' => $validated['api_url'],
            'list_uid' => $validated['list_uid'],
            'status' => $connection->status,
        ]);

        if ($connection->status !== 'connected') {
            return $this->saved($request, __('MailWizz-Integration gespeichert. Die Synchronisierung ist ausgeschaltet.'), reload: true);
        }

        // Den Zugang gleich prüfen, damit eine falsche Eingabe sofort auffällt.
        $provider = new MailwizzProvider($credentials['api_url'], $credentials['api_key'], $credentials['list_uid']);
        try {
            $response = $provider->testConnection();
        } catch (OutboundUrlBlocked $e) {
            // Zwischen der Prüfung beim Speichern und dem Verbindungstest kann der Name auf eine andere Adresse
            // zeigen; der Test prüft selbst.
            return $this->testFailed($request, $connection, $e->getMessage());
        } catch (Throwable) {
            return $this->testFailed($request, $connection, __('MailWizz ist unter dieser Adresse nicht erreichbar. Bitte die API-URL prüfen oder es später erneut versuchen.'));
        }

        if (! $response->successful()) {
            return $this->testFailed($request, $connection, $this->testFailure($response->status()));
        }

        return $this->saved($request, __('MailWizz-Integration gespeichert und Verbindung getestet.'), reload: true);
    }

    /**
     * Anbindung auf „Fehler“ setzen und den Grund behalten: Die Meldung zum Speichern verschwindet, der Grund steht
     * danach weiter an der Anbindung und auf der Seite.
     */
    private function testFailed(Request $request, IntegrationConnection $connection, string $message): mixed
    {
        $connection->update([
            'status' => 'error',
            'settings' => array_merge($connection->settings ?? [], ['last_error' => $message]),
        ]);

        return $this->failed($request, 'api_url', $message);
    }

    /**
     * Was eine Antwort außerhalb von 2xx beim Verbindungstest bedeutet. Weiterleitungen folgt der Test bewusst nicht:
     * Ihr Ziel hat niemand geprüft.
     */
    private function testFailure(int $status): string
    {
        return match (true) {
            $status >= 300 && $status < 400 => __('MailWizz leitet die Anfrage an eine andere Adresse weiter (Status :status). Bitte die Adresse eintragen, auf die MailWizz weiterleitet.', ['status' => $status]),
            $status === 401, $status === 403 => __('MailWizz lehnt den API-Key ab (Status :status). Bitte den API-Key in MailWizz prüfen und neu eintragen.', ['status' => $status]),
            $status === 404 => __('MailWizz findet die Liste unter dieser Adresse nicht (Status 404). Bitte API-URL und Listen-UID prüfen.'),
            default => __('Verbindungstest fehlgeschlagen (Status :status). Bitte API-URL, API-Key und Listen-UID prüfen.', ['status' => $status]),
        };
    }

    private function connection(): ?IntegrationConnection
    {
        return IntegrationConnection::whereNull('location_id')
            ->where('provider', self::PROVIDER)
            ->first();
    }

    /**
     * Entschlüsselte Zugangsdaten; unlesbare Daten (etwa nach einem Wechsel
     * des App-Schlüssels) zählen als nicht eingerichtet.
     *
     * @return array<string, mixed>
     */
    private function credentials(?IntegrationConnection $connection): array
    {
        if (! $connection?->credentials_encrypted) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($connection->credentials_encrypted), true) ?: [];
        } catch (DecryptException) {
            return [];
        }
    }

    private function statusLabel(?string $status): string
    {
        return match ($status) {
            'connected' => __('Verbunden'),
            'disconnected' => __('Ausgeschaltet'),
            'error' => __('Fehler beim letzten Test'),
            default => __('Nicht eingerichtet'),
        };
    }
}
