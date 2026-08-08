<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Liest die Rechtstexte und beantwortet die eine Frage, die vor dem Echtbetrieb
 * zählt: Steht da noch die Vorlage?
 *
 * Solange Impressum oder AGB unausgefüllt sind, darf niemand annehmen, hier
 * einen Vertrag schließen zu können. Der Hinweis darauf verschwindet von
 * selbst, sobald die Texte gepflegt sind – es gibt bewusst keinen Schalter zum
 * Abnicken.
 */
class LegalDocumentStatus
{
    /**
     * Zeichenketten, die es nur in der ausgelieferten Vorlage gibt.
     *
     * Bewusst kuratiert statt „alles in eckigen Klammern“: In der
     * Datenschutzerklärung stehen auch Klammern, die eine Entscheidung
     * markieren und nicht zwingend eine Lücke sind (etwa der Zahlungsanbieter,
     * den man streicht statt ausfüllt). Ein Fehlalarm wäre hier teuer – der
     * Hinweis würde nie verschwinden.
     *
     * Verglichen wird gegen den zeilenumbruchfreien Text (siehe flatten()),
     * damit auch Marker treffen, die in der Vorlage über zwei Zeilen laufen.
     */
    private const PLATZHALTER = [
        // Der Vorlagen-Hinweis am Kopf jeder Datei
        'Bitte anpassen',
        'Bitte ergänzen Sie die in eckigen Klammern',
        // Impressum
        'Musterfirma',
        'Mustermann',
        'Musterstraße',
        'Musterstadt',
        'HRB 00000',
        'DE000000000',
        '+49 (0)123 456789',
        // Kontakt (Impressum und Datenschutz)
        'kontakt@example.com',
        // Pflichtangaben des Verantwortlichen in der Datenschutzerklärung
        '[Firmenname]',
        '[Straße Hausnummer]',
        '[PLZ Ort]',
        '[Name, Kontakt]',
        '[+49',
    ];

    /**
     * Ergebnis für die Lebensdauer DIESER Instanz merken – pending() und
     * pendingLabel() werden beim Rendern nacheinander aufgerufen.
     *
     * Bewusst kein Singleton im Container und kein Cache: Eine korrigierte
     * Datei muss beim nächsten Seitenaufruf wirken, genau wie der Rechtstext
     * selbst. Ein Singleton würde den Zustand über Anfragen hinweg einfrieren,
     * sobald die App in einem dauerhaften Worker läuft.
     *
     * @var array<string, string>|null
     */
    private ?array $offen = null;

    /**
     * Rechtstexte, die noch die Vorlage enthalten – als Schlüssel => Titel.
     *
     * @return array<string, string>
     */
    public function pending(): array
    {
        if ($this->offen !== null) {
            return $this->offen;
        }

        /** @var array<string, string> $titel */
        $titel = config('swayy.legal.documents', []);

        $offen = [];

        foreach ($titel as $key => $name) {
            if (! $this->isFilledIn($this->markdown($key), $key)) {
                $offen[$key] = $name;
            }
        }

        return $this->offen = $offen;
    }

    /**
     * Sind alle Rechtstexte gepflegt?
     */
    public function isReady(): bool
    {
        return $this->pending() === [];
    }

    /**
     * Titel der offenen Dokumente, fertig für einen Satz:
     * „Impressum und AGB“ bzw. „Impressum, Datenschutzerklärung und AGB“.
     */
    public function pendingLabel(): string
    {
        $namen = array_values($this->pending());

        if ($namen === []) {
            return '';
        }

        if (count($namen) === 1) {
            return $namen[0];
        }

        $letzter = array_pop($namen);

        return implode(', ', $namen).' und '.$letzter;
    }

    /**
     * Der Markdown-Quelltext eines Dokuments – erst von der Disk, sonst die
     * mitgelieferte Vorlage. Dieselbe Reihenfolge wie beim Ausliefern der
     * Seite, sonst beurteilt der Hinweis etwas anderes als der Besucher sieht.
     */
    public function markdown(string $key): string
    {
        $disk = Storage::disk('local');
        $pfad = "legal/{$key}.md";

        if ($disk->exists($pfad)) {
            return (string) $disk->get($pfad);
        }

        return $this->template($key);
    }

    /**
     * Die mitgelieferte Vorlage aus dem Repo.
     */
    private function template(string $key): string
    {
        $datei = resource_path("legal/{$key}.md");

        return is_file($datei) ? (string) file_get_contents($datei) : '';
    }

    /**
     * Zeilenumbrüche und Markdown-Zitatzeichen zu einfachen Leerzeichen
     * einebnen.
     *
     * Ohne das trifft kein Marker, der in der Vorlage umbrochen ist – und die
     * Warnkästen am Dateikopf sind genau das. Der Satz „Bitte ergänzen Sie die
     * in eckigen Klammern …“ steht in datenschutz.md über zwei Zeilen, mit
     * „> “ am Anfang der zweiten.
     */
    private function flatten(string $text): string
    {
        return (string) preg_replace('/\s*\R\s*>?\s*/u', ' ', $text);
    }

    private function isFilledIn(string $markdown, string $key): bool
    {
        $text = trim($markdown);

        if ($text === '') {
            return false;
        }

        $vergleich = $this->flatten($text);

        foreach (self::PLATZHALTER as $marker) {
            if (Str::contains($vergleich, $marker, ignoreCase: true)) {
                return false;
            }
        }

        // Zweites Netz für den Fall, dass die Vorlagen einmal geändert werden,
        // ohne die Markerliste mitzupflegen: Wortgleich mit der Vorlage heißt
        // in jedem Fall unausgefüllt.
        return $text !== trim($this->template($key));
    }
}
