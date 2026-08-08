<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingRequest;
use App\Models\Invitation;
use App\Models\OpeningHour;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ReservationLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Regressionstests zum Audit vom 07.08.2026 – Sicherheitsteil.
 */
class AuditFixes20260807Test extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function billingRequest(int $tenantId, string $plan = 'professional'): BillingRequest
    {
        return BillingRequest::create([
            'tenant_id' => $tenantId,
            'contact_name' => 'Max Muster',
            'contact_email' => 'max@example.test',
            'company_name' => 'Muster GmbH',
            'address_line1' => 'Marktplatz 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'plan_key' => $plan,
            'token' => 'tok-'.$tenantId.'-'.uniqid(),
        ]);
    }

    // ── A1: Billing-Routen ────────────────────────────────────────────────

    public function test_a_normal_admin_cannot_see_the_billing_requests_of_all_tenants(): void
    {
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $this->billingRequest($other['tenant']->id);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/billing-requests')->assertForbidden();
    }

    public function test_a_normal_admin_cannot_activate_a_paid_plan(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'trial']);
        $request = $this->billingRequest($setup['tenant']->id);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->post('/admin/billing-requests/'.$request->id.'/activate')
            ->assertForbidden();

        $this->assertSame('trial', $setup['tenant']->fresh()->status);
    }

    public function test_the_platform_owner_may_activate(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['status' => 'trial']);
        $request = $this->billingRequest($setup['tenant']->id);

        $owner = $this->createMember($setup['tenant'], 'tenant_admin');
        $owner->update(['saas_role' => 'super_admin']);
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->post('/admin/billing-requests/'.$request->id.'/activate')
            ->assertRedirect();

        $this->assertSame('active', $setup['tenant']->fresh()->status);
    }

    // ── A2: Einladung ─────────────────────────────────────────────────────

    public function test_an_invitation_never_logs_into_an_existing_account(): void
    {
        $setup = $this->createTenantSetup();

        // Konto existiert bereits – mit eigenem Passwort.
        $opfer = User::factory()->create(['email' => 'opfer@example.test']);

        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'opfer@example.test',
            'role' => 'staff',
            'all_locations' => true,
        ]);
        $this->clearTenantContext();

        $this->post('/invitation/'.$invitation->token, [
            'name' => 'Angreifer',
            'password' => 'irgendwas-langes-123',
            'password_confirmation' => 'irgendwas-langes-123',
        ])->assertRedirect(route('login'));

        $this->assertGuest();
        // Die Mitgliedschaft entsteht trotzdem – die Einladung war ja echt.
        $this->assertDatabaseHas('tenant_users', [
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $opfer->id,
            'role' => 'staff',
        ]);
        // Und das Passwort des Opfers ist unangetastet.
        $this->assertTrue(Auth::attempt(['email' => 'opfer@example.test', 'password' => 'password']));
    }

    public function test_an_invitation_for_a_new_account_still_works(): void
    {
        $setup = $this->createTenantSetup();
        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'neu@example.test',
            'role' => 'host',
            'all_locations' => true,
        ]);
        $this->clearTenantContext();

        $this->post('/invitation/'.$invitation->token, [
            'name' => 'Neue Kollegin',
            'password' => 'ein-langes-passwort',
            'password_confirmation' => 'ein-langes-passwort',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticated();
    }

    public function test_an_invitation_only_grants_locations_of_the_inviting_tenant(): void
    {
        $setup = $this->createTenantSetup();
        $fremd = $this->createTenantSetup();

        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'neu2@example.test',
            'role' => 'host',
            'all_locations' => false,
            'location_ids' => [$setup['location']->id, $fremd['location']->id],
        ]);
        $this->clearTenantContext();

        $this->post('/invitation/'.$invitation->token, [
            'name' => 'Neue Kollegin',
            'password' => 'ein-langes-passwort',
            'password_confirmation' => 'ein-langes-passwort',
        ])->assertRedirect();

        $this->assertDatabaseHas('location_user', ['location_id' => $setup['location']->id]);
        $this->assertDatabaseMissing('location_user', ['location_id' => $fremd['location']->id]);
    }

    // ── B1/B3/B4/B5: Buchungslogik ────────────────────────────────────────

    public function test_a_longer_duration_is_checked_against_the_full_window(): void
    {
        // Genau ein Tisch, Standarddauer 120, danach eine Folgebuchung.
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 8]]);
        $tz = $setup['location']->timezone;
        $start = CarbonImmutable::now($tz)->addDay()->setTime(18, 0);
        $this->clearTenantContext();

        $lifecycle = app(ReservationLifecycleService::class);
        $lifecycle->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $start->addHours(3), // 21:00-23:00
            'source' => 'manual',
            'guest_name' => 'Zweite Gruppe',
            'table_ids' => [$setup['tables'][0]->id],
        ]);
        $this->clearTenantContext();

        // 18:00 mit 300 Minuten Dauer laeuft bis 23:00 und kollidiert.
        $this->expectException(ValidationException::class);
        $lifecycle->create($setup['location'], [
            'party_size' => 8,
            'start_local' => $start,
            'duration_minutes' => 300,
            'source' => 'manual',
            'guest_name' => 'Firmenessen',
        ]);
    }

    public function test_a_slot_after_midnight_can_be_booked(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $tz = $setup['location']->timezone;

        // Bar-Zeiten: 18:00 bis 02:00 des Folgetags.
        OpeningHour::where('location_id', $setup['location']->id)->delete();
        foreach (range(0, 6) as $weekday) {
            OpeningHour::create([
                'tenant_id' => $setup['tenant']->id,
                'location_id' => $setup['location']->id,
                'weekday' => $weekday,
                'opens_at' => '18:00',
                'closes_at' => '02:00',
            ]);
        }
        $this->clearTenantContext();

        // 00:30 des Folgetags gehoert zum Fenster, das am Vortag um 18:00 beginnt.
        $start = CarbonImmutable::now($tz)->addDays(2)->setTime(0, 30);
        $reservation = app(ReservationLifecycleService::class)->create($setup['location'], [
            'party_size' => 2,
            'start_local' => $start,
            'source' => 'online',
            'guest_name' => 'Nachtschwaermer',
        ]);

        $this->assertNotNull($reservation->id);
    }

    // ── A3: Rollenvergabe ─────────────────────────────────────────────────

    public function test_operations_manager_cannot_invite_an_owner(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->post('/admin/users/invite', [
            'email' => 'neuer.inhaber@example.test',
            'role' => 'tenant_owner',
        ])->assertSessionHasErrors('role');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_the_owner_may_still_appoint_another_owner(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->post('/admin/users/invite', [
            'email' => 'zweiter.inhaber@example.test',
            'role' => 'tenant_owner',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_a_manager_cannot_promote_someone_to_owner_afterwards(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $kollege = $this->createMember($setup['tenant'], 'staff');
        $membership = TenantUser::where('user_id', $kollege->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($manager)
            ->put('/admin/users/'.$membership->id.'/role', ['role' => 'tenant_owner'])
            ->assertForbidden();
    }
}
