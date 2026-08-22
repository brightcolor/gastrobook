<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EventBooking;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Flexible deposit refunds: off / manual (staff approval) / auto, each either
 * processed immediately or in a scheduled batch. Refund amount is a configurable
 * percentage of the paid deposit.
 */
class RefundService
{
    public function __construct(
        private readonly PaymentProviderManager $payments,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * No-show protection: the paid deposit is kept by the restaurant instead of
     * being refunded. Marks the reservation's payment as forfeited, records it
     * in the audit log and cancels a still-pending refund request (e.g. from an
     * earlier cancellation attempt) so no money goes back by accident.
     *
     * Returns the forfeited amount in minor units, or null when there was no
     * paid deposit to keep.
     */
    public function forfeitForNoShow(Reservation $reservation, ?User $actor = null): ?int
    {
        $intent = PaymentIntent::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'paid')
            ->latest()
            ->first();

        if ($intent === null) {
            return null;
        }

        return DB::transaction(function () use ($reservation, $intent, $actor) {
            // A refund that has not been paid out yet must not be processed
            // anymore — the deposit is being kept instead.
            $pending = Refund::withoutGlobalScopes()
                ->where('reservation_id', $reservation->id)
                ->whereIn('status', ['pending', 'approved'])
                ->lockForUpdate()
                ->get();

            foreach ($pending as $refund) {
                $refund->update(['status' => 'rejected', 'error' => 'no_show_forfeit']);
                $this->audit->log('refund.rejected', $refund, null, [
                    'reason' => 'no_show_forfeit',
                ], null, $actor, $reservation->tenant_id);
            }

            $reservation->update(['payment_status' => 'forfeited']);

            $this->audit->log('payment.forfeited', $reservation, null, [
                'amount_minor' => (int) $intent->amount_minor,
                'currency' => $intent->currency,
                'reason' => 'no_show',
            ], null, $actor, $reservation->tenant_id);

            return (int) $intent->amount_minor;
        });
    }

    /**
     * Create a refund request for a cancelled reservation according to the
     * location's refund policy. Returns null when refunds are off or there is
     * nothing refundable.
     */
    public function requestForReservation(Reservation $reservation, string $source = 'guest_cancel', ?User $actor = null): ?Refund
    {
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        if ($location === null) {
            return null;
        }

        $settings = $location->effectiveSettings();
        $mode = $settings->refund_mode;
        if ($mode === 'off') {
            return null;
        }

        $intent = PaymentIntent::withoutGlobalScopes()
            ->where('reservation_id', $reservation->id)
            ->where('status', 'paid')
            ->latest()
            ->first();
        if ($intent === null || empty($intent->metadata['refund_ref'])) {
            return null;
        }

        // Zwei gleichzeitige Aufrufe (Gast klickt zweimal auf Stornieren, oder
        // Gast und Mitarbeiter sagen dieselbe Buchung gleichzeitig ab) duerfen
        // nicht beide eine Erstattungszeile anlegen - sonst geht das Geld
        // zweimal raus.
        //
        // Gesperrt wird die RESERVIERUNG, nicht die noch nicht vorhandene
        // Erstattungszeile: SELECT ... FOR UPDATE sperrt nur Zeilen, die es
        // schon gibt. Beim ersten Durchlauf trifft die Abfrage auf refunds
        // nichts, ein Praedikatsperre gibt es in READ COMMITTED nicht - beide
        // Aufrufer bekaemen null und wuerden beide einfuegen. Die
        // Reservierungszeile existiert dagegen immer und traegt die
        // Serialisierung.
        $refund = $this->createOnce(
            fn () => Refund::withoutGlobalScopes()
                ->where('reservation_id', $reservation->id)
                ->whereNotIn('status', ['rejected', 'failed'])
                ->first(),
            function () use ($reservation, $intent, $settings, $mode, $source, $actor) {
                Reservation::withoutGlobalScope('tenant')->lockForUpdate()->find($reservation->id);

                $existing = Refund::withoutGlobalScopes()
                    ->where('reservation_id', $reservation->id)
                    ->whereNotIn('status', ['rejected', 'failed'])
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                $percent = max(0, min(100, (int) $settings->refund_percent));
                $amount = (int) round($intent->amount_minor * $percent / 100);
                if ($amount <= 0) {
                    return null;
                }

                $refund = Refund::create([
                    'tenant_id' => $reservation->tenant_id,
                    'reservation_id' => $reservation->id,
                    'payment_intent_id' => $intent->id,
                    'provider' => $intent->provider,
                    'amount_minor' => $amount,
                    'currency' => $intent->currency,
                    'status' => $mode === 'auto' ? 'approved' : 'pending',
                    'source' => $source,
                    'reason' => 'cancellation',
                    'requested_by' => $actor?->id,
                ]);

                $this->audit->log('refund.requested', $refund, null, [
                    'amount_minor' => $amount, 'mode' => $mode,
                ], null, $actor, $reservation->tenant_id);

                return $refund;
            }
        );

        if ($refund === null) {
            return null;
        }

        if ($refund->status === 'approved' && $settings->refund_processing === 'immediate') {
            $this->process($refund);
        }

        return $refund->fresh();
    }

