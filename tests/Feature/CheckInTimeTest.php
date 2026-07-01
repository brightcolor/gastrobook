<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CheckInTimeTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function confirmedToday(array $setup): Reservation
    {
        $tz = $setup['location']->timezone;
        $start = CarbonImmutable::now($tz)->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $tz, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Gast',
        ]);
    }

    public function test_check_in_uses_chosen_time_for_seated_at(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $r = $this->confirmedToday($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson("/admin/reservations/{$r->id}/transition", [
            'status' => 'seated', 'seated_at' => '14:25',
        ])->assertOk();

        $r->refresh();
        $this->assertSame(ReservationStatus::Seated, $r->status);
        $this->assertSame('14:25', $r->seated_at->setTimezone($setup['location']->timezone)->format('H:i'));
    }

    public function test_check_in_without_time_falls_back_to_now(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $r = $this->confirmedToday($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->postJson("/admin/reservations/{$r->id}/transition", [
            'status' => 'seated',
        ])->assertOk();

        $r->refresh();
        $this->assertNotNull($r->seated_at);
        // seated_at is "now", not the planned 19:00 start.
        $this->assertTrue($r->seated_at->diffInMinutes(now()) < 2);
    }

    public function test_invalid_check_in_time_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $r = $this->confirmedToday($setup);
        $this->clearTenantContext();

        // Validation failures redirect app-wide (302 + session errors), even
        // for postJson — they are not 422 JSON here.
        $this->actingAs($admin)->postJson("/admin/reservations/{$r->id}/transition", [
            'status' => 'seated', 'seated_at' => '25:99',
        ])->assertStatus(302)->assertSessionHasErrors('seated_at');

        $this->assertSame(ReservationStatus::Confirmed, $r->fresh()->status);
    }

    public function test_check_in_shortly_after_midnight_lands_on_previous_day(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $tz = $setup['location']->timezone;

        // Reservation started at 23:30 the previous evening.
        $start = CarbonImmutable::now($tz)->subDay()->setTime(23, 30);
        $r = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $tz, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Gast',
        ]);
        $this->clearTenantContext();

        // Staff checks the guest in shortly after midnight, choosing the actual arrival time (23:30).
        $now = CarbonImmutable::now($tz)->addDay()->setTime(0, 10);
        CarbonImmutable::setTestNow($now);

        try {
            $this->actingAs($admin)->postJson("/admin/reservations/{$r->id}/transition", [
                'status' => 'seated', 'seated_at' => '23:30',
            ])->assertOk();
        } finally {
            CarbonImmutable::setTestNow();
        }

        $r->refresh();
        $seatedLocal = $r->seated_at->setTimezone($tz);
        $this->assertSame('23:30', $seatedLocal->format('H:i'));
        // Must be shortly before "now" (previous day), not ~24h in the future.
        $this->assertTrue($seatedLocal->lessThan($now));
        $this->assertTrue($now->diffInHours($seatedLocal) < 2);
    }
}
