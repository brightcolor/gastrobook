<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\BillingRequestConfirmMail;
use App\Mail\GuestLinkMail;
use App\Mail\Layout\MailFrameFactory;
use App\Mail\PasswordResetMail;
use App\Mail\ProvisionedWelcomeMail;
use App\Mail\TemplatedMail;
use App\Mail\TrialExpiryWarningMail;
use App\Models\BillingRequest;
use App\Models\Location;
use App\Models\LocationSettings;
use App\Models\Tenant;
use App\Services\NotificationTemplateRenderer;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/**
 * Schreibt jede Mailart mit Beispieldaten als HTML und Text (optional .eml)
 * zum Ansehen, für Bildschirmfotos und die Spam-Prüfung. Verschickt nichts
 * an echte Empfänger und braucht keine Datenbank-Einträge.
 */
class MailPreview extends Command
{
    protected $signature = 'mail:vorschau {--ziel= : Ordner für die Dateien (Vorgabe storage/app/mail-vorschau)} {--eml : zusätzlich vollständige .eml-Dateien schreiben}';

    protected $description = 'Schreibt jede Mailart mit Beispieldaten als Datei, ohne etwas zu verschicken.';

    public function handle(MailFrameFactory $frames): int
    {
        // Nur im Speicher: Rendern und .eml laufen nie über den eingestellten
        // Versandweg, auch wenn dieser gerade nicht erreichbar ist.
        config(['mail.default' => 'array']);

        $ziel = (string) ($this->option('ziel') ?: storage_path('app/mail-vorschau'));
        File::ensureDirectoryExists($ziel);

        foreach ($this->samples($frames) as $art => $mail) {
            /** @var array{0: string, 1: string} $rendered */
            $rendered = (fn () => $this->renderForAssertions())->call($mail);
            File::put("{$ziel}/{$art}.html", $rendered[0]);
            File::put("{$ziel}/{$art}.txt", $rendered[1]);
            if ($this->option('eml')) {
                File::put("{$ziel}/{$art}.eml", $this->eml($mail));
            }
            $this->line("{$art}: geschrieben");
        }

        $this->info("Fertig: {$ziel}");

        return self::SUCCESS;
    }

