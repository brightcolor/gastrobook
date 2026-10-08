<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AdminComponentsTest extends TestCase
{
    public function test_drawer_renders_a_labelled_modal_panel(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-drawer id="standort-neu" title="Neuer Standort" description="Name und Adresse genügen für den Anfang.">
                <p>Inhalt</p>
                <x-slot:footer>
                    <button type="submit">Anlegen</button>
                </x-slot:footer>
            </x-drawer>
            BLADE);

        $this->assertStringContainsString('id="standort-neu" data-drawer hidden', $html);
        // Blade lässt um @if-Direktiven im Tag doppelte Leerzeichen stehen.
        $this->assertMatchesRegularExpression('/role="dialog" aria-modal="true" aria-labelledby="standort-neu-title"\s+aria-describedby="standort-neu-description"/', $html);
        $this->assertStringContainsString('<h2 id="standort-neu-title"', $html);
        $this->assertStringContainsString('data-drawer-body', $html);
        $this->assertStringContainsString('<p>Inhalt</p>', $html);
        $this->assertStringContainsString('<button type="submit">Anlegen</button>', $html);
        $this->assertSame(2, substr_count($html, 'data-drawer-close'));
    }

    public function test_inline_confirm_renders_button_and_hidden_question(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-inline-confirm id="remove-7" action="/admin/users/7" method="DELETE" label="Entfernen"
                question="„Kim“ aus dem Betrieb entfernen?" confirm="Entfernen" />
            BLADE);

        $this->assertStringContainsString('data-inline-confirm-open', $html);
        $this->assertMatchesRegularExpression('/id="remove-7" data-inline-confirm-panel\s+hidden\s+method="POST"/', $html);
        $this->assertStringContainsString('action="/admin/users/7"', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('data-ajax', $html);
        $this->assertStringContainsString('„Kim“ aus dem Betrieb entfernen?', $html);
        $this->assertStringContainsString('btn-danger', $html);
        $this->assertStringContainsString('data-inline-confirm-cancel', $html);
    }

    public function test_inline_confirm_can_start_open_and_post_natively(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-inline-confirm id="tenant-delete" action="/admin/account/tenant" method="DELETE" label="Betrieb löschen …"
                question="Betrieb endgültig löschen?" confirm="Betrieb jetzt löschen" :ajax="false" :open="true">
                <input name="confirm">
            </x-inline-confirm>
            BLADE);

        $this->assertStringNotContainsString('data-ajax', $html);
        $this->assertMatchesRegularExpression('/id="tenant-delete" data-inline-confirm-panel\s+method="POST"/', $html);
        $this->assertMatchesRegularExpression('/data-inline-confirm-open\s+hidden/', $html);
        $this->assertStringContainsString('<input name="confirm">', $html);
    }

    public function test_status_icon_names_the_state_in_words(): void
    {
        $html = Blade::render('<x-status-icon state="off" label="Inaktiv" />');

        $this->assertStringContainsString('title="Inaktiv"', $html);
        $this->assertStringContainsString('<span class="sr-only">Inaktiv</span>', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_status_icon_falls_back_to_off_for_unknown_states(): void
    {
        $off = Blade::render('<x-status-icon state="off" label="X" />');
        $unknown = Blade::render('<x-status-icon state="unbekannt" label="X" />');

        $this->assertSame($off, $unknown);
    }

    public function test_empty_state_offers_one_action(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-empty-state title="Noch keine Einladungen">
                Eingeladene Personen erscheinen hier, bis sie den Link öffnen.
                <x-slot:action>
                    <button type="button">Person einladen</button>
                </x-slot:action>
            </x-empty-state>
            BLADE);

        $this->assertStringContainsString('Noch keine Einladungen', $html);
        $this->assertStringContainsString('Eingeladene Personen erscheinen hier', $html);
        $this->assertStringContainsString('<button type="button">Person einladen</button>', $html);
    }
}
