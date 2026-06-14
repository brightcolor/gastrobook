<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BrandingLogoTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_location_logo_can_be_uploaded_and_served_publicly(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->post('/admin/settings/logo', [
            'logo' => UploadedFile::fake()->image('logo.png', 200, 80),
        ])->assertRedirect()->assertSessionHas('success');

        $setup['location']->refresh();
        $this->assertNotNull($setup['location']->brand_logo_path);
        Storage::disk('public')->assertExists($setup['location']->brand_logo_path);

        // Public, no auth required
        $this->get('/brand/'.$setup['tenant']->slug.'/'.$setup['location']->slug.'/logo')->assertOk();
    }

    public function test_booking_page_shows_contact_details(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update([
            'address_line1' => 'Hauptstr. 1',
            'postal_code' => '10115',
            'city' => 'Berlin',
            'phone' => '+49 30 123456',
            'email' => 'hallo@laden.de',
        ]);
        $this->clearTenantContext();

        $this->get('/book/'.$setup['tenant']->slug.'/'.$setup['location']->slug)
            ->assertOk()
            ->assertSee('Hauptstr. 1')
            ->assertSee('Berlin')
            ->assertSee('tel:+493012345', false)
            ->assertSee('mailto:hallo@laden.de', false);
    }
}