    /** @return array<string, Mailable> */
    private function samples(MailFrameFactory $frames): array
    {
        [$tenant, $du] = $this->demo('du');
        [$tenantSie, $sie] = $this->demo('Sie');
        $vars = [
            'guest_name' => 'Testgast Claude', 'reservation_date' => '24.11.2026', 'reservation_time' => '18:00',
            'party_size' => '2', 'location_name' => 'Trattoria Luna', 'reservation_code' => 'R-CIHHL2',
            'cancel_link' => url('/reservation/R-CIHHL2/manage/beispiel'), 'payment_link' => url('/pay/R-CIHHL2/beispiel'),
            'payment_amount' => '20,00 EUR', 'payment_deadline' => '20.11.2026 18:00', 'waitlist_link' => url('/warteliste/1/beispiel'),
            'feedback_link' => url('/feedback/beispiel'), 'custom_message' => '', 'table_name' => 'Tisch 7',
        ];
        $replace = [];
        foreach ($vars as $key => $value) {
            $replace['{'.$key.'}'] = $value;
        }
        $vorlage = function (string $key, string $address, Tenant $t, Location $l) use ($vars, $replace, $frames): TemplatedMail {
            $tpl = NotificationTemplateRenderer::defaults($address)[$key];
            $body = strtr(NotificationTemplateRenderer::dropEmptyLines($tpl['body'], $vars), $replace);

            return new TemplatedMail(strtr($tpl['subject'], $replace), $body, 'Trattoria Luna', null, $frames->forGuest($key, $t, $l));
        };
        $unsubscribe = url('/marketing/abmelden/1?signature=beispiel');
        $trialTenant = new Tenant(['name' => 'Trattoria Luna']);
        $trialTenant->trial_ends_at = now()->addDays(5);

        return [
            'reservation_confirmed' => $vorlage('reservation_confirmed', 'du', $tenant, $du),
            'reservation_requested' => $vorlage('reservation_requested', 'Sie', $tenantSie, $sie),
            'payment_pending' => $vorlage('payment_pending', 'du', $tenant, $du),
            'waitlist_offer' => $vorlage('waitlist_offer', 'du', $tenant, $du),
            'reservation_table_changed' => new TemplatedMail('Tischänderung zu deiner Reservierung R-CIHHL2 – Trattoria Luna',
                "Hallo Testgast Claude,\n\nwir haben dir einen anderen Tisch zugewiesen, damit alles gut passt. Sonst bleibt deine Reservierung, wie sie ist.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\nTisch: Tisch 9\nReservierungsnummer: R-CIHHL2\n\nBei Fragen melde dich gern.\nTrattoria Luna",
                'Trattoria Luna', null, $frames->forGuest('reservation_table_changed', $tenant, $du)),
            'event_booking_confirmed' => new TemplatedMail('Buchungsbestätigung: Trüffelabend',
                "Hallo Testgast Claude,\n\nfür „Trüffelabend“ ist alles gebucht. Wir freuen uns auf dich.\n\nDatum: 05.12.2026\nUhrzeit: 19:00 Uhr\nTickets: 2\nBuchungsnummer: E-4711\nBetrag: 89,00 EUR\n\nJetzt bezahlen: ".url('/pay/event/E-4711/beispiel')."\n\nDie Vorauszahlung verrechnen wir bei deinem Besuch vollständig mit der Rechnung. Bei Nichterscheinen behalten wir sie ein.\n\nBuchung ansehen oder stornieren: ".url('/events/E-4711/beispiel')."\n\nTrattoria Luna",
                'Trattoria Luna', null, $frames->forGuest('event_booking_confirmed', $tenant, $du)),
            'guest_verify' => new GuestLinkMail('Bitte bestätige deine E-Mail-Adresse', "Hallo Testgast Claude,\n\nein Klick noch, dann ist deine Buchung R-CIHHL2 bei uns.",
                url('/konto/verify/beispiel'), 'E-Mail-Adresse bestätigen', 'Trattoria Luna', null, $frames->forGuest('guest_verify', $tenant, $du),
                'Der Link gilt 24 Stunden. Hast du nicht bei uns reserviert, kannst du diese Mail löschen.'),
            'guest_login' => new GuestLinkMail('Ihr Anmeldelink für Trattoria Luna', "Hallo Testgast Claude,\n\nhier ist Ihr Anmeldelink für Ihr Kundenkonto bei Trattoria Luna.",
                url('/konto/luna/login/beispiel'), 'Jetzt anmelden', 'Trattoria Luna', null, $frames->forGuest('guest_login', $tenantSie, $sie),
                'Der Link gilt 1 Stunde. Haben Sie ihn nicht angefordert, können Sie diese Mail löschen.'),
            'marketing' => new TemplatedMail('Neue Karte ab Freitag', "Hallo Testgast Claude,\n\nab Freitag gibt es Trüffel aus dem Piemont.\n\nTisch reservieren: ".url('/book/luna')."\n\n---\nKeine Werbung mehr von uns? Hier abmelden: {$unsubscribe}",
                'Trattoria Luna', null, $frames->forGuest('marketing', $tenant, $du, [], $unsubscribe)),
            'operator_new_reservation' => new TemplatedMail('Neue Reservierung – 24.11. 18:00 · Testgast Claude (2 P.)',
                "Eine neue Reservierung ist eingegangen. Sie wartet auf deine Bestätigung.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\nPersonen: 2\nTisch: Tisch 7\nStatus: Angefragt\nReservierungsnummer: R-CIHHL2\n\nGast: Testgast Claude\nE-Mail: claude-testbuchung@example.test\nTelefon: +49 151 00000000\nQuelle: Online\nGastnotiz: Fensterplatz, wenn möglich\n\nReservierung öffnen: ".url('/admin/reservations/1'),
                null, null, $frames->forSwayy('operator_new_reservation', ['location' => 'Trattoria Luna'])),
            'operator_guest_reply' => new TemplatedMail('Antwort von Testgast Claude: Allergie',
                "Antwort zur Buchung R-CIHHL2 vom 24.11.2026:\n\nHallo, ich habe eine Nussallergie.\nViele Grüße",
                null, null, $frames->forSwayy('operator_guest_reply', ['guest' => 'Testgast Claude', 'location' => 'Trattoria Luna'], structured: false)),
            'swayy_password_reset' => new PasswordResetMail(url('/passwort/neu/beispiel')),
            'swayy_trial_expiry' => new TrialExpiryWarningMail($trialTenant, 'Olivia'),
            'swayy_billing_confirm' => $this->billingConfirm(),
            'swayy_provisioned_welcome' => new ProvisionedWelcomeMail('Olivia', 'Trattoria Luna', true, url('/passwort'), url('/login'), (string) (config('provisioning.panel_base_url') ?: url('/'))),
            'swayy_notice' => new TemplatedMail('Lastschrift eingerichtet – Trattoria Luna', "Hallo,\n\ndas SEPA-Lastschriftmandat für „Trattoria Luna“ ist eingerichtet. Die erste Abbuchung erfolgt zum nächsten Abrechnungstermin.\n\nTarif: Starter\nBetrag: 29,00 EUR im Monat"),
        ];
    }

    private function billingConfirm(): Mailable
    {
        $request = new BillingRequest(['contact_name' => 'Olivia Owner', 'plan_key' => 'starter']);
        $request->token = 'beispiel';
        $request->setRelation('tenant', new Tenant(['name' => 'Trattoria Luna']));

        return new BillingRequestConfirmMail($request);
    }

    /** @return array{0: Tenant, 1: Location} */
    private function demo(string $address): array
    {
        $tenant = new Tenant(['name' => 'Trattoria Luna GmbH', 'imprint_url' => url('/impressum-beispiel'), 'privacy_url' => url('/datenschutz-beispiel')]);
        $location = new Location([
            'name' => 'Trattoria Luna', 'address_line1' => 'Beispielstraße 12', 'postal_code' => '10115', 'city' => 'Berlin',
            'phone' => '030 1234567', 'email' => 'luna@example.test',
        ]);
        $location->setRelation('tenant', $tenant);
        $location->setRelation('settings', new LocationSettings(['guest_address' => $address]));

        return [$tenant, $location];
    }

    /** Vollständige Mail als Text; geht nur über den Mailer „array“ (Speicher). */
    private function eml(Mailable $mail): string
    {
        $mailer = Mail::mailer('array');
        if (! $mailer instanceof Mailer) {
            return '';
        }
        $mail->to('gast@example.test', 'Testgast Claude');
        // sendNow: Queue-fähige Mails sonst nur in die Warteschlange.
        $mailer->sendNow($mail);
        $transport = $mailer->getSymfonyTransport();

        return $transport instanceof ArrayTransport ? (string) $transport->messages()->last()?->toString() : '';
    }
}
