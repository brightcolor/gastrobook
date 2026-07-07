<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BulkTransitionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function make(array $setup, ReservationStatus $status, string $code): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => $status, 'source' => 'online',
            'guest_name_snapshot' => 'Gast '.$code, 'code' => $code, 'manage_token' => str_pad($code, 48, 'x'),
        ]);
    }

    public function test_bulk_confirm_applies_and_skips_invalid(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');

        $requested1 = $this->make($setup, ReservationStatus::Requested, 'R-BLK1');
        $requested2 = $this->make($setup, ReservationStatus::Requested, 'R-BLK2');
        $completed = $this->make($setup, ReservationStatus::Completed, 'R-BLK3'); // terminal → skip
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/bulk-transition', [
            'ids' => [$requested1->id, $requested2->id, $completed->id],
            'status' => 'confirmed',
        ])->assertSessionHas('success');

        $this->assertSame(ReservationStatus::Confirmed, $requested1->fresh()->status);
        $this->assertSame(ReservationStatus::Confirmed, $requested2->fresh()->status);
        $this->assertSame(ReservationStatus::Completed, $completed->fresh()->status);
    }

    public function test_bulk_no_show_requires_permission(): void
    {
        $setup = $this->createTenantSetup();
        $readonly = $this->createMember($setup['tenant'], 'readonly');
        $r = $this->make($setup, ReservationStatus::Confirmed, 'R-BLK4');
        $this->clearTenantContext();

        $this->actingAs($readonly)->post('/admin/reservations/bulk-transition', [
            'ids' => [$r->id],
            'status' => 'no_show',
        ])->assertForbidden();

        $this->assertSame(ReservationStatus::Confirmed, $r->fresh()->status);
    }

    public function test_bulk_rejects_unknown_status(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $r = $this->make($setup, ReservationStatus::Confirmed, 'R-BLK5');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/bulk-transition', [
            'ids' => [$r->id],
            'status' => 'seated', // bewusst nicht als Bulk-Aktion erlaubt (braucht Check-in-Dialog)
        ])->assertSessionHasErrors('status');
    }
}
