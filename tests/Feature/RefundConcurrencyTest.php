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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
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

    // ── Zwei Antraege auf dieselbe Buchung ────────────────────────────────

    /**
     * Die Doppelpruefung in requestForReservation hing an
     * `lockForUpdate()->first()` auf refunds. Das sperrt in PostgreSQL nur
     * vorhandene Zeilen - beim ERSTEN Antrag trifft die Abfrage nichts, und in
     * READ COMMITTED gibt es keine Praedikatsperre. Beide Aufrufer bekamen
     * null, beide legten an, das Geld ging zweimal raus.
     *
     * Der Index ist der Rueckhalt unter der neuen Sperre auf der Reservierung.
     */
    public function test_the_database_refuses_a_second_open_refund_for_one_reservation(): void
    {
        $refund = $this->approvedRefund();

        $this->expectException(UniqueConstraintViolationException::class);

        Refund::create([
            'tenant_id' => $refund->tenant_id,
            'reservation_id' => $refund->reservation_id,
            'payment_intent_id' => $refund->payment_intent_id,
            'provider' => 'stripe', 'amount_minor' => 1000, 'currency' => 'EUR',
            'status' => 'pending', 'source' => 'guest_cancel', 'reason' => 'cancellation',
        ]);
    }

    /**
     * Abgelehnt und fehlgeschlagen duerfen mehrfach dastehen - sonst waere nach
     * einem Fehlversuch beim Anbieter kein zweiter Anlauf mehr moeglich.
     */
    public function test_a_failed_refund_does_not_block_a_new_attempt(): void
    {
        $refund = $this->approvedRefund();
        $refund->update(['status' => 'failed']);

        $zweiter = Refund::create([
            'tenant_id' => $refund->tenant_id,
            'reservation_id' => $refund->reservation_id,
            'payment_intent_id' => $refund->payment_intent_id,
            'provider' => 'stripe', 'amount_minor' => 1000, 'currency' => 'EUR',
            'status' => 'approved', 'source' => 'staff', 'reason' => 'cancellation',
        ]);

        $this->assertNotSame($refund->id, $zweiter->id);
        $this->assertSame(2, Refund::where('reservation_id', $refund->reservation_id)->count());
    }

    /**
     * Zweimal auf "Stornieren" geklickt: eine Erstattungszeile, ein Geldfluss.
     */
    public function test_two_cancellations_refund_the_guest_only_once(): void
    {
        Http::fake(['api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200)]);

        $vorhanden = $this->approvedRefund();
        $reservation = Reservation::withoutGlobalScopes()->findOrFail($vorhanden->reservation_id);
        $location = $reservation->location()->withoutGlobalScopes()->first();
        $location->settings()->update(['refund_mode' => 'auto', 'refund_percent' => 100]);
        $service = app(RefundService::class);

        $erster = $service->requestForReservation($reservation);
        $service->requestForReservation($reservation->fresh());

        $this->assertSame($vorhanden->id, $erster?->id);
        $this->assertSame(1, Refund::where('reservation_id', $reservation->id)->count());
        Http::assertSentCount(1);
    }

    /**
     * Der verlorene Wettlauf: Der andere Vorgang hat zwischen Pruefung und
     * Einfuegen committet, der Index schlaegt beim eigenen Insert zu.
     *
     * Nur mit zwei echten Verbindungen nachstellbar - eine zweite Zeile aus
     * derselben Transaktion rollte beim Abbruch mit zurueck. Geprueft wird
     * darum die Bergung selbst: Ausnahme rein, Zeile des Gewinners raus, kein
     * Absturz und kein zweiter Anlauf.
     */
    public function test_the_recovery_hands_back_the_winners_row_instead_of_failing(): void
    {
        $gewinner = $this->approvedRefund();
        $service = app(RefundService::class);

        $createOnce = new ReflectionMethod($service, 'createOnce');
        $ergebnis = $createOnce->invoke(
            $service,
            fn () => $gewinner,
            function () {
                throw new UniqueConstraintViolationException('pgsql', 'insert into refunds', [], new \RuntimeException('duplicate key'));
            }
        );

        $this->assertSame($gewinner->id, $ergebnis?->id);
    }
}