    public function approve(Refund $refund, User $actor): Refund
    {
        if ($refund->status !== 'pending') {
            return $refund;
        }

        $refund->update(['status' => 'approved', 'approved_by' => $actor->id]);
        $this->audit->log('refund.approved', $refund, null, null, null, $actor, $refund->tenant_id);

        if ($this->processingMode($refund) === 'immediate') {
            $this->process($refund);
        }

        return $refund->fresh();
    }

    public function reject(Refund $refund, User $actor): Refund
    {
        if ($refund->status !== 'pending') {
            return $refund;
        }

        $refund->update(['status' => 'rejected', 'approved_by' => $actor->id]);
        $this->audit->log('refund.rejected', $refund, null, null, null, $actor, $refund->tenant_id);

        return $refund->fresh();
    }

    /**
     * Execute the refund against the payment provider. Idempotent.
     */
    public function process(Refund $refund): bool
    {
        if ($refund->status === 'completed') {
            return true;
        }

        // Atomically claim the refund: only the caller that flips approved→processing
        // in a single UPDATE proceeds. Without this compare-and-swap two concurrent
        // runs (immediate processing + scheduled batch, or the retry button + batch)
        // could both reach $provider->refund() and refund the guest twice.
        $claimed = Refund::withoutGlobalScopes()
            ->whereKey($refund->id)
            ->where('status', 'approved')
            ->update(['status' => 'processing']);

        if ($claimed === 0) {
            // Someone else already claimed/finished it (or it isn't approved).
            return $refund->fresh()?->status === 'completed';
        }

        $refund->setAttribute('status', 'processing');

        // Der try-Block endet BEWUSST direkt hinter dem Anbieteraufruf: Danach
        // ist das Geld unterwegs. Wuerde ein Fehler in der Nachbuchung ebenfalls
        // auf 'failed' gesetzt, gaebe der Wiederholen-Knopf eine zweite echte
        // Erstattung frei. Ohne diesen Block bliebe der Datensatz bei einer
        // Ausnahme fuer immer in 'processing' stehen und wuerde von keinem Lauf
        // mehr angefasst.
        try {
            $tenant = Tenant::find($refund->tenant_id);
            $provider = $tenant ? $this->payments->provider($tenant, $refund->provider) : null;
            $intent = $refund->payment_intent_id ? PaymentIntent::withoutGlobalScopes()->find($refund->payment_intent_id) : null;
            $reference = $intent->metadata['refund_ref'] ?? null;

            if ($provider === null || ! $reference) {
                $refund->update(['status' => 'failed', 'error' => 'Kein Anbieter oder keine Zahlungsreferenz.']);

                return false;
            }

            $result = $provider->refund($reference, $refund->amount_minor, $refund->currency);
        } catch (\Throwable $e) {
            report($e);
            $refund->update([
                'status' => 'failed',
                'error' => 'Anbieter nicht erreichbar. Bitte dort pruefen, ob die Erstattung doch ausgefuehrt wurde, bevor erneut versucht wird. ('.mb_substr($e->getMessage(), 0, 300).')',
            ]);

            return false;
        }
        if (! $result['ok']) {
            $refund->update(['status' => 'failed', 'error' => 'Anbieter hat die Rückerstattung abgelehnt.']);

            return false;
        }

        $fully = $intent === null || $refund->amount_minor >= $intent->amount_minor;

        $refund->update([
            'status' => 'completed',
            'provider_refund_id' => $result['id'],
            'processed_at' => now(),
        ]);
        $intent?->update(['status' => $fully ? 'refunded' : 'partially_refunded']);

        if ($refund->reservation_id) {
            Reservation::withoutGlobalScope('tenant')
                ->where('id', $refund->reservation_id)
                ->update(['payment_status' => $fully ? 'refunded' : 'partially_refunded']);
        }

        if ($refund->event_booking_id) {
            EventBooking::withoutGlobalScopes()
                ->where('id', $refund->event_booking_id)
                ->update(['payment_status' => $fully ? 'refunded' : 'partially_refunded']);
        }

        $this->audit->log('refund.completed', $refund, null, [
            'amount_minor' => $refund->amount_minor, 'provider_refund_id' => $result['id'],
        ], null, null, $refund->tenant_id);

        return true;
    }

