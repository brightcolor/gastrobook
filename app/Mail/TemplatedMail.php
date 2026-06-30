<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TemplatedMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $mailSubject,
        public readonly string $mailBody,
        public readonly ?string $fromName = null,
        public readonly ?string $replyToAddress = null,
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
        // Multipart text + HTML: a text-only / HTML-less mail scores worse with
        // consumer spam filters. The HTML part mirrors the text (escaped, links
        // made clickable) in a simple branded container.
        return new Content(
            text: 'mail.templated-text',
            html: 'mail.templated-html',
            with: ['body' => $this->mailBody, 'fromName' => $this->fromName],
        );
    }
}
