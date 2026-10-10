<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LocationSettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Prüfregeln der Buchungseinstellungen eines Standorts, gemeinsam für die
 * Seiten „Kapazität & Dauer“ und „Buchungsregeln“ und bis Teil 3 für die
 * alte Sammelseite. Die Grenzen stehen in config/swayy.php (booking.*).
 */
final class BookingSettingRules
{
    /**
     * Takt der Uhrzeiten: die Auswahl aus den Einstellungen und der
     * gespeicherte Wert, damit ein Standort mit altem Takt weiter speichert.
     *
     * @return list<int>
     */
    public static function slotIntervals(?LocationSettings $settings = null): array
    {
        $intervals = array_map('intval', (array) config('swayy.booking.slot_intervals'));
        if ($settings !== null && $settings->slot_interval_minutes !== null) {
            $intervals[] = (int) $settings->slot_interval_minutes;
        }
        $intervals = array_values(array_unique($intervals));
        sort($intervals);

        return $intervals;
    }

    /**
     * Kapazität & Dauer. Restaurants: Takt, Standarddauer, Puffer,
     * Tischzeiten, Zählweise und Höchstzahl. Salons: Takt, Puffer und
     * Lückenoptimierung; Dauer und Plätze ergeben sich aus Leistungen und
     * Personen.
     *
     * @return array<string, list<mixed>>
     */
    public static function capacity(?LocationSettings $settings, bool $salon): array
    {
        $limits = (array) config('swayy.booking');
        $interval = ['slot_interval_minutes' => ['required', 'integer', Rule::in(self::slotIntervals($settings))]];
        $buffer = ['buffer_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['buffer_max']]];

        if ($salon) {
            return $interval + $buffer + ['gap_optimization_enabled' => ['required', 'boolean']];
        }

        // Reihenfolge wie auf der Seite: Der erste Fehler der Meldung steht oben im Formular.
        return $interval + [
            'default_duration_minutes' => ['required', 'integer', 'min:'.$limits['duration_min'], 'max:'.$limits['duration_max']],
        ] + $buffer + [
            'capacity_mode' => ['required', 'in:table,person,hybrid'],
            'max_covers_per_slot' => ['nullable', 'integer', 'min:1', 'max:'.$limits['covers_max']],
            'duration_rules' => ['nullable', 'array', 'max:'.$limits['duration_rules_max']],
            'duration_rules.*.min_party' => ['nullable', 'integer', 'min:1', 'max:'.$limits['rule_party_max']],
            'duration_rules.*.max_party' => ['nullable', 'integer', 'min:1', 'max:'.$limits['rule_party_max']],
            'duration_rules.*.duration' => ['nullable', 'integer', 'min:'.$limits['rule_duration_min'], 'max:'.$limits['rule_duration_max']],
        ];
    }

    /**
     * Buchungsregeln: Vorlauf, Vorausbuchung, Gruppengröße online, Fristen,
     * Bestätigung, Warteliste und Walk-ins. Die Schalter sind Pflichtfelder;
     * x-checkbox sendet ohne Haken eine 0.
     *
     * @return array<string, list<mixed>>
     */
    public static function booking(): array
    {
        $limits = (array) config('swayy.booking');

        return [
            'min_lead_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['lead_max_minutes']],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:'.$limits['advance_max_days']],
            'min_party_online' => ['required', 'integer', 'min:1', 'max:'.$limits['party_online_max']],
            'max_party_online' => ['required', 'integer', 'min:1', 'max:'.$limits['party_online_max'], 'gte:min_party_online'],
            'large_group_email' => ['nullable', 'email:rfc', 'max:200'],
            'cancellation_deadline_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['deadline_max_minutes']],
            'modification_deadline_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['deadline_max_minutes']],
            'booking_confirmation_mode' => ['required', 'in:auto,manual'],
            'require_email_confirmation' => ['required', 'boolean'],
            'waitlist_enabled' => ['required', 'boolean'],
            'walkins_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'duration_rules.max' => __('Die Zahl der Tischzeiten ist auf :max begrenzt. Bitte Gruppengrößen zusammenfassen.'),
            'duration_rules.*.duration.min' => __('Eine Tischzeit muss mindestens :min Minuten betragen.'),
            'duration_rules.*.duration.max' => __('Eine Tischzeit darf höchstens :max Minuten betragen.'),
            'max_party_online.gte' => __('Die größte Gruppe ist kleiner als die kleinste. Bitte bei „Größte Gruppe“ mindestens :value eintragen.'),
        ];
    }

