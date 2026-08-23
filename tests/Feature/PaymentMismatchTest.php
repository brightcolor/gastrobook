<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\ExpireUnpaidReservations;
use App\Mail\TemplatedMail;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Services\RefundService;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Eine Zahlung, deren Betrag nicht passt.
 *
 * Der Abgleich verweigert die Buchung - kassiert ist das Geld trotzdem. Der
 * Weg zurueck hat drei Fallen, die alle einmal zugeschnappt sind: Die
 * Erstattung des falschen Betrags belegte den Platz der spaeteren
 * Stornoerstattung; sie holte sich ihr Geld von der falschen Belastung, weil
 * die Referenz am wiederverwendeten Vorgang stand; und ein zweiter falscher
 * Betrag verschwand ganz.
 */
class PaymentMismatchTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function connectStripe(array $setup, string $refundMode = 'manual'): void
    {
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => null,
            'provider' => 'stripe',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'secret_key' => 'sk_test', 'webhook_secret' => 'whsec_test',
            ])),
        ]);
        $setup['tenant']->update(['feature_overrides' => ['deposits_enabled' => true]]);
        $setup['location']->settings->update([
            'refund_mode' => $refundMode,
            'refund_percent' => 100,
            'refund_processing' => 'immediate',
            'owner_notification_email' => 'betrieb@example.test',
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function awaitingPayment(array $setup): Reservation
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
            'payment_amount_minor' => 6000,
            'currency' => 'EUR',
            'payment_due_at' => now()->addMinutes(30),
        ]);
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function intent(array $setup, Reservation $reservation, int $amount = 6000): PaymentIntent
    {
        return PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_neu',
            'type' => 'deposit', 'amount_minor' => $amount, 'currency' => 'EUR', 'status' => 'pending',
            'metadata' => ['sessions' => ['cs_alt', 'cs_zwei', 'cs_neu']],
        ]);
    }

    private function fakeStripe(int $betrag, string $belastung, string $waehrung = 'eur'): void
    {
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_alt', 'payment_status' => 'paid', 'payment_intent' => $belastung,
                'amount_total' => $betrag, 'currency' => $waehrung,
            ], 200),
            'api.stripe.com/v1/refunds' => Http::response(['id' => 're_1', 'status' => 'succeeded'], 200),
        ]);
    }

    /**
     * Der schlimmste der drei: Die Erstattung des falschen Betrags belegte im
     * Eindeutigkeitsindex den Platz "offene Erstattung dieser Reservierung" -
     * und zwar dauerhaft. Zahlte der Gast danach richtig und stornierte
     * spaeter, lief die Stornoerstattung in die Verletzung und wurde lautlos
     * verworfen. Kein Fehler, kein Eintrag, kein Geld.
     */
    public function test_a_mismatch_refund_does_not_block_a_later_cancellation_refund(): void
    {
        Mail::fake();
        $this->fakeStripe(1000, 'pi_falsch');

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup);
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');
        $this->assertSame(1, Refund::withoutGlobalScopes()->count());

        // Danach zahlt der Gast richtig - und storniert.
        $intent->update([
            'status' => 'paid',
            'metadata' => array_merge($intent->metadata ?? [], ['refund_ref' => 'pi_richtig']),
        ]);
        $storno = app(RefundService::class)->requestForReservation($reservation->fresh(), 'guest_cancel');

        $this->assertNotNull($storno, 'Die Stornoerstattung ist lautlos verschwunden.');
        $this->assertSame(6000, $storno->amount_minor);
        $this->assertSame('pi_richtig', $storno->charge_reference);
    }

    /**
     * Und sie holt das Geld von der RICHTIGEN Belastung: Der Vorgang wird
     * wiederverwendet, seine Referenz zeigte nach der zweiten Zahlung auf die
     * gute. Die wartende Erstattung des falschen Betrags nahm sich ihre 10 Euro
     * dann von dort - die falsche Zahlung blieb beim Anbieter liegen.
     */
    public function test_a_waiting_mismatch_refund_targets_the_charge_it_belongs_to(): void
    {
        Mail::fake();
        $this->fakeStripe(1000, 'pi_falsch');

        $setup = $this->createTenantSetup();
        // 'manual': Die Erstattung wartet auf Freigabe - der Normalfall.
        $this->connectStripe($setup, 'manual');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');
        $refund = Refund::withoutGlobalScopes()->sole();
        $this->assertSame('pending', $refund->status);

        // Der Gast zahlt richtig; am Vorgang steht jetzt die neue Belastung.
        $intent->update([
            'status' => 'paid',
            'metadata' => array_merge($intent->metadata ?? [], ['refund_ref' => 'pi_richtig']),
        ]);

        $refund->update(['status' => 'approved']);
        $this->assertTrue(app(RefundService::class)->process($refund->fresh()));

        Http::assertSent(fn ($request) => str_contains((string) $request->url(), '/refunds')
            && ($request['payment_intent'] ?? null) === 'pi_falsch');
    }

    /**
     * Zwei alte Bezahlseiten, zweimal falsch gezahlt: Auf den VORGANG bezogen
     * fand der zweite Anlauf die erste Erstattung und liess es dabei bewenden.
     * Der Gast war 25 Euro los und bekam 10 zurueck.
     */
    public function test_a_second_wrong_payment_gets_its_own_refund(): void
    {
        Mail::fake();
        // Als Folge, nicht als zwei Aufrufe von Http::fake: Ein zweiter Aufruf
        // ergaenzt die Attrappen nur, die erste Regel gewinnt weiterhin - beide
        // Rueckwege bekaemen dieselbe Antwort.
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::sequence()
                ->push(['id' => 'cs_alt', 'payment_status' => 'paid', 'payment_intent' => 'pi_falsch_eins', 'amount_total' => 1000, 'currency' => 'eur'])
                ->push(['id' => 'cs_alt', 'payment_status' => 'paid', 'payment_intent' => 'pi_falsch_zwei', 'amount_total' => 1500, 'currency' => 'eur']),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'manual');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');
        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');

        $betraege = Refund::withoutGlobalScopes()->orderBy('id')->pluck('amount_minor')->all();
        $this->assertSame([1000, 1500], $betraege);
    }

    /**
     * Derselbe Rueckweg zweimal aufgerufen legt keine zweite Erstattung an -
     * und schickt auch keine zweite Mail. Die Adresse liegt beim Gast; ein
     * Neuladen mailte dem Betrieb sonst bei jedem Druck auf F5.
     */
    public function test_reloading_the_return_url_neither_refunds_nor_mails_twice(): void
    {
        Mail::fake();
        $this->fakeStripe(1000, 'pi_falsch');

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'manual');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation);
        $this->clearTenantContext();

        foreach (range(1, 3) as $ignored) {
            $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');
        }

        $this->assertSame(1, Refund::withoutGlobalScopes()->count());
        Mail::assertQueuedCount(1);
    }

    /**
     * Meldet der Anbieter keine Belegnummer, laesst sich nichts ausloesen -
     * gemeldet gehoert es dann erst recht. Vorher endete genau dieser Fall
     * wortlos, und er ist der, in dem das Geld am schwersten zu finden ist.
     */
    public function test_a_mismatch_without_a_charge_reference_still_reaches_the_operator(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions/*' => Http::response([
                'id' => 'cs_alt', 'payment_status' => 'paid',
                'amount_total' => 1000, 'currency' => 'eur',
            ], 200),
        ]);

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'manual');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');

        $this->assertSame(0, Refund::withoutGlobalScopes()->count());
        Mail::assertQueued(TemplatedMail::class, fn (TemplatedMail $mail) => $mail->hasTo('betrieb@example.test'));
    }

    /**
     * Ueberzahlung: Der Gast hat MEHR gezahlt als am Vorgang steht. Gedeckelt
     * am Vorgangsbetrag scheiterte die Rueckzahlung an der eigenen Grenze, und
     * das Geld blieb beim Anbieter.
     */
    public function test_an_overpayment_is_refunded_in_full(): void
    {
        Mail::fake();
        $this->fakeStripe(10000, 'pi_zuviel');

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'auto');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation, 6000);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');

        $refund = Refund::withoutGlobalScopes()->sole();
        $this->assertSame(10000, $refund->amount_minor);
        $this->assertSame('completed', $refund->status);
    }

    /**
     * Eine Erstattung des falschen Betrags verbucht nichts: Die Zahlung galt
     * nie als eingegangen. Stuende danach "erstattet" an der Buchung, zaehlte
     * sie als abgeschlossen bezahlt - der Fristablauf laesst solche Buchungen
     * bewusst stehen, und der Tisch waere bis zum Termin blockiert.
     */
    public function test_a_mismatch_refund_leaves_the_booking_unpaid_and_expirable(): void
    {
        Mail::fake();
        $this->fakeStripe(1000, 'pi_falsch');

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'auto');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');
        $this->assertSame('completed', Refund::withoutGlobalScopes()->sole()->status);

        $frisch = $reservation->fresh();
        $this->assertSame('required', $frisch->payment_status, 'Die Buchung darf nicht als erstattet gelten.');
        $this->assertNotSame('refunded', $intent->fresh()->status);

        // Und die Frist greift ganz normal. Der Vorgang muss dafuer aus der
        // Checkout-Kulanz heraus sein - ein frisch angefasster schuetzt die
        // Buchung fuenfzehn Minuten lang, und das ist hier nicht der Punkt.
        $frisch->forceFill(['payment_due_at' => now()->subMinute()])->save();
        PaymentIntent::withoutGlobalScopes()->whereKey($intent->id)
            ->update(['updated_at' => now()->subHour()]);
        (new ExpireUnpaidReservations)->handle(app(ReservationLifecycleService::class));
        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    /**
     * Und die beiden Toepfe rechnen getrennt: Was wegen eines falschen Betrags
     * zurueckging, darf die spaetere Stornoerstattung nicht an deren Deckel
     * scheitern lassen.
     */
    public function test_the_two_refund_buckets_do_not_cap_each_other(): void
    {
        Mail::fake();
        $this->fakeStripe(1000, 'pi_falsch');

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'auto');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt');

        $intent->update([
            'status' => 'paid',
            'metadata' => array_merge($intent->metadata ?? [], ['refund_ref' => 'pi_richtig']),
        ]);
        $storno = app(RefundService::class)->requestForReservation($reservation->fresh(), 'guest_cancel');

        $this->assertNotNull($storno);
        $this->assertSame('completed', $storno->fresh()->status, 'Die Stornoerstattung ist am Deckel des anderen Topfes gescheitert.');
    }

    /**
     * Und der Hinweis muss auch bei einer Eventbuchung ankommen: Deren
     * Verwaltungsseite ist ein anderes Blatt, und dort fehlte er.
     */
    public function test_the_event_manage_page_shows_the_mismatch_warning(): void
    {
        Mail::fake();
        $this->fakeStripe(1000, 'pi_falsch');

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'manual');

        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(7)->setTime(19, 0);
        $event = Event::withoutGlobalScope('tenant')->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'title' => 'Weinprobe', 'slug' => 'weinprobe',
            'starts_at' => $start->utc(), 'ends_at' => $start->addHours(4)->utc(),
            'capacity' => 10, 'price_minor' => 6000, 'currency' => 'EUR',
            'is_public' => true, 'status' => 'published',
        ]);
        $booking = EventBooking::create([
            'tenant_id' => $setup['tenant']->id, 'event_id' => $event->id,
            'ticket_count' => 1, 'guest_name' => 'Eva Event', 'guest_email' => 'eva@example.test',
            'status' => 'confirmed', 'payment_status' => 'pending', 'amount_minor' => 6000,
        ]);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'event_booking_id' => $booking->id,
            'provider' => 'stripe', 'provider_intent_id' => 'cs_neu',
            'type' => 'prepayment', 'amount_minor' => 6000, 'currency' => 'EUR', 'status' => 'pending',
            'metadata' => ['sessions' => ['cs_alt', 'cs_neu']],
        ]);
        $this->clearTenantContext();

        $this->followingRedirects()
            ->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt')
            ->assertOk()
            ->assertSee('Der gezahlte Betrag passt nicht', false);

        $this->assertSame($booking->id, Refund::withoutGlobalScopes()->sole()->event_booking_id);
    }

    /**
     * Der Abgleich gab es nur fuer Stripe. PayPal nahm jeden Betrag an, den
     * der Anbieter meldete - eine alte, noch offene Bestellung ueber zehn Euro
     * schloss damit eine Anzahlung ueber sechzig ab.
     */
    public function test_paypal_refuses_a_capture_over_the_wrong_amount(): void
    {
        Mail::fake();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['access_token' => 'tok'], 200),
            'api-m.sandbox.paypal.com/v2/checkout/orders/*/capture' => Http::response([
                'status' => 'COMPLETED',
                'purchase_units' => [['payments' => ['captures' => [[
                    'id' => 'CAP_FALSCH',
                    'amount' => ['value' => '10.00', 'currency_code' => 'EUR'],
                ]]]]],
            ], 201),
            'api-m.sandbox.paypal.com/v2/payments/captures/*/refund' => Http::response(['id' => 're_1', 'status' => 'COMPLETED'], 201),
        ]);

        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['feature_overrides' => ['deposits_enabled' => true]]);
        $setup['location']->settings->update([
            'refund_mode' => 'manual', 'owner_notification_email' => 'betrieb@example.test',
        ]);
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null,
            'provider' => 'paypal', 'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode([
                'client_id' => 'cid', 'secret' => 'sec', 'mode' => 'sandbox',
            ])),
        ]);

        $reservation = $this->awaitingPayment($setup);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'paypal', 'provider_intent_id' => 'ORDER123',
            'type' => 'deposit', 'amount_minor' => 6000, 'currency' => 'EUR', 'status' => 'pending',
        ]);
        $this->clearTenantContext();

        $this->get(route('pay.paypal.return', ['intent' => $intent->id]).'?token=ORDER123')
            ->assertRedirect()
            ->assertSessionHas('payment_amount_mismatch');

        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);

        $refund = Refund::withoutGlobalScopes()->sole();
        $this->assertSame(1000, $refund->amount_minor);
        $this->assertSame('CAP_FALSCH', $refund->charge_reference);
    }

    /**
     * Sechzig ist nicht sechzig, wenn das eine Euro und das andere Zloty sind.
     * Verglichen wurde nur die Zahl.
     */
    public function test_the_same_number_in_a_different_currency_is_a_mismatch(): void
    {
        Mail::fake();
        $this->fakeStripe(6000, 'pi_pln', 'pln');

        $setup = $this->createTenantSetup();
        $this->connectStripe($setup, 'manual');
        $reservation = $this->awaitingPayment($setup);
        $intent = $this->intent($setup, $reservation, 6000);
        $this->clearTenantContext();

        $this->get(route('pay.stripe.return', ['intent' => $intent->id]).'?session_id=cs_alt')
            ->assertSessionHas('payment_amount_mismatch');

        $this->assertSame('pending', $intent->fresh()->status);
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
        $this->assertSame(1, Refund::withoutGlobalScopes()->count());
    }
}
