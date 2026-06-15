<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Passwort zurücksetzen – Swayy'));
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset');
    }
}
