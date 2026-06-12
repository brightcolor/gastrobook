<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventBooking;
use App\Services\AuditLogger;
use App\Services\EventBookingService;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EventAdminController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EventBookingService $bookings,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index()
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $events = Event::where('location_id', $location->id)
            ->withCount(['bookings as confirmed_tickets' => fn ($q) => $q->whereIn('status', ['confirmed', 'checked_in'])])
            ->orderByDesc('starts_at')
            ->paginate(25);

        return view('admin.events.index', [
            'location' => $location,
            'events' => $events,
            'rooms' => $location->rooms()->orderBy('sort_order')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        if (! $this->limits->canAdd($location->tenant, 'max_events')) {
            return back()->withErrors(['title' => __('Event-Limit Ihres Tarifs erreicht.')]);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
            'capacity' => ['required', 'integer', 'min:1', 'max:5000'],
            'price' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'room_id' => ['nullable', 'integer'],
            'booking_deadline_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'cancellation_deadline_hours' => ['nullable', 'integer', 'min:0', 'max:720'],
            'is_public' => ['nullable', 'boolean'],
        ]);

        if ($validated['room_id'] ?? null) {
            abort_unless($location->rooms()->where('id', $validated['room_id'])->exists(), 422);
        }

        $tz = $location->timezone;
        $startLocal = CarbonImmutable::parse($validated['date'].' '.$validated['start_time'], $tz);
        $endLocal = CarbonImmutable::parse($validated['date'].' '.$validated['end_time'], $tz);
        if ($endLocal->lte($startLocal)) {
            $endLocal = $endLocal->addDay(); // past-midnight events
        }

        $slug = Str::slug($validated['title']);
        $base = $slug;
        $i = 1;
        while (Event::withoutGlobalScope('tenant')->withTrashed()
            ->where('location_id', $location->id)->where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        $event = Event::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'room_id' => $validated['room_id'] ?? null,
            'title' => $validated['title'],
            'slug' => $slug,
            'description' => $validated['description'] ?? null,
            'starts_at' => $startLocal->utc(),
            'ends_at' => $endLocal->utc(),
            'capacity' => (int) $validated['capacity'],
            'price_minor' => isset($validated['price']) && $validated['price'] !== null
                ? (int) round($validated['price'] * 100)
                : null,
            'currency' => $location->currency,
            'booking_deadline_at' => isset($validated['booking_deadline_hours'])
                ? $startLocal->subHours((int) $validated['booking_deadline_hours'])->utc()
                : null,
            'cancellation_deadline_at' => isset($validated['cancellation_deadline_hours'])
                ? $startLocal->subHours((int) $validated['cancellation_deadline_hours'])->utc()
                : null,
            'is_public' => $request->boolean('is_public', true),
            'status' => 'published',
        ]);

        $this->audit->log('event.created', $event, null, ['title' => $event->title]);

        return back()->with('success', __('Event ":title" angelegt.', ['title' => $event->title]));
    }

    public function show(Event $event)
    {
        $this->authorizeEvent($event);

        $bookings = $event->bookings()->orderBy('created_at')->get();

        return view('admin.events.show', [
            'event' => $event,
            'location' => $this->context->location(),
            'bookings' => $bookings,
            'confirmedTickets' => $bookings->whereIn('status', ['confirmed', 'checked_in'])->sum('ticket_count'),
        ]);
    }

    public function updateStatus(Request $request, Event $event)
    {
        $this->authorizeEvent($event);

        $validated = $request->validate(['status' => ['required', 'in:draft,published,cancelled,completed']]);
        $old = $event->status;
        $event->update(['status' => $validated['status']]);

        $this->audit->log('event.status_changed', $event, ['status' => $old], $validated);

        return back()->with('success', __('Eventstatus geändert.'));
    }

    public function checkIn(Request $request, EventBooking $booking)
    {
        abort_if($booking->tenant_id !== $this->context->tenantId(), 404);

        $this->bookings->checkIn($booking, $request->user());

        return back()->with('success', __(':name eingecheckt.', ['name' => $booking->guest_name]));
    }

    public function cancelBooking(Request $request, EventBooking $booking)
    {
        abort_if($booking->tenant_id !== $this->context->tenantId(), 404);

        $this->bookings->cancel($booking, 'restaurant', $request->user());

        return back()->with('success', __('Buchung storniert.'));
    }

    public function exportAttendees(Event $event): StreamedResponse
    {
        $this->authorizeEvent($event);
        $this->audit->log('event.attendees_exported', $event);

        $bookings = $event->bookings()->whereIn('status', ['confirmed', 'checked_in'])->orderBy('guest_name')->get();

        return response()->streamDownload(function () use ($bookings) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Buchungsnr.', 'Name', 'E-Mail', 'Telefon', 'Tickets', 'Status', 'Notiz'], ';');
            foreach ($bookings as $b) {
                fputcsv($out, [
                    $b->code, $b->guest_name, $b->guest_email, $b->guest_phone,
                    $b->ticket_count, $b->status, $b->note,
                ], ';');
            }
            fclose($out);
        }, 'teilnehmer_'.$event->slug.'.csv', ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function authorizeEvent(Event $event): void
    {
        abort_if($event->tenant_id !== $this->context->tenantId(), 404);
        abort_if($event->location_id !== $this->context->locationId(), 404);
    }
}
