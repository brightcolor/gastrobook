<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\GuestAuthToken;
use App\Support\MailNotice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Jede Meldung, die eine Mail ankündigt, nennt den Spam-Ordner – hier an den
 * Seiten, die Gäste und Betriebe wirklich sehen. MailNoticeGuardTest hält die
 * Regel für neue Meldungen fest.
 */
class SpamHintTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     * @param  array<string, mixed>  $settings
     */
    private function configure(array $setup, array $settings): void
    {
        $setup['location']->settings()->update($settings);
        $this->clearTenantContext();
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function bookingUrl(array $setup): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;
    }

    /**
     * @param  array<string, mixed>  $setup
     * @return array<string, mixed>
     */
    private function payload(array $setup): array
    {
        return [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Max Mustermann',
            'email' => 'max@example.test',
            'phone' => '+49 451 123456',
            'privacy_accepted' => '1',
        ];
    }

    public function test_the_booking_form_names_the_spam_folder_before_sending(): void
    {
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => true, 'guest_address' => 'du']);

        $this->get($this->bookingUrl($setup))->assertOk()->assertSee(MailNotice::spamHint(true));
    }

    public function test_the_booking_form_names_it_for_requests_without_link(): void
    {
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => false, 'auto_confirm' => false]);

        $this->get($this->bookingUrl($setup))->assertOk()->assertSee(MailNotice::spamHint(false));
    }

    public function test_the_confirmation_page_names_it_while_waiting_for_the_click(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => true]);

        $this->followingRedirects()->post($this->bookingUrl($setup), $this->payload($setup))
            ->assertOk()
            ->assertSee(MailNotice::spamHint(false));
    }

    public function test_the_confirmation_page_names_it_for_a_request(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => false, 'auto_confirm' => false]);

        $this->followingRedirects()->post($this->bookingUrl($setup), $this->payload($setup))
            ->assertOk()
            ->assertSee('Anfrage erhalten')
            ->assertSee(MailNotice::spamHint(false));
    }

    public function test_the_page_after_the_click_names_it_for_the_receipt(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->configure($setup, ['require_email_confirmation' => true, 'auto_confirm' => false]);

        $this->post($this->bookingUrl($setup), $this->payload($setup))->assertRedirect();
        $token = GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->firstOrFail();

        $this->get('/konto/verify/'.$token->token)
            ->assertOk()
            ->assertSee('Eingangsbestätigung')
            ->assertSee(MailNotice::spamHint(false));
    }

    public function test_the_password_reset_message_names_it(): void
    {
        Mail::fake();

        $this->from('/passwort-vergessen')
            ->post('/passwort-vergessen', ['email' => 'niemand@example.test'])
            ->assertRedirect('/passwort-vergessen')
            ->assertSessionHas('status', fn (string $status) => str_contains($status, MailNotice::spamHint(false)));
    }
}
