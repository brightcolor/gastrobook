<?php

declare(strict_types=1);

namespace App\Mail\Layout;

/**
 * Zerlegt den Text einer Mail in Bausteine (Spec 2026-10-10, „Struktur aus
 * dem Text“). Betriebe schreiben ihre Vorlagen als einfachen Text; erkannt
 * werden Angaben-Zeilen (Tabelle), Link-Zeilen (Knopf) und Trennlinien.
 * Alles andere bleibt Absatz, nichts geht verloren.
 */
final class TextBlocks
{
    private const LINK_ONLY = '~^https?://\S+$~i';

    private const LINK_LINE = '~^(.+?):\s*(https?://\S+)$~iu';

    private const DIVIDER = '/^\s*([-—=])\1{2,}\s*$/u';

    /**
     * Adresse, die ein Knopf werden darf: Schema, schlichter Host, Port,
     * Rest ohne Leerraum und ohne „\“. Kein „@“ in der Authority: Browser
     * lesen „\“ als „/“, „https://evil.example\@swayy.de“ führt sonst zu
     * evil.example, während parse_url swayy.de meldet.
     */
    private const SAFE_URL = '~^https?://([a-z0-9](?:[a-z0-9.-]*[a-z0-9])?)(?::\d{1,5})?(?:[/?#][^\s\\\\]*)?$~i';

    /**
     * @param  list<string>  $buttonHosts  leer: jeder Host darf einen Knopf ergeben
     */
    public function __construct(
        private readonly int $minRows,
        private readonly int $labelMax,
        private readonly int $buttonLabelMax,
        private readonly ?string $unsubscribeUrl = null,
        private readonly array $buttonHosts = [],
    ) {}

    /**
     * @param  list<string>  $buttonHosts
     */
    public static function fromConfig(?string $unsubscribeUrl = null, array $buttonHosts = []): self
    {
        return new self(
            (int) config('swayy.mail.details_min_rows', 2),
            (int) config('swayy.mail.label_max', 40),
            (int) config('swayy.mail.button_label_max', 60),
            $unsubscribeUrl,
            $buttonHosts,
        );
    }

    /**
     * Einheitliche Zeilenenden, keine Leerzeichen am Zeilenende, höchstens
     * eine Leerzeile am Stück, nichts Leeres am Ende.
     */
    public static function normalize(string $text): string
    {
        $out = [];
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            $line = rtrim($line);
            if ($line === '' && ($out === [] || end($out) === '')) {
                continue;
            }
            $out[] = $line;
        }
        while ($out !== [] && end($out) === '') {
            array_pop($out);
        }

        return implode("\n", $out);
    }

    /**
     * @return list<Block>
     */
    public function parse(string $text): array
    {
        $blocks = [];
        foreach ($this->chunks(self::normalize($text)) as $lines) {
            foreach ($this->blocksFor($lines) as $block) {
                $blocks[] = $block;
            }
        }

        return $this->finish($blocks);
    }

    /**
     * Zeilengruppen, getrennt an Leerzeilen; eine Trennlinie ist immer eine
     * eigene Gruppe.
     *
     * @return list<list<string>>
     */
    private function chunks(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $chunks = [];
        $current = [];
        foreach (explode("\n", $text) as $line) {
            $divider = preg_match(self::DIVIDER, $line) === 1;
            if ($line === '' || $divider) {
                if ($current !== []) {
                    $chunks[] = $current;
                    $current = [];
                }
                if ($divider) {
                    $chunks[] = [$line];
                }

                continue;
            }
            $current[] = $line;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * @param  list<string>  $lines
     * @return list<Block>
     */
    private function blocksFor(array $lines): array
    {
        if (count($lines) === 1 && preg_match(self::DIVIDER, $lines[0]) === 1) {
            return [Block::divider()];
        }

        $buttons = [];
        $rest = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = trim($lines[$i]);
            $next = $i + 1 < $count ? trim($lines[$i + 1]) : null;

            if (preg_match(self::LINK_LINE, $line, $m) === 1 && $this->isButton($m[1], $m[2])) {
                if (! $this->isUnsubscribe($m[2])) {
                    $buttons[] = Block::button(trim($m[1]), $m[2], false);
                }

                continue;
            }

            if ($next !== null && str_ends_with($line, ':') && preg_match(self::LINK_ONLY, $next) === 1
                && $this->isButton(substr($line, 0, -1), $next)) {
                if (! $this->isUnsubscribe($next)) {
                    $buttons[] = Block::button(trim(substr($line, 0, -1)), $next, false);
                }
                $i++;

                continue;
            }

            if ($this->isUnsubscribeLine($line)) {
                continue;
            }

            $rest[] = $lines[$i];
        }

        $blocks = [];
        if ($rest !== []) {
            $rows = $this->rows($rest);
            $blocks[] = $rows !== null ? Block::details($rows) : Block::paragraph(implode("\n", $rest));
        }

        return [...$blocks, ...$buttons];
    }

    private function isButton(string $label, string $url): bool
    {
        $label = trim($label);
        $length = mb_strlen($label);
        if ($length < 2 || $length > $this->buttonLabelMax || preg_match('~https?://~i', $label) === 1) {
            return false;
        }
        if ($this->isUnsubscribe($url)) {
            return true;
        }
        if (preg_match(self::SAFE_URL, $url, $m) !== 1) {
            return false;
        }

        return $this->buttonHosts === [] || in_array(strtolower($m[1]), $this->buttonHosts, true);
    }

    private function isUnsubscribe(string $url): bool
    {
        return $this->unsubscribeUrl !== null && $url === $this->unsubscribeUrl;
    }

    /** Eine Zeile, die auf den Abmeldelink endet, erscheint nur im Fußbereich (auch mit langer Beschriftung). */
    private function isUnsubscribeLine(string $line): bool
    {
        return $this->unsubscribeUrl !== null && str_ends_with($line, ': '.$this->unsubscribeUrl);
    }

    /**
     * @param  list<string>  $lines
     * @return list<array{label: string, value: string}>|null
     */
    private function rows(array $lines): ?array
    {
        if (count($lines) < $this->minRows) {
            return null;
        }

        $rows = [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*([^:]+?)\s*:\s+(\S.*?)\s*$/u', $line, $m) !== 1
                || mb_strlen($m[1]) > $this->labelMax
                || preg_match('~https?://~i', $m[2]) === 1) {
                return null;
            }
            $rows[] = ['label' => $m[1], 'value' => (string) preg_replace('/\s{2,}/u', ' ', $m[2])];
        }

        return $rows;
    }

    /**
     * Erster Knopf ist der Hauptknopf; Trennlinien am Anfang, am Ende und
     * doppelte Trennlinien entfallen.
     *
     * @param  list<Block>  $blocks
     * @return list<Block>
     */
    private function finish(array $blocks): array
    {
        $out = [];
        $primaryGiven = false;
        foreach ($blocks as $block) {
            if ($block->type === Block::BUTTON) {
                $out[] = Block::button($block->label, $block->url, ! $primaryGiven);
                $primaryGiven = true;

                continue;
            }
            if ($block->type === Block::DIVIDER && ($out === [] || $out[count($out) - 1]->type === Block::DIVIDER)) {
                continue;
            }
            $out[] = $block;
        }
        while ($out !== [] && $out[count($out) - 1]->type === Block::DIVIDER) {
            array_pop($out);
        }

        return $out;
    }
}
