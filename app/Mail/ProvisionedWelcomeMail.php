<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrameFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Willkommen fuer einen Betrieb, den das Kundenpanel angelegt hat.
 *
 * Ein Link zum Passwort-Setzen liefe nach der Frist des Brokers ab (60
 * Minuten); die Mail fuehrt deshalb zu „Passwort vergessen“ und in den
 * Kundenbereich, beides ohne Ablauf.
 */
class ProvisionedWelcomeMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $tenantName,
        public readonly bool $waitsForApproval,
        public readonly string $passwordUrl,
        public readonly string $loginUrl,
        public readonly string $panelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Willkommen bei Swayy – '.$this->tenantName);
    }

    public function content(): Content
    {
        return $this->framedContent(
            view('mail.provisioning-welcome', [
                'recipientName' => $this->recipientName,
                'tenantName' => $this->tenantName,
                'waitsForApproval' => $this->waitsForApproval,
                'passwordUrl' => $this->passwordUrl,
                'loginUrl' => $this->loginUrl,
                'panelUrl' => $this->panelUrl,
                'du' => MailFrameFactory::ownerDu(),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_provisioned_welcome', ['tenant' => $this->tenantName]),
        );
    }
}
