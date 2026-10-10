<?php

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrameFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactRequestMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public string $messageText,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Kontaktanfrage über Swayy',
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        // Text von Besuchern der Website: gerahmt, ohne Tabelle und Knöpfe.
        return $this->framedContent(
            view('mail.contact-request', ['senderName' => $this->senderName, 'senderEmail' => $this->senderEmail, 'messageText' => $this->messageText])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_contact', structured: false),
        );
    }
}
