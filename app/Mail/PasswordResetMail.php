<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Support\Duration;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('Passwort zurücksetzen – Swayy'));
    }

    public function content(): Content
    {
        $minutes = (int) config('auth.passwords.users.expire', 60);

        return $this->framedContent(
            view('mail.password-reset', ['url' => $this->url, 'du' => MailFrameFactory::ownerDu(), 'dauer' => Duration::minutes($minutes)])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_password_reset'),
        );
    }
}
