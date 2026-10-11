<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prüfregeln und Meldungen für Öffnungszeiten und Saison. Die Seite
 * „Öffnungszeiten“, der Einrichtungsassistent und die alte Sammelseite
 * speichern über dieselben Regeln, die Vorschau prüft ihre Fenster ebenso.
 * Die Grenzen stehen in config/swayy.php (opening_hours.*).
 *
 * Neben den Regeln für einzelne Felder prüft after() die Fenster eines Tages
 * gegeneinander: gleiche Uhrzeit, zu viele Fenster, Überschneidung.
 */
final class OpeningHoursRules
{
    /** Minuten eines Tages: Fenster über Mitternacht zählen bis hierher und ab 0 weiter. */
    private const DAY_MINUTES = 1440;

    /**
     * Regeln für die Woche beim Speichern.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return ['hours' => ['present', 'array'], 'hours.*' => ['array']] + self::fieldRules('hours.*.', withWeekday: true);
    }

    /**
     * Regeln für die Vorschau: ein Wochentag und die Fenster dieses Tages.
     *
     * @return array<string, list<string>>
     */
    public static function windowRules(): array
    {
        return [
            'weekday' => ['required', 'integer', 'between:0,6'],
            'windows' => ['present', 'array'],
            'windows.*' => ['array'],
        ] + self::fieldRules('windows.*.', withWeekday: false);
    }

    /**
     * Meldungen der Prüfung für eine Liste mit dem Namen $prefix ("hours"
     * beim Speichern, "windows" in der Vorschau). Sie nennen die Ursache und
     * den nächsten Schritt; jede Regel hat einen eigenen Text, damit keine
     * Standardmeldung von Laravel erscheint.
     *
     * @return array<string, string>
     */
    public static function messages(string $prefix = 'hours'): array
    {
        $format = __('Die Zeitfenster haben ein unbekanntes Format. Bitte die Seite neu laden und noch einmal speichern.');
        $weekday = __('Dieser Wochentag ist unbekannt. Bitte die Seite neu laden.');
        $time = __('Bitte die Uhrzeit als HH:MM eintragen, etwa 11:30.');

        $messages = [
            "{$prefix}.present" => $prefix === 'hours'
                ? __('Die Öffnungszeiten fehlen in der Anfrage. Bitte die Seite neu laden und noch einmal speichern.')
                : __('Die Zeitfenster fehlen in der Anfrage. Bitte die Seite neu laden.'),
            "{$prefix}.array" => $format,
            "{$prefix}.*.array" => __('Ein Zeitfenster hat ein unbekanntes Format. Bitte die Seite neu laden und noch einmal speichern.'),
            "{$prefix}.*.opens_at.required" => __('Bitte bei jedem Zeitfenster eintragen, wann es beginnt.'),
            "{$prefix}.*.opens_at.date_format" => $time,
            "{$prefix}.*.closes_at.required" => __('Bitte bei jedem Zeitfenster eintragen, wann es endet.'),
            "{$prefix}.*.closes_at.date_format" => $time,
            "{$prefix}.*.service_name.string" => __('Bitte die Bezeichnung als Text eintragen.'),
            "{$prefix}.*.service_name.max" => __('Die Bezeichnung darf höchstens :max Zeichen haben. Bitte kürzer fassen.'),
        ];

        $weekdayKeys = $prefix === 'hours' ? 'hours.*.weekday' : 'weekday';
        foreach (['required', 'integer', 'between'] as $rule) {
            $messages["{$weekdayKeys}.{$rule}"] = $weekday;
        }

        return $messages;
    }

    /**
     * Prüft die Fenster eines Tages gegeneinander und liefert die Fehler nach
     * Feld ("hours.3.opens_at"). Der Schlüssel der Zeile bleibt, wie das
     * Formular ihn sendet (0, n1, c4). Zeilen mit unlesbarem Wochentag oder
     * unlesbarer Uhrzeit meldet schon die Prüfung der einzelnen Felder.
     *
     * - Öffnen und Schließen zur selben Uhrzeit: Fehler am Schließen.
     * - Mehr Fenster als opening_hours.windows_per_day_max an einem Tag:
     *   Fehler am ersten überzähligen Fenster.
     * - Überschneidung (ein Fenster über Mitternacht reicht bis 24:00 und ab
     *   0:00 weiter): Fehler am Fenster mit dem späteren Beginn.
     *
     * @param  array<array-key, mixed>  $rows  Zeilen mit weekday, opens_at, closes_at
     * @param  bool  $sameDay  alle Zeilen gehören zu einem Tag (Vorschau, dort ohne weekday)
     * @return array<string, string>
     */
    public static function after(array $rows, string $prefix = 'hours', bool $sameDay = false): array
    {
        $max = (int) config('swayy.opening_hours.windows_per_day_max');
        $errors = [];

        /** @var array<int, list<array{key: array-key, opens: ?int, closes: ?int}>> $days */
        $days = [];
        foreach ($rows as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            $day = $sameDay ? 0 : self::weekday($row['weekday'] ?? null);
            if ($day === null) {
                continue;
            }
            $days[$day][] = [
                'key' => $key,
                'opens' => self::minutes($row['opens_at'] ?? null),
                'closes' => self::minutes($row['closes_at'] ?? null),
            ];
        }

        foreach ($days as $windows) {
            foreach ($windows as $window) {
                if ($window['opens'] !== null && $window['opens'] === $window['closes']) {
                    $errors["{$prefix}.{$window['key']}.closes_at"] = __('Öffnet und schließt zur selben Uhrzeit. Bitte eine andere Schließzeit wählen.');
                }
            }

            if (count($windows) > $max) {
                $errors["{$prefix}.{$windows[$max]['key']}.opens_at"] = __('An einem Tag gehen höchstens :max Zeitfenster. Bitte Fenster zusammenlegen.', ['max' => $max]);
            }

            foreach (self::overlaps($windows) as $key => $other) {
                $errors["{$prefix}.{$key}.opens_at"] ??= __('Dieses Zeitfenster überschneidet sich mit :window am selben Tag. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.', ['window' => $other]);
            }
        }

        return $errors;
    }

