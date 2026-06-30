<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Models\WaitlistEntry;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $context)
    {
        $location = $context->location();
        if ($location === null) {
            return view('admin.no-location');
        }

        $todayLocal = CarbonImmutable::now($location->timezone)->startOfDay();

        $base = Reservation::query()->where('location_id', $location->id);

        $todayReservations = (clone $base)
            ->whereDate('reservation_date', $todayLocal->toDateString())
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->orderBy('start_at')
            ->with('tables')
            ->get();

        $stats = $this->computeStats($location->id, $location->timezone);

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

        $tenant = $location->tenant;
        $onboardingPending = $tenant->onboarding_completed_at === null;

        // The banner must reflect the real setup state, not just the timestamp:
        // an operator who configured everything manually (without the wizard)
        // shouldn't be nagged forever. If the essentials exist, mark onboarding
        // done so the banner disappears and the state stays consistent.
        if ($onboardingPending && $this->isSetupComplete($location, $tenant)) {
            $tenant->update(['onboarding_completed_at' => now()]);
            $onboardingPending = false;
        }

        return view('admin.dashboard', compact('location', 'stats', 'upcoming', 'overdue', 'todayReservations', 'sources', 'onboardingPending'));
    }

    /**
     * The essentials a tenant needs before guests can book: opening hours plus
     * either tables (restaurant) or staff + services (salon).
     */
    private function isSetupComplete(Location $location, Tenant $tenant): bool
    {
        if (! $location->openingHours()->exists()) {
            return false;
        }

        return $tenant->isSalon()
            ? StaffMember::where('tenant_id', $tenant->id)->exists()
                && Service::where('tenant_id', $tenant->id)->exists()
            : $location->tables()->exists();
    }

    public function stats(TenantContext $context): JsonResponse
    {
        $location = $context->location();
        if ($location === null) {
            return response()->json(['error' => 'no location'], 404);
        }

        $data = $this->computeStats($location->id, $location->timezone);
        $data['last_created_at'] = Reservation::where('location_id', $location->id)
            ->max('created_at');

        return response()->json($data);
    }

    private function computeStats(int $locationId, string $timezone): array
    {
        $todayLocal = CarbonImmutable::now($timezone)->startOfDay();
        $tomorrowLocal = $todayLocal->addDay();

        $base = Reservation::query()->where('location_id', $locationId);

        return [
            'today_count' => (clone $base)
                ->whereDate('reservation_date', $todayLocal->toDateString())
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->count(),
            'today_covers' => (clone $base)
                ->whereDate('reservation_date', $todayLocal->toDateString())
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->sum('party_size'),
            'tomorrow_count' => (clone $base)
                ->whereDate('reservation_date', $tomorrowLocal->toDateString())
                ->whereIn('status', ReservationStatus::activeStatuses())
                ->count(),
            'open_requests' => (clone $base)
                ->where('status', ReservationStatus::Requested->value)
                ->where('start_at', '>', now())
                ->count(),
            'waitlist_waiting' => WaitlistEntry::where('location_id', $locationId)
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
    }
}
