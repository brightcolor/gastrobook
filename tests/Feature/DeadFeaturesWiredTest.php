<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\DepositRule;
use App\Models\Event;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Services\EventBookingService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Fields that existed in the schema (and partly in the logic) but had no way in
 * or out through the UI. Each test here pins one of them down end to end.
 */
class DeadFeaturesWiredTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    // ── Anzahlung: Grundbetrag ────────────────────────────────────────────

    public function test_deposit_rule_flat_amount_is_saved_and_added_to_the_total(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/deposit-rules', [
            'name' => 'Gruppen',
            'amount_per_person' => 5,
            'flat_amount' => 20,
            'payment_deadline_minutes' => 60,
            'cancel_unpaid_automatically' => '1',
        ])->assertRedirect();

        $rule = DepositRule::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();

        $this->assertSame(2000, $rule->flat_amount_minor);
        // 20 € base + 4 × 5 € per person
        $this->assertSame(4000, $rule->amountFor(4));
    }

    public function test_flat_amount_defaults_to_zero_when_left_empty(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/deposit-rules', [
            'name' => 'Nur pro Person',
            'amount_per_person' => 10,
        ])->assertRedirect();

        $rule = DepositRule::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();

        $this->assertSame(0, $rule->flat_amount_minor);
        $this->assertSame(2000, $rule->amountFor(2));
    }

    // ── Auto-Storno unbezahlter Buchungen ─────────────────────────────────

    private function overdueReservation(array $setup, ?DepositRule $rule): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);

        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 4,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::PaymentPending,
            'source' => 'online',
            'guest_name_snapshot' => 'Test',
            'payment_status' => 'required',
            'payment_due_at' => now()->subHour(),
            'deposit_rule_id' => $rule?->id,
        ]);

        return $reservation;
    }

    private function rule(array $setup, bool $autoCancel): DepositRule
    {
        return DepositRule::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => $autoCancel ? 'mit Auto-Storno' : 'ohne Auto-Storno',
            'type' => 'deposit',
            'amount_per_person_minor' => 1000,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'cancel_unpaid_automatically' => $autoCancel,
        ]);
    }

    public function test_unpaid_booking_is_expired_when_the_rule_wants_it(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->overdueReservation($setup, $this->rule($setup, true));
        $this->clearTenantContext();

        $this->runExpiryTask();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    public function test_unpaid_booking_stays_open_when_the_rule_opts_out(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->overdueReservation($setup, $this->rule($setup, false));
        $this->clearTenantContext();

        $this->runExpiryTask();

        // The whole point of the flag: the business decides by hand.
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
    }

    public function test_unpaid_booking_without_a_rule_still_expires(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->overdueReservation($setup, null);
        $this->clearTenantContext();

        $this->runExpiryTask();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    /** Run the scheduled closure that expires unpaid bookings. */
    private function runExpiryTask(): void
    {
        $schedule = app(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->description, 'expire') || $event->description === null) {
                // Callback events carry no command string; run them all – the
                // other closures are idempotent on an empty data set.
                if ($event instanceof CallbackEvent) {
                    $event->run(app());
                }
            }
        }
    }

    // ── Kinderstuhl am Tisch ──────────────────────────────────────────────

    public function test_high_chair_flag_can_be_set_on_a_table(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/floorplan/tables/'.$table->id, [
            'name' => $table->name,
            'min_capacity' => $table->min_capacity,
            'max_capacity' => $table->max_capacity,
            'high_chair_possible' => true,
        ])->assertSuccessful();

        $this->assertTrue(RestaurantTable::withoutGlobalScopes()->find($table->id)->high_chair_possible);
    }

    // ── Event: Anzahlung und Bild ─────────────────────────────────────────

    private function eventPayload(array $extra = []): array
    {
        return array_merge([
            'title' => 'Weinabend',
            'date' => now()->addMonth()->format('Y-m-d'),
            'start_time' => '19:00',
            'end_time' => '23:00',
            'capacity' => 30,
            'price' => 80,
        ], $extra);
    }

    public function test_event_deposit_is_saved_and_only_the_deposit_is_charged(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/events', $this->eventPayload(['deposit' => 20]))
            ->assertRedirect();

        $event = Event::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();
        $this->assertSame(8000, $event->price_minor);
        $this->assertSame(2000, $event->deposit_minor);

        $booking = app(EventBookingService::class)->book($event, [
            'ticket_count' => 3,
            'guest_name' => 'Klara Meier',
            'guest_email' => 'klara@example.test',
        ]);

        // 3 × 20 € deposit, not 3 × 80 € full price.
        $this->assertSame(6000, $booking->amount_minor);
    }

    public function test_event_without_deposit_still_charges_the_full_price(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/events', $this->eventPayload())->assertRedirect();

        $event = Event::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();
        $booking = app(EventBookingService::class)->book($event, [
            'ticket_count' => 2,
            'guest_name' => 'Klara Meier',
            'guest_email' => 'klara@example.test',
        ]);

        $this->assertNull($event->deposit_minor);
        $this->assertSame(16000, $booking->amount_minor);
    }

    public function test_deposit_larger_than_the_price_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/events', $this->eventPayload(['price' => 50, 'deposit' => 80]))
            ->assertSessionHasErrors('deposit');
    }

    public function test_event_image_is_stored_and_served_publicly(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/events', $this->eventPayload([
            'image' => UploadedFile::fake()->image('event.jpg', 800, 600),
        ]))->assertRedirect();

        $event = Event::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();

        $this->assertNotNull($event->image_path);
        Storage::disk('public')->assertExists($event->image_path);

        // Reachable without login – there is no public/storage symlink, so this
        // has to go through the app.
        $this->get(route('events.image', [
            $setup['tenant']->slug, $setup['location']->slug, $event->slug,
        ]))->assertOk();
    }

    public function test_image_route_404s_for_an_event_without_one(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/events', $this->eventPayload())->assertRedirect();
        $event = Event::withoutGlobalScopes()->where('location_id', $setup['location']->id)->firstOrFail();

        $this->get(route('events.image', [
            $setup['tenant']->slug, $setup['location']->slug, $event->slug,
        ]))->assertNotFound();
    }
}
