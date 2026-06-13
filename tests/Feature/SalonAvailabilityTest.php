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
        $response->assertSee('Leistung wählen');
        $response->assertSee('Termin buchen');
        $response->assertSee($this->service->name);
    }
}
