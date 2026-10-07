<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class GeneralSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_business_name_and_location_master_data_can_be_saved(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $originalSlug = $setup['location']->slug;
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/general', [
            'business_name' => 'Trattoria Aurora GmbH',
            'location_name' => 'Aurora am Markt',
            'timezone' => 'Europe/Vienna',
            'phone' => '+49 30 123456',
            'email' => 'hallo@aurora.test',
            'address_line1' => 'Marktplatz 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'public_intro' => 'Herzlich willkommen!',
        ])->assertRedirect();

        $this->assertSame('Trattoria Aurora GmbH', $setup['tenant']->fresh()->name);

        $loc = $setup['location']->fresh();
        $this->assertSame('Aurora am Markt', $loc->name);
        $this->assertSame('Europe/Vienna', $loc->timezone);
        $this->assertSame('+49 30 123456', $loc->phone);
        $this->assertSame('hallo@aurora.test', $loc->email);
        $this->assertSame('Berlin', $loc->city);
        // Slug stays stable so booking links keep working.
        $this->assertSame($originalSlug, $loc->slug);
    }

    public function test_business_name_is_required(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->put('/admin/settings/general', [
            'business_name' => '', 'location_name' => 'X', 'timezone' => 'Europe/Berlin',
        ])->assertSessionHasErrors('business_name');
    }

    public function test_staff_cannot_edit_master_data(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->put('/admin/settings/general', [
            'business_name' => 'Hack', 'location_name' => 'X', 'timezone' => 'Europe/Berlin',
        ])->assertForbidden();
    }

    public function test_relay_cannot_be_enabled_without_domain(): void
    {
        config(['swayy.guest_mail_relay.domain' => '']);
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->from('/admin/settings')
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
