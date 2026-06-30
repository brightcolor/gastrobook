<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\AuditLogger;
use App\Services\RefundService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationLifecycleService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
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
        ]);
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
        // the location timezone on the current day.
        $seatedAt = null;
        if (! empty($validated['seated_at'])
            && in_array($target, [ReservationStatus::Seated, ReservationStatus::PartiallyArrived], true)) {
            [$h, $m] = array_map('intval', explode(':', $validated['seated_at']));
            $tz = $reservation->timezone ?: ($this->context->location()?->timezone ?? config('app.timezone'));
            $seatedAt = CarbonImmutable::now($tz)->setTime($h, $m)->utc();
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
