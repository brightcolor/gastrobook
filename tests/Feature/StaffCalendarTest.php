<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Reservation;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Day plan for the salon: one column per staff member. Until now the only way
 * to see who has what coming up was the flat appointment list.
 */
class StaffCalendarTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /** @return array{0: array<string, mixed>, 1: User, 2: StaffMember} */
    private function salon(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Anna Bergmann',
            'is_active' => true,
        ]);

        return [$setup, $admin, $staff];
    }

    private function appointment(array $setup, ?int $staffId, CarbonImmutable $startLocal, string $guest): Reservation
    {
        return Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'staff_member_id' => $staffId,
            'reservation_date' => $startLocal->toDateString(),
            'start_at' => $startLocal->utc(),
            'end_at' => $startLocal->addMinutes(60)->utc(),
            'guest_name_snapshot' => $guest,
        ]);
    }

    public function test_the_day_plan_shows_the_staff_column_and_the_appointment(): void
    {
        [$setup, $admin, $staff] = $this->salon();
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->setTime(14, 0);
        $this->appointment($setup, $staff->id, $day, 'Frau Kessler');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->toDateString())
            ->assertOk()
            ->assertSee('Anna Bergmann')
            ->assertSee('Frau Kessler')
            ->assertSee('14:00–15:00');
    }

    public function test_another_days_appointment_is_not_shown(): void
    {
        [$setup, $admin, $staff] = $this->salon();
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->setTime(14, 0);
        $this->appointment($setup, $staff->id, $day, 'Frau Kessler');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->addDays(3)->toDateString())
            ->assertOk()
            ->assertDontSee('Frau Kessler');
    }

    public function test_appointments_without_a_staff_member_are_listed_separately(): void
    {
        [$setup, $admin] = $this->salon();
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->setTime(16, 0);
        $this->appointment($setup, null, $day, 'Herr Ohnezuordnung');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->toDateString())
            ->assertOk()
            ->assertSee('Ohne Mitarbeiter')
            ->assertSee('Herr Ohnezuordnung');
    }

    public function test_an_absence_is_visible_in_the_column(): void
    {
        [$setup, $admin, $staff] = $this->salon();
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->setTime(13, 0);

        StaffAbsence::create([
            'tenant_id' => $setup['tenant']->id,
            'staff_member_id' => $staff->id,
            'starts_at' => $day->utc(),
            'ends_at' => $day->addHours(4)->utc(),
            'reason' => 'Fortbildung',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->toDateString())
            ->assertOk()
            ->assertSee('Fortbildung');
    }

    public function test_a_restaurant_has_no_day_plan(): void
    {
        $setup = $this->createTenantSetup(); // stays TenantType::Restaurant
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar')->assertForbidden();
    }

    public function test_service_staff_may_open_the_day_plan(): void
    {
        [$setup] = $this->salon();
        $member = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        // Only reservations.view is required – the plan is a working tool, not a setting.
        $this->actingAs($member)->get('/admin/staff/calendar')->assertOk();
    }

    public function test_a_broken_date_falls_back_to_today_instead_of_erroring(): void
    {
        [, $admin] = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar?date=kaputt')->assertOk();
    }
}
