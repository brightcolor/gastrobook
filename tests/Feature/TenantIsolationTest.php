<?php

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_global_scope_hides_other_tenants_data(): void
    {
        $a = $this->createTenantSetup();
        $b = $this->createTenantSetup();

        Guest::create(['tenant_id' => $a['tenant']->id, 'last_name' => 'Alpha']);
        Guest::create(['tenant_id' => $b['tenant']->id, 'last_name' => 'Beta']);

        $this->actAsTenant($a['tenant']);
        $this->assertSame(['Alpha'], Guest::pluck('last_name')->all());

        $this->actAsTenant($b['tenant']);
        $this->assertSame(['Beta'], Guest::pluck('last_name')->all());
    }

    public function test_user_cannot_view_reservation_of_other_tenant_via_admin(): void
    {
        $a = $this->createTenantSetup();
        $b = $this->createTenantSetup();

        $reservationB = Reservation::factory()->create([
            'tenant_id' => $b['tenant']->id,
            'location_id' => $b['location']->id,
        ]);

        $userA = $this->createMember($a['tenant']);
        $this->clearTenantContext();

        $this->actingAs($userA)
            ->get('/admin/reservations/'.$reservationB->id)
            ->assertNotFound();
    }

    public function test_user_cannot_switch_to_foreign_tenant(): void
    {
        $a = $this->createTenantSetup();
        $b = $this->createTenantSetup();

        $userA = $this->createMember($a['tenant']);
        $this->clearTenantContext();

        $this->actingAs($userA)
            ->post('/admin/switch-tenant', ['tenant_id' => $b['tenant']->id])
            ->assertForbidden();
    }

    public function test_api_token_of_tenant_a_cannot_read_tenant_b(): void
    {
        $a = $this->createTenantSetup();
        $b = $this->createTenantSetup();

        Reservation::factory()->create([
            'tenant_id' => $b['tenant']->id,
            'location_id' => $b['location']->id,
            'guest_name_snapshot' => 'GeheimerGast',
        ]);

        $userA = $this->createMember($a['tenant']);
        $token = $userA->createToken('test', ['tenant:'.$a['tenant']->id, 'reservations:read'])->plainTextToken;
        $this->clearTenantContext();

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reservations');

        $response->assertOk();
        $this->assertStringNotContainsString('GeheimerGast', $response->getContent());
    }

    public function test_api_token_without_tenant_binding_is_rejected(): void
    {
        $a = $this->createTenantSetup();
        $userA = $this->createMember($a['tenant']);
        $token = $userA->createToken('test', ['reservations:read'])->plainTextToken;
        $this->clearTenantContext();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/reservations')
            ->assertForbidden();
    }
}
