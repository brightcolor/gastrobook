<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Mail\TemplatedMail;
use App\Models\Location;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\ReservationStatusHistory;
use App\Models\RestaurantTable;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class ReservationLifecycleService
{
    public function __construct(
        private readonly ReservationAvailabilityService $availability,
        private readonly TableAssignmentService $tableAssignment,
        private readonly GuestProfileService $guests,
        private readonly PaymentRequirementService $payments,
        private readonly NoShowRiskService $noShowRisk,
        private readonly NotificationTemplateRenderer $templates,
        private readonly WebhookDispatchService $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Create a reservation with availability re-check, automatic table
     * assignment, guest profile linking, deposit rules, history, audit,
     * webhook and confirmation mail.
     *
     * @param array{
     *     party_size: int, start_local: CarbonImmutable, source: string,
     *     guest_name: string, guest_email?: ?string, guest_phone?: ?string,
     *     guest_note?: ?string, allergy_note?: ?string, internal_note?: ?string,
     *     occasion?: ?string, room_id?: ?int, table_ids?: array<int>,
     *     consents?: array<string,bool>, ip?: ?string, event_id?: ?int,
     *     skip_availability_check?: bool, duration_minutes?: ?int,
     * } $data
     */
    public function create(Location $location, array $data, ?User $actor = null): Reservation
    {
        $online = $data['source'] === 'online';
        $settings = $location->effectiveSettings();
        $startLocal = $data['start_local'];
        $duration = $data['duration_minutes'] ?? $settings->durationFor($data['party_size']);
        $startUtc = $startLocal->utc();
        $endUtc = $startUtc->addMinutes($duration);

        return DB::transaction(function () use ($location, $data, $actor, $online, $settings, $startLocal, $startUtc, $endUtc) {
            $tableIds = $data['table_ids'] ?? [];

            if (! ($data['skip_availability_check'] ?? false)) {
                // Serialize concurrent availability checks for the same location+slot.
                // Without this, two transactions under READ COMMITTED can both see a free
                // table, both pass the check, and both commit → double-booking.
                // pg_advisory_xact_lock is automatically released when the transaction ends.
                if (DB::getDriverName() === 'pgsql') {
                    $lockKey = crc32("swayy_booking_{$location->id}_{$startUtc->timestamp}");
                    DB::statement('SELECT pg_advisory_xact_lock(?)', [$lockKey]);
                }
                if ($tableIds === []) {
                    $check = $this->availability->checkExact($location, $startLocal, $data['party_size'], [
                        'online' => $online,
                        'room_id' => $data['room_id'] ?? null,
                    ]);
                    if (! $check['available']) {
                        throw ValidationException::withMessages([
                            'start_at' => $this->availabilityMessage($check['reason']),
                        ]);
                    }
                    $tableIds = $check['table_ids'];
                } else {
                    // Manually chosen tables: conflict check, overbooking requires permission upstream
                    $busy = $this->tableAssignment->busyTableIds($location, $startUtc, $endUtc, null);
                    $conflicts = array_intersect($tableIds, $busy);
                    if ($conflicts !== []) {
                        throw ValidationException::withMessages([
                            'table_ids' => __('Mindestens ein gewählter Tisch ist in diesem Zeitraum bereits belegt.'),
                        ]);
                    }
                }
            }

            // Guest profile (skip for anonymous walk-ins)
            $guest = null;
            if (! empty($data['guest_email']) || ! empty($data['guest_phone'])) {
                $guest = $this->guests->findOrCreate($location->tenant, [
                    'name' => $data['guest_name'],
                    'email' => $data['guest_email'] ?? null,
                    'phone' => $data['guest_phone'] ?? null,
                    'allergies' => $data['allergy_note'] ?? null,
                    'source' => $online ? 'online_booking' : $data['source'],
                ]);

                foreach ($data['consents'] ?? [] as $type => $granted) {
                    $this->guests->recordConsent($guest, $type, $granted, $online ? 'booking_widget' : 'staff', $data['ip'] ?? null);
                }
            }

            // Initial status
            $emailConfirm = $data['email_confirmation_required'] ?? false;
            $status = ReservationStatus::Confirmed;
            if ($data['source'] === 'walk_in') {
                $status = ReservationStatus::Seated;
            } elseif ($online && ($settings->request_only || ! $settings->auto_confirm)) {
                $status = ReservationStatus::Requested;
            }
            // Hold the booking until the guest confirms their email (once)
            if ($emailConfirm) {
                $status = ReservationStatus::Requested;
            }

            // Deposit / no-show protection
            $paymentStatus = 'not_required';
            $paymentAmount = null;
            $paymentDueAt = null;
            $rule = $this->payments->requirementFor($location, $startLocal, $data['party_size'], $data['event_id'] ?? null, $data['room_id'] ?? null);
            if ($rule !== null && $online) {
                $paymentStatus = 'required';
                $paymentAmount = $rule->amountFor($data['party_size']);
                $paymentDueAt = now()->addMinutes($rule->payment_deadline_minutes);
                if ($rule->type !== 'card_guarantee') {
                    $status = ReservationStatus::PaymentPending;
                }
            }

            $reservation = Reservation::create([
                'tenant_id' => $location->tenant_id,
                'location_id' => $location->id,
                'guest_id' => $guest?->id,
                'event_id' => $data['event_id'] ?? null,
                'party_size' => $data['party_size'],
                'reservation_date' => $startLocal->toDateString(),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'timezone' => $location->timezone,
                'status' => $status,
                'source' => $data['source'],
                'table_chosen_by_guest' => $data['table_chosen_by_guest'] ?? false,
                'occasion' => $data['occasion'] ?? null,
                'guest_name_snapshot' => $data['guest_name'],
                'guest_email_snapshot' => $data['guest_email'] ?? null,
                'guest_phone_snapshot' => $data['guest_phone'] ?? null,
                'guest_note' => $data['guest_note'] ?? null,
                'allergy_note' => $data['allergy_note'] ?? null,
                'internal_note' => $data['internal_note'] ?? null,
                'service_id' => $data['service_id'] ?? null,
                'staff_member_id' => $data['staff_member_id'] ?? null,
                'payment_status' => $paymentStatus,
                'payment_amount_minor' => $paymentAmount,
                'currency' => $paymentAmount !== null ? $location->currency : null,
                'payment_due_at' => $paymentDueAt,
                'confirmed_at' => $status === ReservationStatus::Confirmed ? now() : null,
                'seated_at' => $status === ReservationStatus::Seated ? now() : null,
                'no_show_risk' => $this->noShowRisk->score($guest, $data['party_size']),
                'created_by' => $actor?->id,
            ]);

            if ($tableIds !== []) {
                $reservation->tables()->sync($tableIds);
            }

            ReservationStatusHistory::create([
                'tenant_id' => $location->tenant_id,
                'reservation_id' => $reservation->id,
                'from_status' => null,
                'to_status' => $status->value,
                'user_id' => $actor?->id,
                'actor' => $actor ? 'user' : ($online ? 'guest' : 'system'),
            ]);

            $this->audit->log('reservation.created', $reservation, null, [
                'code' => $reservation->code,
                'status' => $status->value,
                'party_size' => $reservation->party_size,
                'start_at' => $startUtc->toIso8601String(),
            ], null, $actor, $location->tenant_id);

            DB::afterCommit(function () use ($reservation, $location, $status, $emailConfirm) {
                $this->webhooks->dispatch($location->tenant, 'reservation.created', $this->webhookPayload($reservation));
                // When email confirmation is required, the caller sends the
                // verification mail instead of the normal confirmation mail.
                if ($reservation->guest_email_snapshot && ! $emailConfirm) {
                    $templateKey = $status === ReservationStatus::Requested ? 'reservation_requested' : 'reservation_confirmed';
                    if (in_array($status, [ReservationStatus::Confirmed, ReservationStatus::Requested], true)) {
                        $this->sendGuestMail($reservation, $templateKey);
                    }
                }
            });

            return $reservation;
        });
    }

    /**
     * Validated status transition with history, side effects, audit, webhooks.
     */
    public function transition(
        Reservation $reservation,
        ReservationStatus $to,
        ?User $actor = null,
        string $actorType = 'user',
        ?string $reason = null,
        ?string $note = null,
    ): Reservation {
        $from = $reservation->status;

        if (! $from->canTransitionTo($to)) {
            throw ValidationException::withMessages([
                'status' => __('Statuswechsel von :from nach :to ist nicht erlaubt.', [
                    'from' => $from->value, 'to' => $to->value,
                ]),
            ]);
        }

        return DB::transaction(function () use ($reservation, $from, $to, $actor, $actorType, $reason, $note) {
            $updates = ['status' => $to];

            match ($to) {
                ReservationStatus::Confirmed => $updates['confirmed_at'] = now(),
                ReservationStatus::Seated, ReservationStatus::PartiallyArrived => $updates['seated_at'] = $reservation->seated_at ?? now(),
                ReservationStatus::Completed => $updates['departed_at'] = now(),
                ReservationStatus::CancelledByGuest,
                ReservationStatus::CancelledByRestaurant,
                ReservationStatus::Rejected => $updates['cancelled_at'] = now(),
                default => null,
            };

            // When an admin manually confirms a reservation that previously
            // failed payment (e.g. waived fee, collected offline), clear the
            // failed payment status so the record isn't perpetually misleading.
            if ($to === ReservationStatus::Confirmed
                && $reservation->payment_status === 'failed') {
                $updates['payment_status'] = 'not_required';
            }

            $reservation->update($updates);

            ReservationStatusHistory::create([
                'tenant_id' => $reservation->tenant_id,
                'reservation_id' => $reservation->id,
                'from_status' => $from->value,
                'to_status' => $to->value,
                'user_id' => $actor?->id,
                'actor' => $actorType,
                'reason' => $reason,
                'note' => $note,
            ]);

            // Guest statistics
            $guest = $reservation->guest;
            if ($guest !== null) {
                match ($to) {
                    ReservationStatus::Completed => $this->guests->registerVisit($guest, $reservation->party_size),
                    ReservationStatus::NoShow => $guest->increment('no_show_count'),
                    ReservationStatus::CancelledByGuest => $guest->increment('cancellation_count'),
                    default => null,
                };
            }

            $this->audit->log('reservation.status_changed', $reservation, ['status' => $from->value], ['status' => $to->value], ['reason' => $reason], $actor, $reservation->tenant_id);

            $webhookEvent = match ($to) {
                ReservationStatus::Confirmed => 'reservation.confirmed',
                ReservationStatus::CancelledByGuest, ReservationStatus::CancelledByRestaurant => 'reservation.cancelled',
                ReservationStatus::NoShow => 'reservation.no_show',
                ReservationStatus::Seated => 'reservation.seated',
                ReservationStatus::Completed => 'reservation.completed',
                default => 'reservation.updated',
            };

            $tenant = $reservation->tenant()->first();

            DB::afterCommit(function () use ($reservation, $tenant, $webhookEvent, $to) {
                if ($tenant !== null) {
                    $this->webhooks->dispatch($tenant, $webhookEvent, $this->webhookPayload($reservation));
                }

                if ($reservation->guest_email_snapshot) {
                    $templateKey = match ($to) {
                        ReservationStatus::Confirmed => 'reservation_confirmed',
                        ReservationStatus::CancelledByGuest, ReservationStatus::CancelledByRestaurant => 'reservation_cancelled',
                        ReservationStatus::Rejected => 'reservation_rejected',
                        default => null,
                    };
                    if ($templateKey !== null) {
                        $this->sendGuestMail($reservation, $templateKey);
                    }
                }
            });

            return $reservation->refresh();
        });
    }

    /**
     * Move a reservation to different tables (drag & drop / table swap).
     *
     * @param  array<int>  $tableIds
     */
    public function reassignTables(Reservation $reservation, array $tableIds, ?User $actor = null, bool $allowConflict = false): void
    {
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $busy = $this->tableAssignment->busyTableIds(
            $location,
            CarbonImmutable::parse($reservation->start_at),
            CarbonImmutable::parse($reservation->end_at),
            $reservation->id
        );

        $conflicts = array_intersect($tableIds, $busy);
        if ($conflicts !== [] && ! $allowConflict) {
            throw ValidationException::withMessages([
                'table_ids' => __('Tisch ist in diesem Zeitraum bereits belegt.'),
            ]);
        }

        $old = $reservation->tables()->pluck('restaurant_tables.id')->all();
        $reservation->tables()->sync($tableIds);

        $this->audit->log(
            $conflicts !== [] ? 'reservation.tables_overbooked' : 'reservation.tables_changed',
            $reservation,
            ['table_ids' => $old],
            ['table_ids' => $tableIds],
            null,
            $actor,
            $reservation->tenant_id
        );

        // Counter-proposal: the staff changed a table the guest had picked
        // themselves. Inform the guest about the new table and drop the
        // "guest wish" flag (it's now a staff assignment).
        $oldSorted = array_map('intval', $old);
        $newSorted = array_map('intval', $tableIds);
        sort($oldSorted);
        sort($newSorted);
        $changed = $oldSorted !== $newSorted;

        if ($reservation->table_chosen_by_guest && $changed && $reservation->guest_email_snapshot) {
            $newNames = RestaurantTable::withoutGlobalScope('tenant')
                ->whereIn('id', $tableIds)->pluck('name')->implode(', ');
            $tenant = $reservation->tenant()->first();

            Mail::to($reservation->guest_email_snapshot)->queue(new TemplatedMail(
                __('Tischänderung zu Ihrer Reservierung :code – :loc', ['code' => $reservation->code, 'loc' => $location->name]),
                __("Hallo :name,\n\nzu Ihrer Reservierung am :date um :time Uhr haben wir Ihnen statt Ihres Wunschtisches Tisch :tables zugewiesen, damit alles optimal passt. An Ihrer Reservierung ändert sich sonst nichts.\n\nBei Fragen melden Sie sich gerne.\n\n:loc", [
                    'name' => $reservation->guest_name_snapshot,
                    'date' => $reservation->localStart()->format('d.m.Y'),
                    'time' => $reservation->localStart()->format('H:i'),
                    'tables' => $newNames ?: '—',
                    'loc' => $location->name,
                ]),
                $tenant?->mail_from_name,
                $tenant?->mail_reply_to,
            ));

            $reservation->update(['table_chosen_by_guest' => false]);
        }
    }

    /**
     * Move a reservation to a new start time (guest/staff reschedule). Keeps the
     * duration, re-checks availability (tables for restaurants, staff for salons)
     * and reassigns tables when needed.
     */
    public function reschedule(Reservation $reservation, CarbonImmutable $newStartLocal, ?int $newPartySize = null, ?User $actor = null, string $actorType = 'guest'): Reservation
    {
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $partySize = $newPartySize ?? $reservation->party_size;
        $duration = (int) $reservation->start_at->diffInMinutes($reservation->end_at);
        $startUtc = $newStartLocal->utc();
        $endUtc = $startUtc->addMinutes($duration);

        return DB::transaction(function () use ($reservation, $location, $newStartLocal, $startUtc, $endUtc, $partySize, $actor, $actorType) {
            $tableIds = null;

            if ($reservation->staff_member_id) {
                // Salon: the assigned staff member must be free at the new slot
                $conflict = Reservation::withoutGlobalScope('tenant')
                    ->where('staff_member_id', $reservation->staff_member_id)
                    ->where('id', '!=', $reservation->id)
                    ->whereIn('status', ReservationStatus::activeStatuses())
                    ->where('start_at', '<', $endUtc)
                    ->where('end_at', '>', $startUtc)
                    ->exists();
                if ($conflict) {
                    throw ValidationException::withMessages(['time' => __('Dieser Zeitpunkt ist leider gerade vergeben – bitte wählen Sie eine andere Zeit.')]);
                }
            } else {
                // Restaurant: reassign a fitting free table/combination
                $assignment = $this->tableAssignment->findTables($location, $startUtc, $endUtc, $partySize, [
                    'online' => true,
                    'exclude_reservation_id' => $reservation->id,
                ]);
                if ($assignment === null) {
                    throw ValidationException::withMessages(['time' => __('Zu diesem Zeitpunkt ist leider kein passender Tisch mehr frei – bitte wählen Sie eine andere Zeit oder Personenzahl.')]);
                }
                $tableIds = $assignment['table_ids'];
            }

            $old = [
                'start_at' => $reservation->start_at->toIso8601String(),
                'end_at' => $reservation->end_at->toIso8601String(),
                'party_size' => $reservation->party_size,
            ];

            $reservation->update([
                'reservation_date' => $newStartLocal->toDateString(),
                'start_at' => $startUtc,
                'end_at' => $endUtc,
                'party_size' => $partySize,
            ]);
            if ($tableIds !== null) {
                $reservation->tables()->sync($tableIds);
            }

            ReservationStatusHistory::create([
                'tenant_id' => $reservation->tenant_id,
                'reservation_id' => $reservation->id,
                'from_status' => $reservation->status->value,
                'to_status' => $reservation->status->value,
                'user_id' => $actor?->id,
                'actor' => $actorType,
                'reason' => 'rescheduled',
            ]);

            $this->audit->log('reservation.rescheduled', $reservation, $old, [
                'start_at' => $startUtc->toIso8601String(),
                'end_at' => $endUtc->toIso8601String(),
            ], null, $actor, $reservation->tenant_id);

            DB::afterCommit(function () use ($reservation, $location) {
                $this->webhooks->dispatch($location->tenant, 'reservation.updated', $this->webhookPayload($reservation));
                if ($reservation->guest_email_snapshot) {
                    $this->sendGuestMail($reservation, 'reservation_confirmed');
                }
            });

            return $reservation->refresh();
        });
    }

    public function sendGuestMail(Reservation $reservation, string $templateKey, array $extra = []): void
    {
        $rendered = $this->templates->render($templateKey, $reservation, $extra);
        $tenant = $reservation->tenant()->first();

        Mail::to($reservation->guest_email_snapshot)->queue(new TemplatedMail(
            $rendered['subject'],
            $rendered['body'],
            $tenant?->mail_from_name,
            $tenant?->mail_reply_to,
        ));

        NotificationLog::withoutGlobalScopes()->create([
            'tenant_id' => $reservation->tenant_id,
            'location_id' => $reservation->location_id,
            'reservation_id' => $reservation->id,
            'channel' => 'mail',
            'template_key' => $templateKey,
            'recipient' => $reservation->guest_email_snapshot,
            'subject' => $rendered['subject'],
            'status' => 'queued',
        ]);
    }

    private function webhookPayload(Reservation $reservation): array
    {
        return [
            'code' => $reservation->code,
            'status' => $reservation->status->value,
            'party_size' => $reservation->party_size,
            'date' => $reservation->reservation_date->toDateString(),
            'start_at' => $reservation->start_at->toIso8601String(),
            'end_at' => $reservation->end_at->toIso8601String(),
            'timezone' => $reservation->timezone,
            'source' => $reservation->source,
            'location_id' => $reservation->location_id,
            'guest_name' => $reservation->guest_name_snapshot,
        ];
    }

    private function availabilityMessage(string $reason): string
    {
        return match ($reason) {
            'lead_time' => __('Für diesen Zeitpunkt ist es leider etwas zu kurzfristig – bitte wählen Sie einen Termin etwas weiter in der Zukunft.'),
            'too_far_ahead' => __('Dieser Termin liegt noch zu weit in der Zukunft. Bitte schauen Sie später noch einmal vorbei.'),
            'blackout' => __('Zu diesem Zeitpunkt sind wir leider geschlossen. Bitte wählen Sie einen anderen Tag.'),
            'covers_full' => __('Zu diesem Zeitpunkt sind leider alle Plätze vergeben – wie wäre es mit einem anderen Termin?'),
            'no_table' => __('Zu diesem Zeitpunkt ist kein passender Tisch mehr frei – bitte probieren Sie eine andere Zeit oder Personenzahl.'),
            default => __('Dieser Zeitpunkt ist leider nicht mehr verfügbar – bitte wählen Sie einen anderen Termin.'),
        };
    }
}
