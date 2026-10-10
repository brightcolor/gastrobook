<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Mail\Layout\Block;
use App\Mail\Layout\TextBlocks;
use PHPUnit\Framework\TestCase;

class TextBlocksTest extends TestCase
{
    /**
     * @param  list<string>  $hosts
     * @return list<Block>
     */
    private function parse(string $text, int $minRows = 2, int $labelMax = 40, ?string $unsubscribe = null, array $hosts = []): array
    {
        return (new TextBlocks($minRows, $labelMax, 60, $unsubscribe, $hosts))->parse($text);
    }

    /**
     * @param  list<Block>  $blocks
     * @return list<string>
     */
    private function types(array $blocks): array
    {
        return array_map(fn (Block $block) => $block->type, $blocks);
    }

    public function test_default_confirmation_becomes_paragraphs_table_button(): void
    {
        $blocks = $this->parse("Hallo Testgast,\n\nwir freuen uns auf dich.\n\nDatum: 24.11.2026\nUhrzeit: 18:00 Uhr\nPersonen: 2\n\nReservierung ändern oder stornieren: https://swayy.test/r/abc\n\nBis bald!\nTrattoria Luna");

        $this->assertSame(['paragraph', 'paragraph', 'details', 'button', 'paragraph'], $this->types($blocks));
        $this->assertSame([
            ['label' => 'Datum', 'value' => '24.11.2026'],
            ['label' => 'Uhrzeit', 'value' => '18:00 Uhr'],
            ['label' => 'Personen', 'value' => '2'],
        ], $blocks[2]->rows);
        $this->assertSame('Reservierung ändern oder stornieren', $blocks[3]->label);
        $this->assertSame('https://swayy.test/r/abc', $blocks[3]->url);
        $this->assertTrue($blocks[3]->primary);
        $this->assertSame("Bis bald!\nTrattoria Luna", $blocks[4]->text);
    }

    public function test_alignment_spaces_and_colons_in_values_are_handled(): void
    {
        $blocks = $this->parse("Datum:    24.11.2026\nUhrzeit:  18:00 Uhr\nTisch:    7,  8");

        $this->assertSame([
            ['label' => 'Datum', 'value' => '24.11.2026'],
            ['label' => 'Uhrzeit', 'value' => '18:00 Uhr'],
            ['label' => 'Tisch', 'value' => '7, 8'],
        ], $blocks[0]->rows);
    }

    public function test_label_on_its_own_line_followed_by_a_link_becomes_a_button(): void
    {
        $blocks = $this->parse("Bitte bestätige innerhalb von 15 Minuten:\nhttps://swayy.test/w/1");

        $this->assertSame(['button'], $this->types($blocks));
        $this->assertSame('Bitte bestätige innerhalb von 15 Minuten', $blocks[0]->label);
        $this->assertSame('https://swayy.test/w/1', $blocks[0]->url);
    }

    public function test_link_lines_inside_a_details_block_follow_the_table(): void
    {
        $blocks = $this->parse("Datum: 24.11.2026\nUhrzeit: 18:00 Uhr\nStornieren: https://swayy.test/s\nTickets: 2");

        $this->assertSame(['details', 'button'], $this->types($blocks));
        $this->assertCount(3, $blocks[0]->rows);
    }

    public function test_only_the_first_button_is_primary(): void
    {
        $blocks = $this->parse("Jetzt bezahlen: https://swayy.test/p\n\nÄndern oder stornieren: https://swayy.test/m");

        $this->assertTrue($blocks[0]->primary);
        $this->assertFalse($blocks[1]->primary);
    }

    public function test_single_line_with_colon_stays_a_paragraph_unless_configured(): void
    {
        $this->assertSame(['paragraph'], $this->types($this->parse('Reservierungsnummer: R-CIHHL2')));
        $this->assertSame(['details'], $this->types($this->parse('Reservierungsnummer: R-CIHHL2', minRows: 1)));
        $this->assertSame(['paragraph'], $this->types($this->parse("Datum: 24.11.2026\nUhrzeit: 18:00 Uhr", minRows: 3)));
    }

