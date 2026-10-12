<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Jede protokollierte Aktion braucht eine deutsche Bezeichnung.
 *
 * Ohne sie zeigt die Anzeige Bereich und technischen Namen: "Zahlung: Aktion
 * payment.amount_mismatch". Das Protokoll liest jemand, der wissen will, was
 * passiert ist - meistens dann, wenn etwas schiefgegangen ist, und dafür
 * braucht er einen Satz.
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

    /**
     * Präfixe aller Aktionen, die der Code protokolliert. Gezählt werden nur
     * Aufrufe des Protokollierers ($audit->log, $this->audit->log) und die
     * log()-Hilfe eines Dienstes ($this->log, die an den Protokollierer
     * weitergibt). Andere log()-Aufrufe, etwa auf einem Logkanal, gehören
     * nicht zu den Aktionen. Das erste Argument darf ein Ausdruck sein
     * ('location.'.($aktiv ? …) oder ein Wenn-Dann-Paar); jede Zeichenkette
     * darin mit Punkt nennt ein Präfix.
     *
     * @return array<int, string>
     */
    private function loggedPrefixes(): array
    {
        $prefixes = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            if (! preg_match_all('/(?:\$audit|\$this->audit|\$this)->log\(\s*([^,)]+)/', $file->getContents(), $calls)) {
                continue;
            }

            foreach ($calls[1] as $argument) {
                if (preg_match_all("/'([a-z0-9_]+)\\./", $argument, $found)) {
                    foreach ($found[1] as $prefix) {
                        $prefixes[$prefix] = true;
                    }
                }
            }
        }

        ksort($prefixes);

        return array_keys($prefixes);
    }

    public function test_every_logged_action_prefix_has_an_area(): void
    {
        $prefixes = $this->loggedPrefixes();

        // Der Fund selbst muss belastbar sein: Findet das Muster kaum noch
        // etwas, wäre der Test lautlos wertlos.
        $this->assertGreaterThan(30, count($prefixes), 'Die Suche nach Präfixen findet fast nichts mehr - stimmt das Muster noch?');
        // Ein Wenn-Dann-Paar im ersten Argument zählt mit, ebenso die log()-Hilfe eines Dienstes.
        $this->assertContains('marketing_campaign', $prefixes);
        $this->assertContains('provisioning', $prefixes);
        $this->assertContains('support', $prefixes);
        $this->assertContains('auth', $prefixes);

        $ohne = array_values(array_filter($prefixes, fn (string $prefix) => ! array_key_exists($prefix, AuditLog::SUBJECTS)));

        $this->assertSame([], $ohne, 'Für diese Präfixe fehlt ein Bereich in AuditLog::SUBJECTS: '.implode(', ', $ohne));
    }

    public function test_the_areas_of_login_support_and_customer_panel_have_german_names(): void
    {
        $this->assertSame('Anmeldung', AuditLog::SUBJECTS['auth']);
        $this->assertSame('Support', AuditLog::SUBJECTS['support']);
        $this->assertSame('Kundenpanel', AuditLog::SUBJECTS['provisioning']);
    }

    public function test_a_missing_label_names_the_area_and_the_technical_action(): void
    {
        // Bereich bekannt, Endung unbekannt: deutscher Bereich und technischer Name als Rückfall.
        $this->assertSame('Standort: Aktion location.brand_new_thing', AuditLog::labelFor('location.brand_new_thing'));
        $this->assertSame('Zahlung: Aktion payment.foo_bar', AuditLog::labelFor('payment.foo_bar'));
        $this->assertFalse(AuditLog::hasGermanLabel('location.brand_new_thing'));

        // Bereich unbekannt: nur der technische Name.
        $this->assertSame('Aktion zzz.brand_new_thing', AuditLog::labelFor('zzz.brand_new_thing'));
        $this->assertSame('Aktion ohnepunkt', AuditLog::labelFor('ohnepunkt'));
        $this->assertFalse(AuditLog::hasGermanLabel('zzz.brand_new_thing'));

        // Bereich und Endung bekannt: zusammengesetzter Satz wie bisher.
        $this->assertSame('Tisch gelöscht', AuditLog::labelFor('table.deleted'));
        $this->assertTrue(AuditLog::hasGermanLabel('table.deleted'));
        $this->assertSame('Standort: Kapazität und Dauer geändert', AuditLog::labelFor('location.capacity_updated'));
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
        // Stelle - sie gehoeren trotzdem in die Liste. Dazu kommen Aktionen,
        // die kein Code mehr schreibt, deren Eintraege aber im Protokoll
        // stehen: location.settings_updated schrieb die fruehere
        // Sammelseite der Einstellungen, location.general_updated die Seite
        // Allgemein mit den Daten des Standorts.
        $ausnahmen = ['auth.login', 'location.settings_updated', 'location.general_updated'];

        $tot = array_values(array_diff(array_keys(AuditLog::ACTION_LABELS), $gelogged, $ausnahmen));
        sort($tot);

        $this->assertSame([], $tot, "Fuer diese Aktionen gibt es eine Bezeichnung, aber keine Stelle, die sie schreibt:\n".implode("\n", $tot));
    }
}
