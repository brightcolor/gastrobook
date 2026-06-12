<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\FeedbackResponse;
use App\Models\Reservation;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $from = CarbonImmutable::parse($request->input('from', now()->subDays(30)->toDateString()));
        $until = CarbonImmutable::parse($request->input('until', now()->toDateString()));

        $base = Reservation::query()
            ->where('location_id', $location->id)
            ->whereBetween('reservation_date', [$from->toDateString(), $until->toDateString()]);

        $total = (clone $base)->count();
        $completed = (clone $base)->where('status', ReservationStatus::Completed->value)->count();
        $noShows = (clone $base)->where('status', ReservationStatus::NoShow->value)->count();
        $cancelled = (clone $base)->whereIn('status', [
            ReservationStatus::CancelledByGuest->value,
            ReservationStatus::CancelledByRestaurant->value,
        ])->count();
        $walkIns = (clone $base)->where('source', 'walk_in')->count();
        $online = (clone $base)->where('source', 'online')->count();

        $covers = (clone $base)
            ->whereIn('status', [ReservationStatus::Completed->value, ReservationStatus::Seated->value])
            ->sum('party_size');

        $byDay = (clone $base)
            ->selectRaw('reservation_date, count(*) as cnt, sum(party_size) as covers')
            ->groupBy('reservation_date')
            ->orderBy('reservation_date')
            ->get();

        $bySource = (clone $base)
            ->selectRaw('source, count(*) as cnt')
            ->groupBy('source')
            ->pluck('cnt', 'source');

        $byHour = (clone $base)
            ->get()
            ->groupBy(fn ($r) => $r->localStart()->format('H:00'))
            ->map->count()
            ->sortKeys();

        $avgParty = $total > 0 ? round((clone $base)->avg('party_size'), 1) : 0;

        $feedback = FeedbackResponse::where('location_id', $location->id)
            ->whereBetween('created_at', [$from, $until->endOfDay()])
            ->selectRaw('count(*) as cnt, avg(score) as avg_score')
            ->first();

        return view('admin.reports.index', [
            'location' => $location,
            'from' => $from->toDateString(),
            'until' => $until->toDateString(),
            'stats' => [
                'total' => $total,
                'completed' => $completed,
                'covers' => $covers,
                'no_show_rate' => $total > 0 ? round(100 * $noShows / $total, 1) : 0,
                'cancellation_rate' => $total > 0 ? round(100 * $cancelled / $total, 1) : 0,
                'walk_in_share' => $total > 0 ? round(100 * $walkIns / $total, 1) : 0,
                'online_share' => $total > 0 ? round(100 * $online / $total, 1) : 0,
                'avg_party' => $avgParty,
                'feedback_count' => $feedback->cnt ?? 0,
                'feedback_avg' => $feedback->avg_score ? round($feedback->avg_score, 1) : null,
            ],
            'byDay' => $byDay,
            'bySource' => $bySource,
            'byHour' => $byHour,
        ]);
    }
}
