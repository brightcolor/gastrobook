<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * No-show protection: a paid deposit stays with the restaurant instead of
 * being refunded to the guest.
 */
class NoShowForfeitTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function reservationWithDeposit(array $setup, int $amountMinor = 2500): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->subHours(3);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 4,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'No Show', 'code' => 'R-NS'.random_int(100, 999),
            'manage_token' => str_repeat('n', 48),
            'payment_status' => 'paid', 'payment_amount_minor' => $amountMinor, 'currency' => 'EUR',
        ]);

        PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'paypal',
            'type' => 'deposit',
            'amount_minor' => $amountMinor,
            'currency' => 'EUR',
            'status' => 'paid',
            'metadata' => ['refund_ref' => 'CAPTURE-123'],
        ]);

        return $reservation;
    }

    public function test_no_show_forfeits_the_deposit(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationWithDeposit($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post("/admin/reservations/{$reservation->id}/transition", ['status' => 'no_show'])
            ->assertSessionHas('success');

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::NoShow, $fresh->status);
        $this->assertSame('forfeited', $fresh->payment_status);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $setup['tenant']->id,
            'action' => 'payment.forfeited',
        ]);
    }

    public function test_pending_refund_is_cancelled_on_no_show(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationWithDeposit($setup);

        $intent = PaymentIntent::withoutGlobalScopes()->where('reservation_id', $reservation->id)->first();
        $refund = Refund::create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'payment_intent_id' => $intent->id,
            'provider' => 'paypal',
            'amount_minor' => 2500,
            'currency' => 'EUR',
            'status' => 'approved',
            'source' => 'guest_cancel',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post("/admin/reservations/{$reservation->id}/transition", ['status' => 'no_show']);

        // The money must not go back to the guest.
        $this->assertSame('rejected', $refund->fresh()->status);
        $this->assertSame('no_show_forfeit', $refund->fresh()->error);
    }

    public function test_no_show_without_deposit_changes_nothing_payment_wise(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $start = CarbonImmutable::now($setup['location']->timezone)->subHours(3);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Ohne Anzahlung', 'code' => 'R-NODEP', 'manage_token' => str_repeat('d', 48),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post("/admin/reservations/{$reservation->id}/transition", ['status' => 'no_show'])
            ->assertSessionHas('success');

        $fresh = $reservation->fresh();
        $this->assertSame(ReservationStatus::NoShow, $fresh->status);
        $this->assertSame('not_required', $fresh->payment_status);
    }

    public function test_bulk_no_show_also_forfeits(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $reservation = $this->reservationWithDeposit($setup);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/reservations/bulk-transition', [
            'ids' => [$reservation->id],
            'status' => 'no_show',
        ])->assertSessionHas('success');

        $this->assertSame('forfeited', $reservation->fresh()->payment_status);
    }
}
