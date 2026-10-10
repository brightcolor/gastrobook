<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use Tests\TestCase;

class TemplatedMailTest extends TestCase
{
    public function test_uses_tenant_from_name_and_reply_to(): void
    {
        config(['mail.from.address' => 'no-reply@swayy.app', 'mail.from.name' => 'Swayy']);

        $mail = new TemplatedMail('Betreff', "Hallo\nText", 'Restaurant Sonne', 'kontakt@sonne.test');

        // Authenticated address stays, display name = tenant name.
        $mail->assertFrom('no-reply@swayy.app', 'Restaurant Sonne');
        $mail->assertHasReplyTo('kontakt@sonne.test');
        $mail->assertHasSubject('Betreff');
    }

    public function test_falls_back_to_global_name_without_tenant_name(): void
    {
        config(['mail.from.address' => 'no-reply@swayy.app', 'mail.from.name' => 'Swayy']);

        (new TemplatedMail('Betreff', 'Text'))->assertFrom('no-reply@swayy.app', 'Swayy');
    }

    public function test_sends_multipart_html_with_clickable_links_and_escaped_body(): void
    {
        $mail = new TemplatedMail('Betreff', "Hallo <b>Gast</b>\nLink: https://example.test/manage/abc", 'Restaurant Sonne');

        // Ohne eigenen Rahmen: Swayy-Meldung. Der Link zeigt auf einen fremden Host,
        // bleibt also anklickbarer Link im Text; Eingaben bleiben maskiert.
        $mail->assertSeeInHtml('alt="Swayy"', false);
        $mail->assertSeeInHtml('<a href="https://example.test/manage/abc"', false);
        $mail->assertSeeInHtml('&lt;b&gt;Gast&lt;/b&gt;', false);
        $mail->assertSeeInText('Link: https://example.test/manage/abc');
    }
}
