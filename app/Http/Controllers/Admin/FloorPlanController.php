<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
            'combinations' => ($combinations = $location->tableCombinations()->with('tables:id,name')->get()),
            'joinableTables' => $location->tables()->where('joinable', true)->where('is_active', true)->with('room:id,name')->get(),
            'combosJson' => $combinations->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'min_capacity' => $c->min_capacity,
                'max_capacity' => $c->max_capacity,
                'tables' => $c->tables->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values(),
            ]),
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
            ->with(['tables:restaurant_tables.id,name', 'tags:id,name,color'])
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

            $occupiedSeats = $reservations
                ->filter(fn ($r) => $r->tables->contains('id', $table->id)
                    && $r->start_at->lte($atUtc) && $r->end_at->gt($atUtc))
                ->sum('party_size');

            $tableStates[] = [
                'id' => $table->id,
                'name' => $table->name,
                'room_id' => $table->room_id,
                'status' => $status,
                'pos_x' => $table->pos_x, 'pos_y' => $table->pos_y,
                'width' => $table->width, 'height' => $table->height,
                'rotation' => $table->rotation, 'shape' => $table->shape,
                'capacity' => $table->min_capacity.'-'.$table->max_capacity,
                'seats' => (int) $table->max_capacity,
                'occupied' => min((int) $occupiedSeats, (int) $table->max_capacity),
                'current' => $current ? [
                    'id' => $current->id,
                    'name' => $current->guest_name_snapshot,
                    'party' => $current->party_size,
                    'until' => $current->localEnd()->format('H:i'),
                    'status' => $current->status->value,
                    'tags' => $current->tags->map(fn ($t) => ['name' => $t->name, 'color' => $t->color])->values(),
                ] : null,
                'upcoming' => $upcoming ? [
                    'id' => $upcoming->id,
                    'name' => $upcoming->guest_name_snapshot,
                    'party' => $upcoming->party_size,
                    'at' => $upcoming->localStart()->format('H:i'),
                    'tags' => $upcoming->tags->map(fn ($t) => ['name' => $t->name, 'color' => $t->color])->values(),
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
     * Create a table directly from the floor-plan editor and place it at the
     * given position. Returns the new table in the same shape as state().
     */
    public function storeTable(Request $request, PlanLimitService $limits)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        if (! $limits->canAdd($location->tenant, 'max_tables')) {
            return response()->json(['message' => __('Tisch-Limit Ihres Tarifs erreicht.')], 422);
        }

        $validated = $request->validate([
            'room_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:40'],
            'min_capacity' => ['required', 'integer', 'min:1', 'max:50'],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:50', 'gte:min_capacity'],
            'shape' => ['nullable', 'in:rect,round'],
            'pos_x' => ['nullable', 'integer', 'min:0', 'max:5000'],
            'pos_y' => ['nullable', 'integer', 'min:0', 'max:5000'],
        ]);

        abort_unless($location->rooms()->where('id', $validated['room_id'])->exists(), 422);

        // Size the table by its capacity so it isn't a tiny box and leaves room
        // for the chairs. Set width/height explicitly – otherwise the in-memory
        // model returns null (DB defaults only apply on read) and the editor
        // would render a zero-sized, invisible table.
        $shape = $validated['shape'] ?? 'rect';
        $max = (int) $validated['max_capacity'];
        [$width, $height] = RestaurantTable::sizeForCapacity($shape, $max);

        $table = $location->tables()->create([
            'tenant_id' => $location->tenant_id,
            'room_id' => (int) $validated['room_id'],
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => $max,
            'shape' => $shape,
            'width' => $width,
            'height' => $height,
            'rotation' => 0,
            'pos_x' => (int) ($validated['pos_x'] ?? 40),
            'pos_y' => (int) ($validated['pos_y'] ?? 40),
        ]);

        $this->audit->log('table.created', $table, null, $validated);

        return response()->json(['table' => [
            'id' => $table->id,
            'name' => $table->name,
            'room_id' => $table->room_id,
            'status' => 'free',
            'pos_x' => $table->pos_x, 'pos_y' => $table->pos_y,
            'width' => $table->width, 'height' => $table->height,
            'rotation' => $table->rotation, 'shape' => $table->shape,
            'capacity' => $table->min_capacity.'-'.$table->max_capacity,
            'seats' => (int) $table->max_capacity,
            'occupied' => 0,
            'current' => null,
            'upcoming' => null,
        ]]);
    }

    /**
     * Upload a background image (floor sketch / photo) for a room.
     */
    public function uploadBackground(Request $request, Room $room)
    {
        $this->authorizeRoom($room);

        $request->validate([
            'image' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
        ]);

        // Remove the previous file so we don't orphan storage.
        if ($room->background_path) {
            Storage::disk('public')->delete($room->background_path);
        }

        $path = $request->file('image')->store(
            'floorplan/'.$room->location_id,
            'public'
        );
        $room->update(['background_path' => $path]);

        $this->audit->log('floorplan.background.updated', null, null, null, ['room_id' => $room->id]);

        return response()->json(['url' => route('admin.floorplan.background', $room)]);
    }

    /**
     * Remove a room's background image.
     */
    public function deleteBackground(Room $room)
    {
        $this->authorizeRoom($room);

        if ($room->background_path) {
            Storage::disk('public')->delete($room->background_path);
            $room->update(['background_path' => null]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Stream a room's background image (served through the app so no public
     * symlink is required and access stays tenant-scoped).
     */
    public function background(Room $room)
    {
        $this->authorizeRoom($room);
        abort_if(! $room->background_path || ! Storage::disk('public')->exists($room->background_path), 404);

        return Storage::disk('public')->response($room->background_path, null, [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorizeRoom(Room $room): void
    {
        $location = $this->context->location();
        abort_if($location === null || $room->location_id !== $location->id, 404);
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