    public function test_too_long_label_keeps_a_paragraph(): void
    {
        $this->assertSame(['details'], $this->types($this->parse("Reservierungsnummer: R-1\nPersonen: 2")));
        $this->assertSame(['paragraph'], $this->types($this->parse("Reservierungsnummer: R-1\nPersonen: 2", labelMax: 10)));
    }

    public function test_sentence_ending_with_colon_and_links_in_prose_stay_paragraphs(): void
    {
        $blocks = $this->parse("deine Reservierung ist bestätigt:\n\nMehr findest du auf https://trattoria.example/karte und vor Ort.");

        $this->assertSame(['paragraph', 'paragraph'], $this->types($blocks));
    }

    public function test_long_button_label_stays_a_paragraph(): void
    {
        $label = trim(str_repeat('Sehr lange Beschriftung ', 4));

        $this->assertSame(['paragraph'], $this->types($this->parse($label.': https://swayy.test/x')));
    }

    public function test_divider_lines_and_the_appended_unsubscribe_section(): void
    {
        $url = 'https://swayy.test/marketing/abmelden/5?signature=abc';

        $blocks = $this->parse("Neue Karte ab Freitag.\n\n---\nKeine Werbung mehr von uns? Hier abmelden: {$url}", unsubscribe: $url);
        $this->assertSame(['paragraph'], $this->types($blocks));

        $blocks = $this->parse("Oben\n\n----\n\nUnten");
        $this->assertSame(['paragraph', 'divider', 'paragraph'], $this->types($blocks));
    }

    public function test_unsubscribe_link_inside_prose_stays_an_inline_link(): void
    {
        $url = 'https://swayy.test/marketing/abmelden/5?signature=abc';

        $blocks = $this->parse("Abmelden kannst du dich jederzeit unter {$url} – ein Klick genügt.", unsubscribe: $url);

        $this->assertSame(['paragraph'], $this->types($blocks));
        $this->assertStringContainsString($url, $blocks[0]->text);
    }

    public function test_button_hosts_limit_buttons(): void
    {
        $blocks = $this->parse("Reservierung öffnen: https://swayy.test/admin/r/1\n\nJetzt bezahlen: https://fremde-seite.example/pay", hosts: ['swayy.test']);

        $this->assertSame(['button', 'paragraph'], $this->types($blocks));
        $this->assertSame('https://swayy.test/admin/r/1', $blocks[0]->url);
        $this->assertStringContainsString('https://fremde-seite.example/pay', $blocks[1]->text);
    }

    public function test_tricky_authorities_never_pass_the_host_check(): void
    {
        // Browser lesen „\“ als „/“: https://evil.example\@swayy.test führt zu evil.example.
        foreach (['https://evil.example\@swayy.test/admin', 'https://user@swayy.test/admin', "https://swayy.test\t/x"] as $url) {
            $this->assertNotContains(Block::BUTTON, $this->types($this->parse("Reservierung bestätigen: {$url}", hosts: ['swayy.test'])), $url);
        }
        $this->assertSame(['button'], $this->types($this->parse('Öffnen: https://swayy.test:8443/admin/r/1?x=1#a', hosts: ['swayy.test'])));
    }

    public function test_windows_line_endings_trailing_spaces_and_blank_runs_are_normalized(): void
    {
        $this->assertSame("Hallo,\n\nText", TextBlocks::normalize("Hallo,  \r\n\r\n\r\n\r\nText\r\n\r\n"));
        $this->assertSame(['paragraph', 'paragraph'], $this->types($this->parse("Hallo,  \r\n\r\n\r\nText")));
    }

    public function test_empty_text_has_no_blocks_and_text_without_pattern_is_kept_whole(): void
    {
        $this->assertSame([], $this->parse(''));

        $text = "Einfach nur ein Satz.\nUnd noch einer.";
        $this->assertSame($text, $this->parse($text)[0]->text);
    }

    public function test_javascript_links_are_never_buttons(): void
    {
        $this->assertSame(['paragraph'], $this->types($this->parse('Klick: javascript:alert(1)')));
    }
}
