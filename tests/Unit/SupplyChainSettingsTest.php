<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regeln für Fremdcode in Build und Entwicklung.
 *
 * Jede Action in den Workflows steht auf einem festen Commit, dahinter steht
 * die Version als Kommentar. Jeder Workflow legt die Rechte des GITHUB_TOKEN
 * auf oberster Ebene fest. Dependabot schlägt neue Versionen erst nach einer
 * Wartezeit vor (`cooldown` je Paketquelle), npm löst sie erst nach einer
 * Wartezeit auf (`min-release-age` in der `.npmrc`). Wie lang die Wartezeit
 * ist, steht in den Dateien selbst; die Tests verlangen, dass sie gesetzt ist.
 *
 * Die Prüfungen lesen die Dateien als Text. Der letzte Test füttert sie mit
 * gebauten Fällen und verlangt, dass sie diese richtig einordnen.
 */
class SupplyChainSettingsTest extends TestCase
{
    public function test_workflow_actions_are_pinned_to_a_commit(): void
    {
        foreach ($this->workflowFiles() as $datei) {
            $this->assertSame(
                [],
                self::unpinnedActions((string) file_get_contents($datei)),
                basename($datei).': Jede Action braucht einen festen Commit (40 Zeichen) und dahinter die Version als Kommentar, etwa „@<commit> # v1.2.3“.'
            );
        }
    }

    public function test_workflows_set_token_permissions(): void
    {
        foreach ($this->workflowFiles() as $datei) {
            $this->assertTrue(
                self::setsTokenPermissions((string) file_get_contents($datei)),
                basename($datei).': Auf oberster Ebene fehlt „permissions:“ mit den Rechten des GITHUB_TOKEN, etwa „contents: read“.'
            );
        }
    }

    public function test_dependabot_waits_before_proposing_new_versions(): void
    {
        $datei = self::root().'/.github/dependabot.yml';
        $this->assertFileExists($datei);

        $wartezeiten = self::cooldownDays((string) file_get_contents($datei));

        $this->assertNotSame([], $wartezeiten, 'dependabot.yml enthält keine Paketquelle.');
        foreach ($wartezeiten as $quelle => $tage) {
            $this->assertNotNull($tage, "dependabot.yml: Die Paketquelle „{$quelle}“ braucht „cooldown:“ mit „default-days:“.");
            $this->assertGreaterThanOrEqual(1, $tage, "dependabot.yml: „default-days“ für „{$quelle}“ muss mindestens 1 sein.");
        }
    }

    public function test_npm_resolves_only_releases_of_a_minimum_age(): void
    {
        $datei = self::root().'/.npmrc';
        $this->assertFileExists($datei);

        $tage = self::npmMinReleaseAge((string) file_get_contents($datei));

        $this->assertNotNull($tage, '.npmrc: „min-release-age“ ist nicht gesetzt.');
        $this->assertGreaterThanOrEqual(1, $tage, '.npmrc: „min-release-age“ muss mindestens 1 sein.');
    }

