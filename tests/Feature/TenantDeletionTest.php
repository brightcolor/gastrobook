<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\Tenant;
use App\Models\TenantUser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantDeletionTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_hard_deletes_tenant_and_all_related_data(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $tenant = $setup['tenant'];
        $owner = $this->createMember($tenant, 'tenant_owner');

        $start = CarbonImmutable::now('Europe/Berlin')->addHours(3);
        Reservation::create([
            'tenant_id' => $tenant->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => 'Europe/Berlin',
            'status' => ReservationStatus::Confirmed,
            'source' => 'online',
            'guest_name_snapshot' => 'X',
        ]);
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->delete('/admin/account/tenant', ['confirm' => $tenant->name])
            ->assertRedirect('/');

        // Hard delete: gone for good, not merely soft-deleted.
        $this->assertNull(Tenant::withTrashed()->find($tenant->id));

        // Cascade removed everything that hung off the tenant.
        $this->assertSame(0, Location::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, Reservation::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, TenantUser::where('tenant_id', $tenant->id)->count());

        // Owner is logged out.
        $this->assertGuest();
    }

    public function test_wrong_name_does_not_delete_tenant(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $tenant = $setup['tenant'];
        $owner = $this->createMember($tenant, 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->delete('/admin/account/tenant', ['confirm' => 'falscher name'])
            ->assertSessionHasErrors('confirm_tenant');

        $this->assertNotNull(Tenant::find($tenant->id));
    }

    public function test_non_owner_cannot_delete_tenant(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $tenant = $setup['tenant'];
        $admin = $this->createMember($tenant, 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->delete('/admin/account/tenant', ['confirm' => $tenant->name])
            ->assertForbidden();

        $this->assertNotNull(Tenant::find($tenant->id));
    }
}
