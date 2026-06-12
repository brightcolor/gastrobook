<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationLifecycleService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReservationBookController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ReservationLifecycleService $lifecycle,
        private readonly ReservationAvailabilityService $availability,
    ) {}

    public function index(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $date = $request->input('date', CarbonImmutable::now($location->timezone)->toDateString());

        $query = Reservation::query()
            ->where('location_id', $location->id)
            ->with(['tables', 'guest', 'tags']);

        if ($search = $request->input('q')) {
            $query->where(function ($q) use ($search) {
                $q->where('guest_name_snapshot', 'like', "%{$search}%")
                    ->orWhere('guest_email_snapshot', 'like', "%{$search}%")
                    ->orWhere('guest_phone_snapshot', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%")
                    ->orWhere('guest_note', 'like', "%{$search}%");
            });
        } else {
            $query->whereDate('reservation_date', $date);
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
            'date' => $date,
            'rooms' => $location->rooms()->orderBy('sort_order')->get(),
            'statuses' => ReservationStatus::cases(),
        ]);
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
            'status' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $target = ReservationStatus::from($validated['status']);

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

        $this->lifecycle->transition($reservation, $target, $request->user(), 'user', $validated['reason'] ?? null);

        return back()->with('success', __('Status geändert.'));
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
