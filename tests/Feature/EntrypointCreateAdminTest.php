<?php

declare(strict_types=1);

namespace Tests\Feature;

use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * docker/entrypoint.sh läuft mit set -e. Der Schritt „Oberadmin anlegen“ darf den Start nie abbrechen und meldet einen
 * Fehler des Befehls mit der Ursache. Der Test führt genau diesen Abschnitt (zwischen BEGIN und END create-admin) mit
 * einem falschen php aus, das Ausgabe und Ergebnis vorgibt.
 */
class EntrypointCreateAdminTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $probe = new Process(['sh', '-c', 'exit 0']);
        try {
            $probe->run();
        } catch (\Throwable) {
            $this->markTestSkipped('Auf diesem Rechner steht keine Shell (sh) bereit.');
        }

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'swayy-entrypoint-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'php', "#!/bin/sh\nprintf '%s\\n' \"\$FAKE_OUTPUT\"\nexit \"\$FAKE_EXIT\"\n");
        chmod($this->dir.DIRECTORY_SEPARATOR.'php', 0755);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir) && is_dir($this->dir)) {
            @unlink($this->dir.DIRECTORY_SEPARATOR.'php');
            @rmdir($this->dir);
        }

        parent::tearDown();
    }

    private function entrypoint(): string
    {
        return (string) file_get_contents(base_path('docker/entrypoint.sh'));
    }

    /**
     * Führt den Abschnitt mit set -e aus und gibt Ergebnis und Ausgabe zurück.
     *
     * @return array{0: int, 1: string}
     */
    private function runBlock(string $output, int $exit): array
    {
        $this->assertSame(1, preg_match('/# BEGIN create-admin\n(.*?)# END create-admin/s', $this->entrypoint(), $block), 'Der Abschnitt create-admin fehlt im Entrypoint.');

        $process = new Process(['sh', '-c', "set -e\n".$block[1]."\necho 'START LAEUFT WEITER'\n"], null, [
            'PATH' => $this->dir.PATH_SEPARATOR.(string) getenv('PATH'),
            'FAKE_OUTPUT' => $output,
            'FAKE_EXIT' => (string) $exit,
        ]);
        $process->run();

        return [$process->getExitCode() ?? -1, $process->getOutput().$process->getErrorOutput()];
    }

    public function test_an_existing_admin_or_a_created_one_lets_the_start_continue(): void
    {
        [$status, $output] = $this->runBlock('', 0);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('START LAEUFT WEITER', $output);
        $this->assertStringNotContainsString('konnte nicht angelegt werden', $output);

        [$status, $output] = $this->runBlock('Oberadmin chef@example.test angelegt. Login unter /login.', 0);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('Oberadmin chef@example.test angelegt. Login unter /login.', $output);
        $this->assertStringContainsString('START LAEUFT WEITER', $output);
    }

    public function test_a_failure_names_the_cause_and_the_start_continues(): void
    {
        [$status, $output] = $this->runBlock('Das Passwort muss mindestens 10 Zeichen lang sein.', 1);

        // set -e bricht den Start nicht ab, obwohl der Befehl mit 1 endete.
        $this->assertSame(0, $status);
        $this->assertStringContainsString('Der Oberadmin konnte nicht angelegt werden (Ergebnis 1). Der Befehl meldet:', $output);
        $this->assertStringContainsString('Das Passwort muss mindestens 10 Zeichen lang sein.', $output);
        $this->assertStringContainsString('Der Start läuft weiter.', $output);
        $this->assertStringContainsString('SWAYY_ADMIN_EMAIL', $output);
        $this->assertStringContainsString('START LAEUFT WEITER', $output);
        $this->assertStringNotContainsString('Admin already exists', $output);
    }

    public function test_any_other_exit_code_is_reported_the_same_way(): void
    {
        [$status, $output] = $this->runBlock('Die Datenbank antwortet nicht.', 255);

        $this->assertSame(0, $status);
        $this->assertStringContainsString('(Ergebnis 255)', $output);
        $this->assertStringContainsString('Die Datenbank antwortet nicht.', $output);
        $this->assertStringContainsString('START LAEUFT WEITER', $output);
    }

    public function test_only_the_last_lines_of_a_long_output_are_shown(): void
    {
        $lines = implode("\n", array_map(fn (int $i) => "Zeile {$i}", range(1, 40)));

        [, $output] = $this->runBlock($lines, 1);

        $this->assertStringContainsString('Zeile 40', $output);
        $this->assertStringContainsString('Zeile 21', $output);
        $this->assertStringNotContainsString('Zeile 20'."\n", $output);
    }

    public function test_the_entrypoint_is_valid_shell_and_keeps_its_error_handling(): void
    {
        $process = new Process(['sh', '-n', base_path('docker/entrypoint.sh')]);
        $process->run();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString("set -e\n", $this->entrypoint());
        $this->assertStringNotContainsString('Admin already exists', $this->entrypoint());
    }
}