    /**
     * Zwilling zu requestForReservation fuer Eventbuchungen.
     *
     * Ohne diesen Weg blieb eine bezahlte Eventbuchung bei der Stornierung
     * unerstattet - bei einer Eventabsage musste der Betrieb jede Rueckzahlung
     * von Hand beim Zahlungsanbieter ausloesen.
     */
    public function requestForEventBooking(EventBooking $booking, string $source = 'guest_cancel', ?User $actor = null): ?Refund
    {
        $event = $booking->event()->withoutGlobalScopes()->first();
        $location = $event?->location()->withoutGlobalScope('tenant')->first();
        if ($location === null) {
            return null;
        }

        $settings = $location->effectiveSettings();
        $mode = $settings->refund_mode;
        if ($mode === 'off') {
            return null;
        }

        $intent = PaymentIntent::withoutGlobalScopes()
            ->where('event_booking_id', $booking->id)
            ->where('status', 'paid')
            ->latest()
            ->first();
        if ($intent === null || empty($intent->metadata['refund_ref'])) {
            return null;
        }

        // Dieselbe Sperre wie bei der Reservierung, aus demselben Grund: Die
        // Buchungszeile existiert, die Erstattungszeile noch nicht.
        $refund = $this->createOnce(
            fn () => Refund::withoutGlobalScopes()
                ->where('event_booking_id', $booking->id)
                ->whereNotIn('status', ['rejected', 'failed'])
                ->first(),
            function () use ($booking, $intent, $settings, $mode, $source, $actor) {
                EventBooking::withoutGlobalScopes()->lockForUpdate()->find($booking->id);

                $existing = Refund::withoutGlobalScopes()
                    ->where('event_booking_id', $booking->id)
                    ->whereNotIn('status', ['rejected', 'failed'])
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }

                $percent = max(0, min(100, (int) $settings->refund_percent));
                $amount = (int) round($intent->amount_minor * $percent / 100);
                if ($amount <= 0) {
                    return null;
                }

                $refund = Refund::create([
                    'tenant_id' => $booking->tenant_id,
                    'event_booking_id' => $booking->id,
                    'payment_intent_id' => $intent->id,
                    'provider' => $intent->provider,
                    'amount_minor' => $amount,
                    'currency' => $intent->currency,
                    'status' => $mode === 'auto' ? 'approved' : 'pending',
                    'source' => $source,
                    'reason' => 'cancellation',
                    'requested_by' => $actor?->id,
                ]);

                $this->audit->log('refund.requested', $refund, null, [
                    'amount_minor' => $amount, 'mode' => $mode, 'event_booking_id' => $booking->id,
                ], null, $actor, $booking->tenant_id);

                return $refund;
            }
        );

        if ($refund === null) {
            return null;
        }

        if ($refund->status === 'approved' && $settings->refund_processing === 'immediate') {
            $this->process($refund);
        }

        return $refund->fresh();
    }

    /**
     * Process all approved refunds that are due (scheduled batch).
     */
    public function processDue(): int
    {
        $count = 0;
        Refund::withoutGlobalScopes()
            ->where('status', 'approved')
            ->where(function ($q) {
                $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', now());
            })
            ->get()
            ->each(function (Refund $refund) use (&$count) {
                if ($this->process($refund)) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Genau eine Erstattungszeile anlegen, auch bei zwei gleichzeitigen Laeufen.
     *
     * Die Serialisierung traegt die Sperre auf der Reservierung bzw. der
     * Eventbuchung in $anlegen. Der eindeutige Index auf refunds ist der
     * Rueckhalt darunter: Er faengt jeden Weg ab, der die Sperre nicht nimmt.
     *
     * Der catch steht AUSSERHALB der Transaktion. In PostgreSQL bricht ein
     * Datenbankfehler die laufende Transaktion ab; ein try/catch darin waere
     * kein Netz, sondern nur eine spaetere Fehlermeldung an anderer Stelle.
     *
     * @param  callable(): ?Refund  $vorhandene
     * @param  callable(): ?Refund  $anlegen
     */
    private function createOnce(callable $vorhandene, callable $anlegen): ?Refund
    {
        try {
            return DB::transaction($anlegen);
        } catch (UniqueConstraintViolationException) {
            return $vorhandene();
        }
    }

    private function processingMode(Refund $refund): string
    {
        $reservation = $refund->reservation_id
            ? Reservation::withoutGlobalScope('tenant')->find($refund->reservation_id)
            : null;
        $location = $reservation?->location()->withoutGlobalScope('tenant')->first();

        return $location?->effectiveSettings()->refund_processing ?? 'immediate';
    }
}
