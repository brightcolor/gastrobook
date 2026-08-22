<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Support\PaymentReference;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Kennungen an jeder Zahlung.
 *
 * Ein PayPal- oder Stripe-Konto wird selten nur fuer Swayy genutzt. Ohne eine
 * wiedererkennbare Kennung laesst sich von der Kontoseite her nicht sagen,
 * welche Zahlung aus dem Buchungssystem stammt.
 */
class PaymentReferenceTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function connect(array $setup, string $provider, array $credentials): void
    {
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => null,
            'provider' => $provider,
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode($credentials)),
        ]);
        $setup['tenant']->update(['feature_overrides' => ['deposits_enabled' => true]]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservationAwaitingPayment(array $setup): Reservation
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(3)->setTime(19, 0);

        return Reservation::create([
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
            'payment_status' => 'required',
            'payment_amount_minor' => 4000,
            'currency' => 'EUR',
            'payment_due_at' => now()->addHour(),
        ]);
    }

    // ── Die Kennung selbst ────────────────────────────────────────────────

    public function test_the_reference_starts_with_the_filter_prefix(): void
    {
        $kennung = PaymentReference::forBooking('sternenwald', 'res', 'R-ABC123');

        $this->assertSame('swayy:sternenwald:res:R-ABC123', $kennung);
        $this->assertStringStartsWith(PaymentReference::PREFIX.':', $kennung);
    }

    public function test_the_reference_stays_within_the_provider_limit(): void
    {
        $kennung = PaymentReference::forBooking(str_repeat('x', 200), 'res', 'R-ABC123');

        $this->assertLessThanOrEqual(127, mb_strlen($kennung));
    }

    /**
     * Der Kontoauszug erlaubt nur 22 Zeichen und einen engen Zeichenvorrat.
     */
    public function test_the_statement_name_is_trimmed_and_cleaned(): void
    {
        $this->assertSame('Sternenwald Wismar', PaymentReference::statementName('Sternenwald Wismar'));
        $this->assertLessThanOrEqual(22, mb_strlen(PaymentReference::statementName(str_repeat('Name ', 20))));

        // Nur der Zeichenvorrat, den die Anbieter annehmen - wie genau ein
        // Umlaut umschrieben wird, ist dabei egal.
        $umgeschrieben = PaymentReference::statementName('Café Möhrchen & Söhne');
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9 .-]+$/', $umgeschrieben);
        $this->assertStringContainsString('Caf', $umgeschrieben);

        // Nie leer - sonst weist der Anbieter die Zahlung zurueck.
        $this->assertSame('Reservierung', PaymentReference::statementName('***'));
    }

    // ── PayPal ────────────────────────────────────────────────────────────

    public function test_paypal_gets_reference_invoice_and_statement_name(): void
    {
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => Http::response([
                'id' => 'ORDER1',
                'links' => [['rel' => 'approve', 'href' => 'https://paypal.test/approve']],
            ], 201),
        ]);

        $setup = $this->createTenantSetup();
        $this->connect($setup, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox']);
        $reservation = $this->reservationAwaitingPayment($setup);
        $this->clearTenantContext();

        $this->get(route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]))
            ->assertRedirect();

        Http::assertSent(function (Request $request) use ($setup, $reservation) {
            if (! str_contains($request->url(), '/v2/checkout/orders')) {
                return false;
            }

            $einheit = $request->data()['purchase_units'][0];

            return $einheit['custom_id'] === 'swayy:'.$setup['tenant']->slug.':res:'.$reservation->code
                && $einheit['invoice_id'] !== ''
                && str_starts_with($einheit['invoice_id'], 'SWAYY-')
                && $einheit['soft_descriptor'] === PaymentReference::statementName($setup['location']->name);
        });
    }

    /**
     * PayPal meldet DUPLICATE_INVOICE_ID nur, wenn zu dieser Nummer bereits
     * kassiert wurde. Ein zweiter Anlauf ohne Rechnungsnummer - der
     * naheliegende Reflex - liesse den Gast ein zweites Mal zahlen. Also darf
     * genau das NICHT passieren.
     */
    public function test_a_duplicate_invoice_number_does_not_send_the_guest_to_pay_again(): void
    {
        $versuche = 0;

        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders' => function () use (&$versuche) {
                $versuche++;

                return Http::response([
                    'name' => 'UNPROCESSABLE_ENTITY',
                    'details' => [['issue' => 'DUPLICATE_INVOICE_ID']],
                ], 422);
            },
        ]);

        $setup = $this->createTenantSetup();
        $this->connect($setup, 'paypal', ['client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox']);
        $reservation = $this->reservationAwaitingPayment($setup);
        $this->clearTenantContext();

        $antwort = $this->get(route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]));

        // Zurueck auf die eigene Verwaltungsseite, nicht zu PayPal.
        $antwort->assertRedirect(route('booking.manage', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ]));
        $antwort->assertSessionHas('payment_already_settled');

        $this->assertSame(1, $versuche, 'Es wurde ein zweiter Bezahlvorgang angelegt.');

        // Der Betrieb muss davon erfahren - sonst sucht niemand nach dem Geld.
        $this->assertSame(1, AuditLog::where('tenant_id', $setup['tenant']->id)
            ->where('action', 'payment.already_settled_at_provider')
            ->count());
    }

    // ── Stripe ────────────────────────────────────────────────────────────

    public function test_stripe_gets_the_reference_in_its_metadata(): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response([
                'id' => 'cs_1',
                'url' => 'https://stripe.test/pay',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connect($setup, 'stripe', ['secret_key' => 'sk_test', 'webhook_secret' => 'whsec']);
        $reservation = $this->reservationAwaitingPayment($setup);
        $this->clearTenantContext();

        $this->get(route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]))
            ->assertRedirect('https://stripe.test/pay');

        Http::assertSent(function (Request $request) use ($setup, $reservation) {
            if (! str_contains($request->url(), '/v1/checkout/sessions')) {
                return false;
            }

            $daten = $request->data();

            return ($daten['metadata[reference]'] ?? null) === 'swayy:'.$setup['tenant']->slug.':res:'.$reservation->code
                && ($daten['metadata[source]'] ?? null) === PaymentReference::PREFIX
                && str_contains($daten['payment_intent_data[description]'] ?? '', $reservation->code);
        });
    }

    public function test_the_invoice_number_points_back_at_the_payment(): void
    {
        $setup = $this->createTenantSetup();
        $reservation = $this->reservationAwaitingPayment($setup);

        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'paypal',
            'type' => 'deposit',
            'amount_minor' => 4000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        $this->assertSame('SWAYY-'.$intent->id, PaymentReference::invoice($intent));
    }
}
