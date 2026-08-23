<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\FeedbackRequest;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Jede Gästeseite muss sich rendern lassen.
 *
 * Die Verwaltungsseite lief über mehrere Versionen hinweg in einen
 * Serverfehler, ohne dass ein Test das gemerkt hätte: Die vorhandenen Tests
 * prüften nur die Weiterleitung DORTHIN, nie die Seite selbst.
 *
 * Die Ursache selbst - die Kurzform des PHP-Blocks zieht einen nachfolgenden
 * Block an sich und verschluckt alles dazwischen - deckt BladePhpBlockTest ab,
 * und zwar fuer jede Ansicht.
 */
class PublicPagesRenderTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_management_page_renders(): void
    {
        $setup = $this->createTenantSetup();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();

        $this->get(route('booking.manage', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]))
            ->assertOk()
            ->assertSee($reservation->code)
            ->assertSee('stornieren', false);
    }

    public function test_the_confirmation_page_renders(): void
    {
        $setup = $this->createTenantSetup();

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
        ]);
        $this->clearTenantContext();

        $antwort = $this->get(route('booking.confirmation', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]))->assertOk();

        // Kein fremdes CDN mehr auf der Seite, auf der Buchungscode und
        // Verwaltungstoken im Link stehen.
        $antwort->assertDontSee('cdn.jsdelivr.net');
    }

    public function test_the_booking_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)
            ->assertOk()
            ->assertSee($setup['location']->name);
    }

    public function test_the_reschedule_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->get(route('booking.reschedule', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]))->assertOk();
    }

    public function test_the_cancellation_page_renders(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $reservation = $this->reservation($setup);
        $this->clearTenantContext();

        $this->post(route('booking.cancel', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]))->assertOk();
    }

    public function test_the_waitlist_pages_render(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();

        $entry = WaitlistEntry::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_name' => 'Frau Kessler',
            'guest_email' => 'kessler@example.test',
            'party_size' => 2,
            'desired_date' => CarbonImmutable::now($setup['location']->timezone)->addDay()->toDateString(),
            'status' => 'waiting',
        ]);
        $this->clearTenantContext();

        // Ohne Angebot: die "kein aktives Angebot"-Ansicht.
        $this->get(route('waitlist.respond', ['entry' => $entry->id, 'token' => $entry->manage_token]))
            ->assertOk();

        // Mit Angebot, dann ablehnen: die Absageansicht.
        WaitlistOffer::create([
            'tenant_id' => $setup['tenant']->id,
            'waitlist_entry_id' => $entry->id,
            'offered_start_at' => now()->addDay(),
            'offered_end_at' => now()->addDay()->addHours(2),
            'offer_expires_at' => now()->addHour(),
            'status' => 'open',
        ]);

        $this->get(route('waitlist.respond', ['entry' => $entry->id, 'token' => $entry->manage_token]))
            ->assertOk()
            ->assertSee('Annehmen');

        $this->post(route('waitlist.respond.post', ['entry' => $entry->id, 'token' => $entry->manage_token]), [
            'decision' => 'decline',
        ])->assertOk();
    }

    public function test_the_guest_portal_pages_render(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/konto/'.$setup['tenant']->slug)->assertOk();
        $this->get('/konto/'.$setup['tenant']->slug.'/login/unbekannt')->assertOk();
    }

    public function test_the_location_chooser_renders(): void
    {
        $setup = $this->createTenantSetup();
        Location::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'is_active' => true,
            'online_booking_enabled' => true,
        ]);
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug)->assertOk();
    }

    public function test_the_feedback_pages_render(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->reservation($setup);

        $request = FeedbackRequest::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'reservation_id' => $reservation->id,
            'sent_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->get(route('feedback.show', ['token' => $request->token]))->assertOk();

        $this->post(route('feedback.store', ['token' => $request->token]), [
            'score' => 5,
        ])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservation(array $setup): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed,
            'source' => 'online', 'guest_name_snapshot' => 'Frau Kessler',
            'guest_email_snapshot' => 'kessler@example.test',
        ]);
    }
}
