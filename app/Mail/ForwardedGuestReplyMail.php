<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
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
    use Queueable, RendersInFrame, SerializesModels;

    /** @param list<array{name:string,mime:string,data:string}> $forwardedAttachments */
    public function __construct(
        public readonly string $forwardSubject,
        public readonly string $forwardBody,
        public readonly ?string $fromName,
        public readonly string $guestReplyTo,
        public readonly array $forwardedAttachments = [],
        public readonly ?MailFrame $frame = null,
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
        // Text des Gastes: gerahmt, aber ohne Tabelle und Knöpfe (Spec, Review Focus 1).
        return $this->framedContent(
            $this->forwardBody,
            $this->frame ?? app(MailFrameFactory::class)->forSwayy('operator_guest_reply', ['guest' => $this->guestReplyTo], structured: false),
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
