<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\AuditLogger;
use App\Services\RefundService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationLifecycleService;
use App\Services\TableAssignmentService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationBookController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ReservationLifecycleService $lifecycle,
        private readonly ReservationAvailabilityService $availability,
        private readonly RefundService $refunds,
        private readonly TableAssignmentService $tableAssignment,
    ) {}

    public function index(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        [$from, $to, $preset] = $this->resolveDateRange($request, $location->timezone);

        $query = Reservation::query()
            ->where('location_id', $location->id)
            ->with(['tables', 'guest', 'tags']);

        if ($search = $request->input('q')) {
            // A free-text search spans all dates so staff can find a guest
            // regardless of when they booked.
            $query->where(function ($q) use ($search) {
                $q->where('guest_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('guest_email_snapshot', 'like', "%{$search}%")
                    ->orWhere('guest_phone_snapshot', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('guest_note', 'like', "%{$search}%");
            });
        } elseif ($from !== null && $to !== null) {
            // whereDate normalises the comparison so a stored time component on
            // reservation_date doesn't push the last day out of range.
            $query->whereDate('reservation_date', '>=', $from)
                ->whereDate('reservation_date', '<=', $to);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($source = $request->input('source')) {
            $query->where('source', $source);
        }
        if ($roomId = $request->input('room_id')) {
            $query->whereHas('tables', fn ($q) => $q->where('room_id', $roomId));
        }

        $sort = $request->input('sort', 'start_at');
        $allowedSorts = ['start_at' => 'start_at', 'name' => 'guest_name_snapshot', 'status' => 'status', 'party' => 'party_size', 'created' => 'created_at'];
        $query->orderBy($allowedSorts[$sort] ?? 'start_at');

        $reservations = $query->paginate(50)->withQueryString();

        return view('admin.reservations.index', [
            'location' => $location,
            'reservations' => $reservations,
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'rangeLabel' => $this->rangeLabel($from, $to, $preset),
            'rooms' => $location->rooms()->orderBy('sort_order')->get(),
            'statuses' => ReservationStatus::cases(),
            'timeline' => $request->input('view') === 'timeline' ? $this->timelineData($location, $from) : null,
        ]);
    }

    /**
     * Day grid data for the timeline view: tables (grouped by room) on the
     * y-axis, the location's opening window on the x-axis, every active or
     * completed reservation of the day as a positioned bar.
     *
     * @return array{day: CarbonImmutable, dayStart: CarbonImmutable, dayEnd: CarbonImmutable, hours: array<int, CarbonImmutable>, rooms: mixed, unassigned: mixed, nowPct: ?float}
     */
    private function timelineData($location, ?string $from): array
    {
        $tz = $location->timezone;
        $day = CarbonImmutable::parse($from ?? 'today', $tz)->startOfDay();

        // Opening window for that weekday; sensible fallback when unset.
        $hoursRows = $location->openingHours()->where('weekday', $day->dayOfWeek)->get();
        $opens = $hoursRows->min('opens_at') ?: '11:00';
        $closes = $hoursRows->max('closes_at') ?: '23:00';
        $dayStart = $day->setTimeFromTimeString(substr((string) $opens, 0, 5))->subHour();
        $dayEnd = $day->setTimeFromTimeString(substr((string) $closes, 0, 5))->addHour();
        if ($dayEnd->lessThanOrEqualTo($dayStart)) {
            $dayEnd = $dayEnd->addDay(); // over-midnight closing
        }
        $span = max(1, $dayEnd->diffInMinutes($dayStart));

        $reservations = Reservation::query()
            ->where('location_id', $location->id)
            ->whereDate('reservation_date', $day->toDateString())
            ->whereIn('status', array_merge(ReservationStatus::activeStatuses(), [ReservationStatus::Completed->value]))
            ->with(['tables:id,name'])
            ->orderBy('start_at')
            ->get();

        $bar = function (Reservation $r) use ($dayStart, $span, $tz) {
            $start = $r->start_at->copy()->setTimezone($tz);
            $end = $r->end_at->copy()->setTimezone($tz);
            $left = max(0, $dayStart->diffInMinutes($start, false)) / $span * 100;
            $right = min($span, max(0, $dayStart->diffInMinutes($end, false))) / $span * 100;

            return [
                'reservation' => $r,
                'left' => round($left, 2),
                'width' => round(max(1.5, $right - $left), 2),
                'label' => $start->format('H:i').' '.$r->guest_name_snapshot.' · '.$r->party_size.' P.',
            ];
        };

        $byTable = [];
        $unassigned = [];
        foreach ($reservations as $r) {
            if ($r->tables->isEmpty()) {
                $unassigned[] = $bar($r);

                continue;
            }
            foreach ($r->tables as $t) {
                $byTable[$t->id][] = $bar($r);
            }
        }

        $rooms = $location->rooms()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->map(fn ($room) => [
                'name' => $room->name,
                'tables' => $room->tables->map(fn ($t) => [
                    'name' => $t->name,
                    'capacity' => $t->min_capacity.'–'.$t->max_capacity,
                    'bars' => $byTable[$t->id] ?? [],
                ]),
            ]);

        $now = CarbonImmutable::now($tz);
        $nowPct = ($now->between($dayStart, $dayEnd))
            ? round($dayStart->diffInMinutes($now) / $span * 100, 2)
            : null;

        // Hour ticks for the header row
        $hours = [];
        for ($h = $dayStart->copy()->startOfHour()->addHour(); $h < $dayEnd; $h = $h->addHour()) {
            $hours[] = $h;
        }

        return [
            'day' => $day,
            'dayStart' => $dayStart,
            'dayEnd' => $dayEnd,
            'span' => $span,
            'hours' => $hours,
            'rooms' => $rooms,
            'unassigned' => $unassigned,
            'nowPct' => $nowPct,
        ];
    }

    /**
     * Resolve the active date range from a Kimai-style preset
     * (today/this_week/last_30_days/…) or an explicit from/to pair.
     * Returns [fromDate|null, toDate|null, presetKey] – nulls mean "all dates".
     *
     * @return array{0: ?string, 1: ?string, 2: string}
     */
    private function resolveDateRange(Request $request, string $tz): array
    {
        $now = CarbonImmutable::now($tz);
        $preset = $request->input('range');

        $range = match ($preset) {
            'today' => [$now, $now],
            'yesterday' => [$now->subDay(), $now->subDay()],
            'this_week' => [$now->startOfWeek(), $now->endOfWeek()],
            'last_week' => [$now->subWeek()->startOfWeek(), $now->subWeek()->endOfWeek()],
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'last_7_days' => [$now->subDays(6), $now],
            'last_30_days' => [$now->subDays(29), $now],
            'all' => [null, null],
            default => null,
        };

        if ($range !== null) {
            return [$range[0]?->toDateString(), $range[1]?->toDateString(), $preset];
        }

        // Custom range, or legacy single `date`, else default to today.
        $from = $request->input('from') ?: $request->input('date');
        $to = $request->input('to') ?: $from;
        if ($from === null && $to === null) {
            return [$now->toDateString(), $now->toDateString(), 'today'];
        }
        // Keep the pair ordered so a reversed selection still works.
        if ($from && $to && $from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to, 'custom'];
    }

    private function rangeLabel(?string $from, ?string $to, string $preset): string
    {
        $labels = [
            'today' => 'Heute', 'yesterday' => 'Gestern', 'this_week' => 'Diese Woche',
            'last_week' => 'Letzte Woche', 'this_month' => 'Dieser Monat',
            'last_month' => 'Letzter Monat', 'last_7_days' => 'Letzte 7 Tage',
            'last_30_days' => 'Letzte 30 Tage', 'all' => 'Alle Termine',
        ];
        if (isset($labels[$preset])) {
            return $labels[$preset];
        }
        if ($from === null || $to === null) {
            return 'Alle Termine';
        }
        $f = CarbonImmutable::parse($from)->format('d.m.Y');
        $t = CarbonImmutable::parse($to)->format('d.m.Y');

        return $f === $t ? $f : "$f – $t";
    }

    public function show(Reservation $reservation)
    {
        $this->authorizeReservation($reservation);

        $reservation->load(['tables.room', 'guest.tags', 'guest.notes', 'statusHistories.user', 'notes.user', 'tags']);

        return view('admin.reservations.show', [
            'reservation' => $reservation,
            'location' => $this->context->location(),
        ]);
    }

    public function create(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        return view('admin.reservations.create', [
            'location' => $location,
            'rooms' => $location->rooms()->with('tables')->orderBy('sort_order')->get(),
            'prefill' => [
                'date' => $request->input('date', CarbonImmutable::now($location->timezone)->toDateString()),
                'time' => $request->input('time', '19:00'),
                'party_size' => (int) $request->input('party_size', 2),
                'table_id' => $request->filled('table_id') ? (int) $request->input('table_id') : null,
            ],
        ]);
    }

    /**
     * Floor plan availability for the internal "new reservation" form —
     * same idea as the public floor plan, but for staff: all active tables
     * (not just online-bookable ones), occupied/unsuitable are flagged but
     * the final say stays with the staff member.
     */
    public function floorplanAvailability(Request $request): JsonResponse
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:600'],
        ]);

        $settings = $location->effectiveSettings();
        $partySize = (int) $validated['party_size'];
        $duration = (int) ($validated['duration_minutes'] ?? $settings->durationFor($partySize));
        $buffer = (int) $settings->buffer_minutes;

        $startUtc = CarbonImmutable::parse($validated['date'].' '.$validated['time'], $location->timezone)->utc();
        $windowStart = $startUtc->subMinutes($buffer);
        $windowEnd = $startUtc->addMinutes($duration + $buffer);

        $busy = $this->tableAssignment->busyTableIds($location, $windowStart, $windowEnd, null);

        $blockedRooms = $location->blackoutPeriods()
            ->whereNotNull('room_id')
            ->whereNull('reduce_covers_to')
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->pluck('room_id')
            ->all();

        $rooms = $location->rooms()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->map(fn ($room) => [
                'id' => $room->id,
                'name' => $room->name,
                'plan_width' => (int) $room->plan_width,
                'plan_height' => (int) $room->plan_height,
                'tables' => $room->tables->map(function ($t) use ($busy, $blockedRooms, $partySize) {
                    $status = 'available';
                    if (in_array($t->room_id, $blockedRooms, true)) {
                        $status = 'blocked';
                    } elseif (in_array($t->id, $busy, true)) {
                        $status = 'occupied';
                    } elseif ($partySize < $t->min_capacity || $partySize > $t->max_capacity) {
                        $status = 'unsuitable';
                    }

                    return [
                        'id' => $t->id,
                        'name' => $t->name,
                        'status' => $status,
                        'capacity' => $t->min_capacity.'–'.$t->max_capacity,
                        'pos_x' => (int) $t->pos_x,
                        'pos_y' => (int) $t->pos_y,
                        'width' => (int) ($t->width ?: 60),
                        'height' => (int) ($t->height ?: 60),
                        'rotation' => (int) $t->rotation,
                        'shape' => $t->shape ?: 'rect',
                    ];
                })->values(),
            ])
            ->filter(fn ($room) => $room['tables']->isNotEmpty())
            ->values();

        return response()->json(['rooms' => $rooms]);
    }

    public function store(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'duration_minutes' => ['nullable', 'integer', 'min:30', 'max:600'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'occasion' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'internal_note' => ['nullable', 'string', 'max:1000'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'source' => ['nullable', 'in:manual,phone'],
            'table_ids' => ['nullable', 'array'],
            'table_ids.*' => ['integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        // Manual table choice: validate tables belong to this location
        $tableIds = collect($validated['table_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $location->tables()->where('id', $id)->exists())
            ->values()
            ->all();

        $force = (bool) ($validated['force'] ?? false);
        if ($force && ! $request->user()->canInTenant('overbook.manual', $this->context->tenant(), $location)) {
            abort(403, 'Überbuchung erfordert eine erweiterte Berechtigung.');
        }

        $reservation = $this->lifecycle->create($location, [
            'party_size' => (int) $validated['party_size'],
            'start_local' => CarbonImmutable::parse($validated['date'].' '.$validated['time'], $location->timezone),
            'duration_minutes' => $validated['duration_minutes'] ?? null,
            'source' => $validated['source'] ?? 'manual',
            'guest_name' => $validated['name'],
            'guest_email' => $validated['email'] ?? null,
            'guest_phone' => $validated['phone'] ?? null,
            'guest_note' => $validated['note'] ?? null,
            'internal_note' => $validated['internal_note'] ?? null,
            'allergy_note' => $validated['allergies'] ?? null,
            'occasion' => $validated['occasion'] ?? null,
            'table_ids' => $tableIds,
            'skip_availability_check' => $force,
        ], $request->user());

        return redirect()->route('admin.reservations.show', $reservation)
            ->with('success', __('Reservierung :code angelegt.', ['code' => $reservation->code]));
    }

    public function transition(Request $request, Reservation $reservation)
    {
        $this->authorizeReservation($reservation);

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::enum(ReservationStatus::class)],
            'reason' => ['nullable', 'string', 'max:255'],
            'seated_at' => ['nullable', 'date_format:H:i'],
        ]);

        $target = ReservationStatus::from($validated['status']);

        // Check-in time chosen by staff in the seating dialog. Interpreted in
        // the location timezone, normally on the current day.
        $seatedAt = null;
        if (! empty($validated['seated_at'])
            && in_array($target, [ReservationStatus::Seated, ReservationStatus::PartiallyArrived], true)) {
            [$h, $m] = array_map('intval', explode(':', $validated['seated_at']));
            $tz = $reservation->timezone ?: ($this->context->location()?->timezone ?? config('app.timezone'));
            $nowLocal = CarbonImmutable::now($tz);
            $candidate = $nowLocal->setTime($h, $m);
            // Checking in shortly after midnight for a reservation from the previous
            // evening (e.g. now=00:10, chosen time=23:30) would otherwise land ~24h
            // in the future. If the chosen time is implausibly far ahead of "now",
            // assume the staff meant the previous day.
            if ($candidate->greaterThan($nowLocal->addHours(6))) {
                $candidate = $candidate->subDay();
            }
            $seatedAt = $candidate->utc();
        }

        $permissionMap = [
            ReservationStatus::Confirmed->value => 'reservations.update',
            ReservationStatus::Rejected->value => 'reservations.cancel',
            ReservationStatus::CancelledByRestaurant->value => 'reservations.cancel',
            ReservationStatus::CancelledByGuest->value => 'reservations.cancel',
            ReservationStatus::Seated->value => 'reservations.seat',
            ReservationStatus::PartiallyArrived->value => 'reservations.seat',
            ReservationStatus::Completed->value => 'reservations.depart',
            ReservationStatus::NoShow->value => 'reservations.no_show',
        ];
        $permission = $permissionMap[$target->value] ?? 'reservations.update';
        if (! $request->user()->canInTenant($permission, $this->context->tenant(), $this->context->location())) {
            abort(403);
        }

        $this->lifecycle->transition($reservation, $target, $request->user(), 'user', $validated['reason'] ?? null, null, $seatedAt);

        // Staff cancellation → request a deposit refund per the location's policy
        if (in_array($target, [
            ReservationStatus::CancelledByGuest,
            ReservationStatus::CancelledByRestaurant,
            ReservationStatus::Rejected,
        ], true)) {
            $this->refunds->requestForReservation($reservation->fresh(), 'staff', $request->user());
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'status' => $reservation->fresh()->status->value,
            ]);
        }

        return back()->with('success', __('Status geändert.'));
    }

    /**
     * Bulk status change from the reservation book: applies the transition to
     * every selected reservation, silently skipping the ones where it is not
     * allowed (wrong current status) and reporting a summary.
     */
    public function bulkTransition(Request $request)
    {
        $validated = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::in(['confirmed', 'cancelled_by_restaurant', 'no_show', 'completed'])],
        ]);

        $target = ReservationStatus::from($validated['status']);

        $permissionMap = [
            ReservationStatus::Confirmed->value => 'reservations.update',
            ReservationStatus::CancelledByRestaurant->value => 'reservations.cancel',
            ReservationStatus::NoShow->value => 'reservations.no_show',
            ReservationStatus::Completed->value => 'reservations.depart',
        ];
        if (! $request->user()->canInTenant($permissionMap[$target->value], $this->context->tenant(), $this->context->location())) {
            abort(403);
        }

        $reservations = Reservation::whereIn('id', $validated['ids'])->get();

        $done = 0;
        $skipped = 0;
        foreach ($reservations as $reservation) {
            $this->authorizeReservation($reservation);

            if (! $reservation->status->canTransitionTo($target)) {
                $skipped++;

                continue;
            }

            $this->lifecycle->transition($reservation, $target, $request->user(), 'user', 'Sammelaktion');

            if ($target === ReservationStatus::CancelledByRestaurant) {
                $this->refunds->requestForReservation($reservation->fresh(), 'staff', $request->user());
            }
            $done++;
        }

        $msg = trans_choice('{1}:count Reservierung geändert.|[2,*]:count Reservierungen geändert.', $done, ['count' => $done]);
        if ($skipped > 0) {
            $msg .= ' '.trans_choice('{1}:count übersprungen (Statuswechsel nicht erlaubt).|[2,*]:count übersprungen (Statuswechsel nicht erlaubt).', $skipped, ['count' => $skipped]);
        }

        return back()->with('success', $msg);
    }

    /**
     * Adjust the party size of an existing reservation – e.g. a seated walk-in
     * grows when more guests join. Capped at the assigned table's seats
     * (incl. squeeze seats); beyond that a bigger/extra table is required.
     */
    public function updateParty(Request $request, Reservation $reservation, AuditLogger $audit)
    {
        $this->authorizeReservation($reservation);
        if (! $request->user()->canInTenant('reservations.update', $this->context->tenant(), $this->context->location())) {
            abort(403);
        }

        $validated = $request->validate([
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $new = (int) $validated['party_size'];

        $reservation->loadMissing('tables');
        $capacity = (int) $reservation->tables->sum(fn ($t) => $t->max_capacity + $t->extra_capacity);
        if ($capacity > 0 && $new > $capacity) {
            return response()->json([
                'message' => __('An diesem Tisch ist Platz für höchstens :n Personen. Bitte einen größeren Tisch wählen oder einen weiteren Tisch dazunehmen.', ['n' => $capacity]),
                'max' => $capacity,
            ], 422);
        }

        $old = $reservation->party_size;
        $reservation->update(['party_size' => $new]);
        $audit->log('reservation.party_updated', $reservation, ['party_size' => $old], ['party_size' => $new]);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'party_size' => $new]);
        }

        return back()->with('success', __('Personenzahl aktualisiert.'));
    }

    public function moveTable(Request $request, Reservation $reservation)
    {
        $this->authorizeReservation($reservation);

        $validated = $request->validate([
            'table_ids' => ['required', 'array', 'min:1'],
            'table_ids.*' => ['integer'],
            'force' => ['nullable', 'boolean'],
        ]);

        $location = $this->context->location();
        $tableIds = collect($validated['table_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $location->tables()->where('id', $id)->exists())
            ->values()
            ->all();
        abort_if($tableIds === [], 422);

        $force = (bool) ($validated['force'] ?? false);
        if ($force && ! $request->user()->canInTenant('overbook.manual', $this->context->tenant(), $location)) {
            abort(403);
        }

        $this->lifecycle->reassignTables($reservation, $tableIds, $request->user(), $force);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true]);
        }

        return back()->with('success', __('Tisch geändert.'));
    }

    public function export(Request $request): StreamedResponse
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $from = $request->input('from', now()->subMonth()->toDateString());
        $until = $request->input('until', now()->addMonth()->toDateString());

        $reservations = Reservation::query()
            ->where('location_id', $location->id)
            ->whereBetween('reservation_date', [$from, $until])
            ->orderBy('start_at')
            ->with('tables')
            ->get();

        return response()->streamDownload(function () use ($reservations) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Code', 'Datum', 'Uhrzeit', 'Personen', 'Name', 'E-Mail', 'Telefon', 'Status', 'Quelle', 'Tische', 'Notiz'], ';');
            foreach ($reservations as $r) {
                fputcsv($out, [
                    $r->code,
                    $r->reservation_date->format('d.m.Y'),
                    $r->localStart()->format('H:i'),
                    $r->party_size,
                    $r->guest_name_snapshot,
                    $r->guest_email_snapshot,
                    $r->guest_phone_snapshot,
                    $r->status->value,
                    $r->source,
                    $r->tables->pluck('name')->implode(', '),
                    $r->guest_note,
                ], ';');
            }
            fclose($out);
        }, 'reservierungen_'.$from.'_'.$until.'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function authorizeReservation(Reservation $reservation): void
    {
        // Global scope already filters by tenant; explicit check as defense in depth.
        abort_if($reservation->tenant_id !== $this->context->tenantId(), 404);
        $location = $this->context->location();
        abort_if($location !== null && $reservation->location_id !== $location->id
            && ! request()->user()->canAccessLocation($this->context->tenant(), $reservation->location), 403);
    }
}
