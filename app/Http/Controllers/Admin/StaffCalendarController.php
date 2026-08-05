<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Day plan for a salon: one column per staff member, appointments as blocks.
 * Read-only on purpose – booking, moving and cancelling stay in the existing
 * screens, this is the overview that was missing.
 */
class StaffCalendarController extends Controller
{
    /** Pixels per minute – 15 minutes are visible without squinting. */
    private const SCALE = 1.1;

    private const FALLBACK_START = 8;

    private const FALLBACK_END = 20;

    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_unless($this->context->tenant()->isSalon(), 403);

        $tz = $location->timezone;
        $date = $this->requestedDate($request, $tz);

        $members = StaffMember::where('location_id', $location->id)
            ->where('is_active', true)
            ->with('workingHours')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $dayStartUtc = $date->startOfDay()->utc();
        $dayEndUtc = $date->endOfDay()->utc();

        $reservations = Reservation::where('location_id', $location->id)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where('start_at', '<', $dayEndUtc)
            ->where('end_at', '>', $dayStartUtc)
            ->with(['guest:id,is_vip', 'services'])
            ->orderBy('start_at')
            ->get();

        $absences = StaffAbsence::whereIn('staff_member_id', $members->pluck('id'))
            ->where('starts_at', '<', $dayEndUtc)
            ->where('ends_at', '>', $dayStartUtc)
            ->get();

        // Fallback for staff without their own roster: the location's hours.
        $weekday = $date->dayOfWeekIso - 1; // 0 = Monday
        $locationRanges = $location->openingHours()->where('weekday', $weekday)->get()
            ->map(fn ($h) => ['from' => $h->opens_at, 'until' => $h->closes_at]);

        $windows = [];
        foreach ($members as $member) {
            $windows[$member->id] = $this->windowsFor($member, $locationRanges, $date, $weekday);
        }

        [$from, $to] = $this->bounds($windows, $reservations, $date);

