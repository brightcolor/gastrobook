<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReservationPartyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function seatedWalkIn(array $setup, int $party = 2): Reservation
    {
        $start = CarbonImmutable::now('Europe/Berlin')->subMinutes(20);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => $party,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Seated,
            'source' => 'walk_in',
            'guest_name_snapshot' => 'Walk-in',
        ]);
        $r->tables()->attach($setup['tables'][0]->id);

        return $r;
    }

    public function test_more_guests_can_join_within_table_capacity(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $r = $this->seatedWalkIn($setup, 2);
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson("/admin/reservations/{$r->id}/party", ['party_size' => 4])
            ->assertOk()
            ->assertJson(['ok' => true, 'party_size' => 4]);

        $this->assertSame(4, $r->fresh()->party_size);
    }

    public function test_cannot_exceed_table_capacity(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $r = $this->seatedWalkIn($setup, 2);
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson("/admin/reservations/{$r->id}/party", ['party_size' => 6])
            ->assertStatus(422)
            ->assertJson(['max' => 4]);

        $this->assertSame(2, $r->fresh()->party_size);
    }
}
