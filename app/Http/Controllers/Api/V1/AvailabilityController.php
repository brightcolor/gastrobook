<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\ReservationAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class AvailabilityController extends Controller
{
    public function __construct(private readonly ReservationAvailabilityService $availability) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->tokenCan('availability:read'), 403);

        $validated = $request->validate([
            'location_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $location = Location::findOrFail($validated['location_id']); // tenant scope applies

        $localDate = CarbonImmutable::parse($validated['date'], $location->timezone)->startOfDay();
        $slots = $this->availability->slotsFor($location, $localDate, (int) $validated['party_size'], ['online' => false]);

        return response()->json([
            'data' => [
                'location_id' => $location->id,
                'timezone' => $location->timezone,
                'date' => $validated['date'],
                'party_size' => (int) $validated['party_size'],
                'slots' => $slots,
            ],
        ]);
    }
}
