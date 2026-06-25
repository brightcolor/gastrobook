<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\BillingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the customer to verify their e-mail address. */
class BillingRequestConfirmMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $confirmUrl;

    public function __construct(public readonly BillingRequest $billingRequest)
    {
        $this->confirmUrl = route('billing.confirm', ['token' => $billingRequest->token]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Bitte bestätigen Sie Ihre Anfrage – Swayy',
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.billing-request-confirm');
    }
}
