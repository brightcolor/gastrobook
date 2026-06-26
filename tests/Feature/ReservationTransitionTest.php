<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReservationTransitionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function requestedReservation(array $setup): Reservation
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addHours(3);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Requested,
            'source' => 'online',
            'guest_name_snapshot' => 'Anfrage Gast',
        ]);
        $r->tables()->attach($setup['tables'][0]->id);

        return $r;
    }

    public function test_invalid_status_is_rejected_gracefully_not_500(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $r = $this->requestedReservation($setup);
        $this->clearTenantContext();

        // A bogus status must be caught by validation, never reach
        // ReservationStatus::from() and blow up with a ValueError (HTTP 500).
        // The app surfaces validation errors as a redirect-back with session errors.
        $response = $this->actingAs($admin)->post("/admin/reservations/{$r->id}/transition", [
            'status' => 'totally-not-a-status',
        ]);

        $response->assertStatus(302)->assertSessionHasErrors('status');
        $this->assertNotSame(500, $response->getStatusCode());
        $this->assertSame(ReservationStatus::Requested, $r->fresh()->status);
    }

    public function test_valid_transition_still_works(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $r = $this->requestedReservation($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson("/admin/reservations/{$r->id}/transition", [
            'status' => ReservationStatus::Confirmed->value,
        ])->assertOk()->assertJson(['ok' => true, 'status' => ReservationStatus::Confirmed->value]);

        $this->assertSame(ReservationStatus::Confirmed, $r->fresh()->status);
    }
}
