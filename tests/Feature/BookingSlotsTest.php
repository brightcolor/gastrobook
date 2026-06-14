<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingSlotsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function url(array $setup, string $date, int $party): string
    {
        return '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug
            .'/slots?date='.$date.'&party_size='.$party;
    }

    public function test_oversized_party_gets_contact_hint_not_waitlist(): void
    {
        // Largest table seats 4
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['location']->update(['phone' => '+49 30 123456']);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $response = $this->getJson($this->url($setup, $date, 6))->assertOk();

        $response->assertJson(['oversized' => true, 'max_party' => 4]);
        // No waitlist suggestion when the party simply cannot be seated
        $this->assertArrayNotHasKey('waitlist_available', $response->json());
        $this->assertSame([], $response->json('slots'));
    }

    public function test_fitting_party_gets_slots_not_oversized(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();

        $response = $this->getJson($this->url($setup, $date, 2))->assertOk();

        $this->assertArrayNotHasKey('oversized', $response->json());
        $this->assertNotEmpty($response->json('slots'));
    }

    public function test_fully_booked_day_returns_selectable_next_slots(): void
    {
        // One table for 4; block the whole requested day so no slot is free.
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->startOfDay();
        $block = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 4,
            'reservation_date' => $date->toDateString(),
            'start_at' => $date->setTime(12, 0)->utc(),
            'end_at' => $date->setTime(23, 0)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Blocker',
        ]);
        $block->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        $response = $this->getJson($this->url($setup, $date->toDateString(), 4))->assertOk();

        $this->assertSame([], $response->json('slots'));
        $next = $response->json('next_slots');
        $this->assertNotEmpty($next);
        $this->assertArrayHasKey('date', $next[0]);
        $this->assertArrayHasKey('time', $next[0]);
        $this->assertSame(4, $response->json('party_size'));
        $this->assertNotSame($date->toDateString(), $next[0]['date']);
    }
}
