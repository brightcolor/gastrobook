<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\Location;
use App\Models\OpeningHour;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Models\Tenant;
use App\Services\SalonAvailabilityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalonAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Location $location;

    private Service $service;

    private StaffMember $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['type' => TenantType::Salon]);
        $this->location = Location::factory()->create(['tenant_id' => $this->tenant->id, 'timezone' => 'Europe/Berlin']);

        // Mo-Fr 09:00-18:00
        foreach (range(0, 4) as $day) {
            OpeningHour::create([
                'tenant_id' => $this->tenant->id,
                'location_id' => $this->location->id,
                'weekday' => $day,
                'opens_at' => '09:00',
                'closes_at' => '18:00',
            ]);
        }

        $this->service = Service::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Haarschnitt',
            'duration_minutes' => 30,
            'price_minor' => 2500,
            'is_active' => true,
        ]);

        $this->staff = StaffMember::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Anna',
            'is_active' => true,
        ]);

        $this->staff->services()->attach($this->service);
    }

    public function test_slots_are_generated_within_opening_hours(): void
    {
        $service = app(SalonAvailabilityService::class);
        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();

        $slots = $service->slotsFor($this->location, $this->staff, $this->service, $date);

        $this->assertNotEmpty($slots);
        $this->assertEquals('09:00', $slots[0]['time']);
        // Last slot must end at or before 18:00, so last start ≤ 17:30
        $this->assertLessThanOrEqual('17:30', end($slots)['time']);
    }

    public function test_slot_is_blocked_when_staff_has_existing_reservation(): void
    {
        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();
        $startUtc = $date->setTime(10, 0)->utc();

        Reservation::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'staff_member_id' => $this->staff->id,
            'service_id' => $this->service->id,
            'party_size' => 1,
            'reservation_date' => $date->toDateString(),
            'start_at' => $startUtc,
            'end_at' => $startUtc->addMinutes(30),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Test Guest',
        ]);

        $service = app(SalonAvailabilityService::class);
        $slots = $service->slotsFor($this->location, $this->staff, $this->service, $date);

        $tenOClock = collect($slots)->firstWhere('time', '10:00');
        $this->assertNotNull($tenOClock);
        $this->assertFalse($tenOClock['available']);

        // Adjacent slot should still be free
        $tenThirty = collect($slots)->firstWhere('time', '10:30');
        $this->assertNotNull($tenThirty);
        $this->assertTrue($tenThirty['available']);
    }

    public function test_slots_by_staff_includes_any_key(): void
    {
        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();
        $service = app(SalonAvailabilityService::class);

        $result = $service->slotsByStaff($this->location, $this->service, $date);

        $this->assertArrayHasKey(0, $result);
        $this->assertArrayHasKey($this->staff->id, $result);
    }

    public function test_first_available_staff_skips_busy_member(): void
    {
        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();
        $startUtc = $date->setTime(9, 0)->utc();

        Reservation::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'staff_member_id' => $this->staff->id,
            'service_id' => $this->service->id,
            'party_size' => 1,
            'reservation_date' => $date->toDateString(),
            'start_at' => $startUtc,
            'end_at' => $startUtc->addMinutes(30),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Test Guest',
        ]);

        $service = app(SalonAvailabilityService::class);
        // Only one staff member, who is busy at 09:00
        $result = $service->firstAvailableStaff($this->service, $startUtc, $this->location);

        $this->assertNull($result);
    }

    public function test_working_hours_restrict_available_slots(): void
    {
        // Anna works Monday 13:00-17:00 only
        StaffWorkingHour::create([
            'tenant_id' => $this->tenant->id,
            'staff_member_id' => $this->staff->id,
            'weekday' => 0, // Monday
            'starts_at' => '13:00',
            'ends_at' => '17:00',
        ]);

        $service = app(SalonAvailabilityService::class);
        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();
        $slots = collect($service->slotsFor($this->location, $this->staff, $this->service, $date));

        // 09:00 is within opening hours but outside Anna's working hours
        $this->assertFalse($slots->firstWhere('time', '09:00')['available']);
        // 13:00 is inside her working hours
        $this->assertTrue($slots->firstWhere('time', '13:00')['available']);
        // 16:30 start + 30 min = 17:00 end, still fits
        $this->assertTrue($slots->firstWhere('time', '16:30')['available']);
        // 17:00 start would end 17:30 > working window
        $seventeen = $slots->firstWhere('time', '17:00');
        if ($seventeen !== null) {
            $this->assertFalse($seventeen['available']);
        }
    }

    public function test_absence_blocks_slots(): void
    {
        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();

        StaffAbsence::create([
            'tenant_id' => $this->tenant->id,
            'staff_member_id' => $this->staff->id,
            'starts_at' => $date->setTime(10, 0)->utc(),
            'ends_at' => $date->setTime(12, 0)->utc(),
            'reason' => 'Arzttermin',
        ]);

        $service = app(SalonAvailabilityService::class);
        $slots = collect($service->slotsFor($this->location, $this->staff, $this->service, $date));

        $this->assertFalse($slots->firstWhere('time', '10:00')['available']);
        $this->assertFalse($slots->firstWhere('time', '11:30')['available']);
        $this->assertTrue($slots->firstWhere('time', '09:00')['available']);
        $this->assertTrue($slots->firstWhere('time', '12:00')['available']);
    }

    public function test_buffer_enforces_gap_between_appointments(): void
    {
        // 15 min buffer
        $this->location->settings()->create([
            'tenant_id' => $this->tenant->id,
            'buffer_minutes' => 15,
        ]);

        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();
        $startUtc = $date->setTime(10, 0)->utc();

        Reservation::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'staff_member_id' => $this->staff->id,
            'service_id' => $this->service->id,
            'party_size' => 1,
            'reservation_date' => $date->toDateString(),
            'start_at' => $startUtc,
            'end_at' => $startUtc->addMinutes(30), // 10:00-10:30
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Test Guest',
        ]);

        $service = app(SalonAvailabilityService::class);
        $slots = collect($service->slotsFor($this->location, $this->staff, $this->service, $date));

        // 10:30 would start exactly when prior ends, but 15 min buffer blocks it
        $this->assertFalse($slots->firstWhere('time', '10:30')['available']);
        // 10:00 booked
        $this->assertFalse($slots->firstWhere('time', '10:00')['available']);
        // 11:00 is clear of the buffer (10:30 end + 15 min = 10:45 < 11:00)
        $this->assertTrue($slots->firstWhere('time', '11:00')['available']);
    }

    public function test_combined_duration_sums_services(): void
    {
        $second = Service::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Föhnen',
            'duration_minutes' => 20,
            'price_minor' => 1500,
            'is_active' => true,
        ]);

        $service = app(SalonAvailabilityService::class);
        $this->assertSame(50, $service->combinedDuration(collect([$this->service, $second])));
    }

    public function test_eligible_staff_requires_all_services(): void
    {
        $color = Service::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Färben',
            'duration_minutes' => 60,
            'price_minor' => 6000,
            'is_active' => true,
        ]);

        // Ben only does the haircut, not colouring
        $ben = StaffMember::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Ben',
            'is_active' => true,
        ]);
        $ben->services()->attach($this->service);

        // Anna does both
        $this->staff->services()->attach($color);

        $service = app(SalonAvailabilityService::class);
        $eligible = $service->eligibleStaff(
            Service::with('staff')->whereIn('id', [$this->service->id, $color->id])->get()
        );

        $this->assertCount(1, $eligible);
        $this->assertSame($this->staff->id, $eligible->first()->id);
    }

    public function test_multi_service_booking_creates_pivot_and_combined_duration(): void
    {
        $color = Service::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Färben',
            'duration_minutes' => 60,
            'price_minor' => 6000,
            'is_active' => true,
        ]);
        $this->staff->services()->attach($color);

        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin');

        $response = $this->post(route('booking.store', [$this->tenant->slug, $this->location->slug]), [
            'service_ids' => [$this->service->id, $color->id],
            'staff_member_id' => 0,
            'date' => $date->toDateString(),
            'time' => '10:00',
            'name' => 'Test Kunde',
            'email' => 'kunde@example.com',
            'privacy_accepted' => '1',
        ]);

        $response->assertRedirect();

        $reservation = Reservation::withoutGlobalScopes()->latest('id')->first();
        $this->assertNotNull($reservation);
        $this->assertSame(90, (int) $reservation->start_at->diffInMinutes($reservation->end_at)); // 30 + 60
        $this->assertSame($this->staff->id, $reservation->staff_member_id);
        $this->assertCount(2, $reservation->services);
    }

    public function test_gap_optimizer_prefers_adjacent_staff(): void
    {
        // Second staff member, both do the haircut
        $ben = StaffMember::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'name' => 'Ben',
            'is_active' => true,
            'sort_order' => 0, // would be picked first without optimization
        ]);
        $ben->services()->attach($this->service);
        $this->staff->update(['sort_order' => 1]);

        $date = CarbonImmutable::parse('next monday', 'Europe/Berlin')->startOfDay();

        // Anna already has 09:00-09:30; a 09:30 booking docks onto her
        Reservation::create([
            'tenant_id' => $this->tenant->id,
            'location_id' => $this->location->id,
            'staff_member_id' => $this->staff->id,
            'service_id' => $this->service->id,
            'party_size' => 1,
            'reservation_date' => $date->toDateString(),
            'start_at' => $date->setTime(9, 0)->utc(),
            'end_at' => $date->setTime(9, 30)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'Bestehend',
        ]);

        $this->location->settings()->create([
            'tenant_id' => $this->tenant->id,
            'gap_optimization_enabled' => true,
        ]);

        $service = app(SalonAvailabilityService::class);
        $startUtc = $date->setTime(9, 30)->utc();
        $chosen = $service->firstAvailableStaffForServices(
            Service::with('staff')->whereIn('id', [$this->service->id])->get(),
            $startUtc,
            $this->location->fresh()
        );

        // Anna (adjacent) preferred over Ben (empty), despite Ben's lower sort_order
        $this->assertSame($this->staff->id, $chosen->id);
    }

    public function test_tenant_type_helpers(): void
    {
        $this->assertTrue($this->tenant->isSalon());
        $this->assertFalse($this->tenant->isRestaurant());

        $restaurant = Tenant::factory()->create(['type' => TenantType::Restaurant]);
        $this->assertTrue($restaurant->isRestaurant());
        $this->assertFalse($restaurant->isSalon());
    }

    public function test_booking_page_shows_salon_ui(): void
    {
        $response = $this->get(route('booking.show', [$this->tenant->slug, $this->location->slug]));
        $response->assertOk();
        $response->assertSee('Leistungen wählen');
        $response->assertSee('Termin buchen');
        $response->assertSee($this->service->name);
    }
}
