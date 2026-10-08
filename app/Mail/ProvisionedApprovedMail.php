<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** „Du bist freigeschaltet“: Der Betrieb nimmt ab jetzt Online-Reservierungen an. */
class ProvisionedApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $tenantName,
        public readonly string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Freigeschaltet: '.$this->tenantName.' – Swayy');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.provisioning-approved');
    }
}
