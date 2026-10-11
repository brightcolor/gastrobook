<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Location;
use App\Models\Plan;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TeamPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_every_role_has_a_label_and_a_summary(): void
    {
        foreach (array_keys((array) config('permissions.roles')) as $role) {
            $this->assertNotSame("roles.{$role}.label", __("roles.{$role}.label"), $role);
            $this->assertNotSame("roles.{$role}.summary", __("roles.{$role}.summary"), $role);
        }
    }

    public function test_page_shows_role_names_drawers_and_copyable_invitation_links(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->createMember($setup['tenant'], 'staff');
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'neu@example.test',
            'role' => 'host',
            'all_locations' => true,
            'invited_by' => $owner->id,
        ]);
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/users')->assertOk()->getContent();

        $this->assertStringContainsString('data-drawer-open="einladen"', $html);
        $this->assertStringContainsString('id="einladen" data-drawer hidden', $html);
        $this->assertStringContainsString('>Service</option>', $html);
        $this->assertStringContainsString('Empfang · gültig bis', $html);
        $this->assertStringContainsString('data-copy="'.route('invitation.accept', $invitation->token).'"', $html);
        $this->assertStringContainsString('data-reset-on-error', $html);
        $this->assertStringNotContainsString('>staff<', $html);
        // Rollenwechsel speichert erst per Knopf; Pfeiltasten im Auswahlfeld lösen nichts aus.
        $this->assertStringNotContainsString('data-autosubmit', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" class="btn btn-secondary">Speichern<span class="sr-only"> Rolle von „[^“]+“<\/span><\/button>/u', $html);
        // Der Link steht sichtbar zum Markieren da, falls der Browser das Kopieren verweigert.
        $this->assertMatchesRegularExpression('/id="invite-link-'.$invitation->id.'" type="text" readonly value="'.preg_quote(e(route('invitation.accept', $invitation->token)), '/').'"/', $html);

        foreach (['index', 'partials/locations'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/users/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }

    public function test_location_access_appears_only_with_several_locations(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/users')->assertOk()->assertDontSee('data-drawer-open="zugang-', false);

        Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Filiale Süd']);

        $this->actingAs($owner)->get('/admin/users')->assertOk()->assertSee('data-drawer-open="zugang-', false);
    }

    public function test_json_invite_creates_an_invitation_and_reloads(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => 'service@example.test',
            'role' => 'staff',
            'all_locations' => '1',
        ])->assertOk()->assertExactJson([
            'message' => 'Einladung für service@example.test angelegt. Den Link findest du unter „Offene Einladungen“ zum Kopieren.',
            'reload' => true,
        ]);

        $this->assertSame(1, Invitation::withoutGlobalScopes()->where('email', 'service@example.test')->count());
    }

    public function test_invite_without_any_location_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => 'service@example.test',
            'role' => 'staff',
            'all_locations' => '0',
        ])->assertStatus(422)
            ->assertJsonPath('errors.location_ids.0', 'Bitte mindestens einen Standort auswählen oder „Alle Standorte“ anhaken.');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_inviting_a_member_again_explains_where_to_change_access(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => $staff->email,
            'role' => 'host',
            'all_locations' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Diese Person gehört schon zum Team. Rolle und Standorte änderst du direkt in der Liste.');

        $this->assertSame('staff', TenantUser::where('user_id', $staff->id)->value('role'));
    }

    public function test_an_overlong_email_is_rejected_with_the_limit(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $email = 'person@'.str_repeat('abcdefghij.', 18).'test';

        $this->actingAs($owner)->postJson('/admin/users/invite', ['email' => $email, 'role' => 'host', 'all_locations' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Die E-Mail-Adresse ist zu lang. Bitte höchstens 200 Zeichen verwenden.');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_an_email_at_the_limit_is_accepted(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $email = 'person@'.str_repeat('abcdefghij.', 17).'testen';
        $this->assertSame(200, strlen($email));

        $this->actingAs($owner)->postJson('/admin/users/invite', ['email' => $email, 'role' => 'host', 'all_locations' => '1'])
            ->assertOk();

        $this->assertSame(1, Invitation::withoutGlobalScopes()->where('email', $email)->count());
    }

    public function test_the_operations_manager_learns_who_changes_access(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($manager)->postJson('/admin/users/invite', ['email' => $staff->email, 'role' => 'host', 'all_locations' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Diese Person gehört schon zum Team. Rolle und Standorte ändert eine Person mit der Rolle „Inhaber“ oder „Administration“.');

        $this->assertSame('staff', TenantUser::where('user_id', $staff->id)->value('role'));
    }

    public function test_the_named_roles_follow_the_permissions(): void
    {
        config(['permissions.roles.location_manager' => ['users.roles.manage', 'reservations.view']]);
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($manager)->postJson('/admin/users/invite', ['email' => $staff->email, 'role' => 'host', 'all_locations' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Diese Person gehört schon zum Team. Rolle und Standorte ändert eine Person mit der Rolle „Inhaber“, „Administration“ oder „Standortleitung“.');
    }

    public function test_role_summaries_name_what_stays_with_the_owner(): void
    {
        $this->assertStringContainsString('bleiben beim Inhaber', __('roles.tenant_admin.summary'));
        $this->assertStringContainsString('Ernennung zum Inhaber', __('roles.tenant_admin.summary'));
        $this->assertStringContainsString('bis zur eigenen Stufe', __('roles.operations_manager.summary'));
        $this->assertStringContainsString('Rollen ändert die Administration', __('roles.operations_manager.summary'));
    }

    public function test_role_change_answers_without_reload(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $membership = TenantUser::where('user_id', $staff->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson(route('admin.users.role', $membership), ['role' => 'host'])
            ->assertOk()
            ->assertExactJson(['message' => "Rolle von {$staff->name} ist jetzt „Empfang“."]);
    }

    public function test_demoting_the_last_owner_is_rejected_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $membership = TenantUser::where('user_id', $owner->id)->firstOrFail();
        $this->clearTenantContext();

        // Einen Inhaber ändert nur ein Inhaber; der einzige stuft sich hier selbst herab.
        $this->actingAs($owner)->putJson(route('admin.users.role', $membership), ['role' => 'staff'])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', 'Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du diese Rolle ändern.');

        $this->assertSame('tenant_owner', $membership->fresh()->role);
    }

    public function test_an_admin_cannot_remove_the_owner_and_learns_why_in_the_row(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $membership = TenantUser::where('user_id', $owner->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($admin)->deleteJson(route('admin.users.remove', $membership))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Einen Inhaber ändert oder entfernt nur ein Inhaber des Betriebs. Bitte einen Inhaber darum.');

        $this->actingAs($admin)->deleteJson(route('admin.users.delete', $membership))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Einen Inhaber ändert oder entfernt nur ein Inhaber des Betriebs. Bitte einen Inhaber darum.');

        $this->assertNotNull($membership->fresh());
    }

    public function test_removing_a_member_reloads_the_list(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $membership = TenantUser::where('user_id', $staff->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson(route('admin.users.remove', $membership))
            ->assertOk()
            ->assertExactJson(['message' => "{$staff->name} ist aus dem Team entfernt.", 'reload' => true]);
    }

    public function test_inviting_yourself_explains_who_can_change_your_access(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => $owner->email,
            'role' => 'staff',
            'all_locations' => '1',
        ])->assertForbidden()
            ->assertJsonPath('message', 'Deine eigene Rolle und deine Standorte ändert eine andere Person mit Rechten für das Team. Bitte sie darum.');
    }

    public function test_the_user_limit_names_a_next_step_the_role_can_take(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_users' => 1]])->id]);
        $this->createMember($setup['tenant'], 'tenant_owner');
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        // Die Betriebsleitung lädt ein, öffnet „Abo & Rechnungen“ aber nicht und entfernt niemanden.
        $this->actingAs($manager)->postJson('/admin/users/invite', [
            'email' => 'service@example.test',
            'role' => 'staff',
            'all_locations' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', fn (string $message) => str_ends_with($message, 'Für weitere Personen bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.'));
    }

    public function test_operations_manager_sees_roles_as_text(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/users')
            ->assertOk()
            ->assertDontSee('data-autosubmit', false)
            ->assertDontSee('data-inline-confirm-open', false)
            ->assertSee('Betriebsleitung');
    }
}
