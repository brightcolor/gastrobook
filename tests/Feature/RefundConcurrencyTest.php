<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Services\RefundService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RefundConcurrencyTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function approvedRefund(): Refund
    {
        $s = $this->createTenantSetup();
        $s['tenant']->update(['feature_overrides' => ['deposits_enabled' => true]]);
        IntegrationConnection::create([
            'tenant_id' => $s['tenant']->id, 'location_id' => null, 'provider' => 'stripe', 'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['secret_key' => 'sk_x', 'webhook_secret' => 'wh_x'])),
        ]);

        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $s['tenant']->id, 'location_id' => $s['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addMinutes(120)->utc(),
            'timezone' => 'Europe/Berlin', 'status' => ReservationStatus::CancelledByGuest, 'source' => 'online',
            'guest_name_snapshot' => 'Gast', 'payment_status' => 'paid', 'payment_amount_minor' => 1000, 'currency' => 'EUR',
        ]);
        $intent = PaymentIntent::create([
            'tenant_id' => $s['tenant']->id, 'reservation_id' => $reservation->id, 'provider' => 'stripe',
            'provider_intent_id' => 'cs_x', 'type' => 'deposit', 'amount_minor' => 1000, 'currency' => 'EUR',
            'status' => 'paid', 'metadata' => ['refund_ref' => 'pi_x'],
        ]);

        return Refund::create([
            'tenant_id' => $s['tenant']->id, 'reservation_id' => $reservation->id, 'payment_intent_id' => $intent->id,
            'provider' => 'stripe', 'amount_minor' => 1000, 'currency' => 'EUR', 'status' => 'approved',
            'source' => 'staff', 'reason' => 'cancellation',
        ]);
    }

    public function test_process_twice_refunds_provider_only_once(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);
        $refund = $this->approvedRefund();
        $service = app(RefundService::class);

        $this->assertTrue($service->process($refund));
        // Second call (e.g. scheduler firing after the retry button) must be a no-op.
        $this->assertTrue($service->process($refund->fresh()));

        Http::assertSentCount(1);
        $this->assertSame('completed', $refund->fresh()->status);
    }

    public function test_already_processing_refund_is_not_executed_again(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);
        $refund = $this->approvedRefund();
        $refund->update(['status' => 'processing']); // simulate another worker mid-flight

        $this->assertFalse(app(RefundService::class)->process($refund));
        Http::assertNothingSent();
    }
}
