<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LegalDocumentsTest extends TestCase
{
    public function test_legal_page_renders_markdown_from_storage(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('legal/impressum.md', "# Mein Impressum\n\nMusterzeile **fett**.");

        $this->get('/impressum')
            ->assertOk()
            ->assertSee('Mein Impressum')
            ->assertSee('<strong>fett</strong>', false);
    }

    public function test_edit_takes_effect_without_restart(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('legal/datenschutz.md', '# Stand A');
        $this->get('/datenschutz')->assertOk()->assertSee('Stand A');

        // Simulate the operator editing the file on disk
        Storage::disk('local')->put('legal/datenschutz.md', '# Stand B');
        $this->get('/datenschutz')->assertOk()->assertSee('Stand B')->assertDontSee('Stand A');
    }

    public function test_falls_back_to_template_when_file_missing(): void
    {
        Storage::fake('local'); // no files written → fallback to resources/legal

        $this->get('/agb')->assertOk()->assertSee('Allgemeine Geschäftsbedingungen');
    }

    public function test_install_command_creates_missing_files(): void
    {
        Storage::fake('local');

        $this->artisan('swayy:install-legal')->assertSuccessful();

        Storage::disk('local')->assertExists('legal/impressum.md');
        Storage::disk('local')->assertExists('legal/datenschutz.md');
        Storage::disk('local')->assertExists('legal/agb.md');
    }

    public function test_install_command_does_not_overwrite_without_force(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('legal/impressum.md', 'EIGENER TEXT');

        $this->artisan('swayy:install-legal')->assertSuccessful();

        $this->assertSame('EIGENER TEXT', Storage::disk('local')->get('legal/impressum.md'));
    }
}
