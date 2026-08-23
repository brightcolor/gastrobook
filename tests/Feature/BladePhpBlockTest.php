<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Keine Ansicht darf am PHP-Block zerbrechen.
 *
 * Blade zieht die Klammer-Kurzform und einen nachfolgenden Block zusammen: Der
 * Block beginnt an der Kurzform und endet erst am Ende des zweiten, alles
 * dazwischen landet unausgeführt im Ausgabetext. Genau daran lief die
 * Verwaltungsseite jedes Gastes über sechs Versionen in einen Serverfehler.
 *
 * Der frühere Test suchte das Muster mit einem eigenen Regex und übersah dabei
 * vier brechende Varianten. Hier wird stattdessen jede Ansicht wirklich
 * kompiliert und das Ergebnis von PHP selbst geprüft — was Blade erzeugt, muss
 * gültiges PHP sein. Der zweite Test hält den Detektor ehrlich: Er füttert ihn
 * mit gebauten Fällen und verlangt, dass er sie erkennt.
 */
class BladePhpBlockTest extends TestCase
{
    /**
     * Kompiliert eine Vorlage und meldet den Syntaxfehler, falls einer entsteht.
     */
    private function compileError(string $template): ?string
    {
        try {
            $php = Blade::compileString($template);
            // Escapte Direktiven (@@php) und @verbatim-Bloecke sollen als Text
            // stehen bleiben - sie landen unveraendert in der Ausgabe und sind
            // KEIN verschluckter Block. Fuer die Ueberlebenspruefung darum aus
            // einer Kopie der Quelle entfernen; die Syntaxpruefung unten laeuft
            // weiter ueber das echte Kompilat.
            $ohneText = Blade::compileString($this->withoutLiteralDirectives($template));
        } catch (\Throwable $e) {
            return 'laesst sich nicht uebersetzen: '.mb_substr($e->getMessage(), 0, 200);
        }

        // Zwei Pruefungen, weil eine allein nicht reicht.
        //
        // Ein verschluckter Block ist SYNTAKTISCH gueltig: `@php` innerhalb von
        // PHP-Quelltext ist der Fehlerunterdruecker vor einer Konstanten und
        // tut schlicht nichts - `php -l` schweigt, die Seite bricht erst zur
        // Laufzeit an einer nicht gesetzten Variablen. Ueberlebt eine
        // Direktive die Uebersetzung, wurde sie also verschluckt.
        if (preg_match('/@(php|endphp)\b/', $ohneText) === 1) {
            return 'Direktive @php/@endphp hat die Uebersetzung ueberlebt - ein Block hat sie verschluckt.';
        }

        $datei = tempnam(sys_get_temp_dir(), 'blade').'.php';
        file_put_contents($datei, $php);

        $ausgabe = [];
        $code = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($datei).' 2>&1', $ausgabe, $code);
        @unlink($datei);

        return $code === 0 ? null : implode(' ', $ausgabe);
    }

    /**
     * Alles, was als TEXT stehen bleiben soll, aus der Quelle nehmen.
     *
     * `@@php` ist die escapte Direktive und wird zu einem literalen `@php` in
     * der Ausgabe; `@verbatim` haelt seinen ganzen Inhalt davon frei. Beides
     * sieht nach der Uebersetzung aus wie ein verschluckter Block.
     */
    private function withoutLiteralDirectives(string $template): string
    {
        // Nur echte Direktiven, nicht die Zeichenkette. Ohne den Anker vor
        // `@verbatim` versteckte eine beliebige Erwaehnung des Wortes - in
        // einem JS-String, in einem HTML-Kommentar, auf einer Doku-Seite -
        // alles dazwischen vor dem Detektor, verschluckte Bloecke inklusive.
        $ohne = preg_replace('/(?<![\S@])@verbatim\b.*?(?<![\S@])@endverbatim\b/s', '', $template) ?? $template;

        return str_replace(['@@php', '@@endphp'], '', $ohne);
    }

    public function test_every_view_compiles_to_valid_php(): void
    {
        $kaputt = [];

        foreach (File::allFiles(resource_path('views')) as $datei) {
            if (! str_ends_with($datei->getFilename(), '.blade.php')) {
                continue;
            }

            $fehler = $this->compileError((string) file_get_contents($datei->getPathname()));
            if ($fehler !== null) {
                $kaputt[] = $datei->getRelativePathname().': '.$fehler;
            }
        }

        $this->assertSame([], $kaputt, 'Diese Ansichten kompilieren nicht.');
    }

    /**
     * Der Detektor selbst, an gebauten Fällen.
     *
     * Ohne diese Gegenprobe könnte die Prüfung oben verrotten, ohne dass es
     * auffällt — sie wäre dann eine Behauptung über das Repo statt eines
     * Nachweises.
     */
    public function test_the_detector_actually_catches_the_broken_forms(): void
    {
        $kurz = '@php($a = 1)';
        $block = "@php\n\$b = 2;\n@endphp";

        $muss_brechen = [
            'Kurzform vor Block' => $kurz."\n".$block,
            'Kurzform vor einzeiligem Block' => $kurz."\n@php \$b = 2; @endphp",
            'Kurzform im Kommentar vor Block' => '{{-- '.$kurz.' --}}'."\n".$block,
            'Block, Kurzform, Block' => $block."\n".$kurz."\n".$block,
            // Das Wort in einer Zeichenkette ist keine Direktive und darf
            // nichts verstecken.
            'kaputt zwischen erwaehnten @verbatim' => $kurz."\n".'<script>var a="@verbatim";</script>'."\n".$block."\n".'<!-- @endverbatim -->',
        ];

        foreach ($muss_brechen as $name => $vorlage) {
            $this->assertNotNull(
                $this->compileError($vorlage),
                'Nicht erkannt: '.$name
            );
        }

        $muss_laufen = [
            'nur Kurzform' => $kurz,
            'nur Block' => $block,
            'Block vor Kurzform' => $block."\n".$kurz,
            'zwei Bloecke' => $block."\n".$block,
            // Text, kein Block: Beides soll in der Ausgabe stehen bleiben und
            // darf den Detektor nicht ausloesen.
            'escapte Direktive' => 'Schreibe @@php fuer einen Block.',
            'verbatim' => "@verbatim\n@php\n\$x = 1;\n@endphp\n@endverbatim",
        ];

        foreach ($muss_laufen as $name => $vorlage) {
            $this->assertNull(
                $this->compileError($vorlage),
                'Faelschlich als kaputt gemeldet: '.$name
            );
        }
    }
}