    /**
     * Feldnamen, wie sie auf den neuen Seiten stehen.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'slot_interval_minutes' => __('Takt der Uhrzeiten'),
            'default_duration_minutes' => __('Standarddauer'),
            'buffer_minutes' => __('Puffer nach jeder Buchung'),
            'capacity_mode' => __('Zählweise'),
            'max_covers_per_slot' => __('Höchstens gleichzeitig'),
            'gap_optimization_enabled' => __('Lückenoptimierung'),
            'duration_rules' => __('Tischzeiten'),
            'duration_rules.*.min_party' => __('Ab Personen'),
            'duration_rules.*.max_party' => __('Bis Personen'),
            'duration_rules.*.duration' => __('Tischzeit'),
            'min_lead_minutes' => __('Mindestvorlauf'),
            'max_advance_days' => __('Vorausbuchung'),
            'min_party_online' => __('Kleinste Gruppe'),
            'max_party_online' => __('Größte Gruppe'),
            'large_group_email' => __('Adresse für größere Gruppen'),
            'cancellation_deadline_minutes' => __('Stornofrist'),
            'modification_deadline_minutes' => __('Umbuchungsfrist'),
            'booking_confirmation_mode' => __('Online-Buchungen'),
            'require_email_confirmation' => __('E-Mail-Adresse bestätigen lassen'),
            'waitlist_enabled' => __('Warteliste anbieten'),
            'walkins_enabled' => __('Walk-ins erfassen'),
        ];
    }

    /**
     * Tischzeiten je Gruppengröße („2–4 Personen → 90 Minuten“), sortiert
     * nach Gruppengröße. LocationSettings::durationFor() nimmt die erste
     * passende Regel; Überschneidungen werden deshalb abgelehnt, sonst griffe
     * eine Regel weiter unten still nie. Leere Zeilen fallen weg.
     *
     * $atField: Der Fehler nennt das Feld der Zeile („duration_rules.n2.min_party“),
     * sonst steht er unter „duration_rules“ (alte Sammelseite).
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<array{min_party: int, max_party: int, duration: int}>
     */
    public static function durationRules(array $rows, bool $atField = true): array
    {
        $rules = [];

        foreach ($rows as $key => $row) {
            $row = is_array($row) ? $row : [];
            $min = (int) ($row['min_party'] ?? 0);
            $max = (int) ($row['max_party'] ?? 0);
            $duration = (int) ($row['duration'] ?? 0);

            if ($min < 1 && $max < 1 && $duration < 1) {
                continue;
            }

            if ($min < 1 || $max < 1 || $duration < 1) {
                $field = $min < 1 ? 'min_party' : ($max < 1 ? 'max_party' : 'duration');
                self::fail($atField ? "duration_rules.{$key}.{$field}" : 'duration_rules',
                    __('Bitte bei jeder Tischzeit „Ab“, „Bis“ und Minuten ausfüllen oder die Zeile entfernen.'));
            }

            if ($max < $min) {
                self::fail($atField ? "duration_rules.{$key}.max_party" : 'duration_rules',
                    __('Bei „:min bis :max Personen“ ist die Bis-Zahl kleiner als die Ab-Zahl. Bitte die Zahlen tauschen.', ['min' => $min, 'max' => $max]));
            }

            $rules[] = ['key' => (string) $key, 'min_party' => $min, 'max_party' => $max, 'duration' => $duration];
        }

        usort($rules, fn (array $a, array $b) => $a['min_party'] <=> $b['min_party']);

        foreach ($rules as $i => $rule) {
            $previous = $rules[$i - 1] ?? null;
            if ($previous !== null && $rule['min_party'] <= $previous['max_party']) {
                self::fail($atField ? "duration_rules.{$rule['key']}.min_party" : 'duration_rules', __(
                    'Die Gruppengrößen überschneiden sich: „:a“ und „:b“. Jede Gruppengröße darf nur in einer Regel vorkommen.',
                    ['a' => $previous['min_party'].'–'.$previous['max_party'], 'b' => $rule['min_party'].'–'.$rule['max_party']],
                ));
            }
        }

        return array_map(
            fn (array $rule) => ['min_party' => $rule['min_party'], 'max_party' => $rule['max_party'], 'duration' => $rule['duration']],
            $rules,
        );
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
