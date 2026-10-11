<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Liest config/swayy.php mit gesetzten Umgebungsvariablen neu ein, um Grenzen
 * und Vorgaben ohne die zwischengespeicherte Konfiguration zu prüfen.
 */
trait ReadsSwayyConfig
{
    /**
     * @param  array<string, string>  $env
     * @return array{0: array<string, mixed>, 1: string} Konfiguration und Warnungen im Fehlerprotokoll
     */
    private function swayyConfig(array $env): array
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'swayy-config');
        $previous = ini_set('error_log', $file);
        foreach ($env as $name => $value) {
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        try {
            $config = require config_path('swayy.php');
        } finally {
            foreach (array_keys($env) as $name) {
                unset($_SERVER[$name], $_ENV[$name]);
            }
            ini_set('error_log', $previous === false ? '' : $previous);
            $warnings = (string) file_get_contents($file);
            @unlink($file);
        }

        return [$config, $warnings];
    }

    /**
     * Jede Variable steht in .env.example als auskommentierte Zeile.
     *
     * @param  list<string>  $variables
     */
    private function assertDocumentedInEnvExample(array $variables): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));
        foreach ($variables as $variable) {
            $this->assertStringContainsString("# {$variable}=", $example, "{$variable} fehlt in .env.example");
        }
    }
}
