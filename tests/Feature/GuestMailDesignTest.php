<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\Layout\Block;
use App\Mail\Layout\TextBlocks;
use App\Mail\TemplatedMail;
use App\Services\NotificationTemplateRenderer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_online_booking_mail_has_headline_details_and_button(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['guest_address' => 'du', 'require_email_confirmation' => false]);
        $this->clearTenantContext();
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(), 'time' => '19:00', 'party_size' => 2,
            'name' => 'Frau Kessler', 'email' => 'gast@example.test', 'phone' => '+49 451 123456', 'privacy_accepted' => '1',
        ])->assertRedirect();

        Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail): bool {
            $html = $mail->render();

            return in_array($mail->frame?->type, ['reservation_confirmed', 'reservation_requested'], true)
                && str_contains($html, '</h1>')
                && str_contains($html, 'class="panel"')
                && str_contains($html, 'class="btn"')
                && ! str_contains($mail->mailBody, '{');
        });
    }

    public function test_guest_mail_names_the_recipient(): void
    {
        // Echter Mailer (array, Queue sync): Mail::fake verwirft den Namen.
        // Ein nacktes To mit HTML und Bild wertet SpamAssassin ab (TO_NO_BRKTS_HTML_IMG).
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['require_email_confirmation' => false]);
        $this->clearTenantContext();
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(), 'time' => '19:00', 'party_size' => 2,
            'name' => 'Frau Kessler', 'email' => 'gast@example.test', 'phone' => '+49 451 123456', 'privacy_accepted' => '1',
        ])->assertRedirect();

        $to = collect(app('mailer')->getSymfonyTransport()->messages())
            ->map(fn ($sent) => $sent->getOriginalMessage()->getHeaders()->get('To')?->getBodyAsString())
            ->filter(fn (?string $header) => $header !== null && str_contains($header, 'gast@example.test'));

        $this->assertNotEmpty($to);
        $this->assertStringContainsString('Frau Kessler <gast@example.test>', (string) $to->first());
    }

    public function test_lines_with_empty_placeholder_disappear(): void
    {
        $body = "Hallo {guest_name},\n\nJetzt bezahlen: {payment_link}\nReservierungsnummer: {reservation_code}\n\nTisch: {table_name}\n\n{custom_message}\n\nBis bald!";

        $this->assertSame(
            "Hallo {guest_name},\n\nReservierungsnummer: {reservation_code}\n\n\n{custom_message}\n\nBis bald!",
            NotificationTemplateRenderer::dropEmptyLines($body, ['guest_name' => 'Ana', 'payment_link' => '', 'reservation_code' => 'R-1', 'table_name' => '', 'custom_message' => '']),
        );
    }

    public function test_every_default_template_parses_and_never_repeats_its_headline(): void
    {
        foreach (['du', 'Sie'] as $address) {
            foreach (NotificationTemplateRenderer::defaults($address) as $key => $template) {
                $headline = (string) __("mails.headlines.{$key}.{$address}", [], 'de');
                $this->assertNotSame("mails.headlines.{$key}.{$address}", $headline, "Überschrift fehlt: {$key}");
                $this->assertStringNotContainsStringIgnoringCase($headline, $template['body'], "{$key} ({$address}) wiederholt die Überschrift");

                $body = strtr($template['body'], [
                    '{cancel_link}' => 'https://swayy.test/m', '{payment_link}' => 'https://swayy.test/p',
                    '{feedback_link}' => 'https://swayy.test/f', '{waitlist_link}' => 'https://swayy.test/w',
                ]);
                $blocks = TextBlocks::fromConfig()->parse($body);
                $types = array_map(fn (Block $b) => $b->type, $blocks);
                if (str_contains($template['body'], '_link}')) {
                    $this->assertContains(Block::BUTTON, $types, "{$key} ({$address}) ohne Knopf");
                }
                if (in_array($key, ['reservation_confirmed', 'reservation_requested', 'reservation_reminder', 'payment_pending'], true)) {
                    $this->assertContains(Block::DETAILS, $types, "{$key} ({$address}) ohne Tabelle");
                }
            }
        }
    }

    public function test_sie_defaults_never_say_du_and_du_defaults_never_say_sie(): void
    {
        foreach (NotificationTemplateRenderer::defaults('Sie') as $key => $template) {
            $this->assertDoesNotMatchRegularExpression('/\b(du|dich|dir|dein\w*)\b/i', $template['body'], $key);
        }
        foreach (NotificationTemplateRenderer::defaults('du') as $key => $template) {
            $this->assertDoesNotMatchRegularExpression('/\b(Sie|Ihnen|Ihr\w*)\b/', $template['body'], $key);
        }
    }
}
