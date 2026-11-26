<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * bin/next-version berechnet die Version, die GitHub beim Merge eines Pull
 * Requests vergibt (.github/workflows/release.yml). Die Fälle rechnen mit
 * anderen Nummern als der aktuellen Version.
 */
final class NextVersionScriptTest extends TestCase
{
    private const SCRIPT = __DIR__.'/../../bin/next-version';

    /**
     * @return array{int, string, string} Rückgabewert, Ausgabe, Fehlerausgabe
     */
    private function runScript(string ...$arguments): array
    {
        $command = 'bash '.escapeshellarg(self::SCRIPT).' '.implode(' ', array_map('escapeshellarg', $arguments));
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $error = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), trim($output), trim($error)];
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function levels(): iterable
    {
        yield 'ohne Label gilt patch' => ['1.141.0', '', '1.141.1'];
        yield 'patch' => ['1.141.0', 'patch', '1.141.1'];
        yield 'minor setzt patch auf 0' => ['1.141.7', 'minor', '1.142.0'];
        yield 'major setzt minor und patch auf 0' => ['1.141.7', 'major', '2.0.0'];
        yield 'major ab 0' => ['0.9.4', 'major', '1.0.0'];
        yield 'andere Labels zählen nicht' => ['1.141.0', 'bug,enhancement', '1.141.1'];
        yield 'mehrere Stufen: major gewinnt' => ['1.141.0', 'patch,minor,major', '2.0.0'];
        yield 'mehrere Stufen: minor vor patch' => ['1.141.3', 'patch,minor', '1.142.0'];
        yield 'Groß- und Kleinschreibung und Leerzeichen' => ['1.141.0', 'bug, Minor ', '1.142.0'];
        yield 'mehrstellige Zahlen' => ['3.99.99', 'patch', '3.99.100'];
    }

    #[DataProvider('levels')]
    public function test_the_next_version_follows_the_level(string $version, string $labels, string $expected): void
    {
        [$code, $output] = $this->runScript($version, $labels);

        self::assertSame(0, $code);
        self::assertSame($expected, $output);
    }

    public function test_labels_are_optional(): void
    {
        [$code, $output] = $this->runScript('1.141.0');

        self::assertSame(0, $code);
        self::assertSame('1.141.1', $output);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidVersions(): iterable
    {
        yield 'leer' => [''];
        yield 'zwei Stellen' => ['1.141'];
        yield 'vier Stellen' => ['1.141.0.1'];
        yield 'mit v' => ['v1.141.0'];
        yield 'Text' => ['neu'];
    }

    #[DataProvider('invalidVersions')]
    public function test_an_invalid_version_is_rejected_with_a_message_for_the_operator(string $version): void
    {
        [$code, $output, $error] = $this->runScript($version, 'patch');

        self::assertSame(1, $code);
        self::assertSame('', $output);
        self::assertStringContainsString('MAJOR.MINOR.PATCH', $error);
        self::assertStringContainsString('Tag', $error, 'Die Meldung nennt, wo die Version herkommt.');
    }

    public function test_the_release_workflow_writes_the_version_where_the_app_reads_it(): void
    {
        $workflow = (string) file_get_contents(__DIR__.'/../../.github/workflows/release.yml');
        $version = (string) file_get_contents(__DIR__.'/../../config/version.php');

        // Das Muster im Workflow muss den Eintrag in config/version.php treffen.
        $this->assertStringContainsString("sed -i -E \"s/'number' => '[^']*'/'number' => '\$NEW'/\" config/version.php", $workflow);
        $this->assertMatchesRegularExpression("/'number' => '\\d+\\.\\d+\\.\\d+'/", $version);
        $this->assertStringContainsString('bin/next-version', $workflow);
        // Zeilen, die den Platzhalter beschreiben, bleiben stehen (README, Abschnitt „Version und Tag beim Merge“).
        $this->assertStringContainsString('sed -i "/Platzhalter/!s/@@VERSION@@/$NEW/g" CHANGELOG.md README.md', $workflow);
        $this->assertStringContainsString('gh workflow run ci.yml', $workflow, 'Ohne diesen Aufruf baut niemand das Abbild für das Tag.');
    }
}
