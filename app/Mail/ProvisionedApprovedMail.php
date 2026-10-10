<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrameFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Der Betrieb ist freigeschaltet und nimmt ab jetzt Online-Reservierungen an. */
class ProvisionedApprovedMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly string $tenantName,
        public readonly string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Freigeschaltet: '.$this->tenantName.' – Swayy');
    }

    public function content(): Content
    {
        return $this->framedContent(
            view('mail.provisioning-approved', [
                'recipientName' => $this->recipientName,
                'tenantName' => $this->tenantName,
                'loginUrl' => $this->loginUrl,
                'du' => MailFrameFactory::ownerDu(),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_provisioned_approved', ['tenant' => $this->tenantName]),
        );
    }
}
