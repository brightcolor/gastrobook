<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class SalonAdminTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function salonStaff(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Anna',
            'is_active' => true,
        ]);
        $this->clearTenantContext();

        return [$admin, $staff];
    }

    /**
     * Salon mit einer Mitarbeiterin, die ganztaegig arbeitet und eine Leistung
     * anbietet.
     *
     * @return array{0: User, 1: StaffMember, 2: Service, 3: array<string, mixed>}
     */
    private function salonWithService(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');

        $staff = StaffMember::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Anna',
            'is_active' => true,
        ]);

        foreach (range(0, 6) as $weekday) {
            StaffWorkingHour::create([
                'tenant_id' => $setup['tenant']->id,
                'staff_member_id' => $staff->id,
                'weekday' => $weekday,
                'starts_at' => '09:00',
                'ends_at' => '20:00',
            ]);
        }

        $service = Service::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'name' => 'Schnitt',
            'duration_minutes' => 60,
            'price_minor' => 3500,
            'currency' => 'EUR',
            'is_active' => true,
        ]);
        $service->staff()->attach($staff->id);

        $this->clearTenantContext();

        return [$admin, $staff, $service, $setup];
    }

    /**
     * Ueberbuchen einer Mitarbeiterin geht nur ausdruecklich.
     *
     * Der Salonpfad setzt `skip_availability_check`, weil eine Tischsuche hier
     * sinnlos ist - und uebersprang damit auch die Personenpruefung unter der
     * Sperre. Der Haken "trotzdem buchen" war dadurch wirkungslos in die andere
     * Richtung: Es ging IMMER, auch ohne ihn.
     */
    public function test_a_stylist_is_double_booked_only_on_request(): void
    {
        [$admin, $staff, $service, $setup] = $this->salonWithService();
        $tag = CarbonImmutable::now($setup['location']->timezone)->addDay()->toDateString();

        $termin = [
            'date' => $tag, 'time' => '14:00', 'party_size' => 1,
            'name' => 'Bea', 'service_ids' => [$service->id], 'staff_member_id' => $staff->id,
        ];

        $this->actingAs($admin)->post('/admin/reservations', $termin)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());

        // Zweiter Termin bei derselben Person zur selben Zeit: abgelehnt.
        $this->actingAs($admin)->post('/admin/reservations', ['name' => 'Cara'] + $termin)
            ->assertSessionHasErrors();
        $this->assertSame(1, Reservation::withoutGlobalScopes()->count());

        // Mit ausdruecklichem Ueberbuchen: angelegt.
        $this->actingAs($admin)->post('/admin/reservations', ['name' => 'Cara', 'force' => 1] + $termin)
            ->assertSessionHasNoErrors();
        $this->assertSame(2, Reservation::withoutGlobalScopes()->count());
    }

    public function test_working_hours_saved_for_valid_range(): void
    {
        [$admin, $staff] = $this->salonStaff();

        $this->actingAs($admin)->put("/admin/staff/{$staff->id}/working-hours", [
            'hours' => [['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00']],
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('staff_working_hours', [
            'staff_member_id' => $staff->id, 'weekday' => 0,
        ]);
    }

    public function test_working_hours_reject_end_before_start(): void
    {
        [$admin, $staff] = $this->salonStaff();

        $this->actingAs($admin)->put("/admin/staff/{$staff->id}/working-hours", [
            'hours' => [['weekday' => 0, 'starts_at' => '18:00', 'ends_at' => '09:00']],
        ])->assertSessionHasErrors('hours.0.ends_at');

        $this->assertDatabaseCount('staff_working_hours', 0);
    }

    public function test_absence_reject_end_before_start(): void
    {
        [$admin, $staff] = $this->salonStaff();

        $this->actingAs($admin)->post("/admin/staff/{$staff->id}/absences", [
            'starts_on' => '2026-07-01', 'starts_time' => '14:00',
            'ends_on' => '2026-07-01', 'ends_time' => '09:00',
        ])->assertSessionHasErrors('ends_time');

        $this->assertDatabaseCount('staff_absences', 0);
    }

    public function test_absence_saved_for_valid_range(): void
    {
        [$admin, $staff] = $this->salonStaff();

        $this->actingAs($admin)->post("/admin/staff/{$staff->id}/absences", [
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-03',
            'reason' => 'Urlaub',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('staff_absences', [
            'staff_member_id' => $staff->id, 'reason' => 'Urlaub',
        ]);
    }
}
