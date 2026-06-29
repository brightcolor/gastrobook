<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\NotificationTemplate;
use App\Services\NotificationTemplateRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class NotificationTemplateTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_editor_lists_built_in_templates(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get('/admin/templates')
            ->assertOk()
            ->assertSee('Reservierung bestätigt')
            ->assertSee('{guest_name}');
    }

    public function test_override_is_saved_and_used_by_renderer(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/templates/reservation_confirmed', [
            'subject' => 'Yay, Tisch fix bei {location_name}!',
            'body' => 'Hi {guest_name}, bis {reservation_time}!',
        ])->assertRedirect();

        $tpl = NotificationTemplate::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)->where('key', 'reservation_confirmed')->first();
        $this->assertNotNull($tpl);

        // The renderer must resolve the tenant override over the built-in default.
        $resolved = app(NotificationTemplateRenderer::class)
            ->resolve('reservation_confirmed', $setup['tenant']->id, $setup['location']->id, 'de');
        $this->assertSame('Yay, Tisch fix bei {location_name}!', $resolved['subject']);
    }

    public function test_reset_removes_the_override(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        NotificationTemplate::withoutGlobalScopes()->create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => null,
            'key' => 'reservation_reminder', 'locale' => 'de',
            'subject' => 'Custom', 'body' => 'Custom body', 'is_active' => true,
        ]);
        $this->clearTenantContext();

        $this->actingAs($admin)->delete('/admin/templates/reservation_reminder')->assertRedirect();

        $this->assertSame(0, NotificationTemplate::withoutGlobalScopes()
            ->where('tenant_id', $setup['tenant']->id)->where('key', 'reservation_reminder')->count());
    }

    public function test_unknown_key_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/templates/totally_unknown', [
            'subject' => 'x', 'body' => 'y',
        ])->assertNotFound();
    }

    public function test_staff_cannot_manage_templates(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/templates')->assertForbidden();
    }
}
