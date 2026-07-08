<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Mail\GuestLinkMail;
use App\Models\Guest;
use App\Models\Reservation;
use App\Services\GuestAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Magic-link and billing mails must be delivered synchronously (not queued),
 * so they still go out when the queue worker / Redis is down.
 */
class CriticalMailsSyncTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_magic_link_is_sent_not_queued(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Ada', 'last_name' => 'Lovelace',
            'email' => 'ada@example.test',
        ]);
        $this->clearTenantContext();

        app(GuestAuthService::class)->sendMagicLink($setup['tenant'], 'ada@example.test');

        Mail::assertSent(GuestLinkMail::class);   // sent immediately
        Mail::assertNotQueued(GuestLinkMail::class);
    }

    public function test_email_verification_is_sent_not_queued(): void
    {
        Mail::fake();

        $setup = $this->createTenantSetup();
        $guest = Guest::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'first_name' => 'Grace', 'last_name' => 'Hopper',
            'email' => 'grace@example.test',
        ]);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'guest_id' => $guest->id,
            'party_size' => 2, 'reservation_date' => now()->addDay()->toDateString(),
            'start_at' => now()->addDay(), 'end_at' => now()->addDay()->addHours(2),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Requested,
            'source' => 'online', 'guest_name_snapshot' => 'Grace Hopper', 'guest_email_snapshot' => 'grace@example.test',
            'code' => 'R-VERIFY', 'manage_token' => str_repeat('v', 48),
        ]);
        $this->clearTenantContext();

        app(GuestAuthService::class)->sendVerification($guest, $reservation);

        Mail::assertSent(GuestLinkMail::class);
        Mail::assertNotQueued(GuestLinkMail::class);
    }
}