    public function test_the_checks_classify_constructed_cases(): void
    {
        $commit = str_repeat('a1', 20);
        $digest = str_repeat('b2', 32);

        $workflow = <<<YAML
            jobs:
              bauen:
                steps:
                  - uses: actions/checkout@v5
                  - uses: actions/setup-node@main
                  - uses: actions/cache@{$commit}
                  - uses: docker://alpine:3.20
                  - uses: actions/checkout@{$commit} # v5.1.0
                  - uses: 'docker/build-push-action@{$commit}' # v6.19.2
                  - uses: owner/repo/unterordner@{$commit} # 2.0.1
                  - uses: ./.github/actions/lokal
                  - uses: docker://alpine@sha256:{$digest}
            YAML;

        $this->assertSame([
            'uses: actions/checkout@v5',
            'uses: actions/setup-node@main',
            "uses: actions/cache@{$commit}",
            'uses: docker://alpine:3.20',
        ], self::unpinnedActions($workflow));

        $this->assertTrue(self::setsTokenPermissions("on: push\npermissions:\n  contents: read\njobs: {}\n"));
        $this->assertTrue(self::setsTokenPermissions("permissions: read-all\n"));
        $this->assertFalse(self::setsTokenPermissions("jobs:\n  bauen:\n    permissions:\n      contents: read\n"));
        $this->assertFalse(self::setsTokenPermissions("permissions: write-all\n"));
        $this->assertFalse(self::setsTokenPermissions("on: push\njobs: {}\n"));

        $dependabot = <<<'YAML'
            version: 2
            updates:
              - package-ecosystem: composer
                directory: /
                cooldown:
                  default-days: 3
                commit-message:
                  prefix: "chore(deps)"
              - package-ecosystem: "npm"
                cooldown:
                  semver-major-days: 30
                  default-days: 14
              - package-ecosystem: github-actions
                schedule:
                  interval: monthly
              - package-ecosystem: docker
                cooldown:
                  semver-major-days: 30
                schedule:
                  default-days: 5
            YAML;

        $this->assertSame(
            ['composer' => 3, 'npm' => 14, 'github-actions' => null, 'docker' => null],
            self::cooldownDays($dependabot)
        );

        $this->assertSame(3, self::npmMinReleaseAge("ignore-scripts=true\nmin-release-age=3\n"));
        $this->assertSame(14, self::npmMinReleaseAge("min-release-age = 14\r\naudit=true\r\n"));
        $this->assertNull(self::npmMinReleaseAge("# min-release-age=7\n; min-release-age=7\n"));
        $this->assertNull(self::npmMinReleaseAge("ignore-scripts=true\n"));
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return list<string>
     */
    private function workflowFiles(): array
    {
        $dateien = array_merge(
            glob(self::root().'/.github/workflows/*.yml') ?: [],
            glob(self::root().'/.github/workflows/*.yaml') ?: [],
        );

        $this->assertNotSame([], $dateien, 'Unter .github/workflows liegt kein Workflow.');

        return $dateien;
    }

    /**
     * Alle `uses:`-Zeilen, die nicht fest auf einen Stand zeigen.
     *
     * Fest ist eine Action mit 40-stelligem Commit und Versionskommentar, ein
     * Container mit sha256-Digest und eine Action aus dem eigenen Repository.
     *
     * @return list<string>
     */
    private static function unpinnedActions(string $workflow): array
    {
        $offen = [];

        foreach (preg_split('/\R/', $workflow) ?: [] as $zeile) {
            if (preg_match('/^\s*(?:-\s+)?(uses:\s*([\'"]?)([^\s\'"#]+)\2)\s*(?:#\s*(\S.*))?$/', $zeile, $m) !== 1) {
                continue;
            }

            $ziel = $m[3];
            $version = $m[4] ?? '';

            $fest = match (true) {
                str_starts_with($ziel, './') => true,
                str_starts_with($ziel, 'docker://') => preg_match('/@sha256:[0-9a-f]{64}$/', $ziel) === 1,
                default => preg_match('/^[^@\s]+@[0-9a-f]{40}$/', $ziel) === 1 && $version !== '',
            };

            if (! $fest) {
                $offen[] = 'uses: '.$ziel;
            }
        }

        return $offen;
    }

    /**
     * Legt der Workflow auf oberster Ebene fest, was der GITHUB_TOKEN darf?
     */
    private static function setsTokenPermissions(string $workflow): bool
    {
        if (preg_match('/^permissions:[ \t]*([^\r\n#]*)/m', $workflow, $m) !== 1) {
            return false;
        }

        return trim($m[1]) !== 'write-all';
    }

    /**
     * Wartezeit in Tagen je Paketquelle (`cooldown.default-days`), null ohne Angabe.
     *
     * @return array<string, int|null>
     */
    private static function cooldownDays(string $config): array
    {
        $eintraege = preg_split('/^[ \t]*-[ \t]+package-ecosystem:[ \t]*/m', $config) ?: [];
        array_shift($eintraege);

        $tage = [];

        foreach ($eintraege as $eintrag) {
            $zeilen = preg_split('/\R/', $eintrag) ?: [];
            $quelle = trim((string) array_shift($zeilen), " \t'\"");
            $tage[$quelle] = null;
            $blockEinzug = null;

            foreach ($zeilen as $zeile) {
                if (trim($zeile) === '') {
                    continue;
                }

                $einzug = strlen($zeile) - strlen(ltrim($zeile));

                if ($blockEinzug === null) {
                    if (preg_match('/^\s*cooldown:\s*$/', $zeile) === 1) {
                        $blockEinzug = $einzug;
                    }

                    continue;
                }

                if ($einzug <= $blockEinzug) {
                    break;
                }

                if (preg_match('/^\s*default-days:\s*(\d+)\s*(?:#.*)?$/', $zeile, $m) === 1) {
                    $tage[$quelle] = (int) $m[1];
                }
            }
        }

        return $tage;
    }

    /**
     * Wartezeit aus einer `.npmrc` in Tagen, null ohne Angabe.
     */
    private static function npmMinReleaseAge(string $npmrc): ?int
    {
        $tage = null;

        foreach (preg_split('/\R/', $npmrc) ?: [] as $zeile) {
            if (preg_match('/^\s*min-release-age\s*=\s*(\d+)\s*$/', $zeile, $m) === 1) {
                $tage = (int) $m[1];
            }
        }

        return $tage;
    }
}
