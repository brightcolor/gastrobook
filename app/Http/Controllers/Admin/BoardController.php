<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\Reservation;
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

        $with = ['tables:restaurant_tables.id,name', 'staffMember:id,name', 'services:id,name'];

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
        ];
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
                ['label' => 'Fertig', 'status' => ReservationStatus::Completed->value, 'style' => 'primary'],
            ],
            default => [],
        };
    }
}
