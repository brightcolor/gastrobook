<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\BillingRequest;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\FeedbackRequest;
use App\Models\Guest;
use App\Models\Invitation;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Models\WaitlistOffer;
use App\Services\GuestAuthService;
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
     * Die Verwaltungsseite einer Eventbuchung - dieselbe Rolle wie die der
     * Reservierung, und bis hierher genauso ungeprueft: Die vorhandenen Tests
     * riefen nur den Storno-POST auf, nie die Seite.
     */
    public function test_the_event_management_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(7)->setTime(19, 0);
        $event = Event::withoutGlobalScope('tenant')->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'title' => 'Weinprobe', 'slug' => 'weinprobe',
            'starts_at' => $start->utc(), 'ends_at' => $start->addHours(4)->utc(),
            'capacity' => 10, 'price_minor' => 5000, 'currency' => 'EUR',
            'is_public' => true, 'status' => 'published',
        ]);
        $booking = EventBooking::create([
            'tenant_id' => $setup['tenant']->id,
            'event_id' => $event->id,
            'ticket_count' => 2,
            'guest_name' => 'Eva Event',
            'guest_email' => 'eva@example.test',
            'status' => 'confirmed',
        ]);
        $this->clearTenantContext();

        $this->get(route('events.manage', ['code' => $booking->code, 'token' => $booking->manage_token]))
            ->assertOk()
            ->assertSee($booking->code)
            ->assertSee('Weinprobe');
    }

    public function test_the_invitation_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'neue.kollegin@example.test',
            'role' => 'host',
            'all_locations' => true,
            'token' => 'inv-'.uniqid(),
            'expires_at' => now()->addDays(7),
        ]);
        $this->clearTenantContext();

        $this->get(route('invitation.accept', ['token' => $invitation->token]))
            ->assertOk()
            ->assertSee($invitation->email);
    }

    public function test_the_email_verification_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->reservation($setup);
        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Ida', 'last_name' => 'Kessler',
            'email' => 'kessler@example.test',
        ]);
        $token = app(GuestAuthService::class)->issue($guest, 'verify', $reservation->id);
        $this->clearTenantContext();

        $this->get(route('guest.verify', ['token' => $token]))->assertOk();

        // Und ein zweiter Aufruf desselben Links landet auf der Fehlerseite,
        // statt auf einem Serverfehler.
        $this->get(route('guest.verify', ['token' => $token]))->assertOk();
    }

    public function test_the_billing_confirmation_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'trial_expired']);
        $anfrage = BillingRequest::create([
            'tenant_id' => $setup['tenant']->id,
            'contact_name' => 'Ida Kessler',
            'contact_email' => 'kessler@example.test',
            'company_name' => 'Beispielbetrieb GmbH',
            'address_line1' => 'Beispielweg 1',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'plan_key' => 'starter',
            'token' => 'bil-'.uniqid(),
        ]);
        $this->clearTenantContext();

        $this->get(route('billing.confirm', ['token' => $anfrage->token]))->assertOk();

        // Der zweite Klick auf denselben Link gehoert auf die Hinweisseite.
        $this->get(route('billing.confirm', ['token' => $anfrage->token]))->assertOk();
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
