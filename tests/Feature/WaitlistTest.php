<?php

namespace Tests\Feature;

use App\Models\WaitlistEntry;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class WaitlistTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_offer_and_accept_creates_confirmed_reservation(): void
    {
        Mail::fake();
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $service = app(WaitlistService::class);

        $tomorrow = CarbonImmutable::now('Europe/Berlin')->addDay();

        $entry = $service->createEntry($setup['location'], [
            'guest_name' => 'Warte Gast',
            'guest_email' => 'warte@example.test',
            'party_size' => 2,
            'desired_date' => $tomorrow->toDateString(),
            'desired_time' => '19:00',
        ]);

        $this->assertSame('waiting', $entry->status);

        $start = $tomorrow->setTime(19, 0);
        $offer = $service->offer($entry, $start->utc(), $start->utc()->addMinutes(120));
        $this->assertSame('offered', $entry->refresh()->status);

        $reservation = $service->acceptOffer($offer);

        $this->assertSame('confirmed', $reservation->status->value);
        $this->assertSame('accepted', $entry->refresh()->status);
        $this->assertSame($reservation->id, $entry->reservation_id);
        Mail::assertQueued(\App\Mail\TemplatedMail::class);
    }

    public function test_expired_offers_are_cleaned_up(): void
    {
        $setup = $this->createTenantSetup();
        $this->actAsTenant($setup['tenant'], $setup['location']);

        $entry = WaitlistEntry::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'guest_name' => 'Alt',
            'party_size' => 2,
            'desired_date' => now()->toDateString(),
            'status' => 'offered',
        ]);
        $entry->offers()->create([
            'tenant_id' => $setup['tenant']->id,
            'offered_start_at' => now()->addHour(),
            'offered_end_at' => now()->addHours(3),
            'offer_expires_at' => now()->subMinutes(5),
            'status' => 'open',
        ]);

        $this->clearTenantContext();
        app(WaitlistService::class)->expireStale();

        $this->assertSame('expired', $entry->offers()->withoutGlobalScopes()->first()->status);
        $this->assertSame('waiting', $entry->refresh()->status);
    }
}
