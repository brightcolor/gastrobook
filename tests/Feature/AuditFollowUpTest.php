<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\EventBooking;
use App\Models\Guest;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\GuestPrivacyService;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Regressionstests zur zweiten Audit-Runde (07./08.08.2026).
 */
class AuditFollowUpTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    // ── Feedback-Rueckstau ────────────────────────────────────────────────

    public function test_switching_feedback_back_on_does_not_mail_the_backlog(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['feedback_enabled' => false, 'feedback_hours_after' => 18]);

        foreach ([400, 30, 2] as $tageHer) {
            Reservation::factory()->create([
                'tenant_id' => $setup['tenant']->id,
                'location_id' => $setup['location']->id,
                'status' => ReservationStatus::Completed,
                'start_at' => now()->subDays($tageHer),
                'end_at' => now()->subDays($tageHer)->addHours(2),
                'departed_at' => now()->subDays($tageHer)->addHours(2),
                'guest_email_snapshot' => "gast{$tageHer}@example.test",
            ]);
        }
        $this->clearTenantContext();

        $setup['location']->settings()->update(['feedback_enabled' => true]);
        (new \App\Jobs\SendFeedbackRequests)->handle(app(ReservationLifecycleService::class));

        // Nur der frische Besuch, nicht der Altbestand.
        $this->assertSame(1, \App\Models\FeedbackRequest::withoutGlobalScopes()->count());
    }

    // ── Vorwaertssuche ────────────────────────────────────────────────────

    public function test_the_forward_search_stops_after_a_handful_of_open_days(): void
    {
        $setup = $this->createTenantSetup([]); // keine Tische -> nie verfuegbar
        $tz = $setup['location']->timezone;
        $this->clearTenantContext();

        $treffer = app(\App\Services\ReservationAvailabilityService::class)
            ->nextSlots($setup['location'], CarbonImmutable::now($tz)->addDay(), 2);

        // Ohne Budget wuerde hier der komplette Horizont (90 Tage) gerechnet.
        $this->assertSame([], $treffer);
    }

    // ── No-Show-Korrektur ─────────────────────────────────────────────────

    public function test_correcting_a_no_show_takes_the_counter_back(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::factory()->create(['tenant_id' => $setup['tenant']->id, 'no_show_count' => 0]);
        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $guest->id,
            'status' => ReservationStatus::Confirmed,
        ]);
        $this->clearTenantContext();

        $lifecycle = app(ReservationLifecycleService::class);
        $lifecycle->transition($reservation, ReservationStatus::NoShow, null, 'system');
        $this->assertSame(1, $guest->fresh()->no_show_count);

        // Der Gast kam doch – Korrektur auf abgeschlossen.
        $lifecycle->transition($reservation->fresh(), ReservationStatus::Completed, null, 'system');
        $this->assertSame(0, $guest->fresh()->no_show_count);
    }

    // ── Anonymisierung: Versandprotokoll ──────────────────────────────────

    public function test_anonymising_clears_the_notification_log(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'lindner@example.test',
        ]);
        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_id' => $guest->id,
        ]);
        NotificationLog::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'reservation_id' => $reservation->id,
            'channel' => 'mail',
            'template_key' => 'reservation_confirmed',
            'recipient' => 'lindner@example.test',
            'subject' => 'Ihre Reservierung',
            'status' => 'queued',
        ]);
        $this->clearTenantContext();

        app(GuestPrivacyService::class)->anonymize($guest->fresh());

        $log = NotificationLog::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('-', $log->recipient);
        $this->assertNull($log->subject);
    }

    // ── Umbuchung: Zeitgrenzen ────────────────────────────────────────────

    public function test_a_guest_cannot_reschedule_into_the_past(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $tz = $setup['location']->timezone;
        $this->clearTenantContext();

        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => CarbonImmutable::now($tz)->addDays(2)->setTime(19, 0),
            'source' => 'online',
            'guest_name' => 'Gast',
        ]);
        $this->clearTenantContext();

        $this->expectException(ValidationException::class);
        app(ReservationLifecycleService::class)
            ->reschedule($reservation->fresh(), CarbonImmutable::now($tz)->subDay()->setTime(19, 0));
    }

    // ── Eventstornierung ──────────────────────────────────────────────────

    public function test_cancelling_an_event_booking_asks_for_a_refund(): void
    {
        $setup = $this->createTenantSetup();
        // Modus "aus" -> die Anfrage darf nichts anlegen, aber auch nicht krachen.
        $setup['location']->settings()->update(['refund_mode' => 'off']);

        $event = \App\Models\Event::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'title' => 'Weinprobe', 'slug' => 'weinprobe',
            'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(3),
            'capacity' => 20, 'status' => 'published',
        ]);
        $booking = EventBooking::create([
            'tenant_id' => $setup['tenant']->id, 'event_id' => $event->id,
            'code' => 'EV999999', 'ticket_count' => 2, 'guest_name' => 'Gast',
            'payment_status' => 'paid',
        ]);
        $this->clearTenantContext();

        app(\App\Services\EventBookingService::class)->cancel($booking, 'restaurant');

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(0, \App\Models\Refund::withoutGlobalScopes()->count());
    }

    // ── Salon: Zuweisung ──────────────────────────────────────────────────

    public function test_a_salon_appointment_can_be_created_with_a_staff_member(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $tz = $setup['location']->timezone;

        $service = Service::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Haarschnitt', 'duration_minutes' => 60, 'price_minor' => 4500, 'is_active' => true,
        ]);
        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Anna', 'is_active' => true,
        ]);
        $staff->services()->attach($service->id);
        $this->clearTenantContext();

        $tag = CarbonImmutable::now($tz)->addDay();

        $this->actingAs($admin)->post('/admin/reservations', [
            'date' => $tag->toDateString(),
            'time' => '14:00',
            'party_size' => 1,
            'name' => 'Frau Kessler',
            'service_ids' => [$service->id],
            'staff_member_id' => $staff->id,
        ])->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->firstOrFail();
        $this->assertSame($staff->id, $reservation->staff_member_id);
        $this->assertSame(1, $reservation->services()->count());
    }

    public function test_a_salon_appointment_can_be_assigned_afterwards(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Anna', 'is_active' => true,
        ]);
        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'staff_member_id' => null,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/reservations/'.$reservation->id.'/staff', ['staff_member_id' => $staff->id])
            ->assertRedirect();

        $this->assertSame($staff->id, $reservation->fresh()->staff_member_id);
    }

    // ── Anzahlungsregel: Wochentage und Raum ──────────────────────────────

    public function test_weekdays_and_room_can_be_set_on_a_deposit_rule(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/deposit-rules', [
            'name' => 'Wochenende im Wintergarten',
            'amount_per_person' => 10,
            'weekdays' => [4, 5],
            'room_id' => $setup['room']->id,
        ])->assertRedirect();

        $rule = \App\Models\DepositRule::withoutGlobalScopes()->firstOrFail();
        $this->assertSame([4, 5], $rule->weekdays);
        $this->assertSame($setup['room']->id, $rule->room_id);
    }

    // ── Eventbuchung: Standortgrenze ──────────────────────────────────────

    public function test_event_check_in_respects_the_location(): void
    {
        $setup = $this->createTenantSetup();
        $zweiter = \App\Models\Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $event = \App\Models\Event::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $zweiter->id,
            'title' => 'Fremdes Event', 'slug' => 'fremd',
            'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(2),
            'capacity' => 10, 'status' => 'published',
        ]);
        $booking = EventBooking::create([
            'tenant_id' => $setup['tenant']->id, 'event_id' => $event->id,
            'code' => 'EV888888', 'ticket_count' => 1, 'guest_name' => 'Gast',
        ]);
        $this->clearTenantContext();

        // Aktiver Standort ist der erste – die Buchung gehoert zum zweiten.
        $this->actingAs($admin)
            ->post('/admin/event-bookings/'.$booking->id.'/check-in')
            ->assertNotFound();
    }
}
