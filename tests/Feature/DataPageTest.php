<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DataPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_last_owner_sees_export_import_and_tenant_deletion(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get(route('admin.settings.data.show'))->assertOk()->getContent();

        $this->assertStringContainsString('id="export"', $html);
        $this->assertStringContainsString('href="'.route('admin.account.export').'"', $html);
        $this->assertStringContainsString('id="import"', $html);
        $this->assertStringContainsString('action="'.route('admin.account.import').'"', $html);
        $this->assertStringContainsString('id="betrieb-loeschen"', $html);
        $this->assertStringContainsString('action="'.route('admin.account.tenant.destroy').'"', $html);

        $source = (string) file_get_contents(resource_path('views/admin/settings/data.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source);
    }

    public function test_with_several_owners_deletion_explains_the_way(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get(route('admin.settings.data.show'))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.account.tenant.destroy').'"', false)
            ->assertSee('Löschen kann den Betrieb nur, wer als einziger Inhaber übrig ist.');
    }

    public function test_admins_get_an_explanation_instead_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get(route('admin.settings.data.show'))
            ->assertForbidden()
            ->assertSee('„Daten“ steht nur Inhabern des Betriebs offen.');
    }

    public function test_the_import_size_limit_comes_from_the_settings(): void
    {
        config(['swayy.uploads.import_max_kb' => 1024]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->from(route('admin.settings.data.show'))
            ->post(route('admin.account.import'), [
                'file' => UploadedFile::fake()->createWithContent('export.json', str_repeat(' ', 1100 * 1024).'{}'),
                'confirm' => '1',
            ])
            ->assertRedirect(route('admin.settings.data.show'))
            ->assertSessionHasErrors(['file' => 'Die Datei ist größer als 1 MB, mehr nimmt der Import nicht an. Bitte den Support von Swayy fragen.']);
    }

    public function test_the_import_limit_defaults_to_twenty_megabytes(): void
    {
        $this->assertSame(20480, config('swayy.uploads.import_max_kb'));
    }

    public function test_my_account_keeps_only_personal_things(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get(route('admin.account.show'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('admin.account.export'), $html);
        $this->assertStringContainsString('id="profil"', $html);
        $this->assertStringContainsString('id="konto-loeschen"', $html);
        // Der einzige Inhaber löscht zuerst den Betrieb: Hinweis mit Weg zur Seite „Daten“.
        $this->assertStringContainsString('href="'.route('admin.settings.data.show').'#betrieb-loeschen"', $html);

        $source = (string) file_get_contents(resource_path('views/admin/account/index.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source);
    }
}
