<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\RemindUnconfirmedReservations;
use App\Mail\GuestLinkMail;
use App\Mail\TemplatedMail;
use App\Models\GuestAuthToken;
use App\Models\NotificationLog;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Kurz bevor der Bestätigungslink abläuft, bekommt der Gast eine Erinnerung
 * mit demselben Link. Nur der Gast, genau einmal, Vorlauf einstellbar.
 */
class VerifyReminderTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function book(array $setup, bool $manualApproval = false): Reservation
    {
        $setup['location']->settings()->update([
            'require_email_confirmation' => true,
            'auto_confirm' => ! $manualApproval,
            'owner_notification_enabled' => true,
            'owner_notification_email' => 'team@betrieb.test',
        ]);
        $this->clearTenantContext();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => CarbonImmutable::now($setup['location']->timezone)->addDays(3)->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Max Mustermann',
            'email' => 'max@example.test',
            'phone' => '+49 451 123456',
            'privacy_accepted' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        return Reservation::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    private function token(Reservation $reservation): GuestAuthToken
    {
        return GuestAuthToken::withoutGlobalScopes()
            ->where('purpose', 'verify')
            ->where('reservation_id', $reservation->id)
            ->firstOrFail();
    }

    /** Lässt die Zeit vergehen, bis der Link noch so viele Minuten gilt. */
    private function travelUntilMinutesLeft(Reservation $reservation, int $minutes): void
    {
        $this->travelTo($this->token($reservation)->expires_at->copy()->subMinutes($minutes));
    }

    private function runReminders(): void
    {
        app()->call([new RemindUnconfirmedReservations, 'handle']);
    }

    private function reminders(): int
    {
        return Mail::queued(GuestLinkMail::class, fn ($m) => str_starts_with($m->subjectLine, 'Erinnerung:'))->count();
    }

    public function test_no_reminder_while_the_link_has_more_than_the_lead_left(): void
    {
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());

        $this->travelUntilMinutesLeft($reservation, 361);
        $this->runReminders();

        $this->assertSame(0, $this->reminders());
    }

    public function test_the_guest_is_reminded_six_hours_before_the_link_expires(): void
    {
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());

        $this->travelUntilMinutesLeft($reservation, 359);
        $this->runReminders();

        $this->assertSame(1, $this->reminders());
        $token = $this->token($reservation);
        Mail::assertQueued(GuestLinkMail::class, fn ($m) => $m->hasTo('max@example.test')
            && str_contains($m->subjectLine, $reservation->code)
            && $m->url === route('guest.verify', ['token' => $token->token])
            && str_contains($m->note, 'Danach geben wir den Tisch wieder frei'));
        $this->assertSame(1, GuestAuthToken::withoutGlobalScopes()->where('reservation_id', $reservation->id)->count(),
            'Die Erinnerung trägt denselben Link, ein zweiter gültiger entsteht nicht.');
    }

    public function test_only_the_guest_is_reminded(): void
    {
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup(), manualApproval: true);

        $this->travelUntilMinutesLeft($reservation, 300);
        $this->runReminders();

        Mail::assertNotQueued(GuestLinkMail::class, fn ($m) => $m->hasTo('team@betrieb.test'));
        Mail::assertNotQueued(TemplatedMail::class, fn ($m) => $m->hasTo('team@betrieb.test'));
        $this->assertSame(1, $this->reminders());
    }

    public function test_exactly_one_reminder_per_booking(): void
    {
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());

        $this->travelUntilMinutesLeft($reservation, 300);
        $this->runReminders();
        $this->travelUntilMinutesLeft($reservation, 100);
        $this->runReminders();

        $this->assertSame(1, $this->reminders());
        $this->assertSame(1, NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', RemindUnconfirmedReservations::TEMPLATE_KEY)
            ->count());
    }

    public function test_no_reminder_after_the_click(): void
    {
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());
        $this->get('/konto/verify/'.$this->token($reservation)->token)->assertOk();

        $this->travelUntilMinutesLeft($reservation, 300);
        $this->runReminders();

        $this->assertSame(0, $this->reminders());
    }

    public function test_no_reminder_for_a_booking_the_team_already_decided(): void
    {
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());
        $reservation->forceFill(['status' => ReservationStatus::Rejected])->saveQuietly();

        $this->travelUntilMinutesLeft($reservation, 300);
        $this->runReminders();

        $this->assertSame(0, $this->reminders());
    }

    public function test_the_lead_follows_the_setting(): void
    {
        config(['swayy.guest_auth.verify_reminder_minutes' => 120]);
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());

        $this->travelUntilMinutesLeft($reservation, 300);
        $this->runReminders();
        $this->assertSame(0, $this->reminders());

        $this->travelUntilMinutesLeft($reservation, 110);
        $this->runReminders();
        $this->assertSame(1, $this->reminders());
    }

    public function test_zero_switches_the_reminder_off(): void
    {
        config(['swayy.guest_auth.verify_reminder_minutes' => 0]);
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());

        $this->travelUntilMinutesLeft($reservation, 10);
        $this->runReminders();

        $this->assertSame(0, $this->reminders());
    }

    /**
     * Gilt der Link kürzer als der Vorlauf, käme die Erinnerung direkt nach
     * der Buchung. Dann gibt es keine.
     */
    public function test_no_reminder_when_the_link_is_shorter_than_the_lead(): void
    {
        config(['swayy.guest_auth.verify_minutes' => 240]);
        Mail::fake();
        $reservation = $this->book($this->createTenantSetup());

        $this->travelUntilMinutesLeft($reservation, 200);
        $this->runReminders();

        $this->assertSame(0, $this->reminders());
    }
}
