<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Tests\TestCase;

/**
 * Jede protokollierte Aktion braucht eine deutsche Bezeichnung.
 *
 * Ohne sie setzt die Anzeige aus dem technischen Namen etwas zusammen, und der
 * ist englisch: "Zahlung Amount Mismatch", "Plattform-Benutzer User Role
 * Changed". Das Protokoll liest jemand, der wissen will, was passiert ist -
 * meistens dann, wenn etwas schiefgegangen ist.
 *
 * Dreimal einzeln nachgebessert, darum jetzt ueber ALLE Stellen auf einmal:
 * Der Test sucht die geloggten Aktionen im Quelltext und laesst keine neue
 * ohne Bezeichnung durch.
 */
class AuditLabelTest extends TestCase
{
    /**
     * @return array<int, string>
     */
    private function loggedActions(): array
    {
        $actions = [];

        $dateien = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($dateien as $datei) {
            if (! $datei->isFile() || $datei->getExtension() !== 'php') {
                continue;
            }

            $inhalt = (string) file_get_contents($datei->getPathname());

            // Nur der erste Parameter von log(), und nur wenn er eine
            // Zeichenkette mit Punkt ist - alles andere ist keine Aktion.
            if (preg_match_all("/->log\(\s*'([a-z0-9_]+\.[a-z0-9_.]+)'/", $inhalt, $treffer)) {
                foreach ($treffer[1] as $action) {
                    $actions[$action] = true;
                }
            }
        }

        return array_keys($actions);
    }

    public function test_every_logged_action_has_a_german_label(): void
    {
        $actions = $this->loggedActions();

        // Der Fund selbst muss belastbar sein: Findet das Muster nichts mehr,
        // waere der Test lautlos wertlos.
        $this->assertGreaterThan(80, count($actions), 'Die Suche nach Aktionen findet fast nichts mehr - stimmt das Muster noch?');

        $ohne = array_values(array_filter(
            $actions,
            fn (string $action) => ! AuditLog::hasGermanLabel($action)
        ));
        sort($ohne);

        $this->assertSame([], $ohne, "Diese Aktionen erscheinen im Protokoll mit einer englischen Kruecke:\n".implode("\n", array_map(
            fn (string $a) => '  '.$a.'  →  '.AuditLog::labelFor($a),
            $ohne
        )));
    }

    /**
     * Und umgekehrt: keine Bezeichnung fuer eine Aktion, die es nicht mehr
     * gibt. Solche Zeilen bleiben sonst ewig stehen und erwecken den Eindruck,
     * die Liste sei gepflegt.
     */
    public function test_no_label_points_at_a_dead_action(): void
    {
        $gelogged = $this->loggedActions();

        // Diese werden nicht ueber AuditLogger geschrieben, sondern an anderer
        // Stelle - sie gehoeren trotzdem in die Liste.
        $ausnahmen = ['auth.login'];

        $tot = array_values(array_diff(array_keys(AuditLog::ACTION_LABELS), $gelogged, $ausnahmen));
        sort($tot);

        $this->assertSame([], $tot, "Fuer diese Aktionen gibt es eine Bezeichnung, aber keine Stelle, die sie schreibt:\n".implode("\n", $tot));
    }
}
