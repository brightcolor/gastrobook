<?php

namespace Tests\Feature;

use App\Mail\TemplatedMail;
use App\Models\Guest;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PublicBookingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_booking_page_renders(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)
            ->assertOk()
            ->assertSee($setup['location']->name);
    }

    public function test_slots_endpoint_returns_available_times(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $response = $this->getJson('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/slots?date='.$date.'&party_size=2');

        $response->assertOk();
        $this->assertNotEmpty($response->json('slots'));
        $this->assertContains('19:00', $response->json('slots'));
    }

    public function test_guest_can_book_online_and_gets_confirmation_mail(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $response = $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date,
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Anna Online',
            'email' => 'anna@example.test',
            'phone' => '+49 170 9999999',
            'privacy_accepted' => '1',
            'newsletter' => '1',
        ]);

        $response->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->where('guest_name_snapshot', 'Anna Online')->first();
        $this->assertNotNull($reservation);
        $this->assertSame('confirmed', $reservation->status->value);
        $this->assertSame('online', $reservation->source);
        $this->assertNotEmpty($reservation->tables);

        // Guest profile created with consents
        $guest = Guest::withoutGlobalScopes()->where('email', 'anna@example.test')->first();
        $this->assertNotNull($guest);
        $this->assertTrue($guest->marketing_consent);
        $this->assertSame(2, $guest->consents()->withoutGlobalScopes()->count()); // privacy + newsletter

        Mail::assertQueued(TemplatedMail::class);
    }

    public function test_honeypot_blocks_bots(): void
    {
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'website' => 'http://spam.example',
            'date' => CarbonImmutable::now()->addDay()->toDateString(),
            'time' => '19:00',
            'party_size' => 2,
            'name' => 'Bot',
            'email' => 'bot@example.test',
            'phone' => '123',
            'privacy_accepted' => '1',
        ])->assertStatus(422);
    }

    public function test_guest_can_cancel_via_secure_link(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $start = CarbonImmutable::now('Europe/Berlin')->addDays(3)->setTime(19, 0);
        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->utc()->addMinutes(120),
            'guest_email_snapshot' => 'gast@example.test',
        ]);
        $this->clearTenantContext();

        $this->post('/reservation/'.$reservation->code.'/cancel/'.$reservation->manage_token)
            ->assertOk();

        $this->assertSame('cancelled_by_guest', $reservation->refresh()->status->value);
    }

    public function test_cancel_with_wrong_token_fails(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
        ]);
        $this->clearTenantContext();

        $this->post('/reservation/'.$reservation->code.'/cancel/falscher-token')
            ->assertNotFound();

        $this->assertSame('confirmed', $reservation->refresh()->status->value);
    }

    public function test_cancellation_deadline_is_enforced(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        // Reservation in 30 minutes, deadline is 120 minutes
        $start = CarbonImmutable::now('Europe/Berlin')->addMinutes(30);
        $reservation = Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->utc()->addMinutes(120),
        ]);
        $this->clearTenantContext();

        $this->from('/reservation/'.$reservation->code.'/manage/'.$reservation->manage_token)
            ->post('/reservation/'.$reservation->code.'/cancel/'.$reservation->manage_token)
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertSame('confirmed', $reservation->refresh()->status->value);
    }
}
