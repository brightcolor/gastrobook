<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Services\AuditLogger;
use App\Support\IntegrationStatus;
use App\Support\TenantContext;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\View\View;

/**
 * Einstellungen › Zahlungen › Zahlungsanbieter: Zugang zu Stripe und PayPal.
 * Gilt für alle Standorte. Die Schlüssel liegen verschlüsselt in der
 * Verbindung und erscheinen nie wieder in einer Seite; ein leeres Feld behält
 * den gespeicherten Wert. Nur Verweise auf das Konto des Betriebs werden
 * gespeichert, Kartendaten berühren Swayy nie.
 */
class PaymentProvidersController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $stripe = $this->connection('stripe');
        $paypal = $this->connection('paypal');
        $stripeCredentials = $this->credentials($stripe);
        $paypalCredentials = $this->credentials($paypal);
        $stripeReady = $this->filled($stripeCredentials, 'secret_key') && $this->filled($stripeCredentials, 'webhook_secret');
        $paypalReady = $this->filled($paypalCredentials, 'client_id') && $this->filled($paypalCredentials, 'secret');

        // Wann sich der Stripe-Webhook zuletzt gemeldet hat. Ob der Endpunkt drüben wirklich eingerichtet ist, lässt sich
        // von hier aus nicht abfragen, ob je einer angekommen ist, schon. Die Uhrzeit steht in der Zeit des Standorts.
        $seen = AuditLog::where('tenant_id', $tenant->id)
            ->where('action', 'payment.webhook_received')
            ->latest('id')
            ->value('created_at');
        $zone = $this->context->location()?->timezone;
        if ($seen !== null && $zone) {
            $seen = $seen->copy()->setTimezone($zone);
        }

        return view('admin.settings.payment-providers', [
            'stripe' => $stripe,
            'paypal' => $paypal,
            // Zugangsdaten verlassen den Controller nur als Ja/Nein; der PayPal-Modus ist kein Geheimnis.
            'stripeHasSecret' => $this->filled($stripeCredentials, 'secret_key'),
            'stripeHasWebhookSecret' => $this->filled($stripeCredentials, 'webhook_secret'),
            'paypalHasClient' => $this->filled($paypalCredentials, 'client_id'),
            'paypalHasSecret' => $this->filled($paypalCredentials, 'secret'),
            'paypalMode' => in_array($paypalCredentials['mode'] ?? null, ['sandbox', 'live'], true) ? $paypalCredentials['mode'] : 'live',
            'stripeStatus' => $this->status($stripe, $stripeReady),
            'paypalStatus' => $this->status($paypal, $paypalReady),
            'stripeWebhookSeen' => $seen,
            'webhookUrl' => route('webhooks.stripe'),
            'limits' => (array) config('swayy.payments'),
        ]);
    }

    /**
     * Stripe-Zugang (für alle Standorte). Gespeichert werden nur Verweise auf das Konto des Betriebs, verschlüsselt;
     * Kartendaten berühren dieses System nie.
     */
    public function updateStripe(Request $request): mixed
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate($this->secretRules(['secret_key', 'webhook_secret']) + [
            // Die neue Seite und die alte Sammelseite senden eine 0 vor dem Kästchen; Aufrufer ohne Angabe lassen die Anbindung eingeschaltet.
            'enabled' => ['nullable', 'boolean'],
        ], $this->secretMessages([
            'secret_key' => [__('Der Secret Key'), __('Bitte ihn im Stripe-Dashboard unter Developers → API keys kopieren und ohne Leerzeichen einfügen.')],
            'webhook_secret' => [__('Das Webhook-Signing-Secret'), __('Bitte es im Stripe-Dashboard beim Webhook-Endpunkt kopieren und ohne Leerzeichen einfügen.')],
        ]));

        $connection = $this->connection('stripe') ?? new IntegrationConnection([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => 'stripe',
        ]);

        $credentials = $this->credentials($connection);
        foreach (['secret_key', 'webhook_secret'] as $field) {
            if (! empty($validated[$field])) {
                $credentials[$field] = $validated[$field];
            }
        }

        $missing = array_filter([
            'secret_key' => empty($credentials['secret_key'])
                ? __('Der Secret Key fehlt. Bitte ihn aus dem Stripe-Dashboard unter Developers → API keys eintragen.')
                : null,
            'webhook_secret' => empty($credentials['webhook_secret'])
                ? __('Das Webhook-Signing-Secret fehlt. Bitte es im Stripe-Dashboard beim Webhook-Endpunkt abrufen und eintragen.')
                : null,
        ]);
        if ($missing !== []) {
            return $this->rejected($request, $missing);
        }

        $connection->credentials_encrypted = Crypt::encryptString((string) json_encode($credentials));
        $connection->status = $request->boolean('enabled', true) ? 'connected' : 'disconnected';
        $connection->save();

        $this->audit->log('integration.stripe_updated', $connection, null, ['status' => $connection->status]);

        if ($connection->status !== 'connected') {
            return $this->saved($request, __('Stripe ausgeschaltet. Die Schlüssel bleiben gespeichert.'), reload: true);
        }

        return $this->saved($request, __('Stripe-Integration gespeichert. Webhook-URL: :url', [
            'url' => route('webhooks.stripe'),
        ]), reload: true);
    }

    /**
     * PayPal-Zugang (für alle Standorte). Gespeichert werden nur Zugangsdaten der App, verschlüsselt; das Geld geht auf
     * das PayPal-Konto des Betriebs.
     */
    public function updatePaypal(Request $request): mixed
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $validated = $request->validate($this->secretRules(['client_id', 'secret']) + [
            'mode' => ['required', 'in:sandbox,live'],
            // Wie bei Stripe: Beide Formulare senden eine 0 vor dem Kästchen, Aufrufer ohne Angabe lassen die Anbindung eingeschaltet.
            'enabled' => ['nullable', 'boolean'],
        ], $this->secretMessages([
            'client_id' => [__('Die Client-ID'), __('Bitte sie im PayPal Developer Dashboard unter Apps & Credentials kopieren und ohne Leerzeichen einfügen.')],
            'secret' => [__('Das Secret'), __('Bitte es im PayPal Developer Dashboard unter Apps & Credentials kopieren und ohne Leerzeichen einfügen.')],
        ]) + [
            'mode.required' => __('Bitte „Test (Sandbox)“ oder „Live“ wählen.'),
            'mode.in' => __('Bitte „Test (Sandbox)“ oder „Live“ wählen.'),
        ]);

        $connection = $this->connection('paypal') ?? new IntegrationConnection([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => 'paypal',
        ]);

        $credentials = $this->credentials($connection);
        foreach (['client_id', 'secret'] as $field) {
            if (! empty($validated[$field])) {
                $credentials[$field] = $validated[$field];
            }
        }
        $credentials['mode'] = $validated['mode'];

        $missing = array_filter([
            'client_id' => empty($credentials['client_id'])
                ? __('Die Client-ID fehlt. Bitte sie aus dem PayPal Developer Dashboard unter Apps & Credentials eintragen.')
                : null,
            'secret' => empty($credentials['secret'])
                ? __('Das Secret fehlt. Bitte es aus dem PayPal Developer Dashboard unter Apps & Credentials eintragen.')
                : null,
        ]);
        if ($missing !== []) {
            return $this->rejected($request, $missing);
        }

        $connection->credentials_encrypted = Crypt::encryptString((string) json_encode($credentials));
        $connection->status = $request->boolean('enabled', true) ? 'connected' : 'disconnected';
        $connection->save();

        $this->audit->log('integration.paypal_updated', $connection, null, [
            'mode' => $credentials['mode'],
            'status' => $connection->status,
        ]);

        if ($connection->status !== 'connected') {
            return $this->saved($request, __('PayPal ausgeschaltet. Die Zugangsdaten bleiben gespeichert.'), reload: true);
        }

        return $this->saved($request, __('PayPal-Integration gespeichert.'), reload: true);
    }

    /**
     * @param  list<string>  $fields
     * @return array<string, list<string>>
     */
    private function secretRules(array $fields): array
    {
        $rules = [];
        foreach ($fields as $field) {
            $rules[$field] = ['nullable', 'string', 'max:'.(int) config('swayy.payments.secret_max')];
        }

        return $rules;
    }

    /**
     * Meldungen zu Text und Länge der Schlüssel und zum Schalter.
     *
     * @param  array<string, array{0: string, 1: string}>  $fields  Feld => [Satzanfang („Der Secret Key“), nächster Schritt]
     * @return array<string, string>
     */
    private function secretMessages(array $fields): array
    {
        $messages = [];
        foreach ($fields as $field => [$subject, $nextStep]) {
            $messages[$field.'.string'] = __(':subject muss ein Text sein. :step', ['subject' => $subject, 'step' => $nextStep]);
            $messages[$field.'.max'] = __(':subject ist zu lang (höchstens :max Zeichen). :step', [
                'subject' => $subject,
                'max' => (int) config('swayy.payments.secret_max'),
                'step' => $nextStep,
            ]);
        }
        $messages['enabled.boolean'] = __('Der Schalter hat einen ungültigen Wert. Bitte die Seite neu laden und noch einmal speichern.');

        return $messages;
    }

    private function connection(string $provider): ?IntegrationConnection
    {
        return IntegrationConnection::whereNull('location_id')
            ->where('provider', $provider)
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

    /**
     * @param  array<string, mixed>  $credentials
     */
    private function filled(array $credentials, string $key): bool
    {
        return ($credentials[$key] ?? '') !== '';
    }

    /**
     * Zustand für die Statuszeile: Symbol und Wort. Ohne vollständige, lesbare Zugangsdaten gilt der Anbieter als
     * nicht eingerichtet, was auch in der Verbindung steht.
     *
     * @return array{state: string, label: string}
     */
    private function status(?IntegrationConnection $connection, bool $ready): array
    {
        return match (true) {
            $connection === null || ! $ready => ['state' => 'off', 'label' => IntegrationStatus::NOT_SET_UP],
            $connection->status === 'connected' => ['state' => 'ok', 'label' => IntegrationStatus::label('connected')],
            default => ['state' => 'off', 'label' => IntegrationStatus::label('disconnected')],
        };
    }
}
