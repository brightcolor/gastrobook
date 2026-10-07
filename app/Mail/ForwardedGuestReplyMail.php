<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Leitet eine Gästeantwort an den Betrieb weiter. Absender bleibt
 * MAIL_FROM_ADDRESS (SPF/DKIM), Anzeigename ist der Betrieb. Reply-To ist die
 * Gastadresse: Der Betrieb antwortet direkt an den Gast.
 */
class ForwardedGuestReplyMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param list<array{name:string,mime:string,data:string}> $forwardedAttachments */
    public function __construct(
        public readonly string $forwardSubject,
        public readonly string $forwardBody,
        public readonly ?string $fromName,
        public readonly string $guestReplyTo,
        public readonly array $forwardedAttachments = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->fromName ?: config('mail.from.name')),
            replyTo: [new Address($this->guestReplyTo)],
            subject: $this->forwardSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.templated-text',
            with: ['body' => $this->forwardBody, 'fromName' => $this->fromName],
        );
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return array_map(
            fn (array $a) => Attachment::fromData(fn () => $a['data'], $a['name'])->withMime($a['mime']),
            $this->forwardedAttachments,
        );
    }
}
