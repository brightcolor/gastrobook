<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Location;
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

    /** Spaltenköpfe des Terminplans: der Teil der Seite vor den Terminen. */
    private function columnHeaders(string $html): string
    {
        $start = strpos($html, 'flex min-w-max gap-2');
        $this->assertNotFalse($start);

        return substr($html, $start);
    }

    public function test_a_paused_person_with_an_appointment_that_day_keeps_a_labelled_column(): void
    {
        [$setup, $admin, $staff] = $this->salon();
        $paused = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Berta Pause', 'is_active' => false,
        ]);
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->setTime(14, 0);
        $this->appointment($setup, $paused->id, $day, 'Frau Wartend');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->toDateString())
            ->assertOk()
            ->assertSee('Berta Pause')
            ->assertSee('Frau Wartend')
            ->assertSee('14:00–15:00')
            ->getContent();

        // Das Wort steht sichtbar neben dem Namen der Spalte; die aktive Person trägt es nicht.
        $this->assertMatchesRegularExpression('/Berta Pause<\/span>\s*<span[^>]*>pausiert<\/span>/', $html);
        $this->assertSame(1, substr_count($this->columnHeaders($html), 'pausiert'));
        $this->assertDoesNotMatchRegularExpression('/Anna Bergmann<\/span>\s*<span[^>]*>(pausiert|entfernt)/', $html);
    }

    public function test_a_removed_person_with_an_appointment_that_day_keeps_a_labelled_column(): void
    {
        [$setup, $admin] = $this->salon();
        $removed = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id,
            'name' => 'Cora Weg', 'is_active' => true,
        ]);
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->subDays(2)->setTime(11, 0);
        $this->appointment($setup, $removed->id, $day, 'Herr Früher');
        $removed->delete();
        $this->clearTenantContext();

        $html = (string) $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->toDateString())
            ->assertOk()
            ->assertSee('Cora Weg')
            ->assertSee('Herr Früher')
            ->getContent();

        $this->assertMatchesRegularExpression('/Cora Weg<\/span>\s*<span[^>]*>entfernt<\/span>/', $html);
        // Der Termin steht in der Spalte, nicht unter „Ohne Mitarbeiter“.
        $this->assertStringNotContainsString('Ohne Mitarbeiter', $html);
    }

    public function test_paused_and_removed_people_without_an_appointment_that_day_stay_hidden(): void
    {
        [$setup, $admin] = $this->salon();
        $paused = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Berta Pause', 'is_active' => false,
        ]);
        $removed = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Cora Weg', 'is_active' => true,
        ]);
        $removed->delete();
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->setTime(14, 0);
        // Termine an einem anderen Tag und ein abgesagter Termin am selben Tag zählen nicht.
        $this->appointment($setup, $paused->id, $day->addDays(2), 'Frau Später');
        $this->appointment($setup, $removed->id, $day->subDays(3), 'Herr Gestern');
        Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'staff_member_id' => $paused->id,
            'reservation_date' => $day->toDateString(), 'start_at' => $day->utc(), 'end_at' => $day->addHour()->utc(),
            'status' => 'cancelled_by_guest', 'guest_name_snapshot' => 'Frau Abgesagt',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->toDateString())
            ->assertOk()
            ->assertSee('Anna Bergmann')
            ->assertDontSee('Berta Pause')
            ->assertDontSee('Cora Weg')
            ->assertDontSee('pausiert')
            ->assertDontSee('entfernt');
    }

    public function test_people_of_other_locations_and_tenants_never_get_a_column(): void
    {
        [$setup, $admin] = $this->salon();
        $tz = $setup['location']->timezone;
        $day = CarbonImmutable::now($tz)->addDay()->setTime(14, 0);

        $elsewhere = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $pausedElsewhere = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $elsewhere->id, 'name' => 'Dora Woanders', 'is_active' => false,
        ]);
        Reservation::factory()->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $elsewhere->id, 'staff_member_id' => $pausedElsewhere->id,
            'reservation_date' => $day->toDateString(), 'start_at' => $day->utc(), 'end_at' => $day->addHour()->utc(),
            'guest_name_snapshot' => 'Frau Woanders',
        ]);

        $foreign = $this->createTenantSetup();
        $removedForeign = StaffMember::create([
            'tenant_id' => $foreign['tenant']->id, 'location_id' => $foreign['location']->id, 'name' => 'Emil Fremd', 'is_active' => true,
        ]);
        $removedForeign->delete();
        Reservation::factory()->create([
            'tenant_id' => $foreign['tenant']->id, 'location_id' => $foreign['location']->id, 'staff_member_id' => $removedForeign->id,
            'reservation_date' => $day->toDateString(), 'start_at' => $day->utc(), 'end_at' => $day->addHour()->utc(),
            'guest_name_snapshot' => 'Frau Fremd',
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/staff/calendar?date='.$day->toDateString())
            ->assertOk()
            ->assertDontSee('Dora Woanders')
            ->assertDontSee('Frau Woanders')
            ->assertDontSee('Emil Fremd')
            ->assertDontSee('Frau Fremd');
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
