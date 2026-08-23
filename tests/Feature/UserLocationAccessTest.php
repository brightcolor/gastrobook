<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Standortfreigabe je Mitglied.
 *
 * Der Weg schreibt in eine Tabelle, die keinen Mandantenbezug erzwingt: Ohne
 * die Bedingung auf den Mandanten loescht er die Freigaben desselben Nutzers
 * bei einem ANDEREN Betrieb gleich mit. Und eine Mitgliedschaft ohne "alle
 * Standorte" und ohne einen einzigen freigegebenen ist der Zustand, in dem gar
 * kein Standort mehr aufloesbar ist - die Person sieht dann nichts mehr.
 */
class UserLocationAccessTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function memberWithLocations(array $setup, string $role = 'host'): TenantUser
    {
        $user = $this->createMember($setup['tenant'], $role, allLocations: false);

        return TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)
            ->where('user_id', $user->id)
            ->sole();
    }

    public function test_a_foreign_location_id_is_ignored(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $mitglied = $this->memberWithLocations($setup);

        $fremd = Location::factory()->create(['tenant_id' => Tenant::factory()->create()->id]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->put('/admin/users/'.$mitglied->id.'/locations', [
                'location_ids' => [$setup['location']->id, $fremd->id],
            ])
            ->assertRedirect();

        $this->assertSame(
            [$setup['location']->id],
            DB::table('location_user')->where('user_id', $mitglied->user_id)->pluck('location_id')->all()
        );
    }

    public function test_a_membership_cannot_end_up_without_any_location(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $mitglied = $this->memberWithLocations($setup);
        DB::table('location_user')->insert([
            'location_id' => $setup['location']->id,
            'user_id' => $mitglied->user_id,
            'tenant_id' => $setup['tenant']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->put('/admin/users/'.$mitglied->id.'/locations', ['location_ids' => []])
            ->assertSessionHasErrors('location_ids');

        // Und die vorhandene Freigabe steht noch.
        $this->assertSame(1, DB::table('location_user')->where('user_id', $mitglied->user_id)->count());
    }

    /**
     * Derselbe Mensch arbeitet in zwei Betrieben. Aendert der eine seine
     * Freigaben, darf das die des anderen nicht anfassen - die Zeilen stehen
     * in derselben Tabelle.
     */
    public function test_changing_one_tenant_leaves_the_other_tenants_grants_alone(): void
    {
        $einer = $this->createTenantSetup();
        $anderer = $this->createTenantSetup();
        $admin = $this->createMember($einer['tenant'], 'tenant_admin');

        $mitglied = $this->memberWithLocations($einer);
        TenantUser::create([
            'tenant_id' => $anderer['tenant']->id,
            'user_id' => $mitglied->user_id,
            'role' => 'host',
            'all_locations' => false,
        ]);
        DB::table('location_user')->insert([
            'location_id' => $anderer['location']->id,
            'user_id' => $mitglied->user_id,
            'tenant_id' => $anderer['tenant']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->put('/admin/users/'.$mitglied->id.'/locations', ['location_ids' => [$einer['location']->id]])
            ->assertRedirect();

        $this->assertSame(1, DB::table('location_user')
            ->where('user_id', $mitglied->user_id)
            ->where('tenant_id', $anderer['tenant']->id)
            ->count(), 'Die Freigabe des anderen Betriebs ist mitgeloescht worden.');
    }

    public function test_a_host_cannot_change_location_access(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $mitglied = $this->memberWithLocations($setup);
        $this->clearTenantContext();

        $this->actingAs($host)
            ->put('/admin/users/'.$mitglied->id.'/locations', ['all_locations' => 1])
            ->assertForbidden();
    }

    /**
     * Und die eigene Freigabe nicht: Wer sich selbst aussperrt, kommt an die
     * Maske nicht mehr heran, mit der er es rueckgaengig machen koennte.
     */
    public function test_nobody_changes_their_own_location_access(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $eigene = TenantUser::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)
            ->where('user_id', $admin->id)
            ->sole();
        $this->clearTenantContext();

        $this->actingAs($admin)
            ->put('/admin/users/'.$eigene->id.'/locations', ['location_ids' => [$setup['location']->id]])
            ->assertForbidden();
    }
}
