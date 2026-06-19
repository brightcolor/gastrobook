<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\FloorZone;
use App\Models\Room;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FloorZoneController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $zones = FloorZone::where('location_id', $location->id)
            ->orderBy('room_id')
            ->orderBy('sort_order')
            ->get(['id', 'room_id', 'name', 'color', 'opacity', 'points', 'sort_order']);

        return response()->json($zones);
    }

    public function store(Request $request): JsonResponse
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:80'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'opacity' => ['required', 'integer', 'min:0', 'max:100'],
            'points' => ['required', 'array', 'min:3'],
            'points.*' => ['required', 'array:0,1'],
        ]);

        $room = Room::where('id', $data['room_id'])
            ->where('location_id', $location->id)
            ->firstOrFail();

        $zone = FloorZone::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'room_id' => $room->id,
            'name' => $data['name'],
            'color' => $data['color'],
            'opacity' => $data['opacity'],
            'points' => $data['points'],
        ]);

        $this->audit->log('zone.created', ['name' => $zone->name]);

        return response()->json($zone->only(['id', 'room_id', 'name', 'color', 'opacity', 'points', 'sort_order']), 201);
    }

    public function update(Request $request, FloorZone $zone): JsonResponse
    {
        $location = $this->context->location();
        abort_if($location === null || $zone->location_id !== $location->id, 403);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:80'],
            'color' => ['sometimes', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'opacity' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'points' => ['sometimes', 'array', 'min:3'],
            'points.*' => ['sometimes', 'array:0,1'],
        ]);

        $zone->update($data);
        $this->audit->log('zone.updated', ['id' => $zone->id]);

        return response()->json($zone->only(['id', 'room_id', 'name', 'color', 'opacity', 'points', 'sort_order']));
    }

    public function destroy(FloorZone $zone): JsonResponse
    {
        $location = $this->context->location();
        abort_if($location === null || $zone->location_id !== $location->id, 403);

        $this->audit->log('zone.deleted', ['name' => $zone->name]);
        $zone->delete();

        return response()->json(['ok' => true]);
    }
}
