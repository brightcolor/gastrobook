<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class PublicDataLeakTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_public_floorplan_never_exposes_guest_data(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['location']->settings->update(['public_floorplan_enabled' => true]);

        $start = CarbonImmutable::now('Europe/Berlin')->subMinutes(10);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Seated,
            'source' => 'online',
            'guest_name_snapshot' => 'GEHEIM Mustermann',
            'guest_email_snapshot' => 'geheim@example.com',
            'guest_phone_snapshot' => '+49 30 7777',
        ]);
        $r->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        $now = CarbonImmutable::now('Europe/Berlin')->format('H:i');
        $url = '/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug
            .'/floorplan?date='.$start->toDateString().'&time='.$now.'&party_size=2';

        $response = $this->getJson($url)->assertOk();

        $body = $response->getContent();
        $this->assertStringNotContainsString('GEHEIM', $body);
        $this->assertStringNotContainsString('geheim@example.com', $body);
        $this->assertStringNotContainsString('7777', $body);
    }

    public function test_public_slots_only_returns_times_no_guest_data(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $this->clearTenantContext();

        $date = CarbonImmutable::now('Europe/Berlin')->addDay()->toDateString();
        $body = $this->getJson('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug
            .'/slots?date='.$date.'&party_size=2')->assertOk()->getContent();

        $this->assertStringNotContainsString('@', $body); // no e-mail addresses
        $this->assertStringNotContainsString('guest', strtolower($body));
    }
}
