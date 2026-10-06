<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestLinkMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $intro,
        public string $url,
        public string $buttonLabel,
        public ?string $fromName = null,
        public ?string $replyToAddress = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Absenderadresse bleibt MAIL_FROM_ADDRESS (SPF/DKIM), nur der
            // Anzeigename ist der des Betriebs.
            from: new Address(
                config('mail.from.address'),
                $this->fromName ?: config('mail.from.name'),
            ),
            subject: $this->subjectLine,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.guest-link');
    }
}
