<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * docker/nginx.conf reicht Adressen mit Dateiendung an die Anwendung weiter,
 * wenn es die Datei nicht gibt.
 *
 * Vom 07.10. bis 27.11.2026 endete die Location für statische Dateien mit
 * „try_files $uri =404“. Die Widget-Skripte, die die Anwendung erzeugt
 * (/embed/{betrieb}.js, /widget/{betrieb}/popup.js), kamen nie bei Laravel an,
 * und auf den Websites der Betriebe lud kein Reservierungsknopf.
 */
class NginxConfigTest extends TestCase
{
    private const CONFIG = __DIR__.'/../../docker/nginx.conf';

    /**
     * @return array{pattern: string, body: string}
     */
    private function staticLocation(): array
    {
        $config = (string) file_get_contents(self::CONFIG);
        $this->assertSame(1, preg_match('/location ~\* (\S+) \{(.*?)\n    \}/s', $config, $m), 'Die Location für statische Dateien fehlt in docker/nginx.conf.');

        return ['pattern' => $m[1], 'body' => $m[2]];
    }

    public function test_missing_static_files_fall_back_to_the_application(): void
    {
        $body = $this->staticLocation()['body'];

        $this->assertStringContainsString('try_files $uri /index.php?$query_string;', $body);
        $this->assertStringNotContainsString('=404', $body);
    }

    public function test_every_route_with_a_file_extension_reaches_the_application(): void
    {
        $pattern = '~'.trim($this->staticLocation()['pattern']).'~i';
        $matching = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
            ->filter(fn (string $uri) => preg_match($pattern, $uri) === 1)
            ->values()
            ->all();

        // Die Widget-Skripte fallen unter die Regel; ohne Rückfall auf index.php antworteten sie mit 404.
        $this->assertContains('/embed/{tenantSlug}/{locationSlug}.js', $matching);
        $this->assertContains('/widget/{tenantSlug}/popup.js', $matching);
        $this->assertStringContainsString('/index.php', $this->staticLocation()['body']);
    }
}
