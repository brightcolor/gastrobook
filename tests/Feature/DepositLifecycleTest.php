<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Jobs\ExpireUnpaidReservations;
use App\Models\DepositRule;
use App\Models\NotificationLog;
use App\Models\PaymentIntent;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Der Weg einer anzahlungspflichtigen Buchung: vormerken, auffordern,
 * erinnern, und nach Fristablauf den Tisch wieder freigeben.
 */
class DepositLifecycleTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $regel
     * @return array{0: array<string, mixed>, 1: DepositRule}
     */
    private function setupWithRule(array $regel = []): array
    {
        $setup = $this->createTenantSetup();

        $rule = DepositRule::withoutGlobalScopes()->create(array_merge([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Grosse Gruppen',
            'type' => 'deposit',
            'min_party_size' => 4,
            'amount_per_person_minor' => 1000,
            'flat_amount_minor' => 0,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'cancel_unpaid_automatically' => true,
            'is_active' => true,
        ], $regel));

        return [$setup, $rule];
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function book(array $setup, int $partySize = 4): Reservation
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

    private function runExpiry(): void
    {
        (new ExpireUnpaidReservations)->handle(app(ReservationLifecycleService::class));
    }

    /**
     * Die Buchung so alt machen, wie der Fall es braucht. Die Halbzeit rechnet
     * der Lauf aus created_at und payment_due_at, also muessen beide zusammen
     * passen - payment_due_at allein zu verschieben ergaebe eine andere Frist
     * als gedacht.
     */
    private function age(Reservation $reservation, int $gebuchtVorMinuten, int $fristMinuten): void
    {
        $gebucht = now()->subMinutes($gebuchtVorMinuten);

        $reservation->forceFill([
            'created_at' => $gebucht,
            'payment_due_at' => $gebucht->copy()->addMinutes($fristMinuten),
        ])->saveQuietly();

        // Die Zahlungsaufforderung ging beim Anlegen raus - ihr Zeitstempel ist
        // die Bezugsgroesse fuer die Halbzeit und muss mitaltern.
        NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'payment_pending')
            ->update(['created_at' => $gebucht]);

        $reservation->refresh();
    }

    private function logCount(Reservation $reservation, string $key): int
    {
        return NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', $key)
            ->count();
    }

    // ── Anlegen ───────────────────────────────────────────────────────────

    public function test_a_booking_over_the_threshold_waits_for_the_deposit(): void
    {
        [$setup, $rule] = $this->setupWithRule();
        $this->clearTenantContext();

        $reservation = $this->book($setup);

        $this->assertSame(ReservationStatus::PaymentPending, $reservation->status);
        $this->assertSame('required', $reservation->payment_status);
        $this->assertSame(4000, $reservation->payment_amount_minor);
        $this->assertSame($rule->id, $reservation->deposit_rule_id);
        $this->assertNotNull($reservation->payment_due_at);
    }

    public function test_a_booking_below_the_threshold_is_confirmed_right_away(): void
    {
        [$setup] = $this->setupWithRule();
        $this->clearTenantContext();

        $reservation = $this->book($setup, 2);

        $this->assertSame('not_required', $reservation->payment_status);
        $this->assertNotSame(ReservationStatus::PaymentPending, $reservation->status);
    }

    /**
     * Der Gast bekam die Zahlungsaufforderung bis v1.111.0 nur auf der
     * Bestaetigungsseite zu sehen. Wer den Tab schloss, verlor den Tisch, ohne
     * je erfahren zu haben, warum.
     */
    public function test_the_guest_is_asked_for_the_deposit_by_mail(): void
    {
        Mail::fake();

        [$setup] = $this->setupWithRule();
        $this->clearTenantContext();

        $reservation = $this->book($setup);

        $log = NotificationLog::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('template_key', 'payment_pending')
            ->first();

        $this->assertNotNull($log, 'Es wurde keine Zahlungsaufforderung verschickt.');
        $this->assertSame('kessler@example.test', $log->recipient);
    }

    // ── Erinnerung ────────────────────────────────────────────────────────

    public function test_a_reminder_goes_out_once_half_the_deadline_has_passed(): void
    {
        Mail::fake();

        [$setup] = $this->setupWithRule();
        $this->clearTenantContext();
        $reservation = $this->book($setup);

        // Vor 15 Minuten gebucht, Frist 60 Minuten: erste Haelfte laeuft noch.
        $this->age($reservation, gebuchtVorMinuten: 15, fristMinuten: 60);
        $this->runExpiry();
        $this->assertSame(0, $this->logCount($reservation, 'payment_reminder'));

        // Vor 40 Minuten gebucht: Halbzeit ueberschritten.
        $this->age($reservation, gebuchtVorMinuten: 40, fristMinuten: 60);
        $this->runExpiry();
        $this->assertSame(1, $this->logCount($reservation, 'payment_reminder'));

        // Ein zweiter Lauf darf nicht nochmal erinnern.
        $this->runExpiry();
        $this->assertSame(1, $this->logCount($reservation, 'payment_reminder'));
    }

    // ── Fristablauf ───────────────────────────────────────────────────────

    public function test_an_unpaid_booking_releases_the_table_after_the_deadline(): void
    {
        Mail::fake();

        [$setup] = $this->setupWithRule();
        $this->clearTenantContext();
        $reservation = $this->book($setup);

        $reservation->update(['payment_due_at' => now()->subMinute()]);
        $this->runExpiry();

        $frisch = $reservation->fresh();
        $this->assertSame(ReservationStatus::Expired, $frisch->status);
        $this->assertSame('expired', $frisch->payment_status);
        $this->assertFalse($frisch->status->isActive(), 'Der Tisch ist weiterhin belegt.');
        $this->assertSame(1, $this->logCount($reservation, 'payment_expired'));
    }

    /**
     * „Kein Auto-Storno" ist eine bewusste Einstellung: Der Betrieb telefoniert
     * lieber hinterher, als den Tisch aufzugeben.
     */
    public function test_a_rule_without_auto_cancel_keeps_the_booking(): void
    {
        Mail::fake();

        [$setup] = $this->setupWithRule(['cancel_unpaid_automatically' => false]);
        $this->clearTenantContext();
        $reservation = $this->book($setup);

        $reservation->update(['payment_due_at' => now()->subMinute()]);
        $this->runExpiry();

        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
        $this->assertSame(0, $this->logCount($reservation, 'payment_expired'));
    }

    /**
     * Ein Gast klickt bei Minute 58 auf „Jetzt bezahlen" und ist noch beim
     * Zahlungsanbieter, wenn die Frist umschlaegt. Storniert man ihm dazwischen
     * den Tisch, bekommt er sein Geld zwar zurueck, hat aber puenktlich gezahlt
     * und steht ohne Tisch da.
     */
    public function test_a_running_checkout_holds_the_table_past_the_deadline(): void
    {
        Mail::fake();

        [$setup] = $this->setupWithRule();
        $this->clearTenantContext();
        $reservation = $this->book($setup);

        PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe',
            'type' => 'deposit',
            'amount_minor' => $reservation->payment_amount_minor,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);

        $reservation->update(['payment_due_at' => now()->subMinute()]);
        $this->runExpiry();

        $this->assertSame(ReservationStatus::PaymentPending, $reservation->fresh()->status);
    }

    public function test_a_stale_checkout_no_longer_holds_the_table(): void
    {
        Mail::fake();

        [$setup] = $this->setupWithRule();
        $this->clearTenantContext();
        $reservation = $this->book($setup);

        $intent = PaymentIntent::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'reservation_id' => $reservation->id,
            'provider' => 'stripe',
            'type' => 'deposit',
            'amount_minor' => $reservation->payment_amount_minor,
            'currency' => 'EUR',
            'status' => 'pending',
        ]);
        // Vor einer Stunde begonnen und nie abgeschlossen.
        $intent->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

        $reservation->update(['payment_due_at' => now()->subMinute()]);
        $this->runExpiry();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    /**
     * Wird die Regel nachtraeglich geloescht, bleibt `deposit_rule_id` leer.
     * Ohne Freigabe hinge die Buchung fuer immer und blockierte den Tisch.
     */
    public function test_a_booking_whose_rule_is_gone_is_still_released(): void
    {
        Mail::fake();

        [$setup, $rule] = $this->setupWithRule();
        $this->clearTenantContext();
        $reservation = $this->book($setup);

        $rule->delete();
        $reservation->update(['payment_due_at' => now()->subMinute()]);
        $this->runExpiry();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
    }

    /**
     * Nach dem Termin ist eine Absagemail nur noch Rauschen – der Status wird
     * trotzdem geradegezogen.
     */
    public function test_no_mail_goes_out_for_a_booking_in_the_past(): void
    {
        Mail::fake();

        [$setup] = $this->setupWithRule();
        $this->clearTenantContext();
        $reservation = $this->book($setup);

        $reservation->update([
            'payment_due_at' => now()->subDays(3),
            'start_at' => now()->subDays(2),
            'end_at' => now()->subDays(2)->addHours(2),
        ]);
        $this->runExpiry();

        $this->assertSame(ReservationStatus::Expired, $reservation->fresh()->status);
        $this->assertSame(0, $this->logCount($reservation, 'payment_expired'));
    }

    // ── Der Tisch ist danach wieder buchbar ───────────────────────────────

    public function test_the_released_table_can_be_booked_again(): void
    {
        Mail::fake();

        // Genau ein Tisch fuer vier Personen.
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        DepositRule::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Alle',
            'type' => 'deposit',
            'amount_per_person_minor' => 1000,
            'flat_amount_minor' => 0,
            'currency' => 'EUR',
            'payment_deadline_minutes' => 60,
            'cancel_unpaid_automatically' => true,
            'is_active' => true,
        ]);
        $this->clearTenantContext();

        $erste = $this->book($setup, 4);
        $this->assertSame(ReservationStatus::PaymentPending, $erste->status);

        $erste->update(['payment_due_at' => now()->subMinute()]);
        $this->runExpiry();
        $this->assertSame(ReservationStatus::Expired, $erste->fresh()->status);

        // Derselbe Platz, dieselbe Zeit – muss jetzt wieder gehen.
        $zweite = $this->book($setup, 4);
        $this->assertNotNull($zweite->id);
        $this->assertSame(ReservationStatus::PaymentPending, $zweite->status);
    }
}
