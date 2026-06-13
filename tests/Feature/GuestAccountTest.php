<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Guest;
use App\Models\GuestAuthToken;
use App\Models\Reservation;
use App\Services\GuestAuthService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GuestAccountTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_booking_requires_email_confirmation_when_enabled(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['require_email_confirmation' => true]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $this->post('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug, [
            'date' => $date, 'time' => '19:00', 'party_size' => 2,
            'name' => 'Neukunde', 'email' => 'neu@example.test', 'phone' => '+49 170 1234567',
            'privacy_accepted' => '1',
        ])->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->where('guest_email_snapshot', 'neu@example.test')->first();
        $this->assertNotNull($reservation);
        // Held until email confirmed
        $this->assertSame(ReservationStatus::Requested, $reservation->status);
        $this->assertDatabaseHas('guest_auth_tokens', ['purpose' => 'verify', 'reservation_id' => $reservation->id]);
    }

    public function test_verify_link_confirms_email_and_reservation(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['require_email_confirmation' => true, 'auto_confirm' => true]);
        $guest = Guest::create([
            'tenant_id' => $setup['tenant']->id, 'first_name' => 'Neu', 'last_name' => 'Kunde',
            'email' => 'verify@example.test', 'source' => 'online',
        ]);
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'guest_id' => $guest->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(),
            'end_at' => $start->addMinutes(120)->utc(), 'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Requested, 'source' => 'online',
            'guest_name_snapshot' => 'Neu Kunde', 'guest_email_snapshot' => 'verify@example.test',
        ]);
        $token = app(GuestAuthService::class)->issue($guest, 'verify', $reservation->id, 1440);
        $this->clearTenantContext();

        $this->get('/konto/verify/'.$token)->assertOk()->assertSee('bestätigt');

        $this->assertNotNull($guest->fresh()->email_verified_at);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        // Token is single-use
        $this->assertNotNull(GuestAuthToken::where('token', $token)->first()->used_at);
    }

    public function test_magic_link_login_opens_portal(): void
    {
        $setup = $this->createTenantSetup();
        $guest = Guest::create([
            'tenant_id' => $setup['tenant']->id, 'first_name' => 'Stamm', 'last_name' => 'Gast',
            'email' => 'stamm@example.test', 'source' => 'online',
        ]);
        $token = app(GuestAuthService::class)->issue($guest, 'login', null, 60);
        $this->clearTenantContext();

        $this->get('/konto/'.$setup['tenant']->slug.'/login/'.$token)
            ->assertRedirect(route('guest.portal.dashboard', $setup['tenant']->slug));

        $this->get(route('guest.portal.dashboard', $setup['tenant']->slug))
            ->assertOk()->assertSee('Stamm');
        $this->assertNotNull($guest->fresh()->email_verified_at);
    }

    public function test_magic_link_request_is_neutral(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->clearTenantContext();

        $this->post('/konto/'.$setup['tenant']->slug, ['email' => 'unbekannt@example.test'])
            ->assertOk()->assertSee('Bitte E-Mails prüfen');

        Mail::assertNothingQueued();
    }

    public function test_guest_can_reschedule_within_deadline(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings->update(['modification_deadline_minutes' => 120]);
        $start = CarbonImmutable::now('Europe/Berlin')->addDays(2)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'party_size' => 2, 'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(),
            'end_at' => $start->addMinutes(120)->utc(), 'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed, 'source' => 'online', 'guest_name_snapshot' => 'Umbucher',
        ]);
        $reservation->tables()->attach($setup['tables'][1]->id);
        $this->clearTenantContext();

        $newDate = $start->addDay()->toDateString();
        $this->post('/reservation/'.$reservation->code.'/reschedule/'.$reservation->manage_token, [
            'date' => $newDate, 'time' => '18:00',
        ])->assertRedirect();

        $this->assertSame($newDate, $reservation->fresh()->reservation_date->toDateString());
        $this->assertSame('18:00', $reservation->fresh()->localStart()->format('H:i'));
    }
}
