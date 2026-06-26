<?php

namespace App\Services;

use App\Mail\TemplatedMail;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EventBookingService
{
    public function __construct(
        private readonly GuestProfileService $guests,
        private readonly WebhookDispatchService $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Book tickets for a public event. Capacity is re-checked inside the
     * transaction to prevent overbooking under concurrency.
     *
     * @param array{
     *     ticket_count: int, guest_name: string, guest_email?: ?string,
     *     guest_phone?: ?string, note?: ?string, consents?: array<string,bool>, ip?: ?string,
     * } $data
     */
    public function book(Event $event, array $data, ?User $actor = null): EventBooking
    {
        $this->assertBookable($event, (int) $data['ticket_count']);

        return DB::transaction(function () use ($event, $data, $actor) {
            // Serialize concurrent bookings for the same event. Without this, two
            // transactions under READ COMMITTED can both read the same remaining
            // capacity, both pass the check, and both commit → the event is oversold.
            // pg_advisory_xact_lock is released automatically when the transaction ends.
            // (Mirrors the locking in ReservationLifecycleService::create.)
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SELECT pg_advisory_xact_lock(?)', [crc32("swayy_event_{$event->id}")]);
            }

            // Re-check capacity now that the slot is serialized.
            $event->refresh();
            if ($event->remainingCapacity() < (int) $data['ticket_count']) {
                throw ValidationException::withMessages([
                    'ticket_count' => __('Es sind nur noch :n Plätze verfügbar.', ['n' => $event->remainingCapacity()]),
                ]);
            }

            $guest = null;
            if (! empty($data['guest_email']) || ! empty($data['guest_phone'])) {
                $guest = $this->guests->findOrCreate($event->tenant()->firstOrFail(), [
                    'name' => $data['guest_name'],
                    'email' => $data['guest_email'] ?? null,
                    'phone' => $data['guest_phone'] ?? null,
                    'source' => 'online_booking',
                ]);
                foreach ($data['consents'] ?? [] as $type => $granted) {
                    $this->guests->recordConsent($guest, $type, $granted, 'booking_widget', $data['ip'] ?? null);
                }
            }

            $amount = $event->price_minor !== null
                ? $event->price_minor * (int) $data['ticket_count']
                : null;

            $booking = EventBooking::create([
                'tenant_id' => $event->tenant_id,
                'event_id' => $event->id,
                'guest_id' => $guest?->id,
                'ticket_count' => (int) $data['ticket_count'],
                'guest_name' => $data['guest_name'],
                'guest_email' => $data['guest_email'] ?? null,
                'guest_phone' => $data['guest_phone'] ?? null,
                'note' => $data['note'] ?? null,
                'status' => 'confirmed',
                'payment_status' => $amount !== null && $amount > 0 ? 'required' : 'not_required',
                'amount_minor' => $amount,
            ]);

            $this->audit->log('event.booking_created', $booking, null, [
                'event' => $event->title,
                'tickets' => $booking->ticket_count,
            ], null, $actor, $event->tenant_id);

            DB::afterCommit(function () use ($booking, $event) {
                $tenant = $event->tenant()->first();
                if ($tenant !== null) {
                    $this->webhooks->dispatch($tenant, 'event.booking_created', [
                        'code' => $booking->code,
                        'event' => $event->slug,
                        'tickets' => $booking->ticket_count,
                        'guest_name' => $booking->guest_name,
                    ]);
                }
                if ($booking->guest_email) {
                    $this->sendConfirmationMail($booking, $event);
                }
            });

            return $booking;
        });
    }

    public function cancel(EventBooking $booking, string $actorType = 'guest', ?User $actor = null): void
    {
        if ($booking->status === 'cancelled') {
            return;
        }

        $event = $booking->event()->withoutGlobalScopes()->first();
        if ($actorType === 'guest' && $event?->cancellation_deadline_at !== null && now()->gte($event->cancellation_deadline_at)) {
            throw ValidationException::withMessages([
                'booking' => __('Die Stornierungsfrist für dieses Event ist abgelaufen.'),
            ]);
        }

        $booking->update(['status' => 'cancelled', 'cancelled_at' => now()]);

        $this->audit->log('event.booking_cancelled', $booking, null, ['by' => $actorType], null, $actor, $booking->tenant_id);
    }

    public function checkIn(EventBooking $booking, ?User $actor = null): void
    {
        if ($booking->status !== 'confirmed') {
            throw ValidationException::withMessages(['booking' => __('Nur bestätigte Buchungen können eingecheckt werden.')]);
        }

        $booking->update(['status' => 'checked_in', 'checked_in_at' => now()]);
        $this->audit->log('event.booking_checked_in', $booking, null, null, null, $actor, $booking->tenant_id);
    }

    private function assertBookable(Event $event, int $tickets): void
    {
        if ($event->status !== 'published' || $event->trashed()) {
            throw ValidationException::withMessages(['event' => __('Dieses Event ist nicht buchbar.')]);
        }
        if ($event->starts_at->isPast()) {
            throw ValidationException::withMessages(['event' => __('Dieses Event liegt in der Vergangenheit.')]);
        }
        if ($event->booking_deadline_at !== null && now()->gte($event->booking_deadline_at)) {
            throw ValidationException::withMessages(['event' => __('Die Buchungsfrist ist abgelaufen.')]);
        }
        if ($tickets < 1) {
            throw ValidationException::withMessages(['ticket_count' => __('Mindestens ein Ticket.')]);
        }
    }

    private function sendConfirmationMail(EventBooking $booking, Event $event): void
    {
        $location = $event->location()->withoutGlobalScope('tenant')->first();
        $startLocal = $event->starts_at->copy()->setTimezone($location?->timezone ?? 'Europe/Berlin');
        $manageLink = route('events.manage', ['code' => $booking->code, 'token' => $booking->manage_token]);

        $paymentBlock = '';
        if ($booking->amount_minor) {
            $payLink = route('pay.event', ['code' => $booking->code, 'token' => $booking->manage_token]);
            $paymentBlock = "\nBetrag: ".number_format($booking->amount_minor / 100, 2, ',', '.').' '.$event->currency
                ."\nJetzt online bezahlen: ".$payLink
                ."\n\nHinweis: Die Vorauszahlung wird bei Ihrem Besuch vollständig mit der Rechnung verrechnet."
                ."\nBei Nichterscheinen (No-Show) erfolgt keine Rückerstattung.\n";
        }

        $body = __(":greeting\n\nIhre Buchung für \":event\" ist bestätigt:\n\nDatum: :date\nUhrzeit: :time Uhr\nTickets: :tickets\nBuchungsnummer: :code\n:payment\nStornieren: :link\n\nWir freuen uns auf Sie!\n:location", [
            'greeting' => 'Hallo '.$booking->guest_name.',',
            'event' => $event->title,
            'date' => $startLocal->format('d.m.Y'),
            'time' => $startLocal->format('H:i'),
            'tickets' => $booking->ticket_count,
            'code' => $booking->code,
            'payment' => $paymentBlock,
            'link' => $manageLink,
            'location' => $location?->name ?? '',
        ]);

        Mail::to($booking->guest_email)->queue(new TemplatedMail(
            __('Buchungsbestätigung: :event', ['event' => $event->title]),
            $body,
        ));
    }
}