    /**
     * Regeln für die Saison (Zeitraum, der jedes Jahr gilt).
     *
     * @return array<string, list<string>>
     */
    public static function seasonRules(): array
    {
        return [
            'label' => ['nullable', 'string', 'max:'.(int) config('swayy.opening_hours.season_label_max')],
            'start_month' => ['required', 'integer', 'between:1,12'],
            'start_day' => ['required', 'integer', 'between:1,31'],
            'end_month' => ['required', 'integer', 'between:1,12'],
            'end_day' => ['required', 'integer', 'between:1,31'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function seasonMessages(): array
    {
        $month = __('Bitte einen Monat aus der Liste wählen.');
        $day = __('Bitte den Tag als Zahl von 1 bis 31 eintragen.');
        $messages = [
            'label.string' => __('Bitte die Bezeichnung als Text eintragen.'),
            'label.max' => __('Die Bezeichnung darf höchstens :max Zeichen haben. Bitte kürzer fassen.'),
        ];
        foreach (['required', 'integer', 'between'] as $rule) {
            $messages["start_month.{$rule}"] = $month;
            $messages["end_month.{$rule}"] = $month;
            $messages["start_day.{$rule}"] = $day;
            $messages["end_day.{$rule}"] = $day;
        }

        return $messages;
    }

    /**
     * Regeln der Felder eines Zeitfensters unter $path ("hours.*.").
     *
     * @return array<string, list<string>>
     */
    private static function fieldRules(string $path, bool $withWeekday): array
    {
        $rules = [];
        if ($withWeekday) {
            $rules[$path.'weekday'] = ['required', 'integer', 'between:0,6'];
        }

        return $rules + [
            $path.'opens_at' => ['required', 'date_format:H:i'],
            $path.'closes_at' => ['required', 'date_format:H:i'],
            $path.'service_name' => ['nullable', 'string', 'max:'.(int) config('swayy.opening_hours.name_max')],
        ];
    }

    /**
     * Fenster, die sich mit einem Fenster davor überschneiden: Schlüssel der
     * Zeile => Zeitraum des früheren Fensters ("11:00–15:00"). Je Fenster
     * zählt der erste Treffer; Fenster mit gleicher Uhrzeit zum Öffnen und
     * Schließen bleiben außen vor (eigener Fehler).
     *
     * @param  list<array{key: array-key, opens: ?int, closes: ?int}>  $windows
     * @return array<array-key, string>
     */
    private static function overlaps(array $windows): array
    {
        $valid = array_values(array_filter($windows, fn (array $w) => $w['opens'] !== null && $w['closes'] !== null && $w['opens'] !== $w['closes']));
        usort($valid, fn (array $a, array $b) => $a['opens'] <=> $b['opens']);

        $found = [];
        foreach ($valid as $i => $window) {
            for ($j = 0; $j < $i; $j++) {
                if (self::intersect(self::segments($valid[$j]['opens'], $valid[$j]['closes']), self::segments($window['opens'], $window['closes']))) {
                    $found[$window['key']] = self::clock($valid[$j]['opens']).'–'.self::clock($valid[$j]['closes']);
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Ein Fenster als Minutenbereiche des Tages; über Mitternacht sind es zwei.
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function segments(int $opens, int $closes): array
    {
        return $closes > $opens ? [[$opens, $closes]] : [[$opens, self::DAY_MINUTES], [0, $closes]];
    }

    /**
     * @param  list<array{0: int, 1: int}>  $a
     * @param  list<array{0: int, 1: int}>  $b
     */
    private static function intersect(array $a, array $b): bool
    {
        foreach ($a as [$aStart, $aEnd]) {
            foreach ($b as [$bStart, $bEnd]) {
                if ($aStart < $bEnd && $bStart < $aEnd) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function weekday(mixed $value): ?int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return is_int($value) && $value >= 0 && $value <= 6 ? $value : null;
    }

    /** Minuten seit Mitternacht für "HH:MM", sonst null. */
    private static function minutes(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $value, $match) !== 1) {
            return null;
        }

        return (int) $match[1] * 60 + (int) $match[2];
    }

    private static function clock(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
