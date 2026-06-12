<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Event;
use App\Models\EventBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class EventBookingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function makeEvent(array $setup, array $overrides = []): Event
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDays(7)->setTime(19, 0);

        return Event::withoutGlobalScope('tenant')->create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'title' => 'Weinprobe',
            'slug' => 'weinprobe',
            'starts_at' => $start->utc(),
            'ends_at' => $start->addHours(4)->utc(),
            'capacity' => 10,
            'price_minor' => 5000,
            'currency' => 'EUR',
            'is_public' => true,
            'status' => 'published',
        ], $overrides));
    }

    public function test_public_event_list_and_detail_render(): void
    {
        $setup = $this->createTenantSetup();
        $event = $this->makeEvent($setup);
        $this->clearTenantContext();

        $base = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug;

        $this->get($base.'/events')->assertOk()->assertSee('Weinprobe');
        $this->get($base.'/events/'.$event->slug)->assertOk()->assertSee('Jetzt buchen');
    }

    public function test_guest_can_book_event_tickets(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $event = $this->makeEvent($setup);
        $this->clearTenantContext();

        $response = $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/events/'.$event->slug, [
            'ticket_count' => 3,
            'name' => 'Eva Event',
            'email' => 'eva@example.test',
            'privacy_accepted' => '1',
        ]);

        $response->assertRedirect();

        $booking = EventBooking::withoutGlobalScopes()->where('guest_name', 'Eva Event')->first();
        $this->assertNotNull($booking);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(3, (int) $booking->ticket_count);
        $this->assertSame(15000, (int) $booking->amount_minor); // 3 × 50 €
        $this->assertSame(7, $event->fresh()->remainingCapacity());

        Mail::assertQueued(TemplatedMail::class);
    }

    public function test_overbooking_is_prevented(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $event = $this->makeEvent($setup, ['capacity' => 5]);
        $this->clearTenantContext();

        $url = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/events/'.$event->slug;

        $this->post($url, [
            'ticket_count' => 4, 'name' => 'Erste Gruppe',
            'email' => 'a@example.test', 'privacy_accepted' => '1',
        ])->assertRedirect();

        $this->post($url, [
            'ticket_count' => 2, 'name' => 'Zweite Gruppe',
            'email' => 'b@example.test', 'privacy_accepted' => '1',
        ])->assertSessionHasErrors('ticket_count');

        $this->assertSame(1, $event->fresh()->remainingCapacity());
    }

    public function test_booking_deadline_is_enforced(): void
    {
        $setup = $this->createTenantSetup();
        $event = $this->makeEvent($setup, ['booking_deadline_at' => now()->subHour()]);
        $this->clearTenantContext();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/events/'.$event->slug, [
            'ticket_count' => 1, 'name' => 'Zu Spät',
            'email' => 'spaet@example.test', 'privacy_accepted' => '1',
        ])->assertSessionHasErrors('event');

        $this->assertSame(0, EventBooking::withoutGlobalScopes()->count());
    }

    public function test_guest_can_cancel_via_token_and_wrong_token_fails(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $event = $this->makeEvent($setup);
        $this->actAsTenant($setup['tenant']);

        $booking = EventBooking::create([
            'tenant_id' => $setup['tenant']->id,
            'event_id' => $event->id,
            'ticket_count' => 2,
            'guest_name' => 'Storno Gast',
            'guest_email' => 'storno@example.test',
            'status' => 'confirmed',
        ]);
        $this->clearTenantContext();

        $this->post('/event-booking/'.$booking->code.'/falscher-token/cancel')->assertNotFound();

        $this->post('/event-booking/'.$booking->code.'/'.$booking->manage_token.'/cancel')
            ->assertRedirect();

        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame(10, $event->fresh()->remainingCapacity()); // tickets released
    }

    public function test_admin_can_create_event_and_check_in_guests(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/events', [
            'title' => 'Silvester-Menü',
            'date' => now()->addDays(30)->toDateString(),
            'start_time' => '19:00',
            'end_time' => '01:00',
            'capacity' => 60,
            'price' => '89.00',
            'is_public' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $event = Event::withoutGlobalScope('tenant')->where('title', 'Silvester-Menü')->first();
        $this->assertNotNull($event);
        $this->assertSame(8900, (int) $event->price_minor);
        // 19:00 → 01:00 crosses midnight
        $this->assertTrue($event->ends_at->gt($event->starts_at));

        $booking = EventBooking::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'event_id' => $event->id,
            'ticket_count' => 2,
            'guest_name' => 'Checkin Gast',
            'status' => 'confirmed',
        ]);

        $this->actingAs($admin)->post('/admin/event-bookings/'.$booking->id.'/check-in')
            ->assertRedirect();

        $this->assertSame('checked_in', $booking->fresh()->status);
    }

    public function test_staff_cannot_manage_events(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/events')->assertForbidden();
        $this->actingAs($staff)->post('/admin/events', [])->assertForbidden();
    }

    public function test_admin_cannot_see_events_of_other_tenant(): void
    {
        $a = $this->createTenantSetup();
        $b = $this->createTenantSetup();
        $eventB = $this->makeEvent($b);

        $adminA = $this->createMember($a['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($adminA)->get('/admin/events/'.$eventB->id)->assertNotFound();
    }
}
