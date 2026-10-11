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
    /** Minuten eines Tages; ein Fenster über Mitternacht endet am nächsten Tag. */
    private const DAY_MINUTES = 1440;

    /** Minuten einer Woche: Die Woche läuft im Kreis, ein Fenster am Sonntagabend reicht in den Montag. */
    private const WEEK_MINUTES = 10080;

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
     * Prüft die Fenster gegeneinander und liefert die Fehler nach Feld
     * ("hours.3.opens_at"). Der Schlüssel der Zeile bleibt, wie das Formular
     * ihn sendet (0, n1, c4). Zeilen mit unlesbarem Wochentag oder unlesbarer
     * Uhrzeit meldet schon die Prüfung der einzelnen Felder.
     *
     * - Öffnen und Schließen zur selben Uhrzeit: Fehler am Schließen.
     * - Mehr Fenster als opening_hours.windows_per_day_max an einem Tag:
     *   Fehler am ersten überzähligen Fenster.
     * - Überschneidung auf der Zeitachse: Ein Fenster über Mitternacht endet am
     *   nächsten Tag und kann sich mit dessen Fenstern überschneiden, der
     *   Sonntag mit dem Montag. Fenster am frühen Morgen und am späten Abend
     *   desselben Wochentags liegen dagegen weit auseinander. Der Fehler steht
     *   am Fenster, das auf der Zeitachse später beginnt.
     *
     * @param  array<array-key, mixed>  $rows  Zeilen mit weekday, opens_at, closes_at
     * @param  bool  $sameDay  alle Zeilen gehören zu einem Datum (Vorschau, dort ohne weekday): Die Zeitachse ist eine Gerade ohne Wochenkreis
     * @param  ?int  $maxWindows  Höchstzahl der Fenster je Tag; ohne Angabe gilt opening_hours.windows_per_day_max. Die Ausnahmen prüfen ihre eigene Grenze selbst und übergeben hier PHP_INT_MAX.
     * @return array<string, string>
     */
    public static function after(array $rows, string $prefix = 'hours', bool $sameDay = false, ?int $maxWindows = null): array
    {
        $max = $maxWindows ?? (int) config('swayy.opening_hours.windows_per_day_max');
        $errors = [];

        /** @var array<int, list<array{key: array-key, day: int, opens: ?int, closes: ?int}>> $days */
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
                'day' => $day,
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
        }

        foreach (self::overlaps(array_merge(...array_values($days)), weekly: ! $sameDay) as $key => $other) {
            $errors["{$prefix}.{$key}.opens_at"] ??= $other['day'] === null
                ? __('Dieses Zeitfenster überschneidet sich mit :window am selben Tag. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.', ['window' => $other['window']])
                : __('Dieses Zeitfenster überschneidet sich mit :day :window. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.', ['day' => $other['day'], 'window' => $other['window']]);
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
     * Fenster, die sich mit einem anderen Fenster überschneiden, auf der
     * Zeitachse der Woche (Montag 0:00 bis Sonntag 24:00, im Kreis) oder, bei
     * $weekly = false, auf einer Geraden für ein einzelnes Datum. Ein Fenster
     * beginnt zur Öffnungszeit seines Tages und dauert bis zur Schließzeit,
     * über Mitternacht bis zum nächsten Tag. Ergebnis: Schlüssel der Zeile, die
     * später beginnt => das andere Fenster mit Zeitraum ("11:00–15:00") und,
     * wenn es an einem anderen Wochentag beginnt, dessen Name. Je Fenster zählt
     * der erste Treffer; Fenster mit gleicher Uhrzeit zum Öffnen und Schließen
     * bleiben außen vor (eigener Fehler).
     *
     * @param  list<array{key: array-key, day: int, opens: ?int, closes: ?int}>  $windows
     * @return array<array-key, array{window: string, day: ?string}>
     */
    private static function overlaps(array $windows, bool $weekly): array
    {
        $line = [];
        foreach ($windows as $order => $window) {
            if ($window['opens'] === null || $window['closes'] === null || $window['opens'] === $window['closes']) {
                continue;
            }
            $start = ($weekly ? $window['day'] * self::DAY_MINUTES : 0) + $window['opens'];
            $length = $window['closes'] > $window['opens'] ? $window['closes'] - $window['opens'] : $window['closes'] - $window['opens'] + self::DAY_MINUTES;
            $line[] = $window + ['order' => $order, 'start' => $start, 'end' => $start + $length];
        }
        usort($line, fn (array $a, array $b) => [$a['start'], $a['order']] <=> [$b['start'], $b['order']]);

        // Im Kreis der Woche liegt jedes Fenster auch eine Woche früher und eine Woche später.
        $shifts = $weekly ? [0, self::WEEK_MINUTES, -self::WEEK_MINUTES] : [0];

        $found = [];
        foreach ($line as $i => $a) {
            foreach (array_slice($line, $i + 1) as $b) {
                foreach ($shifts as $shift) {
                    if ($a['start'] >= $b['end'] + $shift || $a['end'] <= $b['start'] + $shift) {
                        continue;
                    }
                    // Das Fenster, das auf der Zeitachse später beginnt, trägt den Fehler und nennt das andere.
                    [$later, $earlier] = [$b['start'] + $shift, $b['order']] > [$a['start'], $a['order']] ? [$b, $a] : [$a, $b];
                    $found[$later['key']] ??= [
                        'window' => self::clock($earlier['opens']).'–'.self::clock($earlier['closes']),
                        'day' => $weekly && $earlier['day'] !== $later['day'] ? OpeningHoursPreview::WEEKDAYS[$earlier['day']] : null,
                    ];
                    break;
                }
            }
        }

        return $found;
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
