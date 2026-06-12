<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventBooking;
use App\Models\Location;
use App\Models\Tenant;
use App\Services\EventBookingService;
use App\Services\Payments\PaymentProviderManager;
use Illuminate\Http\Request;

class PublicEventController extends Controller
{
    public function __construct(private readonly EventBookingService $bookings) {}

    public function index(string $tenantSlug, string $locationSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);

        $events = Event::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('location_id', $location->id)
            ->where('is_public', true)
            ->where('status', 'published')
            ->where('starts_at', '>', now())
            ->orderBy('starts_at')
            ->get();

        return view('public.events.index', compact('tenant', 'location', 'events'));
    }

    public function show(string $tenantSlug, string $locationSlug, string $eventSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);
        $event = $this->findEvent($tenant, $location, $eventSlug);

        return view('public.events.show', [
            'tenant' => $tenant,
            'location' => $location,
            'event' => $event,
            'remaining' => $event->remainingCapacity(),
            'bookable' => $event->starts_at->isFuture()
                && ($event->booking_deadline_at === null || now()->lt($event->booking_deadline_at))
                && $event->remainingCapacity() > 0,
        ]);
    }

    public function store(Request $request, string $tenantSlug, string $locationSlug, string $eventSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);
        $event = $this->findEvent($tenant, $location, $eventSlug);

        if ($request->filled('website')) {
            abort(422); // honeypot
        }

        $validated = $request->validate([
            'ticket_count' => ['required', 'integer', 'min:1', 'max:50'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
            'privacy_accepted' => ['accepted'],
            'newsletter' => ['nullable', 'boolean'],
        ]);

        $booking = $this->bookings->book($event, [
            'ticket_count' => (int) $validated['ticket_count'],
            'guest_name' => $validated['name'],
            'guest_email' => $validated['email'],
            'guest_phone' => $validated['phone'] ?? null,
            'note' => $validated['note'] ?? null,
            'consents' => array_filter([
                'privacy' => true,
                'newsletter' => (bool) ($validated['newsletter'] ?? false),
            ]),
            'ip' => $request->ip(),
        ]);

        return redirect()->route('events.manage', ['code' => $booking->code, 'token' => $booking->manage_token])
            ->with('just_booked', true);
    }

    public function manage(string $code, string $token)
    {
        $booking = $this->findBooking($code, $token);
        $event = $booking->event()->withoutGlobalScopes()->first();
        $location = $event?->location()->withoutGlobalScope('tenant')->first();

        $tenant = Tenant::find($booking->tenant_id);
        $payEnabled = $tenant !== null
            && in_array($booking->payment_status, ['required', 'pending'], true)
            && $booking->status === 'confirmed'
            && app(PaymentProviderManager::class)->isConfigured($tenant);

        return view('public.events.manage', [
            'booking' => $booking,
            'event' => $event,
            'location' => $location,
            'payEnabled' => $payEnabled,
            'cancellable' => $booking->status === 'confirmed'
                && ($event?->cancellation_deadline_at === null || now()->lt($event->cancellation_deadline_at)),
        ]);
    }

    public function cancel(string $code, string $token)
    {
        $booking = $this->findBooking($code, $token);
        $this->bookings->cancel($booking, 'guest');

        return redirect()->route('events.manage', ['code' => $code, 'token' => $token])
            ->with('success', __('Ihre Buchung wurde storniert.'));
    }

    /**
     * @return array{0: Tenant, 1: Location}
     */
    private function resolve(string $tenantSlug, string $locationSlug): array
    {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'active')->firstOrFail();

        $location = Location::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('slug', $locationSlug)
            ->where('is_active', true)
            ->firstOrFail();

        return [$tenant, $location];
    }

    private function findEvent(Tenant $tenant, Location $location, string $slug): Event
    {
        return Event::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('location_id', $location->id)
            ->where('slug', $slug)
            ->where('is_public', true)
            ->where('status', 'published')
            ->firstOrFail();
    }

    private function findBooking(string $code, string $token): EventBooking
    {
        $booking = EventBooking::withoutGlobalScopes()->where('code', $code)->firstOrFail();

        if (! hash_equals($booking->manage_token, $token)) {
            abort(404);
        }

        return $booking;
    }
}
