<?php

namespace Tests\Unit;

use App\Services\Mail\InboundMessage;
use Tests\TestCase;

class InboundMessageTest extends TestCase
{
    public function test_parses_text_and_attachment(): void
    {
        $raw = implode("\r\n", [
            'From: Max Gast <gast@example.test>',
            'Subject: Re: Ihre Reservierung',
            'Message-ID: <abc@example.test>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/mixed; boundary="b"',
            '',
            '--b',
            'Content-Type: text/plain; charset=utf-8',
            '',
            'Können wir auf 20 Uhr verschieben?',
            '--b',
            'Content-Type: text/plain; name="notiz.txt"',
            'Content-Disposition: attachment; filename="notiz.txt"',
            '',
            'Hallo',
            '--b--',
            '',
        ]);

        $m = InboundMessage::fromRaw($raw);
        $this->assertSame('gast@example.test', $m->fromEmail);
        $this->assertSame('Max Gast', $m->fromName);
        $this->assertStringContainsString('20 Uhr', $m->textBody);
        $this->assertSame('<abc@example.test>', $m->messageId);
        $this->assertFalse($m->isAutomatic);
        $this->assertCount(1, $m->attachments);
        $this->assertSame('notiz.txt', $m->attachments[0]['name']);
        $this->assertSame('text/plain', $m->attachments[0]['mime']);
    }

    public function test_detects_auto_submitted(): void
    {
        config(['swayy.guest_mail_relay.auto_mail_headers' => ['Auto-Submitted']]);
        $raw = "From: x@example.test\r\nAuto-Submitted: auto-replied\r\nSubject: Abwesend\r\n\r\nWeg.";
        $this->assertTrue(InboundMessage::fromRaw($raw)->isAutomatic);
    }

    public function test_decodes_base64_body(): void
    {
        $raw = "From: x@example.test\r\nSubject: Hi\r\nContent-Type: text/plain; charset=utf-8\r\n"
            ."Content-Transfer-Encoding: base64\r\n\r\n".base64_encode('Grüße vom Gast');
        $this->assertStringContainsString('Grüße', InboundMessage::fromRaw($raw)->textBody);
    }
}
