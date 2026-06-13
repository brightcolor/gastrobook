<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\IntegrationConnection;
use App\Models\Location;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\Payments\PaymentProviderManager;
use App\Services\Payments\PayPalProvider;
use App\Services\Payments\StripeProvider;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentProvidersTest extends TestCase
{
    use RefreshDatabase;

    private function tenantWithDeposits(): Tenant
    {
        return Tenant::factory()->create(['feature_overrides' => ['deposits_enabled' => true]]);
    }

    private function connect(Tenant $tenant, string $provider, array $credentials): void
    {
        IntegrationConnection::create([
            'tenant_id' => $tenant->id,
            'location_id' => null,
            'provider' => $provider,
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode($credentials)),
        ]);
    }

    public function test_manager_lists_both_providers(): void
    {
        $tenant = $this->tenantWithDeposits();
        $this->connect($tenant, 'stripe', ['secret_key' => 'sk_x', 'webhook_secret' => 'wh_x']);
        $this->connect($tenant, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox']);

        $manager = new PaymentProviderManager;
        $available = $manager->available($tenant);

        $this->assertArrayHasKey('stripe', $available);
        $this->assertArrayHasKey('paypal', $available);
        $this->assertInstanceOf(StripeProvider::class, $manager->provider($tenant, 'stripe'));
        $this->assertInstanceOf(PayPalProvider::class, $manager->provider($tenant, 'paypal'));
    }

    public function test_no_providers_without_deposit_feature(): void
    {
        $tenant = Tenant::factory()->create(['feature_overrides' => ['deposits_enabled' => false]]);
        $this->connect($tenant, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox']);

        $this->assertSame([], (new PaymentProviderManager)->available($tenant));
    }

    public function test_paypal_provider_creates_order_and_captures(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER123',
                'links' => [['rel' => 'approve', 'href' => 'https://www.sandbox.paypal.com/checkoutnow?token=ORDER123']],
            ], 201),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123/capture' => Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1']]]]],
            ], 201),
        ]);

        $intent = new PaymentIntent(['amount_minor' => 2500, 'currency' => 'EUR']);
        $intent->id = 1;

        $provider = new PayPalProvider('cid', 'sec', 'sandbox');
        $session = $provider->createCheckout($intent, [
            'description' => 'Anzahlung', 'success_url' => 'https://app/return', 'cancel_url' => 'https://app/cancel',
        ]);

        $this->assertSame('ORDER123', $session['id']);
        $this->assertStringContainsString('paypal.com', $session['url']);
        $this->assertSame('CAP1', $provider->captureOrder('ORDER123'));
    }

    public function test_chooser_shown_when_both_providers_active(): void
    {
        $tenant = $this->tenantWithDeposits();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $this->connect($tenant, 'stripe', ['secret_key' => 'sk_x', 'webhook_secret' => 'wh_x']);
        $this->connect($tenant, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox']);

        $reservation = $this->paymentReservation($tenant, $location);

        $this->get(route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]))
            ->assertOk()
            ->assertSee('Zahlungsart wählen')
            ->assertSee('PayPal')
            ->assertSee('Stripe');
    }

    public function test_paypal_return_captures_and_confirms_reservation(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/ORDER123/capture' => Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [['id' => 'CAP1']]]]],
            ], 201),
        ]);

        $tenant = $this->tenantWithDeposits();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $this->connect($tenant, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox']);

        $reservation = $this->paymentReservation($tenant, $location, ReservationStatus::PaymentPending);
        $intent = PaymentIntent::create([
            'tenant_id' => $tenant->id,
            'reservation_id' => $reservation->id,
            'provider' => 'paypal',
            'provider_intent_id' => 'ORDER123',
            'type' => 'deposit',
            'amount_minor' => 1000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        $this->get(route('pay.paypal.return', ['intent' => $intent->id]).'?token=ORDER123')
            ->assertRedirect();

        $this->assertSame('paid', $intent->fresh()->status);
        $this->assertSame('paid', $reservation->fresh()->payment_status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
    }

    public function test_paypal_return_rejects_wrong_order_token(): void
    {
        $tenant = $this->tenantWithDeposits();
        $location = Location::factory()->create(['tenant_id' => $tenant->id]);
        $reservation = $this->paymentReservation($tenant, $location, ReservationStatus::PaymentPending);
        $intent = PaymentIntent::create([
            'tenant_id' => $tenant->id, 'reservation_id' => $reservation->id, 'provider' => 'paypal',
            'provider_intent_id' => 'ORDER123', 'type' => 'deposit', 'amount_minor' => 1000, 'currency' => 'EUR', 'status' => 'pending',
        ]);

        $this->get(route('pay.paypal.return', ['intent' => $intent->id]).'?token=WRONG')
            ->assertForbidden();
    }

    private function paymentReservation(Tenant $tenant, Location $location, ReservationStatus $status = ReservationStatus::PaymentPending): Reservation
    {
        $start = CarbonImmutable::now('Europe/Berlin')->addDay()->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $tenant->id,
            'location_id' => $location->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addMinutes(120)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => $status,
            'source' => 'online',
            'guest_name_snapshot' => 'Zahler',
            'guest_email_snapshot' => 'zahler@example.test',
            'payment_status' => 'pending',
            'payment_amount_minor' => 1000,
            'currency' => 'EUR',
        ]);
    }
}
