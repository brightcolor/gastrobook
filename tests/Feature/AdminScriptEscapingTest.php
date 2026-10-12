<?php

declare(strict_types=1);

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Namen, die das Team eingibt (Tags, Tischkombinationen, Tische), setzen die
 * Skripte der Verwaltung per innerHTML zusammen. Jeder solche Name läuft dort
 * durch esc(), sonst führt ein Name wie <img src=x onerror=…> bei allen
 * Kolleginnen und Kollegen Skript aus.
 */
class AdminScriptEscapingTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    public static function views(): array
    {
        return [
            'Tags im Reservierungsdetail' => [
                'views/admin/reservations/show.blade.php',
                ['`${esc(tag.name)} <button', '>${esc(tag.name)}</button>', 'color:${esc(tag.color)}'],
                ['`${tag.name} <button', '>${tag.name}</button>', 'color:${tag.color}'],
            ],
            'Kombinationen im Tischplan' => [
                'views/admin/floorplan/index.blade.php',
                ['>${esc(c.name)}</p>', '${c.tables.map(t => esc(t.name))'],
                ['>${c.name}</p>', '${c.tables.map(t => t.name)'],
            ],
        ];
    }

    /**
     * @param  list<string>  $escaped
     * @param  list<string>  $raw
     */
    #[DataProvider('views')]
    public function test_names_from_the_team_are_escaped_before_innerhtml(string $view, array $escaped, array $raw): void
    {
        $source = (string) file_get_contents(resource_path($view));

        $this->assertMatchesRegularExpression('/function esc\(s\) \{[^\n]*&lt;[^\n]*&quot;[^\n]*\}/', $source, "{$view} braucht die Funktion esc().");

        foreach ($escaped as $fragment) {
            $this->assertStringContainsString($fragment, $source, "{$view}: maskierte Stelle fehlt.");
        }
        foreach ($raw as $fragment) {
            $this->assertStringNotContainsString($fragment, $source, "{$view}: Name geht unmaskiert in innerHTML.");
        }
    }
}
