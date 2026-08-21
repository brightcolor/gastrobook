<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Der Rueckweg von Stripe schliesst die Zahlung selbst ab.
 *
 * Vorher haing das allein am Webhook. War der bei Stripe nicht eingerichtet -
 * was die Anwendung nicht pruefen kann -, hatte der Gast bezahlt, sah eine
 * Bestaetigung, und die Reservierung verfiel trotzdem nach Fristablauf.
 */
class StripeReturnTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private const SESSION = 'cs_test_ABC123';

    /**
     * @param  array<string, mixed>  $setup
     */
    private function connectStripe(array $setup): void
    {
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => null,
            'provider' => 'stripe',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'secret_key' => 'sk_test_x',
                'webhook_secret' => 'whsec_x',
            ])),
        ]);
        $setup['tenant']->update(['feature_overrides' => ['deposits_enabled' => true]]);
    }

    /**
     * @param  array<string, mixed>  $setup
     * @return array{0: Reservation, 1: PaymentIntent}
     */
    private function pendingPayment(array $setup): array
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);

        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 4,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::PaymentPending,
            'source' => 'online',
            'guest_name_snapshot' => 'Frau Kessler',
            'guest_email_snapshot' => 'kessler@example.test',
            'payment_status' => 'pending',
            'payment_amount_minor' => 4000,
            'currency' => 'EUR',
            'payment_due_at' => now()->addMinutes(30),
        ]);

        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe',
            'provider_intent_id' => self::SESSION,
            'type' => 'deposit',
            'amount_minor' => 4000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        return [$reservation, $intent];
    }

    private function returnUrl(PaymentIntent $intent, string $session = self::SESSION): string
    {
        return route('pay.stripe.return', ['intent' => $intent->id]).'?session_id='.$session;
    }

    // ── Der eigentliche Zweck ─────────────────────────────────────────────

    public function test_a_paid_session_confirms_the_reservation_without_any_webhook(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => self::SESSION,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_999',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        [$reservation, $intent] = $this->pendingPayment($setup);
        $this->clearTenantContext();

        $this->get($this->returnUrl($intent))->assertRedirect();

        $frisch = $reservation->fresh();
        $this->assertSame(ReservationStatus::Confirmed, $frisch->status);
        $this->assertSame('paid', $frisch->payment_status);

        $intent->refresh();
        $this->assertSame('paid', $intent->status);
        // Die Referenz fuer eine spaetere Erstattung muss mitkommen.
        $this->assertSame('pi_test_999', $intent->metadata['refund_ref'] ?? null);
    }

    /**
     * Kartenzahlungen sind sofort da, Lastschrift und Sofortueberweisung nicht.
     * Dann darf der Rueckweg nichts bestaetigen - der Webhook holt es nach.
     */
    public function test_an_unpaid_session_changes_nothing(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => self::SESSION,
                'payment_status' => 'unpaid',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        [$reservation, $intent] = $this->pendingPayment($setup);
        $this->clearTenantContext();

        $this->get($this->returnUrl($intent))->assertRedirect();

        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
        $this->assertSame('pending', $intent->fresh()->status);
    }

    /**
     * Ist Stripe gerade nicht erreichbar, bleibt alles stehen - keine
     * Bestaetigung auf Verdacht.
     */
    public function test_an_unreachable_stripe_does_not_confirm_anything(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response(['error' => 'boom'], 500),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        [$reservation, $intent] = $this->pendingPayment($setup);
        $this->clearTenantContext();

        $this->get($this->returnUrl($intent))->assertRedirect();

        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
        $this->assertSame('pending', $intent->fresh()->status);
    }

    // ── Absicherung ───────────────────────────────────────────────────────

    /**
     * Ohne die Sitzungskennung aus dem Ruecksprung ist der Aufruf wertlos -
     * sonst koennte jemand fremde Vorgaenge durchprobieren.
     */
    public function test_a_wrong_session_id_is_rejected(): void
    {
        Http::fake();

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        [, $intent] = $this->pendingPayment($setup);
        $this->clearTenantContext();

        $this->get($this->returnUrl($intent, 'cs_test_FALSCH'))->assertForbidden();
        $this->get(route('pay.stripe.return', ['intent' => $intent->id]))->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * Zweimal zurueckkommen darf nicht zweimal buchen.
     */
    public function test_returning_twice_is_harmless(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => self::SESSION,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_999',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        [$reservation, $intent] = $this->pendingPayment($setup);
        $this->clearTenantContext();

        $this->get($this->returnUrl($intent))->assertRedirect();
        $this->get($this->returnUrl($intent))->assertRedirect();

        $this->assertSame(ReservationStatus::Confirmed, $reservation->fresh()->status);
        // Genau ein Statuswechsel, nicht zwei.
        $this->assertSame(1, $reservation->statusHistories()->where('to_status', 'confirmed')->count());
    }

    // ── Sichtbarkeit fuer den Betrieb ─────────────────────────────────────

    /**
     * Der Rueckweg allein darf nicht so aussehen, als sei der Webhook
     * eingerichtet - sonst faellt ein fehlender Endpunkt nie auf.
     */
    public function test_the_return_path_does_not_look_like_a_webhook(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => self::SESSION,
                'payment_status' => 'paid',
                'payment_intent' => 'pi_test_999',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        [, $intent] = $this->pendingPayment($setup);
        $this->clearTenantContext();

        $this->get($this->returnUrl($intent))->assertRedirect();

        $this->assertSame(0, AuditLog::where('tenant_id', $setup['tenant']->id)
            ->where('action', 'payment.webhook_received')
            ->count());
    }

    public function test_the_settings_page_warns_while_no_webhook_has_arrived(): void
    {
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('Vom Webhook kam bisher nichts an.');
    }

    public function test_the_settings_page_confirms_a_webhook_that_arrived(): void
    {
        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $admin = $this->createMember($setup['tenant'], 'tenant_owner');

        AuditLog::create([
            'tenant_id' => $setup['tenant']->id,
            'action' => 'payment.webhook_received',
            'entity_type' => Tenant::class,
            'entity_id' => $setup['tenant']->id,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/settings')
            ->assertOk()
            ->assertSee('Der Webhook hat sich zuletzt am')
            ->assertDontSee('Vom Webhook kam bisher nichts an.');
    }
}
