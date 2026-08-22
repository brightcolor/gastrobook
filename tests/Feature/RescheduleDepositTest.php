<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\DepositRule;
use App\Models\IntegrationConnection;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Umbuchen bewertet die Anzahlungspflicht neu.
 *
 * Vorher schrieb reschedule() nur Datum, Uhrzeit und Personenzahl. Betrag,
 * Regel, Status und Frist blieben stehen. Damit liess sich die Anzahlung
 * vollstaendig umgehen: fuer zwei Personen an einem Dienstag buchen, nichts
 * zahlen, danach per Aenderungslink auf zwoelf Personen am Samstagabend
 * umbuchen.
 */
class RescheduleDepositTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $abweichend
     * @return array{0: array<string, mixed>, 1: DepositRule}
     */
    private function setupWithRule(array $abweichend = []): array
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 2], ['min' => 2, 'max' => 12]]);

        $rule = DepositRule::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Grosse Gruppen',
            'type' => 'deposit',
            'min_party_size' => 6,
            'amount_per_person_minor' => 1000,
            'flat_amount_minor' => 0,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'cancel_unpaid_automatically' => true,
            'is_active' => true,
        ], $abweichend));

        return [$setup, $rule];
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function book(array $setup, int $partySize): Reservation
    {
        $tz = $setup['location']->timezone;

        return app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => $partySize,
            'start_local' => CarbonImmutable::now($tz)->addDays(3)->setTime(19, 0),
            'source' => 'online',
            'guest_name' => 'Frau Kessler',
            'guest_email' => 'kessler@example.test',
        ]);
    }

    private function moveTo(Reservation $reservation, int $partySize, int $tageVoraus = 4): Reservation
    {
        $tz = $reservation->timezone;

        return app(ReservationLifecycleService::class)->reschedule(
            $reservation,
            CarbonImmutable::now($tz)->addDays($tageVoraus)->setTime(19, 0),
            $partySize,
            null,
            'guest'
        );
    }

    // ── Der eigentliche Fehler ────────────────────────────────────────────

    public function test_growing_the_party_makes_the_deposit_due(): void
    {
        Mail::fake();
        [$setup] = $this->setupWithRule();

        // Zwei Personen: unter der Schwelle, keine Anzahlung.
        $reservation = $this->book($setup, 2);
        $this->assertSame('not_required', $reservation->payment_status);
        $this->assertSame(ReservationStatus::Confirmed, $reservation->status);

        $umgebucht = $this->moveTo($reservation, 12);

        $this->assertSame('required', $umgebucht->payment_status);
        $this->assertSame(12000, $umgebucht->payment_amount_minor);
        $this->assertSame(ReservationStatus::PaymentPending, $umgebucht->status);
        $this->assertNotNull($umgebucht->payment_due_at);
        $this->assertNotNull($umgebucht->deposit_rule_id);
    }

    /**
     * Die Gegenrichtung: Wer die Gruppe verkleinert, soll nicht auf einer
     * Forderung sitzenbleiben, die niemand mehr stellt - sonst raeumt der
     * Fristlauf die Buchung ab.
     */
    public function test_shrinking_the_party_drops_the_deposit_and_confirms(): void
    {
        Mail::fake();
        [$setup] = $this->setupWithRule();

        $reservation = $this->book($setup, 8);
        $this->assertSame(ReservationStatus::PaymentPending, $reservation->status);
        $this->assertSame(8000, $reservation->payment_amount_minor);

        $umgebucht = $this->moveTo($reservation, 2);

        $this->assertSame('not_required', $umgebucht->payment_status);
        $this->assertNull($umgebucht->payment_amount_minor);
        $this->assertNull($umgebucht->payment_due_at);
        $this->assertNull($umgebucht->deposit_rule_id);
        $this->assertSame(ReservationStatus::Confirmed, $umgebucht->status);
    }

    public function test_the_amount_follows_the_new_party_size(): void
    {
        Mail::fake();
        [$setup] = $this->setupWithRule();

        $reservation = $this->book($setup, 6);
        $this->assertSame(6000, $reservation->payment_amount_minor);

        $this->assertSame(10000, $this->moveTo($reservation, 10)->payment_amount_minor);
    }

    // ── Bereits gezahlt ───────────────────────────────────────────────────

    /**
     * Wer bezahlt hat, darf nicht per Aenderungslink in einen teureren Slot
     * rutschen - das waere derselbe Umweg, nur eine Stufe spaeter.
     */
    public function test_a_guest_cannot_move_a_paid_booking_into_a_pricier_slot(): void
    {
        Mail::fake();
        [$setup] = $this->setupWithRule();

        $reservation = $this->book($setup, 6);
        $reservation->forceFill(['payment_status' => 'paid'])->save();

        $this->expectException(ValidationException::class);
        $this->moveTo($reservation, 10);
    }

    public function test_a_paid_booking_may_move_within_what_it_covered(): void
    {
        Mail::fake();
        [$setup] = $this->setupWithRule();

        $reservation = $this->book($setup, 8);
        $reservation->forceFill(['payment_status' => 'paid'])->save();

        $umgebucht = $this->moveTo($reservation, 6);

        // Zu viel Gezahltes geht nicht automatisch zurueck - das entscheidet
        // der Betrieb, nicht der Aenderungslink.
        $this->assertSame('paid', $umgebucht->payment_status);
        $this->assertSame(8000, $umgebucht->payment_amount_minor);
    }

    /**
     * Der Betrieb kommt durch, aber die Luecke muss auffindbar sein.
     */
    public function test_staff_may_move_a_paid_booking_and_the_gap_is_logged(): void
    {
        Mail::fake();
        [$setup] = $this->setupWithRule();

        $reservation = $this->book($setup, 6);
        $reservation->forceFill(['payment_status' => 'paid'])->save();

        app(ReservationLifecycleService::class)->reschedule(
            $reservation,
            CarbonImmutable::now($reservation->timezone)->addDays(4)->setTime(19, 0),
            10,
            null,
            'staff'
        );

        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $setup['tenant']->id,
            'action' => 'reservation.deposit_shortfall',
        ]);
    }

    // ── Der Bezahlvorgang zieht mit ───────────────────────────────────────

    /**
     * Ein wiederverwendeter Zahlungsvorgang trug den Betrag von vor der
     * Umbuchung. Der Gast haette die Anzahlung fuer die alte Gruppengroesse
     * gezahlt und die Buchung waere trotzdem als bezahlt durchgegangen.
     */
    public function test_an_open_payment_intent_picks_up_the_new_amount(): void
    {
        Mail::fake();
        Http::fake([
            'api.stripe.com/v1/checkout/sessions' => Http::response(['id' => 'cs_2', 'url' => 'https://stripe.test/pay'], 200),
        ]);

        [$setup] = $this->setupWithRule();
        IntegrationConnection::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => null,
            'provider' => 'stripe',
            'status' => 'connected',
            'credentials_encrypted' => Crypt::encryptString(json_encode(['secret_key' => 'sk_test', 'webhook_secret' => 'whsec'])),
        ]);

        $reservation = $this->book($setup, 6);
        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe',
            'type' => 'deposit',
            'amount_minor' => 6000,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        $this->moveTo($reservation, 10);
        $this->assertSame(10000, $reservation->fresh()->payment_amount_minor);

        $this->clearTenantContext();
        $frisch = $reservation->fresh();
        $this->get(route('pay.reservation', ['code' => $frisch->code, 'token' => $frisch->manage_token]))
            ->assertRedirect('https://stripe.test/pay');

        // Kein zweiter Vorgang, aber der alte traegt jetzt den neuen Betrag.
        $this->assertSame(1, PaymentIntent::withoutGlobalScopes()->where('reservation_id', $reservation->id)->count());
        $this->assertSame(10000, $intent->fresh()->amount_minor);
    }
}
