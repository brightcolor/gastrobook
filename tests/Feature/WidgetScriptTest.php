<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

/**
 * Die Skripte, die ein Betrieb auf seiner Website einbindet (embed.js,
 * popup.js), sprechen Salons als Salons an und setzen Namen nur als Text.
 * Das Verhalten im Browser prüft tests/js/widget-scripts.test.js.
 */
class WidgetScriptTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @return array{0: string, 1: string}
     */
    private function scripts(bool $salon, string $name): array
    {
        $setup = $this->createTenantSetup();
        if ($salon) {
            $setup['tenant']->update(['type' => 'salon']);
        }
        $setup['location']->update(['name' => $name]);
        $this->clearTenantContext();
        $path = $setup['tenant']->slug.'/'.$setup['location']->slug;

        return [
            (string) $this->get('/embed/'.$path.'.js')->assertOk()->getContent(),
            (string) $this->get('/widget/'.$path.'/popup.js')->assertOk()->getContent(),
        ];
    }

    private static function js(string $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    }

    public function test_a_salon_books_appointments_in_both_scripts(): void
    {
        [$embed, $popup] = $this->scripts(true, 'Glanz');

        $this->assertStringContainsString(self::js('Termin buchen – Glanz'), $embed);
        $this->assertStringContainsString(self::js('Terminbuchung – Glanz'), $popup);
        $this->assertStringContainsString('|| '.self::js('Termin buchen').';', $popup);
        $this->assertStringNotContainsString('Tisch reservieren', $embed.$popup);
        $this->assertStringNotContainsString('Jetzt reservieren', $popup);
    }

    public function test_a_restaurant_keeps_its_wording(): void
    {
        [$embed, $popup] = $this->scripts(false, 'Sonne');

        $this->assertStringContainsString(self::js('Tisch reservieren – Sonne'), $embed);
        $this->assertStringContainsString(self::js('Reservierung – Sonne'), $popup);
        $this->assertStringContainsString('|| '.self::js('Jetzt reservieren').';', $popup);
    }

    public function test_names_reach_the_page_only_as_text(): void
    {
        [$embed, $popup] = $this->scripts(false, 'Café "Sonne" <b>&</b>');

        // Im Skript steht der Name nur als JavaScript-Zeichenkette mit maskierten Zeichen.
        $this->assertStringNotContainsString('"Sonne"', $embed.$popup);
        $this->assertStringNotContainsString('<b>', $embed.$popup);
        // Markup setzt das Popup nur für das feste Symbol; Knopftext, Titel und Fenster entstehen als Text und Elemente.
        $this->assertSame(1, substr_count($popup, 'innerHTML'));
        $this->assertStringContainsString('btn.innerHTML = icon;', $popup);
        $this->assertStringContainsString('heading.textContent = label;', $popup);
    }
}
