<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\TableBlock;
use App\Models\WaitlistEntry;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BoardController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index()
    {
        $location = $this->context->location();
        if ($location === null) {
            return view('admin.no-location');
        }

        return view('admin.board', [
            'location' => $location,
            'tenant' => $this->context->tenant(),
            'sse' => (bool) config('swayy.board.sse', true),
        ]);
    }

    /**
     * Live data for the operations board (polled by the front-end / SSE fallback).
     */
    public function data(): JsonResponse
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        return response()->json($this->payload($location));
    }

    /**
     * Server-Sent Events stream: pushes the board payload whenever it changes
     * (server-side poll, change-detected). One-way, no extra infrastructure.
     */
    public function stream(Request $request): StreamedResponse
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        // Release the session lock so other requests for this user aren't blocked
        $request->session()->save();

        $response = new StreamedResponse(function () use ($location) {
            @set_time_limit(0);
            $lastHash = null;
            $startedAt = time();

            while (! connection_aborted() && (time() - $startedAt) < 280) {
                $payload = $this->payload($location);
                $json = (string) json_encode($payload);
                $hash = md5($json);

                if ($hash !== $lastHash) {
                    echo 'data: '.$json."\n\n";
                    $lastHash = $hash;
                } else {
                    echo ": ping\n\n"; // heartbeat keeps proxies from closing the connection
                }

                if (ob_get_level() > 0) {
                    @ob_flush();
                }
                flush();
                sleep(4);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('X-Accel-Buffering', 'no'); // disable nginx buffering
        $response->headers->set('Connection', 'keep-alive');

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Location $location): array
    {
        $tz = $location->timezone;
        $nowLocal = CarbonImmutable::now($tz);
        $today = $nowLocal->toDateString();
        $isSalon = $this->context->tenant()->isSalon();

        $with = ['tables:restaurant_tables.id,name', 'staffMember:id,name', 'services:id,name', 'guest:id,is_vip,visit_count'];

        $base = Reservation::query()->where('location_id', $location->id);

        // Today's active reservations → timeline
        $timeline = (clone $base)
            ->whereDate('reservation_date', $today)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->with($with)
            ->orderBy('start_at')
            ->get()
            ->map(fn (Reservation $r) => $this->present($r, $nowLocal));

        // New / needs-attention bookings (requests + recently created), upcoming
        $new = (clone $base)
            ->where(function ($q) {
                $q->whereIn('status', [
                    ReservationStatus::Requested->value,
                    ReservationStatus::PendingConfirmation->value,
                    ReservationStatus::PaymentPending->value,
                ])->orWhere('created_at', '>=', now()->subHours(12));
            })
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where('start_at', '>', now()->subHour())
            ->with($with)
            ->orderByDesc('created_at')
            ->limit(40)
            ->get()
            ->map(fn (Reservation $r) => $this->present($r, $nowLocal));

        $kpis = [
            'today' => $timeline->count(),
            'covers' => (int) $timeline->sum('party'),
            'seated' => $timeline->where('status', ReservationStatus::Seated->value)->count(),
            'open_requests' => $timeline->where('needs_action', true)->count()
                + $new->where('needs_action', true)->count(),
            'arrivals_soon' => $timeline->filter(fn ($r) => $r['status'] === ReservationStatus::Confirmed->value
                && $r['minutes_to_start'] !== null && $r['minutes_to_start'] >= 0 && $r['minutes_to_start'] <= 60)->count(),
            'waitlist' => WaitlistEntry::where('location_id', $location->id)
                ->whereIn('status', ['waiting', 'offered'])
                ->whereDate('desired_date', '>=', $today)
                ->count(),
        ];

        return [
            'now' => $nowLocal->format('H:i'),
            'is_salon' => $isSalon,
            'kpis' => $kpis,
            'new' => $new->values()->all(),
            'timeline' => $timeline->values()->all(),
            'floorplan' => $isSalon ? null : $this->floorplan($location, $nowLocal),
            'can_walkin' => ! $isSalon
                && (bool) $location->effectiveSettings()->walkins_enabled
                && (bool) auth()->user()?->canInTenant('walkins.create', $this->context->tenant(), $location),
            'walkin_url' => route('admin.walkins.store'),
            'create_url' => route('admin.reservations.create'),
        ];
    }

    /**
     * One reservation as shown in a table's detail panel.
     *
     * @return array<string, mixed>
     */
    private function presentTableReservation(Reservation $r, CarbonImmutable $nowLocal): array
    {
        $status = $r->status->value;
        $start = $r->localStart();
        $seatedSince = $r->seated_at?->copy()->setTimezone($nowLocal->timezone)->format('H:i');

        return [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->guest_name_snapshot,
            'party' => $r->party_size,
            'from' => $start->format('H:i'),
            'to' => $r->localEnd()->format('H:i'),
            'status' => $status,
            'status_label' => __('reservations.status.'.$status),
            'source' => $r->source,
            'phone' => $r->guest_phone_snapshot,
            'note' => $r->guest_note,
            'allergy' => $r->allergy_note,
            'risk' => (int) $r->no_show_risk,
            'regular' => $r->guest?->isRegular() ?? false,
            'seated_since' => in_array($status, [
                ReservationStatus::Seated->value,
                ReservationStatus::PartiallyArrived->value,
            ], true) ? ($seatedSince ?? $start->format('H:i')) : null,
            'is_current' => $r->start_at->lte($nowLocal->utc()) && $r->end_at->gt($nowLocal->utc()),
            'actions' => $this->actionsFor($r->status),
        ];
    }

    /**
     * Live floor plan: rooms with their tables and current status, mirroring the
     * geometry configured in the admin floor-plan editor. Returns null when no
     * rooms/tables exist (then the board hides the plan view).
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function floorplan(Location $location, CarbonImmutable $nowLocal): ?array
    {
        $rooms = $location->rooms()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get();

        if ($rooms->isEmpty() || $rooms->every(fn ($room) => $room->tables->isEmpty())) {
            return null;
        }

        $atUtc = $nowLocal->utc();
        $soonUtc = $atUtc->addMinutes(45);

        $reservations = Reservation::query()
            ->where('location_id', $location->id)
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->whereDate('reservation_date', $nowLocal->toDateString())
            ->with(['tables:restaurant_tables.id', 'guest:id,is_vip,visit_count'])
            ->get();

        $tableIds = $rooms->pluck('tables')->flatten()->pluck('id')->all();
        $blockedIds = TableBlock::query()
            ->whereIn('restaurant_table_id', $tableIds)
            ->where('starts_at', '<=', $atUtc)
            ->where('ends_at', '>', $atUtc)
            ->pluck('restaurant_table_id')
            ->all();

        return $rooms->map(fn ($room) => [
            'id' => $room->id,
            'name' => $room->name,
            'is_outdoor' => (bool) $room->is_outdoor,
            'plan_width' => (int) ($room->plan_width ?: 1000),
            'plan_height' => (int) ($room->plan_height ?: 700),
            'tables' => $room->tables->map(function ($t) use ($reservations, $atUtc, $soonUtc, $blockedIds, $nowLocal) {
                $current = $reservations->first(fn ($r) => $r->tables->contains('id', $t->id)
                    && $r->start_at->lte($atUtc) && $r->end_at->gt($atUtc));
                $upcoming = $reservations->first(fn ($r) => $r->tables->contains('id', $t->id)
                    && $r->start_at->gt($atUtc) && $r->start_at->lte($soonUtc));

                $status = 'free';
                if (in_array($t->id, $blockedIds, true)) {
                    $status = 'blocked';
                } elseif ($current !== null) {
                    $status = $current->status === ReservationStatus::Seated ? 'occupied' : 'awaiting';
                    if ($current->status === ReservationStatus::Confirmed && $current->no_show_risk >= 50) {
                        $status = 'no_show_risk';
                    }
                } elseif ($upcoming !== null) {
                    $status = 'soon';
                }

                $info = $current ?? $upcoming;

                // Full schedule of this table for today (for the detail panel).
                $schedule = $reservations
                    ->filter(fn ($r) => $r->tables->contains('id', $t->id))
                    ->sortBy('start_at')
                    ->map(fn ($r) => $this->presentTableReservation($r, $nowLocal))
                    ->values()
                    ->all();

                return [
                    'id' => $t->id,
                    'name' => $t->name,
                    'status' => $status,
                    'pos_x' => (int) $t->pos_x,
                    'pos_y' => (int) $t->pos_y,
                    'width' => (int) ($t->width ?: 64),
                    'height' => (int) ($t->height ?: 64),
                    'shape' => $t->shape ?: 'rect',
                    'rotation' => (int) $t->rotation,
                    'capacity' => $t->min_capacity.'–'.$t->max_capacity,
                    'min_capacity' => (int) $t->min_capacity,
                    'max_capacity' => (int) $t->max_capacity,
                    'guest' => $info?->guest_name_snapshot,
                    'party' => $info?->party_size,
                    'time' => $current
                        ? 'bis '.$current->localEnd()->format('H:i')
                        : ($upcoming ? 'ab '.$upcoming->localStart()->format('H:i') : null),
                    'current_id' => $current?->id,
                    'reservations' => $schedule,
                ];
            })->values()->all(),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Reservation $r, CarbonImmutable $nowLocal): array
    {
        $start = $r->localStart();
        $minutesToStart = (int) round($nowLocal->diffInMinutes($start, false));
        $status = $r->status->value;

        return [
            'id' => $r->id,
            'code' => $r->code,
            'name' => $r->guest_name_snapshot,
            'party' => $r->party_size,
            'time' => $start->format('H:i'),
            'until' => $r->localEnd()->format('H:i'),
            'date' => $r->reservation_date->format('d.m.'),
            'is_today' => $r->reservation_date->toDateString() === $nowLocal->toDateString(),
            'status' => $status,
            'status_label' => __('reservations.status.'.$status),
            'source' => $r->source,
            'tables' => $r->tables->pluck('name')->values(),
            'staff' => $r->staffMember?->name,
            'services' => $r->services->pluck('name')->values(),
            'note' => $r->guest_note,
            'allergy' => $r->allergy_note,
            'risk' => (int) $r->no_show_risk,
            'regular' => $r->guest?->isRegular() ?? false,
            'created_ts' => $r->created_at?->getTimestamp(),
            'minutes_to_start' => $minutesToStart,
            'overdue' => $status === ReservationStatus::Confirmed->value && $minutesToStart < -5,
            'needs_action' => in_array($status, [
                ReservationStatus::Requested->value,
                ReservationStatus::PendingConfirmation->value,
                ReservationStatus::PaymentPending->value,
            ], true),
            'actions' => $this->actionsFor($r->status),
        ];
    }

    /**
     * Board quick-actions for a status (status transitions reuse the existing
     * reservations.transition endpoint with its permission checks).
     *
     * @return array<int, array{label: string, status: string, style: string}>
     */
    private function actionsFor(ReservationStatus $status): array
    {
        return match ($status) {
            ReservationStatus::Requested,
            ReservationStatus::PendingConfirmation,
            ReservationStatus::PaymentPending => [
                ['label' => 'Bestätigen', 'status' => ReservationStatus::Confirmed->value, 'style' => 'primary'],
                ['label' => 'Ablehnen', 'status' => ReservationStatus::Rejected->value, 'style' => 'danger'],
            ],
            ReservationStatus::Confirmed => [
                ['label' => 'Eingetroffen', 'status' => ReservationStatus::Seated->value, 'style' => 'primary'],
                ['label' => 'No-Show', 'status' => ReservationStatus::NoShow->value, 'style' => 'warn'],
                ['label' => 'Storno', 'status' => ReservationStatus::CancelledByRestaurant->value, 'style' => 'danger'],
            ],
            ReservationStatus::Seated,
            ReservationStatus::PartiallyArrived => [
                ['label' => 'Auschecken', 'status' => ReservationStatus::Completed->value, 'style' => 'primary'],
            ],
            default => [],
        };
    }
}
