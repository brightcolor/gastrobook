<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Models\BillingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the customer to verify their e-mail address. */
class BillingRequestConfirmMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public string $confirmUrl;

    public function __construct(public readonly BillingRequest $billingRequest)
    {
        $this->confirmUrl = route('billing.confirm', ['token' => $billingRequest->token]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: MailFrameFactory::ownerDu() ? 'Bitte bestätige deine Anfrage – Swayy' : 'Bitte bestätigen Sie Ihre Anfrage – Swayy',
        );
    }

    public function content(): Content
    {
        return $this->framedContent(
            view('mail.billing-request-confirm', [
                'billingRequest' => $this->billingRequest,
                'confirmUrl' => $this->confirmUrl,
                'du' => MailFrameFactory::ownerDu(),
                'hours' => (int) config('swayy.billing_request.confirm_hours', 72),
            ])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_billing_confirm'),
        );
    }
}
