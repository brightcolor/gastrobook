<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\TenantUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Wer nur einladen darf (users.invite ohne users.roles.manage), vergibt nur
 * Rollen, deren Rechte er selbst alle hat. Vorher reichte das Einladen, um
 * eine zweite eigene Mailadresse als Administration ('*') einzuladen und sich
 * so selbst hochzustufen.
 */
class RoleAssignmentTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesBeyondTheOperationsManager(): array
    {
        return [
            'Administration mit allen Rechten' => ['tenant_admin'],
            'Marketing mit Gaeste-Export' => ['marketing_manager'],
        ];
    }

    #[DataProvider('rolesBeyondTheOperationsManager')]
    public function test_operations_manager_cannot_invite_a_role_with_more_rights(string $role): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->post('/admin/users/invite', [
            'email' => 'zweite.adresse@example.test',
            'role' => $role,
        ])->assertSessionHasErrors('role');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_the_refusal_names_cause_and_next_step(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $response = $this->actingAs($manager)->postJson('/admin/users/invite', [
            'email' => 'zweite.adresse@example.test',
            'role' => 'tenant_admin',
        ])->assertStatus(422)->assertJsonValidationErrors('role');

        $meldung = $response->json('errors.role.0');
        $this->assertStringContainsString('Diese Rolle kannst du nicht vergeben', $meldung);
        $this->assertStringContainsString('Wähle eine Rolle mit gleichen oder weniger Rechten', $meldung);
        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    /**
     * Gibt es zur Adresse schon ein Konto, entsteht die Mitgliedschaft sofort.
     * Auch dieser Weg darf keine hoehere Rolle vergeben.
     */
    public function test_an_existing_account_cannot_be_added_with_more_rights(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $zweitkonto = User::factory()->create(['email' => 'zweitkonto@example.test']);
        $this->clearTenantContext();

        $this->actingAs($manager)->post('/admin/users/invite', [
            'email' => 'zweitkonto@example.test',
            'role' => 'tenant_admin',
        ])->assertSessionHasErrors('role');

        $this->assertFalse(TenantUser::where('user_id', $zweitkonto->id)->exists());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rolesWithinTheOperationsManager(): array
    {
        return [
            'eigene Rolle' => ['operations_manager'],
            'Standortleitung' => ['location_manager'],
            'Gastgeber' => ['host'],
            'Service' => ['staff'],
            'Nur lesen' => ['readonly'],
        ];
    }

    #[DataProvider('rolesWithinTheOperationsManager')]
    public function test_operations_manager_still_invites_roles_within_its_own_rights(string $role): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->post('/admin/users/invite', [
            'email' => 'kollegin@example.test',
            'role' => $role,
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    /**
     * Prueft nur das value-Attribut: Die bisherige Oberflaeche zeigt die
     * Rollen als <option>, die neue (Branch new-ui) als Radio-Buttons.
     */
    public function test_the_invite_form_only_offers_roles_within_your_own_rights(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/users')
            ->assertOk()
            ->assertSee('value="staff"', false)
            ->assertDontSee('value="tenant_admin"', false)
            ->assertDontSee('value="marketing_manager"', false);
    }

    /**
     * Die Grenze kommt aus config/permissions.php. Mit anderen Rollenrechten
     * verschiebt sie sich mit, ohne dass im Code etwas eingetragen wird.
     */
    public function test_the_limit_follows_the_configured_role_permissions(): void
    {
        $rollen = config('permissions.roles');
        $rollen['host'][] = 'users.invite';
        config(['permissions.roles' => $rollen]);

        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->post('/admin/users/invite', [
            'email' => 'service@example.test',
            'role' => 'staff',
        ])->assertSessionHasNoErrors();

        $this->actingAs($host)->post('/admin/users/invite', [
            'email' => 'standortleitung@example.test',
            'role' => 'location_manager',
        ])->assertSessionHasErrors('role');

        // Bekommt der Service ein Recht, das der Gastgeber nicht hat, faellt
        // auch diese Rolle aus der Auswahl.
        $rollen['staff'][] = 'guests.export';
        config(['permissions.roles' => $rollen]);

        $this->actingAs($host)->post('/admin/users/invite', [
            'email' => 'service2@example.test',
            'role' => 'staff',
        ])->assertSessionHasErrors('role');

        $this->assertSame(['service@example.test'], Invitation::withoutGlobalScopes()->pluck('email')->all());
    }

    public function test_an_admin_with_role_management_still_invites_an_admin(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/users/invite', [
            'email' => 'zweite.administration@example.test',
            'role' => 'tenant_admin',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Invitation::withoutGlobalScopes()->count());
    }

    public function test_an_admin_still_cannot_appoint_an_owner_and_learns_why(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $kollege = $this->createMember($setup['tenant'], 'staff');
        $membership = TenantUser::where('user_id', $kollege->id)->firstOrFail();
        $this->clearTenantContext();

        $response = $this->actingAs($admin)
            ->putJson('/admin/users/'.$membership->id.'/role', ['role' => 'tenant_owner'])
            ->assertStatus(422)->assertJsonValidationErrors('role');

        $this->assertStringContainsString('Inhaber', $response->json('errors.role.0'));
        $this->assertSame('staff', $membership->fresh()->role);
    }

    public function test_an_unknown_role_is_refused_with_a_readable_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => 'jemand@example.test',
            'role' => 'gibt_es_nicht',
        ])->assertStatus(422);

        $this->assertStringContainsString('Diese Rolle gibt es nicht', $response->json('errors.role.0'));
    }
}
