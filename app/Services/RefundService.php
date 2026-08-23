<?php

declare(strict_types=1);

namespace App\Services;

use App\Mail\TemplatedMail;
use App\Models\EventBooking;
use App\Models\Location;
use App\Models\PaymentIntent;
use App\Models\Refund;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

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

        // Eine Zahlung, die auf einer toten Reservierung landet, ist
        // gegenstandslos - da gibt es keine Kulanzentscheidung zu treffen. Die
        // Erstattungsregeln des Betriebs beschreiben, was bei einer STORNIERUNG
        // zurueckgeht; hier gibt es gar keine Buchung mehr. Vorher stieg dieser
        // Weg bei der Vorgabe 'off' wortlos aus: Der Betrieb behielt das Geld,
        // der Gast hatte keinen Tisch, und niemand erfuhr davon.
        $gegenstandslos = $source === 'late_payment_auto_refund';

        if ($mode === 'off' && ! $gegenstandslos) {
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
            fn () => $this->blockingRefund('reservation_id', $reservation->id),
            function () use ($reservation, $intent, $settings, $mode, $source, $actor, $gegenstandslos) {
                Reservation::withoutGlobalScope('tenant')->lockForUpdate()->find($reservation->id);

                $existing = $this->blockingRefund('reservation_id', $reservation->id);
                if ($existing !== null) {
                    return $existing;
                }

                // Der Stornoprozentsatz ist Kulanz bei einer Stornierung. Fuer
                // eine gegenstandslose Zahlung gilt er nicht - der Gast bekaeme
                // sonst die Haelfte fuer eine Buchung, die es nie gab.
                $percent = $gegenstandslos ? 100 : max(0, min(100, (int) $settings->refund_percent));
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
                    // Hat der Betrieb Erstattungen nie eingeschaltet, wird hier
                    // kein Geld ohne ihn bewegt - aber der Fall landet
                    // sichtbar in der Erstattungsliste statt nur im Auditlog.
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

        if ($refund !== null && $gegenstandslos) {
            $this->notifyOperator($location, $refund, 'Zahlung ohne gültige Reservierung – '.$reservation->code, [
                'Zu der Reservierung '.$reservation->code.' ist eine Zahlung eingegangen,',
                'obwohl die Buchung nicht mehr gültig ist ('.__('reservations.status.'.$reservation->status->value, [], 'de').').',
                '',
                ...$this->guestLines($reservation->guest_name_snapshot, $reservation->guest_email_snapshot),
            ]);
        }

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
     * Wie lange eine beanspruchte Erstattung laufen darf, bevor sie als
     * haengengeblieben gilt.
     *
     * process() setzt 'processing' in einer eigenen, sofort committeten
     * Anweisung und ruft erst danach den Anbieter. Stirbt der Prozess
     * dazwischen - Worker-Timeout, OOM, Neustart beim Ausrollen -, bleibt die
     * Zeile stehen: Kein Lauf und kein Knopf erreichte sie danach noch, und
     * der Gast bekam sein Geld nie.
     */
    public const STALE_PROCESSING_MINUTES = 15;

    /**
     * Wie lange sich ein Fehlversuch gefahrlos wiederholen laesst.
     *
     * Der Schutz gegen die doppelte Auszahlung ist der Wiederholungsschluessel
     * beim Anbieter - er haelt bei Stripe und PayPal 24 Stunden. Danach ist er
     * vergessen: Derselbe Aufruf loest dann eine ZWEITE echte Erstattung aus.
     * Genau dann ist das gefaehrlich, wenn der erste Versuch in Wahrheit
     * durchlief und nur die Antwort nicht ankam - dieselbe Zeitueberschreitung,
     * die die Zeile ueberhaupt erst auf 'failed' gesetzt hat.
     *
     * Vier Stunden Abstand zur Grenze, damit kein Grenzfall daran haengt.
     */
    public const RETRY_WINDOW_HOURS = 20;

    /**
     * Eine gescheiterte oder haengengebliebene Erstattung wieder freigeben.
     *
     * Als bedingtes Update, nicht als Lesen-Pruefen-Schreiben: Sonst dreht ein
     * zweiter Aufruf den Stand eines gerade laufenden Anbieteraufrufs zurueck
     * und gibt damit eine zweite echte Erstattung frei.
     *
     * Gibt zurueck, ob dieser Aufruf die Erstattung beansprucht hat.
     */
    public function reopen(Refund $refund): bool
    {
        return Refund::withoutGlobalScopes()
            ->whereKey($refund->id)
            ->where(function ($q) {
                $q->where('status', 'failed')
                    ->where('updated_at', '>', now()->subHours(self::RETRY_WINDOW_HOURS))
                    // Haengengeblieben: beansprucht, aber seit einer
                    // Viertelstunde ruehrt sich nichts mehr.
                    ->orWhere(fn ($q) => $q->where('status', 'processing')
                        ->where('updated_at', '<', now()->subMinutes(self::STALE_PROCESSING_MINUTES)));
            })
            ->update(['status' => 'approved', 'error' => null]) === 1;
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

            // Deckel ueber ALLE Erstattungen dieses Vorgangs, nicht nur ueber
            // diese eine Zeile. Ein Fehlversuch darf eine zweite Zeile
            // erlauben - aber 'failed' wird auch dann gesetzt, wenn die
            // Erstattung beim Anbieter in Wahrheit lief (Zeitueberschreitung).
            // Ohne diese Summe zahlten zwei Zeilen zu je 50 % zusammen 100 %
            // aus, waehrend der Vorgang weiter auf "teilweise erstattet" stand.
            $bereits = $this->alreadyRefundedMinor($refund);

            // Eine Erstattung des falschen Betrags deckelt sich an dem, was
            // wirklich kassiert wurde. Der Betrag des Vorgangs ist hier ja
            // gerade der andere - lag die Zahlung darueber, scheiterte die
            // Rueckzahlung sonst an der eigenen Deckelung, und das Geld blieb
            // beim Anbieter liegen.
            $deckel = $refund->source === self::MISMATCH
                ? (int) $refund->amount_minor
                : ($intent !== null ? (int) $intent->amount_minor : (int) $refund->amount_minor);
            $offen = max(0, $deckel - $bereits);

            if ($refund->amount_minor > $offen) {
                $refund->update([
                    'status' => 'failed',
                    'error' => 'Es sind bereits '.number_format($bereits / 100, 2, ',', '.').' '.$refund->currency
                        .' zu dieser Zahlung erstattet. Mehr als der gezahlte Betrag geht nicht zurueck.',
                ]);

                return false;
            }

            // Der Schluessel haengt an DIESER Erstattungszeile: Ein zweiter
            // Anlauf auf dieselbe Zeile - nach einem abgebrochenen Lauf - holt
            // damit das vorhandene Ergebnis, statt ein zweites Mal auszuzahlen.
            $result = $provider->refund(
                $reference,
                $refund->amount_minor,
                $refund->currency,
                'swayy-refund-'.$refund->id,
            );
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

        // "Voll erstattet" ergibt sich aus der Summe aller Erstattungen zu
        // diesem Vorgang, nicht aus dieser einen Zeile: Zweimal die Haelfte
        // sind auch voll erstattet.
        $fully = $intent === null
            || ($bereits + $refund->amount_minor) >= (int) $intent->amount_minor;

        $refund->update([
            'status' => 'completed',
            'provider_refund_id' => $result['id'],
            'processed_at' => now(),
        ]);

        // Ein falscher Betrag wird nicht verbucht. Die Zahlung galt nie als
        // eingegangen; die Buchung bleibt unbezahlt und verfaellt normal an
        // ihrer Frist. Stuende hier "erstattet", zaehlte sie als abgeschlossen
        // bezahlt - der Fristablauf laesst sie dann stehen, unbezahlt und ohne
        // Ende, und der Tisch bliebe bis zum Termin blockiert.
        if ($refund->source !== self::MISMATCH) {
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

        // Wie bei der Reservierung: Eine Zahlung auf eine abgesagte Buchung ist
        // gegenstandslos, da gibt es keine Kulanzentscheidung zu treffen.
        $gegenstandslos = $source === 'late_payment_auto_refund';

        if ($mode === 'off' && ! $gegenstandslos) {
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
            fn () => $this->blockingRefund('event_booking_id', $booking->id),
            function () use ($booking, $intent, $settings, $mode, $source, $actor, $gegenstandslos) {
                EventBooking::withoutGlobalScopes()->lockForUpdate()->find($booking->id);

                $existing = $this->blockingRefund('event_booking_id', $booking->id);
                if ($existing !== null) {
                    return $existing;
                }

                $percent = $gegenstandslos ? 100 : max(0, min(100, (int) $settings->refund_percent));
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

        if ($refund !== null && $gegenstandslos) {
            // Genau wie bei der Reservierung: Ein Auditeintrag ist keine
            // Benachrichtigung. Hier liegt Geld eines Gastes fuer eine
            // Eventbuchung, die abgesagt ist.
            $this->notifyOperator($location, $refund, 'Zahlung ohne gültige Buchung – '.$booking->code, [
                'Zu der Eventbuchung '.$booking->code.' ('.$event->title.') ist eine Zahlung eingegangen,',
                'obwohl die Buchung abgesagt ist.',
                '',
                ...$this->guestLines($booking->guest_name, $booking->guest_email),
            ]);
        }

        if ($refund === null) {
            return null;
        }

        if ($refund->status === 'approved' && $settings->refund_processing === 'immediate') {
            $this->process($refund);
        }

        return $refund->fresh();
    }

    /**
     * Quelle einer Erstattung, die einen unpassenden Betrag zurueckgibt.
     *
     * Sie blockiert keine spaetere Stornoerstattung: Der Gast kann nach der
     * Rueckzahlung erneut - und diesmal richtig - bezahlen und danach ganz
     * normal stornieren. Wuerde diese Zeile die Pruefung auf eine vorhandene
     * Erstattung erfuellen, bekaeme er beim Stornieren gar nichts zurueck.
     */
    public const MISMATCH = 'amount_mismatch_refund';

    /**
     * Es ist Geld eingegangen, das nicht zur Anzahlung passt.
     *
     * Die Zahlung wird NICHT verbucht - die Buchung bleibt unbezahlt. Aber das
     * Geld ist beim Anbieter kassiert. Ohne diesen Weg blieb es dort liegen:
     * keine Erstattung, keine Meldung, nur eine Zeile im Auditlog, und die
     * Buchung verfiel still an der Zahlungsfrist.
     *
     * Erstattet wird der TATSAECHLICH kassierte Betrag, nicht der erwartete.
     * Der ist hier ja gerade der falsche.
     */
    public function requestForMismatch(PaymentIntent $intent, int $charged, ?string $chargeRef): ?Refund
    {
        if ($charged <= 0) {
            return null;
        }

        $reservation = $intent->reservation_id
            ? Reservation::withoutGlobalScopes()->find($intent->reservation_id)
            : null;
        $booking = $intent->event_booking_id
            ? EventBooking::withoutGlobalScopes()->find($intent->event_booking_id)
            : null;

        $location = $reservation?->location()->withoutGlobalScope('tenant')->first();
        if ($location === null && $booking !== null) {
            $location = $booking->event()->withoutGlobalScopes()->first()
                ?->location()->withoutGlobalScope('tenant')->first();
        }
        if ($location === null) {
            return null;
        }

        // Die Referenz auf die Belastung ist die einzige Handhabe, mit der der
        // Anbieter spaeter zurueckzahlen kann. Sie steht sonst nur an einer
        // verbuchten Zahlung - und verbucht wird hier gerade nicht.
        if ($chargeRef) {
            $intent->update(['metadata' => array_merge($intent->metadata ?? [], ['refund_ref' => $chargeRef])]);
            $intent->refresh();
        }

        if (empty($intent->metadata['refund_ref'])) {
            // Ohne Referenz laesst sich beim Anbieter nichts ausloesen. Der
            // Auditeintrag des Aufrufers bleibt der einzige Hinweis.
            return null;
        }

        $settings = $location->effectiveSettings();
        $mode = $settings->refund_mode;

        // Gesperrt wird der Zahlungsvorgang: Die Zeile existiert, die
        // Erstattungszeile noch nicht. Rueckweg des Gastes und Webhook des
        // Anbieters laufen hier sekundengleich gegeneinander.
        $vorhandene = fn () => Refund::withoutGlobalScopes()
            ->where('payment_intent_id', $intent->id)
            ->where('source', self::MISMATCH)
            ->whereNotIn('status', ['rejected', 'failed'])
            ->first();

        $refund = $this->createOnce($vorhandene, function () use ($intent, $reservation, $booking, $charged, $mode, $vorhandene) {
            PaymentIntent::withoutGlobalScopes()->lockForUpdate()->find($intent->id);

            $existing = $vorhandene();
            if ($existing !== null) {
                return $existing;
            }

            $refund = Refund::create([
                'tenant_id' => $intent->tenant_id,
                'reservation_id' => $reservation?->id,
                'event_booking_id' => $booking?->id,
                'payment_intent_id' => $intent->id,
                'provider' => $intent->provider,
                'amount_minor' => $charged,
                'currency' => $intent->currency,
                'status' => $mode === 'auto' ? 'approved' : 'pending',
                'source' => self::MISMATCH,
                'reason' => 'amount_mismatch',
            ]);

            $this->audit->log('refund.requested', $refund, null, [
                'amount_minor' => $charged,
                'expected_minor' => (int) $intent->amount_minor,
                'mode' => $mode,
            ], null, null, $intent->tenant_id);

            return $refund;
        });

        if ($refund === null) {
            return null;
        }

        $kennung = $reservation?->code ?? $booking?->code ?? ('#'.$intent->id);
        $this->notifyOperator($location, $refund, 'Zahlung mit falschem Betrag – '.$kennung, [
            'Zu '.$kennung.' ist eine Zahlung über '.number_format($charged / 100, 2, ',', '.').' '.$intent->currency.' eingegangen,',
            'erwartet waren '.number_format((int) $intent->amount_minor / 100, 2, ',', '.').' '.$intent->currency.'.',
            'Die Buchung gilt deshalb weiter als unbezahlt.',
            '',
            ...$this->guestLines(
                $reservation?->guest_name_snapshot ?? $booking?->guest_name,
                $reservation?->guest_email_snapshot ?? $booking?->guest_email,
            ),
        ]);

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

    /**
     * Was zu diesem Zahlungsvorgang schon zurueckgegangen ist - ohne die
     * eigene Zeile.
     *
     * 'processing' zaehlt mit: Dort ist das Geld womoeglich schon unterwegs.
     * 'failed' zaehlt nicht mit, sonst waere nach einem Fehlversuch kein
     * zweiter Anlauf mehr moeglich.
     */
    private function alreadyRefundedMinor(Refund $refund): int
    {
        if ($refund->payment_intent_id === null) {
            return 0;
        }

        // Zwei getrennte Toepfe: Was wegen eines falschen Betrags zurueckging,
        // gehoert zu einer Zahlung, die nie verbucht wurde. Wuerde es hier
        // mitzaehlen, scheiterte die spaetere Stornoerstattung derselben
        // Buchung an einer Deckelung, die mit ihr nichts zu tun hat.
        $eigenerTopf = $refund->source === self::MISMATCH;

        return (int) Refund::withoutGlobalScopes()
            ->where('payment_intent_id', $refund->payment_intent_id)
            ->whereKeyNot($refund->id)
            ->where('source', $eigenerTopf ? '=' : '!=', self::MISMATCH)
            ->whereIn('status', ['completed', 'processing'])
            ->sum('amount_minor');
    }

    /**
     * Den Betrieb ueber eine Zahlung informieren, die ins Leere lief.
     *
     * Ein Auditeintrag allein reicht dafuer nicht: Dort sieht niemand nach,
     * solange er nicht weiss, dass es etwas zu sehen gibt. Hier liegt Geld
     * eines Gastes fuer eine Buchung, die es nicht mehr gibt.
     *
     * Nimmt die Eckdaten einzeln statt einer Reservierung: Derselbe Fall tritt
     * bei Eventbuchungen auf, und die sind kein Reservation-Objekt.
     *
     * @param  array<int, string>  $zeilen
     */
    private function notifyOperator(Location $location, Refund $refund, string $betreff, array $zeilen): void
    {
        $to = $location->effectiveSettings()->owner_notification_email ?: $location->email;
        if (! $to) {
            return;
        }

        $tenant = Tenant::find($refund->tenant_id);

        Mail::to($to)->queue(new TemplatedMail(
            $betreff,
            implode("\n", [
                ...$zeilen,
                '',
                'Betrag:  '.$refund->amountFormatted(),
                '',
                $refund->status === 'approved'
                    ? 'Die Rückerstattung wurde automatisch angestossen.'
                    : 'Die Rückerstattung liegt zur Freigabe in der Liste der Rückerstattungen.',
            ]),
            $tenant?->mail_from_name,
            $tenant?->mail_reply_to,
        ));
    }

    /**
     * Eine Erstattung, die eine zweite zu derselben Buchung verhindert.
     *
     * Erstattungen eines unpassenden Betrags zaehlen NICHT dazu - sie gehoeren
     * zu einer Zahlung, die nie verbucht wurde. Sonst bekaeme ein Gast, der
     * danach richtig bezahlt und spaeter storniert, nichts mehr zurueck.
     */
    private function blockingRefund(string $spalte, int $id): ?Refund
    {
        return Refund::withoutGlobalScopes()
            ->where($spalte, $id)
            ->where('source', '!=', self::MISMATCH)
            ->whereNotIn('status', ['rejected', 'failed'])
            ->first();
    }

    /**
     * Die Eckdaten des Gastes fuer die Betriebsmeldung.
     *
     * @return array<int, string>
     */
    private function guestLines(?string $name, ?string $email): array
    {
        return [
            'Gast:    '.($name ?: '—'),
            'E-Mail:  '.($email ?: '—'),
        ];
    }

    /**
     * Sofort auszahlen oder im Sammellauf?
     *
     * Der Standort einer Eventbuchung haengt am Event, nicht an einer
     * Reservierung. Ohne diesen Zweig lief jede Event-Erstattung sofort raus,
     * auch wenn der Betrieb Erstattungen bewusst gebuendelt und zeitversetzt
     * fahren laesst - etwa um eine Ruecknahme vor dem Auszahlen zu ermoeglichen.
     */
    private function processingMode(Refund $refund): string
    {
        if ($refund->reservation_id) {
            $reservation = Reservation::withoutGlobalScope('tenant')->find($refund->reservation_id);
            $location = $reservation?->location()->withoutGlobalScope('tenant')->first();

            return $location?->effectiveSettings()->refund_processing ?? 'immediate';
        }

        if ($refund->event_booking_id) {
            $booking = EventBooking::withoutGlobalScopes()->find($refund->event_booking_id);
            $event = $booking?->event()->withoutGlobalScopes()->first();
            $location = $event?->location()->withoutGlobalScope('tenant')->first();

            return $location?->effectiveSettings()->refund_processing ?? 'immediate';
        }

        return 'immediate';
    }
}
