<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\ReservationLifecycleService;
use App\Services\TableAssignmentService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class WalkInController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly TableAssignmentService $tables,
        private readonly ReservationLifecycleService $lifecycle,
    ) {}

    public function index(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $partySize = (int) $request->input('party_size', 2);
        $duration = $location->effectiveSettings()->durationFor($partySize);
        $nowUtc = CarbonImmutable::now();
        $endUtc = $nowUtc->addMinutes($duration);

        $freeTables = $this->tables->freeTables($location, $nowUtc, $endUtc)
            ->map(function ($table) use ($location, $nowUtc) {
                // How long is this table still free?
                $next = $table->reservations()
                    ->whereIn('reservations.status', \App\Enums\ReservationStatus::activeStatuses())
                    ->where('start_at', '>', $nowUtc)
                    ->orderBy('start_at')
                    ->first();
                $table->free_until = $next
                    ? $next->start_at->copy()->setTimezone($location->timezone)->format('H:i')
                    : null;

                return $table;
            });

        $current = $location->reservations()
            ->where('source', 'walk_in')
            ->whereIn('status', ['seated', 'completed'])
            ->whereDate('reservation_date', CarbonImmutable::now($location->timezone)->toDateString())
            ->with('tables')
            ->orderByDesc('seated_at')
            ->get();

        return view('admin.walkins.index', [
            'location' => $location,
            'partySize' => $partySize,
            'freeTables' => $freeTables,
            'walkIns' => $current,
        ]);
    }

    public function store(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_unless($location->effectiveSettings()->walkins_enabled, 403, 'Walk-ins sind deaktiviert.');

        $validated = $request->validate([
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'table_id' => ['required', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40'],
        ]);

        abort_unless($location->tables()->where('id', $validated['table_id'])->exists(), 422);

        $nowLocal = CarbonImmutable::now($location->timezone);

        $reservation = $this->lifecycle->create($location, [
            'party_size' => (int) $validated['party_size'],
            'start_local' => $nowLocal,
            'source' => 'walk_in',
            'guest_name' => $validated['name'] ?: __('Walk-in'),
            'guest_phone' => $validated['phone'] ?? null,
            'table_ids' => [(int) $validated['table_id']],
            'skip_availability_check' => false,
        ], $request->user());

        return redirect()->route('admin.walkins.index')
            ->with('success', __('Walk-in platziert (:code).', ['code' => $reservation->code]));
    }
}
