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
        $this->assertStringContainsString('data-drawer-close data-drawer-backdrop', $html);
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

    public function test_inline_confirm_ties_the_question_to_its_buttons(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-inline-confirm id="remove-7" action="/admin/users/7" method="DELETE" label="Entfernen"
                question="„Kim“ aus dem Betrieb entfernen?" confirm="Entfernen" />
            BLADE);

        $this->assertStringContainsString('<p id="remove-7-question"', $html);
        $this->assertMatchesRegularExpression('/role="group"\s+aria-labelledby="remove-7-question"/', $html);
        $this->assertMatchesRegularExpression('/<button type="submit"[^>]*aria-describedby="remove-7-question"/', $html);
        $this->assertMatchesRegularExpression('/<div data-inline-confirm-group role="group"\s+aria-labelledby="remove-7-question"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*role="group"/', $html);
    }

    public function test_inline_confirm_spaces_a_prepended_error_box_from_the_question(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-inline-confirm id="remove-7" action="/admin/users/7" method="DELETE" label="Entfernen"
                question="„Kim“ aus dem Betrieb entfernen?" confirm="Entfernen" />
            BLADE);

        // forms.js setzt die Fehlerzusammenfassung als erstes Kind des Formulars.
        $this->assertMatchesRegularExpression('/<form[^>]*class="space-y-3 /', $html);
        $this->assertMatchesRegularExpression('/<div data-inline-confirm-group[^>]*class="space-y-3"/', $html);
    }

    public function test_inline_confirm_names_the_subject_for_screen_readers_when_given(): void
    {
        $with = Blade::render(<<<'BLADE'
            <x-inline-confirm id="remove-7" action="/admin/users/7" method="DELETE" label="Entfernen" subject="Kim"
                question="„Kim“ aus dem Betrieb entfernen?" confirm="Entfernen" />
            BLADE);
        $without = Blade::render(<<<'BLADE'
            <x-inline-confirm id="remove-7" action="/admin/users/7" method="DELETE" label="Entfernen"
                question="„Kim“ aus dem Betrieb entfernen?" confirm="Entfernen" />
            BLADE);

        $this->assertMatchesRegularExpression('/data-inline-confirm-open[^>]*>Entfernen<span class="sr-only"> „Kim“<\/span><\/button>/', $with);
        $this->assertStringNotContainsString('sr-only', $without);
    }

    public function test_inline_confirm_can_reload_the_list_after_confirming(): void
    {
        $with = Blade::render(<<<'BLADE'
            <x-inline-confirm id="tag-7" action="/admin/tags/7" method="DELETE" label="Löschen"
                question="„VIP“ löschen?" confirm="Tag löschen" :reload="true" />
            BLADE);
        $without = Blade::render(<<<'BLADE'
            <x-inline-confirm id="tag-7" action="/admin/tags/7" method="DELETE" label="Löschen"
                question="„VIP“ löschen?" confirm="Tag löschen" />
            BLADE);

        $this->assertStringContainsString('data-reload-after-save', $with);
        $this->assertStringNotContainsString('data-reload-after-save', $without);
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
