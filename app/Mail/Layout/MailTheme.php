<?php

declare(strict_types=1);

namespace App\Mail\Layout;

use Illuminate\Support\Facades\Log;

/**
 * Farben einer Mail aus der Markenfarbe.
 *
 * Knopf und Links halten den Mindestkontrast aus swayy.mail.contrast_min:
 * Eine zu helle Markenfarbe wird abgedunkelt, im Dunkelmodus die Linkfarbe
 * aufgehellt. Fläche und Haarlinien sind Mischungen mit dem Kartengrund.
 */
final class MailTheme
{
    private function __construct(public readonly string $brand) {}

    public static function fromBrand(?string $color): self
    {
        $default = strtolower((string) config('swayy.ui.default_brand_color', '#0f766e'));

        if ($color === null || trim($color) === '') {
            return new self($default);
        }

        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            Log::warning("Markenfarbe „{$color}“ ist keine Farbe in der Schreibweise #rrggbb. Die Mail nutzt die Vorgabe {$default}. Bitte die Markenfarbe unter Einstellungen › Allgemein neu wählen.");

            return new self($default);
        }

        return new self(strtolower($color));
    }

    /** Fläche des Knopfs; die Knopfschrift (palette.on_brand) erreicht darauf den Mindestkontrast. */
    public function button(): string
    {
        return self::untilContrast($this->brand, '#000000', $this->palette('on_brand'), self::minimum());
    }

    /** Linkfarbe auf der hellen Karte. */
    public function link(): string
    {
        return self::untilContrast($this->brand, '#000000', $this->palette('card'), self::minimum());
    }

    /** Linkfarbe auf der dunklen Karte. */
    public function darkLink(): string
    {
        return self::untilContrast($this->brand, '#ffffff', $this->palette('dark_card'), self::minimum());
    }

    public function tint(): string
    {
        return self::mix($this->palette('card'), $this->brand, (float) config('swayy.mail.tint'));
    }

    public function line(): string
    {
        return self::mix($this->palette('card'), $this->brand, (float) config('swayy.mail.line'));
    }

    public function darkTint(): string
    {
        return self::mix($this->palette('dark_card'), $this->brand, (float) config('swayy.mail.dark_tint'));
    }

    public function darkLine(): string
    {
        return self::mix($this->palette('dark_card'), $this->brand, (float) config('swayy.mail.dark_line'));
    }

    public function palette(string $key): string
    {
        return (string) config("swayy.mail.palette.{$key}");
    }

    public static function minimum(): float
    {
        return (float) config('swayy.mail.contrast_min', 4.5);
    }

    /**
     * Mischt $color in 20 Schritten mit $toward, bis sie gegen $against den
     * Kontrast $min erreicht. Reicht auch die volle Mischung nicht, gilt $toward.
     */
    private static function untilContrast(string $color, string $toward, string $against, float $min): string
    {
        for ($step = 0; $step <= 20; $step++) {
            $candidate = self::mix($color, $toward, $step / 20);
            if (self::contrast($candidate, $against) >= $min) {
                return $candidate;
            }
        }

        return $toward;
    }

    public static function mix(string $from, string $to, float $share): string
    {
        $a = self::rgb($from);
        $b = self::rgb($to);
        $out = '#';
        foreach ([0, 1, 2] as $i) {
            $out .= sprintf('%02x', (int) round($a[$i] + ($b[$i] - $a[$i]) * $share));
        }

        return $out;
    }

    /** Kontrastverhältnis nach WCAG 2.2. */
    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = array_map(static function (int $channel): float {
            $s = $channel / 255;

            return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [
            (int) hexdec(substr($hex, 1, 2)),
            (int) hexdec(substr($hex, 3, 2)),
            (int) hexdec(substr($hex, 5, 2)),
        ];
    }
}
