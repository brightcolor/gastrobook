<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Reservation;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReservationApiController extends Controller
{
    public function __construct(private readonly ReservationLifecycleService $lifecycle) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->tokenCan('reservations:read'), 403);

        $reservations = Reservation::query()
            ->when($request->input('location_id'), fn ($q, $id) => $q->where('location_id', $id))
            ->when($request->input('date'), fn ($q, $d) => $q->whereDate('reservation_date', $d))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->with('tables:restaurant_tables.id,name')
            ->orderBy('start_at')
            ->paginate(min(100, (int) $request->input('per_page', 25)));

        return response()->json([
            'data' => $reservations->getCollection()->map(fn ($r) => $this->serialize($r)),
            'meta' => [
                'current_page' => $reservations->currentPage(),
                'last_page' => $reservations->lastPage(),
                'total' => $reservations->total(),
            ],
        ]);
    }

    public function show(Request $request, string $code)
    {
        abort_unless($request->user()->tokenCan('reservations:read'), 403);

        $reservation = Reservation::where('code', $code)->with('tables')->firstOrFail();

        return response()->json(['data' => $this->serialize($reservation)]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->tokenCan('reservations:write'), 403);

        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'guest_name' => ['required', 'string', 'max:120'],
            'guest_email' => ['nullable', 'email:rfc'],
            'guest_phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
            'source' => ['nullable', 'in:api,phone_assistant'],
        ]);

        $location = Location::findOrFail($validated['location_id']);

        $reservation = $this->lifecycle->create($location, [
            'party_size' => (int) $validated['party_size'],
            'start_local' => CarbonImmutable::parse($validated['date'].' '.$validated['time'], $location->timezone),
            'source' => $validated['source'] ?? 'api',
            'guest_name' => $validated['guest_name'],
            'guest_email' => $validated['guest_email'] ?? null,
            'guest_phone' => $validated['guest_phone'] ?? null,
            'guest_note' => $validated['note'] ?? null,
        ]);

        return response()->json(['data' => $this->serialize($reservation->load('tables'))], 201);
    }

    public function cancel(Request $request, string $code)
    {
        abort_unless($request->user()->tokenCan('reservations:write'), 403);

        $reservation = Reservation::where('code', $code)->firstOrFail();

        $this->lifecycle->transition(
            $reservation,
            ReservationStatus::CancelledByRestaurant,
            null,
            'system',
            $request->input('reason', 'api_cancellation')
        );

        return response()->json(['data' => $this->serialize($reservation->refresh())]);
    }

    private function serialize(Reservation $r): array
    {
        return [
            'code' => $r->code,
            'status' => $r->status->value,
            'party_size' => $r->party_size,
            'date' => $r->reservation_date->toDateString(),
            'time' => $r->localStart()->format('H:i'),
            'start_at' => $r->start_at->toIso8601String(),
            'end_at' => $r->end_at->toIso8601String(),
            'timezone' => $r->timezone,
            'source' => $r->source,
            'location_id' => $r->location_id,
            'guest' => [
                'name' => $r->guest_name_snapshot,
                'email' => $r->guest_email_snapshot,
                'phone' => $r->guest_phone_snapshot,
            ],
            'tables' => $r->relationLoaded('tables') ? $r->tables->pluck('name') : null,
            'created_at' => $r->created_at->toIso8601String(),
        ];
    }
}
