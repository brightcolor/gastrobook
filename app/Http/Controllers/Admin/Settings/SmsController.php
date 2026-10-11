<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Services\AuditLogger;
use App\Services\Sms\SevenIoProvider;
use App\Support\IntegrationStatus;
use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;
use Throwable;

/**
 * Einstellungen › Gästekommunikation › SMS-Versand: Zugang zu seven.io. Gilt
 * für alle Standorte. Der API-Key liegt verschlüsselt in der Verbindung und
 * erscheint nie wieder in einer Seite; ein leeres Feld behält den gespeicherten
 * Wert. Beim Speichern prüft ein Abruf des Guthabens den Zugang.
 */
class SmsController extends Controller
{
    use RespondsToSettingsForms;

    private const PROVIDER = 'sevenio';

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

        return view('admin.settings.sms', [
            'connection' => $connection,
            // Nur die Absenderkennung verlässt die Zugangsdaten.
            'senderId' => (string) ($credentials['sender_id'] ?? ''),
            'hasKey' => $hasKey,
            'statusLabel' => IntegrationStatus::label($status),
            'statusState' => match ($status) {
                'connected' => 'ok',
                'error' => 'error',
                default => 'off',
            },
            'limits' => (array) config('swayy.sms'),
        ]);
    }

    public function update(Request $request): mixed
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate([
            'api_key' => ['nullable', 'string', 'max:'.(int) config('swayy.sms.api_key_max')],
            'sender_id' => ['nullable', 'bail', 'string', $this->senderIdLength(...)],
            // Beide Formulare senden eine 0 vor dem Kästchen. Fehlt der Schalter, bleibt der Zustand einer bestehenden Anbindung (IntegrationStatus::afterSave).
            'enabled' => ['nullable', 'boolean'],
        ], [
            'api_key.string' => __('Der API-Key muss ein Text sein. Bitte ihn aus app.seven.io kopieren und neu einfügen.'),
            'api_key.max' => __('Der API-Key ist zu lang. Bitte ihn aus app.seven.io kopieren und ohne Leerzeichen einfügen.'),
            'sender_id.string' => __('Die Absenderkennung muss ein Text sein. Bitte neu eintragen.'),
            'enabled.boolean' => __('Der Schalter für den SMS-Versand hat einen ungültigen Wert. Bitte die Seite neu laden und noch einmal speichern.'),
        ]);

        $connection = $this->connection() ?? new IntegrationConnection([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => self::PROVIDER,
        ]);

        $credentials = $this->credentials($connection);
        if (! empty($validated['api_key'])) {
            $credentials['api_key'] = $validated['api_key'];
        }
        // Ein gesendetes, leeres Feld löscht die Kennung; fehlt das Feld, bleibt sie.
        if ($request->has('sender_id')) {
            $credentials['sender_id'] = (string) ($validated['sender_id'] ?? '');
        }
        $credentials['sender_id'] ??= '';

        if (empty($credentials['api_key'])) {
            return $this->failed($request, 'api_key', __('Der API-Key fehlt. Bitte den API-Key aus seven.io eintragen (app.seven.io → Einstellungen → API).'));
        }

        $connection->credentials_encrypted = Crypt::encryptString((string) json_encode($credentials));
        $connection->status = IntegrationStatus::afterSave($request, $connection);
        $connection->save();

        $this->audit->log('integration.sms_updated', $connection, null, [
            'sender_id' => $credentials['sender_id'],
            'status' => $connection->status,
        ]);

        if ($connection->status !== 'connected') {
            return $this->saved($request, __('SMS-Versand ausgeschaltet. Der API-Key bleibt gespeichert.'), reload: true);
        }

        $provider = new SevenIoProvider($credentials['api_key'], $credentials['sender_id']);
        try {
            if (! $provider->testConnection()) {
                $connection->update(['status' => 'error']);

                return $this->failed($request, 'api_key', __('Gespeichert, aber seven.io hat den Verbindungstest abgelehnt. Bitte den API-Key unter app.seven.io → Einstellungen → API prüfen und neu eintragen.'));
            }
        } catch (Throwable) {
            $connection->update(['status' => 'error']);

            return $this->failed($request, 'api_key', __('Gespeichert, aber seven.io war beim Verbindungstest nicht erreichbar. Bitte später noch einmal auf „Speichern“ klicken, um die Verbindung zu testen.'));
        }

        return $this->saved($request, __('SMS-Versand gespeichert. Die Verbindung zu seven.io funktioniert.'), reload: true);
    }

    /**
     * Länge der Absenderkennung je Art: Eine Rufnummer (nur Ziffern, auf Wunsch
     * mit führendem +) darf bis zu sender_id_max Ziffern haben, jede andere
     * Kennung höchstens sender_id_text_max Zeichen.
     *
     * @param  Closure(string): mixed  $fail
     */
    private function senderIdLength(string $attribute, mixed $value, Closure $fail): void
    {
        $value = (string) $value;
        $numberMax = (int) config('swayy.sms.sender_id_max');
        $textMax = (int) config('swayy.sms.sender_id_text_max');

        if (preg_match('/^\+?\d+$/', $value) === 1) {
            if (strlen(ltrim($value, '+')) > $numberMax) {
                $fail(__('Die Rufnummer als Absenderkennung darf höchstens :max Ziffern haben. Bitte kürzen.', ['max' => $numberMax]));
            }

            return;
        }

        if (mb_strlen($value) > $textMax) {
            $fail(__('Die Absenderkennung mit Buchstaben darf höchstens :text Zeichen haben. Bitte kürzen oder eine Rufnummer mit bis zu :max Ziffern eintragen.', ['text' => $textMax, 'max' => $numberMax]));
        }
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
}
