<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\GuestLinkMail;
use App\Mail\TemplatedMail;
use App\Models\GuestAuthToken;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Antwortet ein Gast auf eine Mail, soll die Antwort beim Betrieb ankommen.
 * Absender bleibt MAIL_FROM_ADDRESS, die Antwort laeuft ueber Reply-To.
 */
class GuestMailReplyToTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function setupWith(array $tenant = [], array $location = [], array $settings = []): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(array_merge(['name' => 'Waldhaus GmbH', 'mail_from_name' => null, 'mail_reply_to' => null], $tenant));
        $setup['location']->update(array_merge(['name' => 'Waldhaus am See', 'email' => null], $location));
        $setup['location']->settings()->update(array_merge(['owner_notification_email' => null], $settings));
        $this->clearTenantContext();

        return $setup;
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function bookOnline(array $setup, string $mail = 'gast@example.test'): Reservation
    {
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDays(3);

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $tag->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Frau Kessler',
            'email' => $mail,
            'phone' => '+49 451 123456',
            'privacy_accepted' => '1',
        ])->assertRedirect();

        return Reservation::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function guestMail(string $to = 'gast@example.test'): TemplatedMail
    {
        $mail = null;
        Mail::assertQueued(TemplatedMail::class, function (TemplatedMail $m) use ($to, &$mail) {
            if ($m->hasTo($to)) {
                $mail = $m;

                return true;
            }

            return false;
        });

        return $mail;
    }

    public function test_the_own_reply_address_of_the_business_wins(): void
    {
        Mail::fake();
        $setup = $this->setupWith(
            tenant: ['mail_reply_to' => 'antwort@waldhaus.test', 'mail_from_name' => 'Waldhaus Team'],
            location: ['email' => 'info@waldhaus.test'],
        );

        $this->bookOnline($setup);

        $mail = $this->guestMail();
        $this->assertTrue($mail->hasReplyTo('antwort@waldhaus.test'));
        $this->assertTrue($mail->hasFrom(config('mail.from.address'), 'Waldhaus Team'));
    }

    public function test_without_own_address_the_location_email_is_used(): void
    {
        Mail::fake();
        $setup = $this->setupWith(
            location: ['email' => 'info@waldhaus.test'],
            settings: ['owner_notification_email' => 'chef@waldhaus.test'],
        );

        $this->bookOnline($setup);

        $mail = $this->guestMail();
        $this->assertTrue($mail->hasReplyTo('info@waldhaus.test'));
        // Absendername: Standort statt des allgemeinen Namens der Plattform.
        $this->assertTrue($mail->hasFrom(config('mail.from.address'), 'Waldhaus am See'));
    }

    public function test_then_the_notification_address_is_used(): void
    {
        Mail::fake();
        $setup = $this->setupWith(settings: ['owner_notification_email' => 'chef@waldhaus.test']);

        $this->bookOnline($setup);

        $this->assertTrue($this->guestMail()->hasReplyTo('chef@waldhaus.test'));
    }

    public function test_the_fallback_order_comes_from_the_settings(): void
    {
        config([
            'swayy.guest_mail.reply_to_fallbacks' => ['owner_notification_email'],
            'swayy.guest_mail.from_name_fallbacks' => ['tenant_name'],
        ]);
        Mail::fake();
        $setup = $this->setupWith(
            location: ['email' => 'info@waldhaus.test'],
            settings: ['owner_notification_email' => 'chef@waldhaus.test'],
        );

        $this->bookOnline($setup);

        $mail = $this->guestMail();
        $this->assertTrue($mail->hasReplyTo('chef@waldhaus.test'));
        $this->assertTrue($mail->hasFrom(config('mail.from.address'), 'Waldhaus GmbH'));
    }

    public function test_an_empty_fallback_list_sends_without_reply_to(): void
    {
        config(['swayy.guest_mail.reply_to_fallbacks' => []]);
        Mail::fake();
        $setup = $this->setupWith(location: ['email' => 'info@waldhaus.test']);

        $this->bookOnline($setup);

        $this->assertSame([], $this->guestMail()->envelope()->replyTo);
    }

    public function test_the_operator_notification_answers_straight_to_the_guest(): void
    {
        Mail::fake();
        $setup = $this->setupWith(settings: [
            'owner_notification_enabled' => true,
            'owner_notification_email' => 'chef@waldhaus.test',
        ]);

        $this->bookOnline($setup);

        $this->assertTrue($this->guestMail('chef@waldhaus.test')->hasReplyTo('gast@example.test'));
    }

    public function test_the_operator_reply_to_guest_can_be_switched_off(): void
    {
        config(['swayy.guest_mail.owner_reply_to_guest' => false]);
        Mail::fake();
        $setup = $this->setupWith(settings: [
            'owner_notification_enabled' => true,
            'owner_notification_email' => 'chef@waldhaus.test',
        ]);

        $this->bookOnline($setup);

        $this->assertSame([], $this->guestMail('chef@waldhaus.test')->envelope()->replyTo);
    }

    public function test_the_confirmation_link_mail_carries_the_reply_address_too(): void
    {
        Mail::fake();
        $setup = $this->setupWith(
            location: ['email' => 'info@waldhaus.test'],
            settings: ['require_email_confirmation' => true],
        );

        $this->bookOnline($setup);

        $this->assertSame(1, GuestAuthToken::withoutGlobalScopes()->where('purpose', 'verify')->count());
        Mail::assertSent(GuestLinkMail::class, fn (GuestLinkMail $m) => $m->hasTo('gast@example.test')
            && $m->hasReplyTo('info@waldhaus.test')
            && $m->hasFrom(config('mail.from.address'), 'Waldhaus am See'));
    }

    public function test_a_status_mail_uses_the_same_reply_address(): void
    {
        Mail::fake();
        $setup = $this->setupWith(tenant: ['mail_reply_to' => 'antwort@waldhaus.test']);
        $reservation = $this->bookOnline($setup);

        app(ReservationLifecycleService::class)->sendGuestMail($reservation, 'reservation_cancelled');

        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $m) => str_contains($m->mailSubject, 'storniert')
            && $m->hasReplyTo('antwort@waldhaus.test'));
    }

    // ── Einstellungsseite ─────────────────────────────────────────────────

    public function test_the_business_can_set_name_and_reply_address(): void
    {
        $setup = $this->setupWith();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $this->actingAs($admin)->put('/admin/settings/guest-mail', [
            'mail_from_name' => '  Waldhaus Team ',
            'mail_reply_to' => 'antwort@waldhaus.test',
        ])->assertRedirect();

        $tenant = $setup['tenant']->fresh();
        $this->assertSame('Waldhaus Team', $tenant->mail_from_name);
        $this->assertSame('antwort@waldhaus.test', $tenant->mail_reply_to);

        // Leeren schaltet zurueck auf den Ersatz.
        $this->actingAs($admin)->put('/admin/settings/guest-mail', [
            'mail_from_name' => '',
            'mail_reply_to' => '',
        ])->assertRedirect();
        $this->assertNull($setup['tenant']->fresh()->mail_reply_to);
    }

    public function test_an_invalid_reply_address_is_explained(): void
    {
        $setup = $this->setupWith();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $this->actingAs($admin)->put('/admin/settings/guest-mail', [
            'mail_reply_to' => 'antwort at waldhaus',
        ])->assertSessionHasErrors([
            'mail_reply_to' => 'Die Antwortadresse ist keine gültige E-Mail-Adresse. Bitte so eintragen: info@dein-betrieb.de',
        ]);

        $this->actingAs($admin)->put('/admin/settings/guest-mail', [
            'mail_from_name' => 'Waldhaus <info@evil.test>',
        ])->assertSessionHasErrors('mail_from_name');
    }

    public function test_staff_cannot_change_the_reply_address(): void
    {
        $setup = $this->setupWith();
        $staff = $this->createMember($setup['tenant'], 'staff');

        $this->actingAs($staff)->put('/admin/settings/guest-mail', [
            'mail_reply_to' => 'umleitung@example.test',
        ])->assertForbidden();
    }

    public function test_the_settings_page_shows_what_applies_without_own_address(): void
    {
        $setup = $this->setupWith(location: ['email' => 'info@waldhaus.test']);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('E-Mails an Gäste')
            ->assertSee('Leer: info@waldhaus.test', false);
    }
}
