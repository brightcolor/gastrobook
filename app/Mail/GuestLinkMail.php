<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GuestLinkMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $intro,
        public string $url,
        public string $buttonLabel,
        public ?string $fromName = null,
        public ?string $replyToAddress = null,
        public ?MailFrame $frame = null,
        public string $note = '',
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
        $text = $this->intro."\n\n".$this->buttonLabel.': '.$this->url
            .($this->note !== '' ? "\n\n---\n\n".$this->note : '');

        return $this->framedContent($text, $this->frame ?? app(MailFrameFactory::class)->forGuest('guest_login', null, null));
    }
}
