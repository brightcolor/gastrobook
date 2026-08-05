<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Guest;
use App\Models\GuestConsent;
use App\Models\GuestNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * `guest_notes.view` and `consents.view` were listed in the permission matrix
 * but never checked – everyone who could open a guest saw everything. These
 * tests pin the rights to what they say.
 */
class GuestVisibilityTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function guestWithData(int $tenantId): Guest
    {
        $guest = Guest::factory()->create(['tenant_id' => $tenantId, 'last_name' => 'Winter']);
        GuestNote::create(['tenant_id' => $tenantId, 'guest_id' => $guest->id, 'body' => 'Sitzt gern am Fenster']);
        GuestConsent::create([
            'tenant_id' => $tenantId, 'guest_id' => $guest->id,
            'type' => 'marketing', 'granted' => true, 'channel' => 'online', 'recorded_at' => now(),
        ]);

        return $guest;
    }

    public function test_readonly_sees_the_profile_but_no_notes(): void
    {
        $setup = $this->createTenantSetup();
        $guest = $this->guestWithData($setup['tenant']->id);
        $readonly = $this->createMember($setup['tenant'], 'readonly');
        $this->clearTenantContext();

        $this->actingAs($readonly)->get('/admin/guests/'.$guest->id)
            ->assertOk()
            ->assertSee('Winter')
            ->assertDontSee('Sitzt gern am Fenster')
            ->assertDontSee('Einwilligungshistorie');
    }

    public function test_service_staff_still_sees_notes(): void
    {
        $setup = $this->createTenantSetup();
        $guest = $this->guestWithData($setup['tenant']->id);
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/guests/'.$guest->id)
            ->assertOk()
            ->assertSee('Sitzt gern am Fenster');
    }

    public function test_only_roles_with_the_right_see_the_consent_history(): void
    {
        $setup = $this->createTenantSetup();
        $guest = $this->guestWithData($setup['tenant']->id);
        $host = $this->createMember($setup['tenant'], 'host');
        $marketing = $this->createMember($setup['tenant'], 'marketing_manager');
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/guests/'.$guest->id)->assertDontSee('Einwilligungshistorie');
        $this->actingAs($marketing)->get('/admin/guests/'.$guest->id)->assertSee('Einwilligungshistorie');
        $this->actingAs($manager)->get('/admin/guests/'.$guest->id)->assertSee('Einwilligungshistorie');
    }

    public function test_a_role_without_note_access_cannot_write_notes_either(): void
    {
        $setup = $this->createTenantSetup();
        $guest = $this->guestWithData($setup['tenant']->id);
        $marketing = $this->createMember($setup['tenant'], 'marketing_manager');
        $this->clearTenantContext();

        // marketing_manager has guests.view but neither guests.update nor
        // guest_notes.view – the route right stops it first.
        $this->actingAs($marketing)
            ->post('/admin/guests/'.$guest->id.'/notes', ['body' => 'Heimlich'])
            ->assertForbidden();
    }
}
