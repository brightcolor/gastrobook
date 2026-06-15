<?php

namespace App\Http\Controllers\Public;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Guest;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Services\GuestAuthService;
use App\Services\Payments\PaymentProviderManager;
use App\Services\RefundService;
use App\Services\ReservationAvailabilityService;
use App\Services\ReservationLifecycleService;
use App\Services\SalonAvailabilityService;
use App\Services\TableAssignmentService;
use App\Services\WaitlistService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends Controller
{
    public function __construct(
        private readonly ReservationAvailabilityService $availability,
        private readonly SalonAvailabilityService $salonAvailability,
        private readonly ReservationLifecycleService $lifecycle,
        private readonly WaitlistService $waitlist,
        private readonly TableAssignmentService $tableAssignment,
        private readonly RefundService $refunds,
        private readonly GuestAuthService $guestAuth,
    ) {}

    /**
     * Whether a booking with this email must be confirmed via email first.
     * True only when the setting is on and the guest hasn't verified before.
     */
    private function needsEmailConfirmation(Location $location, ?string $email): bool
    {
        if (! $email || ! $location->effectiveSettings()->require_email_confirmation) {
            return false;
        }

        $guest = Guest::withoutGlobalScopes()
            ->where('tenant_id', $location->tenant_id)
            ->whereRaw('lower(email) = ?', [strtolower(trim($email))])
            ->first();

        return $guest === null || $guest->email_verified_at === null;
    }

    /**
     * Short entry point: /book/{tenant}
     *  - exactly one bookable location → show its booking page directly
     *    (so the shop name only appears once in the URL)
     *  - several locations → a chooser; picking one appends its slug
     */
    public function landing(string $tenantSlug)
    {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'active')->firstOrFail();

        $locations = Location::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('online_booking_enabled', true)
            ->orderBy('name')
            ->get();

        abort_if($locations->isEmpty(), 404);

        if ($locations->count() === 1) {
            return $this->renderBooking(
                $tenant,
                $locations->first(),
                route('booking.store.landing', $tenant->slug),
            );
        }

        return view('public.locations', [
            'tenant' => $tenant,
            'locations' => $locations,
        ]);
    }

    public function show(string $tenantSlug, string $locationSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);

        return $this->renderBooking(
            $tenant,
            $location,
            route('booking.store', [$tenant->slug, $location->slug]),
        );
    }

    /** POST /book/{tenantSlug} — single-location shortcut (no location slug in URL). */
    public function storeLanding(Request $request, string $tenantSlug)
    {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'active')->firstOrFail();

        $location = Location::withoutGlobalScope('tenant')
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->where('online_booking_enabled', true)
            ->sole();

        return $this->store($request, $tenantSlug, $location->slug);
    }

    /** Public branding logo for a location (falls back to the tenant logo). */
    public function locationLogo(string $tenantSlug, string $locationSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);
        $path = $location->brand_logo_path ?: $tenant->brand_logo_path;

        return $this->streamLogo($path);
    }

    /** Public branding logo for a tenant. */
    public function tenantLogo(string $tenantSlug)
    {
        $tenant = Tenant::where('slug', $tenantSlug)->where('status', 'active')->firstOrFail();

        return $this->streamLogo($tenant->brand_logo_path);
    }

    private function streamLogo(?string $path)
    {
        abort_if($path === null || $path === '' || ! Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }

    private function renderBooking(Tenant $tenant, Location $location, string $storeUrl = '')
    {
        $upcomingEvents = Event::withoutGlobalScope('tenant')
            ->where('location_id', $location->id)
            ->where('is_public', true)
            ->where('status', 'published')
            ->where('starts_at', '>', now())
            ->count();

        $data = [
            'tenant' => $tenant,
            'location' => $location,
            'settings' => $location->effectiveSettings(),
            'upcomingEvents' => $upcomingEvents,
            'storeUrl' => $storeUrl ?: route('booking.store', [$tenant->slug, $location->slug]),
        ];

        if ($tenant->isSalon()) {
            $data['services'] = Service::where('location_id', $location->id)
                ->where('is_active', true)
                ->with('staff')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();
        }

        return view('public.booking', $data);
    }

    public function slots(Request $request, string $tenantSlug, string $locationSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);

        if ($tenant->isSalon()) {
            return $this->salonSlots($request, $location);
        }

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
            $partySize = (int) $validated['party_size'];
            $maxParty = $this->largestBookableParty($location);

            if ($maxParty !== null && $partySize > $maxParty) {
                // Gruppe ist generell zu groß – Warteliste/Alternativen helfen nicht
                $response['oversized'] = true;
                $response['max_party'] = $maxParty;
                $response['phone'] = $location->phone;
            } else {
                $desired = $localDate->setTime(19, 0);
                $response['alternatives'] = $this->availability->alternatives($location, $desired, $partySize);
                $response['next_slots'] = $this->availability->nextSlots($location, $localDate, $partySize);
                $response['party_size'] = $partySize;
                $response['waitlist_available'] = $location->effectiveSettings()->waitlist_enabled;
            }
        }

        return response()->json($response);
    }

    /**
     * Largest party that is bookable online at this location (smallest of the
     * online cap and the actual seating capacity). Null = effectively unlimited.
     */
    private function largestBookableParty(Location $location): ?int
    {
        $settings = $location->effectiveSettings();
        $capByCapacity = null;

        if (in_array($settings->capacity_mode, ['table', 'hybrid'], true)) {
            $maxTable = (int) $location->tables()
                ->where('is_active', true)->where('online_bookable', true)->max('max_capacity');
            $maxCombo = (int) $location->tableCombinations()
                ->where('is_active', true)->where('online_bookable', true)->max('max_capacity');
            $capByCapacity = max($maxTable, $maxCombo);
        }

        if (in_array($settings->capacity_mode, ['person', 'hybrid'], true) && $settings->max_covers_per_slot) {
            $byCovers = (int) $settings->max_covers_per_slot;
            $capByCapacity = $capByCapacity === null ? $byCovers : max($capByCapacity, $byCovers);
        }

        $values = array_filter([
            $settings->max_party_online ? (int) $settings->max_party_online : null,
            $capByCapacity ?: null,
        ], fn ($v) => $v !== null);

        return $values === [] ? null : min($values);
    }

    private function salonSlots(Request $request, Location $location): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
            'staff_member_id' => ['nullable', 'integer'],
        ]);

        $services = $this->resolveServices($location, $validated['service_ids']);
        if ($services->isEmpty()) {
            return response()->json(['date' => $validated['date'], 'slots' => [], 'staff_slots' => []]);
        }

        $localDate = CarbonImmutable::parse($validated['date'], $location->timezone)->startOfDay();
        $staffId = (int) ($validated['staff_member_id'] ?? 0);

        $slotsByStaff = $this->salonAvailability->slotsByStaffForServices($location, $services, $localDate);
        $slots = $slotsByStaff[$staffId] ?? $slotsByStaff[0] ?? [];
        $available = array_values(array_filter($slots, fn ($s) => $s['available']));

        // Provide per-staff availability so the frontend can show who is free
        $staffInfo = [];
        foreach ($slotsByStaff as $id => $staffSlots) {
            if ($id === 0) {
                continue;
            }
            $staffInfo[$id] = array_values(array_filter($staffSlots, fn ($s) => $s['available']));
        }

        return response()->json([
            'date' => $validated['date'],
            'slots' => array_map(fn ($s) => $s['time'], $available),
            'staff_slots' => $staffInfo,
        ]);
    }

    /**
     * Load active services for a location, preserving the requested order and
     * eager-loading staff for eligibility checks.
     *
     * @param  array<int, int|string>  $serviceIds
     * @return Collection<int, Service>
     */
    private function resolveServices(Location $location, array $serviceIds): Collection
    {
        $ids = collect($serviceIds)->map(fn ($id) => (int) $id)->unique()->values();

        $services = Service::where('location_id', $location->id)
            ->where('is_active', true)
            ->whereIn('id', $ids)
            ->with('staff')
            ->get()
            ->keyBy('id');

        // Keep the order the customer picked
        return $ids->map(fn ($id) => $services->get($id))->filter()->values();
    }

    /**
     * Read-only floor plan with table availability for a date/time/party size.
     * Opt-in per location; restaurant mode only. Exposes no guest data.
     */
    public function floorplan(Request $request, string $tenantSlug, string $locationSlug): JsonResponse
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);
        abort_if($tenant->isSalon(), 404);

        $settings = $location->effectiveSettings();
        abort_unless($settings->public_floorplan_enabled, 404);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        $partySize = (int) $validated['party_size'];
        $duration = $settings->durationFor($partySize);
        $buffer = (int) $settings->buffer_minutes;

        $startUtc = CarbonImmutable::parse($validated['date'].' '.$validated['time'], $location->timezone)->utc();
        $windowStart = $startUtc->subMinutes($buffer);
        $windowEnd = $startUtc->addMinutes($duration + $buffer);

        $busy = $this->tableAssignment->busyTableIds($location, $windowStart, $windowEnd, null);

        $blockedRooms = $location->blackoutPeriods()
            ->whereNotNull('room_id')
            ->whereNull('reduce_covers_to')
            ->where('starts_at', '<', $windowEnd)
            ->where('ends_at', '>', $windowStart)
            ->pluck('room_id')
            ->all();

        $rooms = $location->rooms()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->with(['tables' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
            ->get()
            ->map(fn ($room) => [
                'id' => $room->id,
                'name' => $room->name,
                'is_outdoor' => (bool) $room->is_outdoor,
                'tables' => $room->tables->map(function ($t) use ($busy, $blockedRooms, $partySize) {
                    $status = 'available';
                    if (! $t->online_bookable || in_array($t->room_id, $blockedRooms, true)) {
                        $status = 'unavailable';
                    } elseif (in_array($t->id, $busy, true)) {
                        $status = 'occupied';
                    } elseif ($partySize < $t->min_capacity || $partySize > $t->max_capacity) {
                        $status = 'unsuitable';
                    }

                    return [
                        'id' => $t->id,
                        'name' => $t->name,
                        'status' => $status,
                        'selectable' => $status === 'available',
                        'capacity' => $t->min_capacity.'–'.$t->max_capacity,
                        'pos_x' => (int) $t->pos_x,
                        'pos_y' => (int) $t->pos_y,
                        'width' => (int) ($t->width ?: 60),
                        'height' => (int) ($t->height ?: 60),
                        'rotation' => (int) $t->rotation,
                        'shape' => $t->shape ?: 'rect',
                    ];
                })->values(),
            ])
            ->filter(fn ($room) => $room['tables']->isNotEmpty())
            ->values();

        return response()->json(['rooms' => $rooms]);
    }

    public function store(Request $request, string $tenantSlug, string $locationSlug)
    {
        [$tenant, $location] = $this->resolve($tenantSlug, $locationSlug);

        if ($tenant->isSalon()) {
            return $this->storeSalon($request, $location);
        }

        $settings = $location->effectiveSettings();

        // Honeypot: bots fill the hidden "website" field
        if ($request->filled('website')) {
            abort(422);
        }

        $rules = [
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'time' => ['required', 'date_format:H:i'],
            'party_size' => ['required', 'integer', 'min:'.$settings->min_party_online, 'max:'.$settings->max_party_online],
            'name' => ['required', 'string', 'max:120'],
            'occasion' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
            'allergies' => ['nullable', 'string', 'max:500'],
            'privacy_accepted' => ['accepted'],
            'newsletter' => ['nullable', 'boolean'],
            'table_id' => ['nullable', 'integer'],
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

        // Optional: guest picked a specific table on the public floor plan.
        $tableIds = [];
        if (! empty($validated['table_id'])) {
            $party = (int) $validated['party_size'];
            $table = $location->tables()
                ->where('is_active', true)
                ->where('online_bookable', true)
                ->where('id', (int) $validated['table_id'])
                ->first();

            // Capacity check (mirrors RestaurantTable::fitsParty without extra seats)
            $fits = $table !== null
                && $party >= $table->min_capacity
                && $party <= $table->max_capacity;

            if (! $fits) {
                return back()
                    ->withErrors(['table_id' => __('Der gewählte Tisch ist für diese Personenzahl nicht verfügbar.')])
                    ->withInput();
            }
            $tableIds = [(int) $table->id];
        }

        $needsConfirm = $this->needsEmailConfirmation($location, $validated['email'] ?? null);

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
                'table_ids' => $tableIds,
                'email_confirmation_required' => $needsConfirm,
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

        if ($needsConfirm && $reservation->guest) {
            $this->guestAuth->sendVerification($reservation->guest, $reservation);
        }

        return redirect()->route('booking.confirmation', [
            'code' => $reservation->code,
            'token' => $reservation->manage_token,
        ])->with('email_confirmation_sent', $needsConfirm);
    }

    private function storeSalon(Request $request, Location $location): RedirectResponse
    {
        if ($request->filled('website')) {
            abort(422);
        }

        $validated = $request->validate([
            'service_ids' => ['required', 'array', 'min:1'],
            'service_ids.*' => ['integer'],
            'staff_member_id' => ['nullable', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc'],
            'phone' => ['nullable', 'string', 'max:40'],
            'note' => ['nullable', 'string', 'max:1000'],
            'privacy_accepted' => ['accepted'],
            'newsletter' => ['nullable', 'boolean'],
        ]);

        $services = $this->resolveServices($location, $validated['service_ids']);
        if ($services->isEmpty()) {
            return back()->withErrors(['service_ids' => __('Bitte mindestens eine Leistung wählen.')])->withInput();
        }

        $duration = $this->salonAvailability->combinedDuration($services);
        $startLocal = CarbonImmutable::parse($validated['date'].' '.$validated['time'], $location->timezone);
        $startUtc = $startLocal->utc();

        // Resolve staff member (explicit choice or gap-optimised auto-assign).
        // The member must offer *all* chosen services and be free for the total.
        $staffMemberId = (int) ($validated['staff_member_id'] ?? 0);
        if ($staffMemberId > 0) {
            $staff = StaffMember::where('location_id', $location->id)
                ->where('is_active', true)
                ->findOrFail($staffMemberId);
            if (! $this->salonAvailability->isStaffAvailableForServices($staff, $services, $startUtc, $location)) {
                return back()->withErrors(['time' => __('Dieser Mitarbeiter ist zu diesem Zeitpunkt nicht verfügbar.')])->withInput();
            }
        } else {
            $staff = $this->salonAvailability->firstAvailableStaffForServices($services, $startUtc, $location);
            if ($staff === null) {
                return back()->withErrors(['time' => __('Zu diesem Zeitpunkt ist kein Mitarbeiter verfügbar. Bitte einen anderen Termin wählen.')])->withInput();
            }
        }

        $needsConfirm = $this->needsEmailConfirmation($location, $validated['email']);

        try {
            $reservation = $this->lifecycle->create($location, [
                'party_size' => 1,
                'start_local' => $startLocal,
                'duration_minutes' => $duration,
                'source' => 'online',
                'service_id' => $services->first()->id, // primary service
                'staff_member_id' => $staff->id,
                'guest_name' => $validated['name'],
                'guest_email' => $validated['email'],
                'guest_phone' => $validated['phone'] ?? null,
                'guest_note' => $validated['note'] ?? null,
                'skip_availability_check' => true,
                'email_confirmation_required' => $needsConfirm,
                'consents' => [
                    'privacy' => true,
                    'newsletter' => (bool) ($validated['newsletter'] ?? false),
                ],
                'ip' => $request->ip(),
            ]);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors())->withInput();
        }

        // Record the full service composition (snapshot price/duration)
        $reservation->services()->sync(
            $services->values()->mapWithKeys(fn (Service $s, int $i) => [
                $s->id => [
                    'sort_order' => $i,
                    'duration_minutes' => $s->duration_minutes,
                    'price_minor' => $s->price_minor,
                ],
            ])->all()
        );

        if ($needsConfirm && $reservation->guest) {
            $this->guestAuth->sendVerification($reservation->guest, $reservation);
        }

        return redirect()->route('booking.confirmation', [
            'code' => $reservation->code,
            'token' => $reservation->manage_token,
        ])->with('email_confirmation_sent', $needsConfirm);
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

        $tenant = Tenant::find($reservation->tenant_id);
        $payEnabled = $tenant !== null
            && in_array($reservation->payment_status, ['required', 'pending'], true)
            && $reservation->payment_amount_minor > 0
            && $reservation->status->isActive()
            && app(PaymentProviderManager::class)->isConfigured($tenant);

        return view('public.manage', [
            'reservation' => $reservation,
            'location' => $location,
            'cancellable' => $cancellable,
            'deadline' => $deadline,
            'payEnabled' => $payEnabled,
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

        // Payment in flight: the Stripe webhook has not arrived yet.
        // Block cancellation until payment is confirmed or the deadline expires,
        // so we never end up with money received on a cancelled reservation.
        if ($reservation->payment_status === 'pending') {
            return back()->withErrors(['status' => __('Deine Zahlung wird gerade verarbeitet. Bitte warte kurz und versuche es erneut.')]);
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

        // Within the cancellation deadline → request a deposit refund per policy
        $refund = $this->refunds->requestForReservation($reservation->fresh(), 'guest_cancel');

        return view('public.cancelled', [
            'location' => $location,
            'refund' => $refund,
        ]);
    }

    public function rescheduleShow(string $code, string $token)
    {
        $reservation = $this->findByCodeAndToken($code, $token);
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $tenant = Tenant::find($reservation->tenant_id);
        $settings = $location->effectiveSettings();

        abort_unless($reservation->status->isActive(), 410);
        if (now()->gte($reservation->start_at->copy()->subMinutes($settings->modification_deadline_minutes))) {
            return view('public.reschedule', [
                'reservation' => $reservation, 'location' => $location, 'tenant' => $tenant,
                'tooLate' => true, 'isSalon' => $tenant?->isSalon() ?? false,
                'serviceIds' => [], 'staffId' => 0,
            ]);
        }

        return view('public.reschedule', [
            'reservation' => $reservation,
            'location' => $location,
            'tenant' => $tenant,
            'settings' => $settings,
            'tooLate' => false,
            'isSalon' => $tenant?->isSalon() ?? false,
            'serviceIds' => $reservation->services->pluck('id')->all(),
            'staffId' => (int) ($reservation->staff_member_id ?? 0),
        ]);
    }

    public function reschedule(Request $request, string $code, string $token)
    {
        $reservation = $this->findByCodeAndToken($code, $token);
        $location = $reservation->location()->withoutGlobalScope('tenant')->first();
        $settings = $location->effectiveSettings();

        abort_unless($reservation->status->isActive(), 410);
        if (now()->gte($reservation->start_at->copy()->subMinutes($settings->modification_deadline_minutes))) {
            return back()->withErrors(['time' => __('Die Umbuchungsfrist ist abgelaufen.')]);
        }

        $isSalon = $location->tenant()->first()?->isSalon() ?? false;

        $rules = [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ];
        if (! $isSalon) {
            $rules['party_size'] = [
                'required', 'integer',
                'min:'.$settings->min_party_online,
                'max:'.$settings->max_party_online,
            ];
        }

        $validated = $request->validate($rules);

        $newStartLocal = CarbonImmutable::parse($validated['date'].' '.$validated['time'], $reservation->timezone);
        $newPartySize = $isSalon ? null : (int) $validated['party_size'];

        try {
            $this->lifecycle->reschedule($reservation, $newStartLocal, $newPartySize, null, 'guest');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('booking.manage', [
            'code' => $reservation->code, 'token' => $reservation->manage_token,
        ])->with('success', __('Ihr Termin wurde umgebucht.'));
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
     *        <div id="swayy-widget"></div>
     */
    public function embedScript(string $tenantSlug, string $locationSlug)
    {
        [, $location] = $this->resolve($tenantSlug, $locationSlug);

        $bookingUrl = route('booking.show', [$tenantSlug, $locationSlug]);

        $js = <<<JS
        (function () {
            var container = document.getElementById('swayy-widget') || document.currentScript.parentNode;
            var iframe = document.createElement('iframe');
            iframe.src = {$this->jsString($bookingUrl)};
            iframe.title = {$this->jsString('Tisch reservieren – '.$location->name)};
            iframe.style.cssText = 'width:100%;min-height:760px;border:0;border-radius:12px;';
            iframe.loading = 'lazy';
            iframe.allow = 'payment';
            container.appendChild(iframe);
            window.addEventListener('message', function (e) {
                if (e.data && e.data.swayyHeight) iframe.style.height = e.data.swayyHeight + 'px';
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
