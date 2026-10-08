<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LocationLogoTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_defaults_are_defined_in_one_place(): void
    {
        $this->assertSame(3072, config('swayy.uploads.logo_max_kb'));
        $this->assertSame(['jpg', 'jpeg', 'png', 'webp'], config('swayy.uploads.logo_types'));
        $this->assertSame('logos', config('swayy.uploads.logo_directory'));
        $this->assertSame('Europe/Berlin', config('swayy.locations.default_timezone'));
    }

    public function test_owner_uploads_a_logo_for_a_location(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertOk()->assertJson(['reload' => true]);

        $path = $setup['location']->fresh()->brand_logo_path;
        $this->assertStringStartsWith('logos/'.$setup['location']->id.'/', (string) $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_storage_directory_comes_from_the_settings(): void
    {
        Storage::fake('public');
        config(['swayy.uploads.logo_directory' => 'marken']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertOk();

        $this->assertStringStartsWith('marken/'.$setup['location']->id.'/', (string) $setup['location']->fresh()->brand_logo_path);
    }

    public function test_svg_is_rejected_with_the_allowed_formats(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.logo.0', 'Die Datei ist kein Bild in einem erlaubten Format. Bitte das Logo als JPG, JPEG, PNG oder WEBP hochladen.');

        $this->assertNull($setup['location']->fresh()->brand_logo_path);
    }

    public function test_the_size_limit_comes_from_the_settings(): void
    {
        Storage::fake('public');
        config(['swayy.uploads.logo_max_kb' => 100]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60)->size(150),
        ])->assertStatus(422)
            ->assertJsonPath('errors.logo.0', 'Das Logo ist größer als 100 KB. Bitte eine kleinere Datei wählen.');
    }

    public function test_the_allowed_formats_come_from_the_settings(): void
    {
        Storage::fake('public');
        config(['swayy.uploads.logo_types' => ['png']]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.jpg', 120, 60),
        ])->assertStatus(422)
            ->assertJsonPath('errors.logo.0', 'Die Datei ist kein Bild in einem erlaubten Format. Bitte das Logo als PNG hochladen.');

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertOk();
    }

    public function test_the_old_upload_on_the_general_page_follows_the_same_settings(): void
    {
        Storage::fake('public');
        config(['swayy.uploads.logo_directory' => 'marken', 'swayy.uploads.logo_max_kb' => 100]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.settings.logo.upload'), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60)->size(150),
        ])->assertStatus(422)->assertJsonValidationErrors('logo');

        $this->actingAs($owner)->postJson(route('admin.settings.logo.upload'), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertOk();

        $this->assertStringStartsWith('marken/'.$setup['location']->id.'/', (string) $setup['location']->fresh()->brand_logo_path);
    }

    public function test_a_location_of_another_tenant_is_not_found(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $other['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertNotFound();
    }

    public function test_operations_manager_may_not_change_logos(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertForbidden();
    }

    public function test_removing_the_logo_deletes_the_file(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        Storage::disk('public')->put('logos/'.$setup['location']->id.'/alt.png', 'x');
        $setup['location']->update(['brand_logo_path' => 'logos/'.$setup['location']->id.'/alt.png']);
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson(route('admin.locations.logo.destroy', $setup['location']))
            ->assertOk()->assertJson(['reload' => true]);

        $this->assertNull($setup['location']->fresh()->brand_logo_path);
        Storage::disk('public')->assertMissing('logos/'.$setup['location']->id.'/alt.png');
    }
}
