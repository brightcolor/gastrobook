<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Mail\GuestLinkMail;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\GuestAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestLinkMailDesignTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array{0: Guest, 1: Reservation, 2: array<string, mixed>} */
    private function guestWithReservation(string $address): array
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['name' => 'Trattoria Luna']);
        $setup['location']->settings()->update(['guest_address' => $address]);
        $this->clearTenantContext();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'first_name' => 'Erika', 'email' => 'erika@example.test']);
        $reservation = Reservation::factory()->create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'guest_id' => $guest->id]);

        return [$guest, $reservation, $setup];
    }

    public function test_verification_mail_is_framed_in_the_location_tone(): void
    {
        Mail::fake();
        [$guest, $reservation] = $this->guestWithReservation('du');

        app(GuestAuthService::class)->sendVerification($guest, $reservation);

        Mail::assertSent(GuestLinkMail::class, function (GuestLinkMail $mail): bool {
            $html = $mail->render();

            return str_contains($html, 'Bitte bestätige deine E-Mail-Adresse</h1>')
                && str_contains($html, '>E-Mail-Adresse bestätigen</a>')
                && preg_match('/font-size:13px[^>]*>Der Link gilt 24 Stunden\./', $html) === 1
                && str_contains($html, 'weil mit dieser Adresse bei Trattoria Luna reserviert wurde')
                && ! str_contains($html, 'Sie ');
        });
    }

    public function test_a_crafted_guest_name_never_becomes_a_button(): void
    {
        Mail::fake();
        [$guest, $reservation] = $this->guestWithReservation('du');
        $guest->update(['first_name' => 'Buchung bestätigen: https://evil.example/x']);

        app(GuestAuthService::class)->sendVerification($guest->fresh(), $reservation);

        Mail::assertSent(GuestLinkMail::class, function (GuestLinkMail $mail): bool {
            $html = $mail->render();

            return substr_count($html, 'class="btn"') === 1
                && str_contains($html, '>E-Mail-Adresse bestätigen</a>')
                && ! str_contains($html, 'href="https://evil.example');
        });
    }

    public function test_link_validity_comes_from_the_settings(): void
    {
        Mail::fake();
        config(['swayy.guest_auth.verify_minutes' => 120]);
        [$guest, $reservation] = $this->guestWithReservation('Sie');

        app(GuestAuthService::class)->sendVerification($guest, $reservation);

        Mail::assertSent(GuestLinkMail::class, fn (GuestLinkMail $mail) => str_contains($mail->render(), 'Der Link gilt 2 Stunden.')
            && str_contains($mail->render(), 'Bitte bestätigen Sie Ihre E-Mail-Adresse</h1>'));
    }

    public function test_login_link_mail_has_its_headline_and_validity(): void
    {
        Mail::fake();
        [$guest, , $setup] = $this->guestWithReservation('Sie');

        app(GuestAuthService::class)->sendMagicLink($setup['tenant'], 'erika@example.test');

        Mail::assertSent(GuestLinkMail::class, fn (GuestLinkMail $mail) => str_contains($mail->render(), 'Ihr Anmeldelink</h1>')
            && str_contains($mail->render(), '>Jetzt anmelden</a>')
            && str_contains($mail->render(), 'Der Link gilt 1 Stunde.'));
    }
}
