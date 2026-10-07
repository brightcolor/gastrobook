<?php

namespace Tests\Feature;

use App\Mail\ForwardedGuestReplyMail;
use Illuminate\Mail\Mailables\Attachment;
use Tests\TestCase;

class ForwardedGuestReplyMailTest extends TestCase
{
    public function test_envelope_sets_reply_to_guest_and_attaches(): void
    {
        $mail = new ForwardedGuestReplyMail(
            forwardSubject: 'Antwort von Max: Re: Reservierung',
            forwardBody: 'Können wir verschieben?',
            fromName: 'Bäckerei Müller',
            guestReplyTo: 'gast@example.test',
            forwardedAttachments: [['name' => 'notiz.txt', 'mime' => 'text/plain', 'data' => 'Hallo']],
        );

        $envelope = $mail->envelope();
        $this->assertSame('gast@example.test', $envelope->replyTo[0]->address);

        $mail->assertHasAttachment(
            Attachment::fromData(fn () => 'Hallo', 'notiz.txt')->withMime('text/plain')
        );
    }
}
