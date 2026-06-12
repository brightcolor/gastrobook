<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class FloorPlanController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $rooms = $location->rooms()->where('is_active', true)->orderBy('sort_order')->with(['tables' => fn ($q) => $q->where('is_active', true)])->get();

        return view('admin.floorplan.index', [
            'location' => $location,
            'rooms' => $rooms,
            'date' => $request->input('date', CarbonImmutable::now($location->timezone)->toDateString()),
        ]);
    }

    /**
     * Live state JSON: table statuses + reservations for a time window.
     */
    public function state(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
        ]);

        $tz = $location->timezone;
        $atLocal = CarbonImmutable::parse(
            $validated['date'].' '.($validated['time'] ?? CarbonImmutable::now($tz)->format('H:i')),
            $tz
        );
        $atUtc = $atLocal->utc();
        $soonUtc = $atUtc->addMinutes(45);

        $reservations = Reservation::query()
            ->where('location_id', $location->id)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->whereDate('reservation_date', $validated['date'])
            ->with('tables:restaurant_tables.id,name')
            ->orderBy('start_at')
            ->get();

        $tableStates = [];
        foreach ($location->tables()->where('is_active', true)->get() as $table) {
            $current = $reservations->first(fn ($r) => $r->tables->contains('id', $table->id)
                && $r->start_at->lte($atUtc) && $r->end_at->gt($atUtc));
            $upcoming = $reservations->first(fn ($r) => $r->tables->contains('id', $table->id)
                && $r->start_at->gt($atUtc) && $r->start_at->lte($soonUtc));

            $blocked = $table->blocks()
                ->where('starts_at', '<=', $atUtc)
                ->where('ends_at', '>', $atUtc)
                ->exists();

            $status = 'free';
            if ($blocked) {
                $status = 'blocked';
            } elseif ($current !== null) {
                $status = $current->status === ReservationStatus::Seated ? 'occupied' : 'awaiting';
                if ($current->status === ReservationStatus::Confirmed && $current->no_show_risk >= 50) {
                    $status = 'no_show_risk';
                }
            } elseif ($upcoming !== null) {
                $status = 'soon';
            }

            $tableStates[] = [
                'id' => $table->id,
                'name' => $table->name,
                'room_id' => $table->room_id,
                'status' => $status,
                'pos_x' => $table->pos_x, 'pos_y' => $table->pos_y,
                'width' => $table->width, 'height' => $table->height,
                'rotation' => $table->rotation, 'shape' => $table->shape,
                'capacity' => $table->min_capacity.'-'.$table->max_capacity,
                'current' => $current ? [
                    'id' => $current->id,
                    'name' => $current->guest_name_snapshot,
                    'party' => $current->party_size,
                    'until' => $current->localEnd()->format('H:i'),
                    'status' => $current->status->value,
                ] : null,
                'upcoming' => $upcoming ? [
                    'id' => $upcoming->id,
                    'name' => $upcoming->guest_name_snapshot,
                    'party' => $upcoming->party_size,
                    'at' => $upcoming->localStart()->format('H:i'),
                ] : null,
            ];
        }

        return response()->json([
            'tables' => $tableStates,
            'reservations' => $reservations->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->guest_name_snapshot,
                'party' => $r->party_size,
                'time' => $r->localStart()->format('H:i'),
                'until' => $r->localEnd()->format('H:i'),
                'status' => $r->status->value,
                'table_ids' => $r->tables->pluck('id'),
                'risk' => $r->no_show_risk,
            ])->values(),
        ]);
    }

    /**
     * Persist drag & drop geometry changes from the floor plan editor.
     */
    public function updatePositions(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'tables' => ['required', 'array'],
            'tables.*.id' => ['required', 'integer'],
            'tables.*.pos_x' => ['required', 'integer', 'min:0', 'max:5000'],
            'tables.*.pos_y' => ['required', 'integer', 'min:0', 'max:5000'],
            'tables.*.width' => ['nullable', 'integer', 'min:30', 'max:600'],
            'tables.*.height' => ['nullable', 'integer', 'min:30', 'max:600'],
            'tables.*.rotation' => ['nullable', 'integer', 'min:-180', 'max:180'],
        ]);

        foreach ($validated['tables'] as $data) {
            $table = RestaurantTable::where('location_id', $location->id)->find($data['id']);
            if ($table === null) {
                continue; // ignore foreign/unknown ids — tenant scope + location check
            }
            $table->update(array_filter([
                'pos_x' => $data['pos_x'],
                'pos_y' => $data['pos_y'],
                'width' => $data['width'] ?? null,
                'height' => $data['height'] ?? null,
                'rotation' => $data['rotation'] ?? null,
            ], fn ($v) => $v !== null));
        }

        $this->audit->log('floorplan.updated', null, null, null, ['count' => count($validated['tables'])]);

        return response()->json(['ok' => true]);
    }
}