        $columns = $members->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'color' => $member->color ?: '#0f766e',
            'windows' => array_map(fn ($w) => [
                'top' => $this->offset($w['opens'], $from),
                'height' => $this->length($w['opens'], $w['closes']),
            ], $windows[$member->id]),
            'absences' => $absences->where('staff_member_id', $member->id)->map(fn ($a) => [
                'top' => $this->offset(CarbonImmutable::parse($a->starts_at)->setTimezone($from->getTimezone()), $from),
                'height' => $this->length(
                    CarbonImmutable::parse($a->starts_at)->setTimezone($from->getTimezone()),
                    CarbonImmutable::parse($a->ends_at)->setTimezone($from->getTimezone())
                ),
                'reason' => $a->reason,
            ])->values()->all(),
            'appointments' => $this->appointmentsFor($reservations, $member->id, $from),
        ])->all();

        // Appointments nobody is assigned to would silently disappear otherwise.
        $unassigned = $this->appointmentsFor($reservations, null, $from);

        return view('admin.staff.calendar', [
            'location' => $location,
            'date' => $date,
            'columns' => $columns,
            'unassigned' => $unassigned,
            'hours' => $this->hourMarks($from, $to),
            'height' => $this->length($from, $to),
            'prev' => $date->subDay()->toDateString(),
            'next' => $date->addDay()->toDateString(),
            'today' => CarbonImmutable::now($tz)->toDateString(),
        ]);
    }

    private function requestedDate(Request $request, string $tz): CarbonImmutable
    {
        $input = (string) $request->query('date', '');
        try {
            $date = $input !== ''
                ? CarbonImmutable::parse($input, $tz)
                : CarbonImmutable::now($tz);
        } catch (\Throwable) {
            $date = CarbonImmutable::now($tz);
        }

        return $date->startOfDay();
    }

    /**
     * Working windows of one staff member on that day. No working hours at all
     * means the location's opening hours apply – the same fallback the booking
     * logic uses.
     *
     * @param  Collection<int, array{from: string, until: string}>  $locationRanges
     * @return array<int, array{opens: CarbonImmutable, closes: CarbonImmutable}>
     */
    private function windowsFor(StaffMember $member, Collection $locationRanges, CarbonImmutable $date, int $weekday): array
    {
        $rows = $member->workingHours;

        $ranges = $rows->isEmpty()
            ? $locationRanges
            : $rows->where('weekday', $weekday)
                ->map(fn ($h) => ['from' => $h->starts_at, 'until' => $h->ends_at]);

        return $ranges->map(function ($range) use ($date) {
            $opens = CarbonImmutable::parse($date->toDateString().' '.$range['from'], $date->getTimezone());
            $closes = CarbonImmutable::parse($date->toDateString().' '.$range['until'], $date->getTimezone());

            return ['opens' => $opens, 'closes' => $closes->lte($opens) ? $closes->addDay() : $closes];
        })->values()->all();
    }

    /**
     * Visible time range: everything that is worked or booked that day, with a
     * sane fallback for an empty day.
     *
     * @param  array<int, array<int, array{opens: CarbonImmutable, closes: CarbonImmutable}>>  $windows
     * @param  Collection<int, Reservation>  $reservations
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function bounds(array $windows, Collection $reservations, CarbonImmutable $date): array
    {
        $from = null;
        $to = null;

        foreach ($windows as $memberWindows) {
            foreach ($memberWindows as $window) {
                $from = $from === null || $window['opens']->lt($from) ? $window['opens'] : $from;
                $to = $to === null || $window['closes']->gt($to) ? $window['closes'] : $to;
            }
        }

        foreach ($reservations as $reservation) {
            $start = CarbonImmutable::parse($reservation->start_at)->setTimezone($date->getTimezone());
            $end = CarbonImmutable::parse($reservation->end_at)->setTimezone($date->getTimezone());
            $from = $from === null || $start->lt($from) ? $start : $from;
            $to = $to === null || $end->gt($to) ? $end : $to;
        }

        $from = ($from ?? $date->setTime(self::FALLBACK_START, 0))->startOfHour();
        $to = ($to ?? $date->setTime(self::FALLBACK_END, 0));
        if ($to->minute > 0 || $to->second > 0) {
            $to = $to->startOfHour()->addHour();
        }
        if ($to->lte($from)) {
            $to = $from->addHours(4);
        }

        return [$from, $to];
    }

    /**
     * @param  Collection<int, Reservation>  $reservations
     * @return array<int, array<string, mixed>>
     */
    private function appointmentsFor(Collection $reservations, ?int $staffId, CarbonImmutable $from): array
    {
        return $reservations
            ->filter(fn ($r) => $r->staff_member_id === $staffId)
            ->map(function ($r) use ($from) {
                $start = CarbonImmutable::parse($r->start_at)->setTimezone($from->getTimezone());
                $end = CarbonImmutable::parse($r->end_at)->setTimezone($from->getTimezone());

                return [
                    'id' => $r->id,
                    'top' => $this->offset($start, $from),
                    'height' => max(22.0, $this->length($start, $end)),
                    'time' => $start->format('H:i').'–'.$end->format('H:i'),
                    'name' => $r->guest_name_snapshot,
                    'party_size' => $r->party_size,
                    'status' => $r->status->value,
                    'is_regular' => (bool) $r->guest?->is_vip,
                    'services' => $r->services->pluck('name')->implode(', '),
                ];
            })->values()->all();
    }

    /**
     * @return array<int, array{label: string, top: float}>
     */
    private function hourMarks(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $marks = [];
        for ($cursor = $from; $cursor->lte($to); $cursor = $cursor->addHour()) {
            $marks[] = ['label' => $cursor->format('H:i'), 'top' => $this->offset($cursor, $from)];
        }

        return $marks;
    }

    private function offset(CarbonImmutable $moment, CarbonImmutable $from): float
    {
        return round(max(0, $from->diffInMinutes($moment, false)) * self::SCALE, 1);
    }

    private function length(CarbonImmutable $start, CarbonImmutable $end): float
    {
        return round(max(0, $start->diffInMinutes($end, false)) * self::SCALE, 1);
    }
}
