<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\AuditLogger;
use App\Services\GuestMailSender;
use App\Services\Mail\GuestReplyAddress;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Gästekommunikation › Absender & Antworten: Absendername und
 * Antwortadresse der Mails an Gäste, dazu die Antwortadresse über Swayy.
 * Leer heißt: Ersatz aus config/swayy.php (guest_mail), siehe GuestMailSender.
 * Gilt für alle Standorte.
 */
class SenderController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly GuestMailSender $mailSender,
    ) {}

    public function show(): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        [$fallbackName, $fallbackReply] = $this->fallbacks($tenant);

        return view('admin.settings.sender', [
            'tenant' => $tenant,
            'location' => $this->context->location(),
            'fallbackName' => $fallbackName,
            'fallbackReply' => $fallbackReply,
            'relayReady' => GuestReplyAddress::isConfigured(),
            'relayDomain' => (string) config('swayy.guest_mail_relay.domain'),
            'limits' => (array) config('swayy.guest_mail'),
        ]);
    }

    public function update(Request $request): mixed
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $nameMax = (int) config('swayy.guest_mail.from_name_max');
        $replyMax = (int) config('swayy.guest_mail.reply_to_max');

        $validated = $request->validate([
            'mail_from_name' => ['nullable', 'string', 'max:'.$nameMax, 'not_regex:/[<>@\r\n"]/'],
            'mail_reply_to' => ['nullable', 'email:rfc', 'max:'.$replyMax],
            // Die alte Sammelseite sendet das Kästchen nur angehakt.
            'mail_relay_enabled' => ['nullable', 'boolean'],
        ], [
            'mail_from_name.string' => __('Der Absendername muss ein Text sein. Bitte neu eintragen.'),
            'mail_from_name.max' => __('Der Absendername darf höchstens :max Zeichen haben. Bitte kürzen.'),
            'mail_from_name.not_regex' => __('Der Absendername darf keine Zeichen wie <, >, @ oder Anführungszeichen enthalten. Bitte nur den Namen des Betriebs eintragen.'),
            'mail_reply_to.email' => __('Die Antwortadresse ist keine gültige E-Mail-Adresse. Bitte so eintragen: info@dein-betrieb.de'),
            'mail_reply_to.max' => __('Die Antwortadresse darf höchstens :max Zeichen haben. Bitte kürzen.'),
            'mail_relay_enabled.boolean' => __('Der Schalter für die Antwortadresse über Swayy hat einen ungültigen Wert. Bitte die Seite neu laden und noch einmal speichern.'),
        ]);

        // Die Swayy-Antwortadresse lässt sich nur aktivieren, wenn die Domain
        // eingerichtet ist, sonst zeigte sie ins Leere.
        $relayOn = $request->boolean('mail_relay_enabled');
        if ($relayOn && ! GuestReplyAddress::isConfigured()) {
            return $this->failed($request, 'mail_relay_enabled', __('Die Antwortadresse über Swayy ist noch nicht eingerichtet (es fehlt die Domain). Bitte wende dich an den Betreiber, bevor du sie aktivierst.'));
        }

        $new = [
            'mail_from_name' => trim((string) ($validated['mail_from_name'] ?? '')) ?: null,
            'mail_reply_to' => trim((string) ($validated['mail_reply_to'] ?? '')) ?: null,
            'mail_relay_enabled' => $relayOn,
        ];
        $old = [
            'mail_from_name' => $tenant->mail_from_name,
            'mail_reply_to' => $tenant->mail_reply_to,
            'mail_relay_enabled' => (bool) $tenant->mail_relay_enabled,
        ];
        $tenant->update($new);

        $this->audit->log('tenant.guest_mail_updated', $tenant, $old, $new);

        return $this->saved($request, __('Absender und Antworten gespeichert.'), reload: true);
    }

    /**
     * Was ohne eigene Angabe gilt, damit die Felder nicht ins Leere zeigen.
     *
     * @return array{0: string, 1: ?string} Ersatzname und Ersatzadresse
     */
    private function fallbacks(Tenant $tenant): array
    {
        $withoutOwn = $tenant->replicate()->fill(['mail_from_name' => null, 'mail_reply_to' => null]);
        $location = $this->context->location();

        return [
            $this->mailSender->fromName($withoutOwn, $location) ?? (string) config('mail.from.name'),
            $this->mailSender->replyTo($withoutOwn, $location),
        ];
    }
}
