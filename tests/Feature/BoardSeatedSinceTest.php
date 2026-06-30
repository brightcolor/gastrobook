<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BoardSeatedSinceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_occupied_table_shows_since_and_seated_ts(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;
        $now = CarbonImmutable::now($tz);

        // A guest seated 75 minutes ago, reservation window covers now.
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $now->toDateString(),
            'start_at' => $now->subHours(2)->utc(), 'end_at' => $now->addHours(1)->utc(),
            'timezone' => $tz, 'status' => ReservationStatus::Seated, 'source' => 'online',
            'guest_name_snapshot' => 'Sitzgast', 'seated_at' => $now->subMinutes(75)->utc(),
        ]);
        $r->tables()->attach($setup['tables'][0]->id);
        $this->clearTenantContext();

        $data = $this->actingAs($admin)->getJson('/admin/board/data')->assertOk()->json();

        $tile = collect($data['floorplan'] ?? [])
            ->flatMap(fn ($room) => $room['tables'])
            ->firstWhere('id', $setup['tables'][0]->id);

        $this->assertNotNull($tile);
        $this->assertSame('occupied', $tile['status']);
        // Shows "seit HH:MM", not "bis …".
        $this->assertStringStartsWith('seit ', $tile['time']);
        $this->assertStringNotContainsString('bis', $tile['time']);
        // Provides the seated timestamp so the client can tick the duration live.
        $this->assertNotNull($tile['seated_ts']);
        $this->assertEqualsWithDelta($now->subMinutes(75)->getTimestamp(), $tile['seated_ts'], 5);
    }

    public function test_upcoming_table_still_shows_ab(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;
        $now = CarbonImmutable::now($tz);

        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $now->toDateString(),
            'start_at' => $now->addMinutes(20)->utc(), 'end_at' => $now->addHours(2)->utc(),
            'timezone' => $tz, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Baldgast',
        ]);
        $r->tables()->attach($setup['tables'][1]->id);
        $this->clearTenantContext();

        $data = $this->actingAs($admin)->getJson('/admin/board/data')->assertOk()->json();
        $tile = collect($data['floorplan'] ?? [])
            ->flatMap(fn ($room) => $room['tables'])
            ->firstWhere('id', $setup['tables'][1]->id);

        $this->assertStringStartsWith('ab ', $tile['time']);
        $this->assertNull($tile['seated_ts']);
    }
}
