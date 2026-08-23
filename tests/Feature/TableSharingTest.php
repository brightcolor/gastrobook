<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\BlackoutPeriod;
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

    /**
     * "Tisch teilen" setzt skip_availability_check und uebersprang damit den
     * GANZEN Pruefblock. Zurueckgeholt waren zuerst nur die Oeffnungszeiten;
     * Sperrzeiten und Platzlimit liefen weiter daran vorbei. Ein geteilter
     * Walk-in ging an einem fuer eine Privatfeier geschlossenen Abend also
     * durch, ein normaler nicht.
     */
    public function test_sharing_respects_a_blackout(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->seatGroup($setup, 2);

        BlackoutPeriod::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(4),
            'reason' => 'Privatfeier',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
            'shared' => true,
        ])->assertStatus(422);

        $this->assertSame(1, Reservation::where('location_id', $setup['location']->id)
            ->where('source', 'walk_in')->count());
    }

    /**
     * Und das Platzlimit gilt auch fuer ihn. Im Modus "Plaetze" oder "gemischt"
     * zaehlt der Betrieb Koepfe, nicht Tische - ein geteilter Walk-in fuellte
     * den Deckel bisher lautlos ueber.
     */
    public function test_sharing_respects_the_covers_limit(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 8]]);
        $setup['location']->settings->update([
            'capacity_mode' => 'hybrid',
            'max_covers_per_slot' => 3,
        ]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->seatGroup($setup, 2);
        $this->clearTenantContext();

        // Am Tisch waeren noch sechs Plaetze frei - der Deckel steht bei drei.
        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 2,
            'shared' => true,
        ])->assertStatus(422);

        // Einer passt noch.
        $this->actingAs($admin)->postJson('/admin/walkins', [
            'table_id' => $setup['tables'][0]->id,
            'party_size' => 1,
            'shared' => true,
        ])->assertOk();
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
