<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Location;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Schranken zwischen Betrieben, Standorten und Rollen.
 */
class AccessBoundariesTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * Ein zweiter Standort im selben Betrieb, mit eigenem Raum und Tisch.
     *
     * @param  array<string, mixed>  $setup
     * @return array{0: Location, 1: RestaurantTable}
     */
    private function secondLocation(array $setup): array
    {
        $location = Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $room = Room::factory()->create(['location_id' => $location->id, 'tenant_id' => $setup['tenant']->id]);
        $table = RestaurantTable::factory()->create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $location->id,
            'room_id' => $room->id,
            'name' => 'B1',
            'min_capacity' => 1,
            'max_capacity' => 4,
        ]);

        return [$location, $table];
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservationAt(array $setup, Location $location): Reservation
    {
        $start = CarbonImmutable::now($location->timezone)->addDays(2)->setTime(19, 0);

        return Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $location->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $location->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'staff',
            'guest_name_snapshot' => 'Frau Kessler',
        ]);
    }

    // ── Standortgrenze beim Tischwechsel ──────────────────────────────────

    /**
     * Gefiltert wurde gegen den AKTIVEN Standort, nicht gegen den der
     * Reservierung. Damit landete an einer Buchung von Standort B ein Tisch
     * von Standort A - und der galt in A weiter als frei, weil jede
     * Belegtabfrage nach location_id filtert.
     */
    public function test_a_table_from_another_location_cannot_be_assigned(): void
    {
        $setup = $this->createTenantSetup();
        [$standortB] = $this->secondLocation($setup);
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['current_location_id' => $setup['location']->id])->save();

        $reservation = $this->reservationAt($setup, $standortB);
        $this->clearTenantContext();

        // Tisch aus Standort A an eine Buchung von Standort B.
        $this->actingAs($admin)
            ->post('/admin/reservations/'.$reservation->id.'/tables', [
                'table_ids' => [$setup['tables'][0]->id],
            ])
            ->assertStatus(422);

        $this->assertCount(0, $reservation->fresh()->tables);
    }

    // ── Standortschranke ohne aufgeloesten Standort ───────────────────────

    /**
     * Ohne aufgeloesten Standort fiel die Schranke ganz aus: Die Bedingung war
     * dann false und es wurde nicht abgebrochen. Erreichbar ueber eine
     * Mitgliedschaft mit "nur bestimmte Standorte" und keinem einzigen
     * freigegebenen.
     */
    public function test_a_member_without_any_location_cannot_open_reservations(): void
    {
        $setup = $this->createTenantSetup();
        $user = User::factory()->create(['current_tenant_id' => $setup['tenant']->id]);
        TenantUser::create([
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $user->id,
            'role' => 'tenant_admin',
            'all_locations' => false,
        ]);

        $reservation = $this->reservationAt($setup, $setup['location']);
        $this->clearTenantContext();

        $this->actingAs($user)->get('/admin/reservations/'.$reservation->id)->assertForbidden();
    }

    // ── users.invite ──────────────────────────────────────────────────────

    /**
     * Die Standortfreigabe hing faktisch an users.invite. Wer diese Rolle hat,
     * aber ausdruecklich NICHT users.roles.manage, konnte sich damit selbst
     * einen bisher gesperrten Standort freischalten.
     */
    public function test_invite_cannot_widen_your_own_location_access(): void
    {
        $setup = $this->createTenantSetup();
        [$standortB] = $this->secondLocation($setup);

        $user = User::factory()->create(['current_tenant_id' => $setup['tenant']->id]);
        TenantUser::create([
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $user->id,
            'role' => 'operations_manager',
            'all_locations' => false,
        ]);
        DB::table('location_user')->insert([
            'location_id' => $setup['location']->id,
            'user_id' => $user->id,
            'tenant_id' => $setup['tenant']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->clearTenantContext();

        $this->actingAs($user)->post('/admin/users/invite', [
            'email' => $user->email,
            'role' => 'host',
            'all_locations' => '0',
            'location_ids' => [$standortB->id],
        ])->assertForbidden();

        $this->assertDatabaseMissing('location_user', [
            'user_id' => $user->id,
            'location_id' => $standortB->id,
        ]);
    }

    /**
     * Auch fuer andere gilt: Eine BESTEHENDE Mitgliedschaft wird ueber diese
     * Route nicht erweitert - das ist eine Aenderung an fremden Rechten und
     * gehoert hinter users.roles.manage.
     */
    public function test_invite_does_not_widen_an_existing_membership(): void
    {
        $setup = $this->createTenantSetup();
        [$standortB] = $this->secondLocation($setup);

        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $kollege = User::factory()->create();
        TenantUser::create([
            'tenant_id' => $setup['tenant']->id,
            'user_id' => $kollege->id,
            'role' => 'host',
            'all_locations' => false,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/users/invite', [
            'email' => $kollege->email,
            'role' => 'host',
            'all_locations' => '0',
            'location_ids' => [$standortB->id],
        ])->assertRedirect();

        $this->assertDatabaseMissing('location_user', [
            'user_id' => $kollege->id,
            'location_id' => $standortB->id,
        ]);
    }

    // ── Betriebswechsel ───────────────────────────────────────────────────

    /**
     * Der Wechsel in einen fremden Betrieb lief fuer Plattform-Administratoren
     * ohne Rollenpruefung, ohne Grund und ohne Auditeintrag - waehrend
     * derselbe Zugriff ueber die Supportfunktion all das verlangt.
     */
    public function test_a_platform_admin_cannot_enter_a_foreign_tenant_via_switch(): void
    {
        $setup = $this->createTenantSetup();
        $fremder = Tenant::factory()->create(['plan_id' => $setup['tenant']->plan_id]);

        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $admin->forceFill(['saas_role' => 'readonly_admin'])->save();
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/switch-tenant', ['tenant_id' => $fremder->id])
            ->assertForbidden();

        $this->assertSame($setup['tenant']->id, $admin->fresh()->current_tenant_id);
    }

    public function test_switching_between_your_own_tenants_still_works(): void
    {
        $setup = $this->createTenantSetup();
        $zweiter = Tenant::factory()->create(['plan_id' => $setup['tenant']->plan_id]);

        $user = $this->createMember($setup['tenant'], 'tenant_admin');
        TenantUser::create([
            'tenant_id' => $zweiter->id,
            'user_id' => $user->id,
            'role' => 'tenant_admin',
            'all_locations' => true,
        ]);
        $this->clearTenantContext();

        $this->actingAs($user)->post('/admin/switch-tenant', ['tenant_id' => $zweiter->id])
            ->assertRedirect();

        $this->assertSame($zweiter->id, $user->fresh()->current_tenant_id);
    }

    // ── Kontoloeschung ────────────────────────────────────────────────────

    /**
     * Geprueft wurde nur der gerade aktive Betrieb. Wer in A mitarbeitet und
     * in B alleiniger Inhaber ist, konnte sein Konto loeschen - B stand danach
     * ohne Inhaber da.
     */
    public function test_deleting_your_account_is_refused_while_another_tenant_has_no_other_owner(): void
    {
        $setup = $this->createTenantSetup();
        $zweiter = Tenant::factory()->create(['plan_id' => $setup['tenant']->plan_id]);

        $user = $this->createMember($setup['tenant'], 'host');
        TenantUser::create([
            'tenant_id' => $zweiter->id,
            'user_id' => $user->id,
            'role' => 'tenant_owner',
            'all_locations' => true,
        ]);
        $this->clearTenantContext();

        $this->actingAs($user)->delete('/admin/account', ['confirm' => 'LÖSCHEN'])
            ->assertSessionHasErrors('confirm');

        $this->assertNotNull(User::find($user->id));
    }

    // ── Abrechnungsantrag ─────────────────────────────────────────────────

    /**
     * Der Antrag hebt die Testphase dauerhaft auf und uebermittelt die
     * Rechnungsdaten. Ohne Rechtepruefung konnte das jedes Mitglied ausloesen.
     */
    public function test_a_member_without_billing_rights_cannot_request_activation(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->post('/admin/trial/request', [
            'contact_name' => 'Frau Kessler',
            'contact_email' => 'kessler@example.test',
            'address_line1' => 'Beispielweg 1',
            'postal_code' => '12345',
            'city' => 'Beispielstadt',
            'country' => 'DE',
            'plan_key' => 'trial',
        ])->assertForbidden();

        $this->assertDatabaseCount('billing_requests', 0);
    }
}
