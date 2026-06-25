<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrialExpiryWarningMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Ihr Testzeitraum endet in 5 Tagen – Swayy',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.trial-expiry-warning');
    }
}
