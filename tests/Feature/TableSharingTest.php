<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TableSharingTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function seatGroup(array $setup, int $party): void
    {
        $start = CarbonImmutable::now('Europe/Berlin')->subMinutes(10);
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
            'guest_name_snapshot' => 'Gruppe 1',
        ]);
        $r->tables()->attach($setup['tables'][0]->id);
    }

    public function test_second_group_can_share_table_within_remaining_seats(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->seatGroup($setup, 2); // 2 of 4 taken
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
            'shared' => true,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertSame(2, Reservation::where('location_id', $setup['location']->id)
            ->where('source', 'walk_in')->count());
    }

    public function test_sharing_rejected_when_seats_insufficient(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->seatGroup($setup, 2); // 2 of 4 taken → only 2 free
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 3,
            'shared' => true,
        ])->assertStatus(422)->assertJson(['free' => 2]);
    }
}
