<?php

declare(strict_types=1);

namespace App\Mail;

use App\Mail\Concerns\RendersInFrame;
use App\Mail\Layout\MailFrameFactory;
use App\Models\BillingRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent to the platform owner once the customer confirmed their e-mail. */
class BillingRequestOwnerMail extends Mailable
{
    use Queueable, RendersInFrame, SerializesModels;

    public string $activateUrl;

    public function __construct(public readonly BillingRequest $billingRequest)
    {
        $this->activateUrl = route('admin.billing-requests.activate', $billingRequest->id);
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Neue Tarifanfrage: '.$this->billingRequest->tenant->name,
            replyTo: [new Address($this->billingRequest->contact_email, $this->billingRequest->contact_name)],
        );
    }

    public function content(): Content
    {
        return $this->framedContent(
            view('mail.billing-request-owner', ['billingRequest' => $this->billingRequest, 'activateUrl' => $this->activateUrl])->render(),
            app(MailFrameFactory::class)->forSwayy('swayy_billing_owner', ['tenant' => (string) $this->billingRequest->tenant->name]),
        );
    }
}
