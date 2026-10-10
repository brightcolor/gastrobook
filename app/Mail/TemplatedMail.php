<?php

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrame;
use App\Mail\Layout\MailFrameFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class TemplatedMail extends Mailable implements ShouldQueue
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $mailBody,
        public readonly ?string $fromName = null,
        public readonly ?string $replyToAddress = null,
        public readonly ?MailFrame $frame = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Keep the authenticated MAIL_FROM_ADDRESS (so SPF/DKIM stay aligned),
            // but use the tenant's name as the display name instead of the generic
            // global name — a generic/mismatched sender is a spam signal.
            from: new Address(
                config('mail.from.address'),
                $this->fromName ?: config('mail.from.name'),
            ),
            subject: $this->mailSubject,
            replyTo: $this->replyToAddress ? [new Address($this->replyToAddress)] : [],
        );
    }

    public function content(): Content
    {
        return $this->framedContent($this->mailBody, $this->resolvedFrame());
    }

    public function headers(): Headers
    {
        return $this->framedHeaders($this->resolvedFrame());
    }

    /**
     * Ohne eigenen Rahmen ist die Mail eine Meldung von Swayy (etwa zur
     * Lastschrift); die Überschrift ist der Betreff ohne „ – Swayy“.
     */
    public function resolvedFrame(): MailFrame
    {
        return $this->frame ?? app(MailFrameFactory::class)->forSwayy('swayy_notice', [
            'subject' => (string) preg_replace('/\s+–\s+Swayy$/u', '', $this->mailSubject),
        ]);
    }
}
