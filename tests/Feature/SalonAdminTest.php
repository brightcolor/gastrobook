<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\StaffMember;
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
