<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationLifecycleService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availability,
        private readonly ReservationLifecycleService $lifecycle,
        private readonly WaitlistService $waitlist,
    ) {}

    public function show(string $tenantSlug, string $locationSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);

        $upcomingEvents = Event::withoutGlobalScope('tenant')
            ->where('location_id', $location->id)
            ->where('is_public', true)
            ->where('status', 'published')
            ->where('starts_at', '>', now())
            ->count();

        return view('public.booking', [
            'tenant' => $tenant,
            'location' => $location,
            'settings' => $location->effectiveSettings(),
            'upcomingEvents' => $upcomingEvents,
        ]);
    }

    public function slots(Request $request, string $tenantSlug, string $locationSlug)
    {
        [, $location] = $this->resolve($tenantSlug, $locationSlug);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $localDate = CarbonImmutable::parse($validated['date'], $location->timezone)->startOfDay();
        $slots = $this->availability->slotsFor($location, $localDate, (int) $validated['party_size']);

        $available = array_values(array_filter($slots, fn ($s) => $s['available']));

        $response = [
            'date' => $validated['date'],
            'slots' => array_map(fn ($s) => $s['time'], $available),
        ];

        if ($available === []) {
            $desired = $localDate->setTime(19, 0);
            $response['alternatives'] = $this->availability->alternatives($location, $desired, (int) $validated['party_size']);
            $response['waitlist_available'] = $location->effectiveSettings()->waitlist_enabled;
        }

        return response()->json($response);
    }

    public function store(Request $request, string $tenantSlug, string $locationSlug)
    {
        [, $location] = $this->resolve($tenantSlug, $locationSlug);
        $settings = $location->effectiveSettings();

        // Honeypot: bots fill the hidden "website" field
        if ($request->filled('website')) {
            abort(422);
        }

        $rules = [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:'.$settings->min_party_online, 'max:'.$settings->max_party_online],
            'name' => ['required', 'string', 'max:120'],
            'occasion' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'privacy_accepted' => ['accepted'],
            'newsletter' => ['nullable', 'boolean'],
        ];
        foreach (['email' => 'email:rfc', 'phone' => 'string|max:40'] as $field => $rule) {
            $fieldRule = $settings->fieldRule($field);
            if ($fieldRule === 'required') {
                $rules[$field] = array_merge(['required'], explode('|', $rule));
            } elseif ($fieldRule === 'optional') {
                $rules[$field] = array_merge(['nullable'], explode('|', $rule));
            }
        }

        $validated = $request->validate($rules);

        $startLocal = CarbonImmutable::parse($validated['date'].' '.$validated['time'], $location->timezone);

        try {
            $reservation = $this->lifecycle->create($location, [
                'party_size' => (int) $validated['party_size'],
                'start_local' => $startLocal,
                'source' => 'online',
                'guest_name' => $validated['name'],
                'guest_email' => $validated['email'] ?? null,
                'guest_phone' => $validated['phone'] ?? null,
                'guest_note' => $validated['note'] ?? null,
                'allergy_note' => $validated['allergies'] ?? null,
                'occasion' => $validated['occasion'] ?? null,
                'consents' => array_filter([
                    'privacy' => true,
                    'newsletter' => (bool) ($validated['newsletter'] ?? false),
                ]),
                'ip' => $request->ip(),
            ]);
        } catch (ValidationException $e) {
            $desired = $startLocal;
            $alternatives = $this->availability->alternatives($location, $desired, (int) $validated['party_size']);

            return back()
                ->withErrors($e->errors())
                ->withInput()
                ->with('alternatives', $alternatives);
        }

        return redirect()->route('booking.confirmation', [
            'code' => $reservation->code,
            'token' => $reservation->manage_token,
        ]);
    }

    public function confirmation(string $code, string $token)
    {
        $reservation = $this->findByCodeAndToken($code, $token);

        return view('public.confirmation', [
            'reservation' => $reservation,
            'location' => $reservation->location()->withoutGlobalScope('tenant')->first(),
        ]);
    }

    public function manage(string $code, string $token)
    {
        $reservation = $this->findByCodeAndToken($code, $token);
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $settings = $location->effectiveSettings();

        $deadline = $reservation->start_at->copy()->subMinutes($settings->cancellation_deadline_minutes);
        $cancellable = $reservation->status->isActive() && now()->lt($deadline);

        return view('public.manage', [
            'reservation' => $reservation,
            'location' => $location,
            'cancellable' => $cancellable,
            'deadline' => $deadline,
        ]);
    }

    public function cancel(Request $request, string $code, string $token)
    {
        $reservation = $this->findByCodeAndToken($code, $token);
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $settings = $location->effectiveSettings();

        if (! $reservation->status->isActive()) {
            return back()->withErrors(['status' => __('Diese Reservierung kann nicht mehr storniert werden.')]);
        }

        if (now()->gte($reservation->start_at->copy()->subMinutes($settings->cancellation_deadline_minutes))) {
            return back()->withErrors(['status' => __('Die Stornierungsfrist ist abgelaufen. Bitte kontaktieren Sie uns telefonisch.')]);
        }

        $this->lifecycle->transition(
            $reservation,
            ReservationStatus::CancelledByGuest,
            null,
            'guest',
            $request->input('reason')
        );

        return view('public.cancelled', ['location' => $location]);
    }

    public function joinWaitlist(Request $request, string $tenantSlug, string $locationSlug)
    {
        [, $location] = $this->resolve($tenantSlug, $locationSlug);

        if ($request->filled('website')) {
            abort(422);
        }

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['nullable', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'privacy_accepted' => ['accepted'],
        ]);

        $this->waitlist->createEntry($location, [
            'guest_name' => $validated['name'],
            'guest_email' => $validated['email'],
            'guest_phone' => $validated['phone'] ?? null,
            'party_size' => (int) $validated['party_size'],
            'desired_date' => $validated['date'],
            'desired_time' => $validated['time'] ?? null,
            'source' => 'online',
        ]);

        return view('public.waitlist-joined', ['location' => $location]);
    }

    /**
     * Embeddable JS snippet: injects the booking page as an iframe.
     * Usage: <script src="https://app.example.com/embed/{tenant}/{location}.js" defer></script>
     *        <div id="gastrobook-widget"></div>
     */
    public function embedScript(string $tenantSlug, string $locationSlug)
    {
        [, $location] = $this->resolve($tenantSlug, $locationSlug);

        $bookingUrl = route('booking.show', [$tenantSlug, $locationSlug]);

        $js = <<<JS
        (function () {
            var container = document.getElementById('gastrobook-widget') || document.currentScript.parentNode;
            var iframe = document.createElement('iframe');
            iframe.src = {$this->jsString($bookingUrl)};
            iframe.title = {$this->jsString('Tisch reservieren – '.$location->name)};
            iframe.style.cssText = 'width:100%;min-height:760px;border:0;border-radius:12px;';
            iframe.loading = 'lazy';
            iframe.allow = 'payment';
            container.appendChild(iframe);
            window.addEventListener('message', function (e) {
                if (e.data && e.data.gastrobookHeight) iframe.style.height = e.data.gastrobookHeight + 'px';
            });
        })();
        JS;

        return response($js, 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
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
            ->where('online_booking_enabled', true)
            ->firstOrFail();

        return [$tenant, $location];
    }

    private function jsString(string $value): string
    {
        return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    private function findByCodeAndToken(string $code, string $token): Reservation
    {
        $reservation = Reservation::withoutGlobalScope('tenant')
            ->where('code', $code)
            ->firstOrFail();

        if (! hash_equals($reservation->manage_token, $token)) {
            abort(404);
        }

        return $reservation;
    }
}
