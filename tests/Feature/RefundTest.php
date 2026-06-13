<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\User;
use App\Services\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function prepare(string $mode, int $percent = 100, string $processing = 'immediate'): array
    {
        $tenantSetup = $this->createTenantSetup();
        $tenant = $tenantSetup['tenant'];
        $tenant->update(['feature_overrides' => ['deposits_enabled' => true]]);

        $tenantSetup['location']->settings->update([
            'refund_mode' => $mode,
            'refund_percent' => $percent,
            'refund_processing' => $processing,
        ]);

        IntegrationConnection::create([
            'tenant_id' => $tenant->id, 'location_id' => null, 'provider' => 'stripe', 'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['secret_key' => 'sk_x', 'webhook_secret' => 'wh_x'])),
        ]);

        return $tenantSetup;
    }

    private function paidReservation(array $s, int $amount = 1000): Reservation
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $s['tenant']->id, 'location_id' => $s['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addMinutes(120)->utc(),
            'timezone' => 'Europe/Berlin', 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Refund Gast', 'payment_status' => 'paid', 'payment_amount_minor' => $amount, 'currency' => 'EUR',
        ]);
        PaymentIntent::create([
            'tenant_id' => $s['tenant']->id, 'reservation_id' => $reservation->id, 'provider' => 'stripe',
            'provider_intent_id' => 'cs_x', 'type' => 'deposit', 'amount_minor' => $amount, 'currency' => 'EUR',
            'status' => 'paid', 'metadata' => ['refund_ref' => 'pi_x'],
        ]);

        return $reservation;
    }

    private function fakeStripeRefundOk(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);
    }

    public function test_off_mode_creates_no_refund(): void
    {
        $s = $this->prepare('off');
        $reservation = $this->paidReservation($s);

        $this->assertNull(app(RefundService::class)->requestForReservation($reservation));
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_auto_immediate_processes_full_refund(): void
    {
        $this->fakeStripeRefundOk();
        $s = $this->prepare('auto', 100, 'immediate');
        $reservation = $this->paidReservation($s, 1000);

        $refund = app(RefundService::class)->requestForReservation($reservation);

        $this->assertSame('completed', $refund->status);
        $this->assertSame(1000, $refund->amount_minor);
        $this->assertSame('refunded', $reservation->fresh()->payment_status);
        Http::assertSent(fn ($r) => $r['payment_intent'] === 'pi_x' && (int) $r['amount'] === 1000);
    }

    public function test_partial_refund_uses_percent(): void
    {
        $this->fakeStripeRefundOk();
        $s = $this->prepare('auto', 50, 'immediate');
        $reservation = $this->paidReservation($s, 1000);

        $refund = app(RefundService::class)->requestForReservation($reservation);

        $this->assertSame(500, $refund->amount_minor);
        $this->assertSame('partially_refunded', $reservation->fresh()->payment_status);
        Http::assertSent(fn ($r) => (int) $r['amount'] === 500);
    }

    public function test_manual_mode_requires_approval(): void
    {
        $this->fakeStripeRefundOk();
        $s = $this->prepare('manual', 100, 'immediate');
        $reservation = $this->paidReservation($s);

        $refund = app(RefundService::class)->requestForReservation($reservation);
        $this->assertSame('pending', $refund->status);
        Http::assertNothingSent();

        $approved = app(RefundService::class)->approve($refund->fresh(), User::factory()->create());
        $this->assertSame('completed', $approved->status);
    }

    public function test_scheduled_mode_defers_to_batch(): void
    {
        $this->fakeStripeRefundOk();
        $s = $this->prepare('auto', 100, 'scheduled');
        $reservation = $this->paidReservation($s);

        $refund = app(RefundService::class)->requestForReservation($reservation);
        $this->assertSame('approved', $refund->status); // not processed inline
        Http::assertNothingSent();

        $processed = app(RefundService::class)->processDue();
        $this->assertSame(1, $processed);
        $this->assertSame('completed', $refund->fresh()->status);
    }

    public function test_no_refund_without_paid_intent(): void
    {
        $s = $this->prepare('auto');
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $s['tenant']->id, 'location_id' => $s['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addMinutes(120)->utc(),
            'timezone' => 'Europe/Berlin', 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Ohne Zahlung',
        ]);

        $this->assertNull(app(RefundService::class)->requestForReservation($reservation));
    }

    public function test_failed_refund_marked_failed(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['error' => 'x'], 402)]);
        $s = $this->prepare('auto', 100, 'immediate');
        $reservation = $this->paidReservation($s);

        $refund = app(RefundService::class)->requestForReservation($reservation);
        $this->assertSame('failed', $refund->status);
        $this->assertSame('paid', $reservation->fresh()->payment_status); // unchanged
    }
}
