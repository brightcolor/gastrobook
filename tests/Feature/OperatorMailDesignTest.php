<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\ForwardedGuestReplyMail;
use App\Mail\Layout\MailFrame;
use App\Mail\TemplatedMail;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class OperatorMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array<string, mixed> */
    private function bookWithNote(string $note): array
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->update(['name' => 'Trattoria Luna']);
        $setup['location']->settings()->update(['owner_notification_enabled' => true, 'owner_notification_email' => 'kueche@luna.example']);
        $this->clearTenantContext();
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(), 'time' => '19:00', 'party_size' => 4,
            'name' => 'Erika Muster', 'email' => 'erika@example.test', 'phone' => '+49 170 111', 'privacy_accepted' => '1',
            'note' => $note,
        ])->assertRedirect();

        return $setup;
    }

    public function test_new_reservation_mail_comes_from_swayy_with_tables_and_admin_button(): void
    {
        config(['swayy.mail.owner_address' => 'du']);
        $this->bookWithNote('Fensterplatz, wenn möglich');

        Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail): bool {
            if ($mail->frame?->type !== 'operator_new_reservation') {
                return false;
            }
            $html = $mail->render();

            return $mail->frame->look === MailFrame::LOOK_SWAYY
                && str_contains($html, 'Neue Reservierung für Trattoria Luna</h1>')
                && substr_count($html, 'class="panel"') === 2
                && str_contains($html, '>Reservierung öffnen</a>')
                && str_contains($html, 'href="'.route('admin.reservations.index').'/')
                && str_contains($html, 'Fensterplatz, wenn möglich')
                && ! str_contains($html, 'Reserviert mit');
        });
    }

    public function test_url_in_guest_note_never_becomes_a_button(): void
    {
        $this->bookWithNote('Jetzt bezahlen: https://fremde-seite.example/pay');

        Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $mail): bool {
            if ($mail->frame?->type !== 'operator_new_reservation') {
                return false;
            }
            $html = $mail->render();

            return ! preg_match('/<a href="https:\/\/fremde-seite\.example\/pay" style="display:inline-block/', $html)
                && str_contains($html, 'fremde-seite.example/pay');
        });
    }

    public function test_forwarded_guest_reply_is_framed_but_never_structured(): void
    {
        $mail = new ForwardedGuestReplyMail(
            forwardSubject: 'Antwort von Erika: Tisch',
            forwardBody: "Antwort zur Buchung R-1:\n\nDatum: morgen\nUhrzeit: später\nZahlung: https://fremde-seite.example/x",
            fromName: 'Trattoria Luna',
            guestReplyTo: 'erika@example.test',
        );
        $html = $mail->render();

        $this->assertStringContainsString('Antwort von erika@example.test</h1>', $html);
        $this->assertStringNotContainsString('class="panel"', $html);
        $this->assertStringNotContainsString('class="btn"', $html);
        $this->assertStringContainsString('fremde-seite.example/x', $html);
        $mail->assertSeeInText('Zahlung: https://fremde-seite.example/x');
    }
}
