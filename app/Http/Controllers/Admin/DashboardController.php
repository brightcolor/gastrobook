<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\WaitlistEntry;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context)
    {
        $location = $context->location();
        if ($location === null) {
            return view('admin.no-location');
        }

        $todayLocal = CarbonImmutable::now($location->timezone)->startOfDay();
        $tomorrowLocal = $todayLocal->addDay();

        $base = Reservation::query()->where('location_id', $location->id);

        $todayReservations = (clone $base)
            ->whereDate('reservation_date', $todayLocal->toDateString())
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->orderBy('start_at')
            ->with('tables')
            ->get();

        $stats = [
            'today_count' => $todayReservations->count(),
            'today_covers' => $todayReservations->sum('party_size'),
            'tomorrow_count' => (clone $base)
                ->whereDate('reservation_date', $tomorrowLocal->toDateString())
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->count(),
            'open_requests' => (clone $base)
                ->where('status', ReservationStatus::Requested->value)
                ->where('start_at', '>', now())
                ->count(),
            'waitlist_waiting' => WaitlistEntry::where('location_id', $location->id)
                ->whereIn('status', ['waiting', 'offered'])
                ->whereDate('desired_date', '>=', $todayLocal->toDateString())
                ->count(),
            'no_shows_week' => (clone $base)
                ->where('status', ReservationStatus::NoShow->value)
                ->where('start_at', '>=', now()->subDays(7))
                ->count(),
            'seated_now' => (clone $base)
                ->where('status', ReservationStatus::Seated->value)
                ->count(),
            'week_covers' => (clone $base)
                ->whereBetween('reservation_date', [$todayLocal->toDateString(), $todayLocal->addDays(6)->toDateString()])
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->sum('party_size'),
        ];

        $upcoming = $todayReservations
            ->filter(fn ($r) => $r->status === ReservationStatus::Confirmed && $r->start_at->isFuture())
            ->take(8);

        $overdue = $todayReservations
            ->filter(fn ($r) => $r->status === ReservationStatus::Confirmed && $r->start_at->isPast());

        $sources = (clone $base)
            ->whereDate('reservation_date', '>=', $todayLocal->subDays(30)->toDateString())
            ->selectRaw('source, count(*) as cnt')
            ->groupBy('source')
            ->pluck('cnt', 'source');

        return view('admin.dashboard', compact('location', 'stats', 'upcoming', 'overdue', 'todayReservations', 'sources'));
    }
}
