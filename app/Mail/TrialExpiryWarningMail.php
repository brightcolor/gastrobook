<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TrialExpiryWarningMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $recipientName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: MailFrameFactory::ownerDu() ? 'Deine Testphase endet bald – Swayy' : 'Ihre Testphase endet bald – Swayy',
        );
    }

    public function content(): Content
    {
        return $this->framedContent(
            view('mail.trial-expiry-warning', [
                'tenant' => $this->tenant,
                'recipientName' => $this->recipientName,
                'du' => MailFrameFactory::ownerDu(),
                'support' => config('services.support_email') ?: config('mail.from.address'),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_trial_expiry', ['tenant' => (string) $this->tenant->name]),
        );
    }
}
