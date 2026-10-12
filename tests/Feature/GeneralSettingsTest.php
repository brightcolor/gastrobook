<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_business_name_is_required(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/general', ['business_name' => ''])->assertSessionHasErrors('business_name');
    }

    public function test_staff_cannot_edit_master_data(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->put('/admin/settings/general', ['business_name' => 'Hack'])->assertForbidden();
    }

    public function test_relay_cannot_be_enabled_without_domain(): void
    {
        config(['swayy.guest_mail_relay.domain' => '']);
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->from('/admin/settings/sender')
            ->put('/admin/settings/guest-mail', ['mail_relay_enabled' => '1'])
            ->assertSessionHasErrors('mail_relay_enabled');

        $this->assertFalse($setup['tenant']->fresh()->mail_relay_enabled);
    }

    public function test_relay_enabled_with_domain(): void
    {
        config(['swayy.guest_mail_relay.domain' => 'antwort.swayy.de']);
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/guest-mail', ['mail_relay_enabled' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertTrue($setup['tenant']->fresh()->mail_relay_enabled);
    }
}
