<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Prüfregeln und Meldungen der Ausnahmen (Einstellungen › Platz & Zeiten ›
 * Ausnahmen). Anlegen, Bearbeiten und die Vorschau prüfen mit denselben
 * Regeln. Die Grenzen stehen in config/swayy.php (exceptions.*).
 *
 * rules() und messages() prüfen die einzelnen Felder. after() prüft danach
 * die Felder gegeneinander und gegen den Standort: Wirkungen, Zeitraum,
 * Zeitfenster, Räume und Tische sowie Uhrzeitpaare. Jede Meldung nennt die
 * Ursache und den nächsten Schritt.
 */
final class ScheduleExceptionRules
{
    /** Die Wirkungen einer Ausnahme in der Reihenfolge, in der Liste und Satz sie nennen. */
    public const EFFECTS = ['closed', 'hours', 'limit', 'lock'];

    /** Wirkungen im Salon: Gästegrenzen und Sperren von Räumen und Tischen gibt es dort nicht. */
    public const SALON_EFFECTS = ['closed', 'hours'];

    /**
     * Die Felder, die zu einer Wirkung gehören. Das Formular hält die Felder
     * aller Wirkungen im Seitentext und sendet sie mit; nur die Felder der
     * gewählten Wirkungen werden geprüft und gespeichert.
     */
    public const EFFECT_FIELDS = [
        'hours' => ['windows'],
        'limit' => ['limit_covers', 'limit_room_id', 'limit_from', 'limit_until'],
        'lock' => ['lock_rooms', 'lock_tables', 'lock_from', 'lock_until'],
    ];

    /** Rechte, die eine Ausnahme verlangt: Sie schreibt Sondertage und Sperrzeiten zusammen (Seite und Speicherwege). */
    public const PERMISSIONS = ['special_hours.manage', 'blackouts.manage'];

    /** Form des Schlüssels eines Eintrags in Adressen (Routen und Vorschau): g-{uuid}, s-{id}, b-{id}, t-{id}. */
    public const KEY_PATTERN = '[gsbt]-[A-Za-z0-9-]+';

    /**
     * Darf der Nutzer einen Tisch über „Sperren…“ sperren? Er braucht die Rechte
     * für Ausnahmen, und der Standort vergibt Tische (CapacityModes::ASSIGNING):
     * In „Nach Personen“ wirkt eine Tischsperre nicht auf Buchungen.
     */
    public static function canLockTables(User $user, Tenant $tenant, Location $location): bool
    {
        return self::canManage($user, $tenant, $location) && CapacityModes::assignsTables($location);
    }

    /**
     * Hat der Nutzer alle Rechte aus PERMISSIONS? Links auf die Seite
     * („Sperren…“ am Tisch) erscheinen nur dann.
     */
    public static function canManage(User $user, Tenant $tenant, ?Location $location): bool
    {
        foreach (self::PERMISSIONS as $permission) {
            if (! $user->canInTenant($permission, $tenant, $location)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Die Eingabe, begrenzt auf die Felder der gewählten Wirkungen. Ein leeres
     * Fenster oder eine leere Gästezahl in einem ausgeblendeten Abschnitt des
     * Formulars löst so keinen Fehler an einem Feld aus, das niemand sieht.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function selected(array $input): array
    {
        $effects = is_array($input['effects'] ?? null) ? $input['effects'] : [];
        foreach (self::EFFECT_FIELDS as $effect => $fields) {
            if (! in_array($effect, $effects, true)) {
                foreach ($fields as $field) {
                    unset($input[$field]);
                }
            }
        }

        return $input;
    }

    /**
     * Schlüssel eines Eintrags zerlegt: Art (g Gruppe, s Sondertag, b Sperrzeit,
     * t Tischsperre) und Wert (UUID der Gruppe oder Nummer der Zeile). Null,
     * wenn der Schlüssel keine dieser Formen hat. Die Prüfung schützt
     * PostgreSQL vor Werten, die eine UUID- oder Zahlenspalte mit einem
     * Datenbankfehler beantwortet.
     *
     * @return ?array{kind: string, value: string|int}
     */
    public static function parseKey(string $key): ?array
    {
        if (preg_match('/^([gsbt])-(.+)$/', $key, $match) !== 1) {
            return null;
        }
        [, $kind, $value] = $match;

        if ($kind === 'g') {
            return Str::isUuid($value) ? ['kind' => 'g', 'value' => strtolower($value)] : null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT);

        return $number === false || $number < 1 ? null : ['kind' => $kind, 'value' => $number];
    }

    /**
     * Die Zeitfenster aus den Feldern, nach Beginn sortiert.
     *
     * @param  array<string, mixed>  $data
     * @return list<array{opens_at: string, closes_at: string}>
     */
    public static function sortedWindows(array $data): array
    {
        $windows = [];
        foreach ((array) ($data['windows'] ?? []) as $window) {
            if (is_array($window) && isset($window['opens_at'], $window['closes_at'])) {
                $windows[] = ['opens_at' => (string) $window['opens_at'], 'closes_at' => (string) $window['closes_at']];
            }
        }
        usort($windows, fn (array $a, array $b) => $a['opens_at'] <=> $b['opens_at']);

        return $windows;
    }

    /**
     * Die eindeutigen Nummern aus einer Auswahlliste.
     *
     * @return list<int>
     */
    public static function ids(mixed $value): array
    {
        return array_values(array_unique(array_map('intval', array_filter((array) $value, 'is_scalar'))));
    }

    /**
     * Regeln für die Felder einer Ausnahme.
     *
     * @return array<string, list<string>>
     */
    public static function rules(bool $salon): array
    {
        $time = ['nullable', 'date_format:H:i'];

        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'until' => ['nullable', 'date_format:Y-m-d'],
            'label' => ['nullable', 'string', 'max:'.(int) config('swayy.exceptions.label_max')],
            'effects' => ['present', 'array'],
            'effects.*' => ['string', 'in:'.implode(',', $salon ? self::SALON_EFFECTS : self::EFFECTS)],
            'windows' => ['nullable', 'array', 'max:'.(int) config('swayy.exceptions.windows_max')],
            'windows.*' => ['array'],
            'windows.*.opens_at' => ['required', 'date_format:H:i'],
            'windows.*.closes_at' => ['required', 'date_format:H:i'],
            'limit_covers' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('swayy.exceptions.covers_max')],
            'limit_room_id' => ['nullable', 'integer'],
            'limit_from' => $time,
            'limit_until' => $time,
            'lock_rooms' => ['nullable', 'array'],
            'lock_rooms.*' => ['integer'],
            'lock_tables' => ['nullable', 'array'],
            'lock_tables.*' => ['integer'],
            'lock_from' => $time,
            'lock_until' => $time,
        ];
    }

    /**
     * Meldungen zu rules(): jede Regel mit eigenem deutschen Text, der die
     * Ursache und den nächsten Schritt nennt.
     *
     * @return array<string, string>
     */
    public static function messages(bool $salon): array
    {
        $guests = $salon ? __('Kunden') : __('Gäste');
        $date = __('Bitte das Datum als TT.MM.JJJJ wählen.');
        $time = __('Bitte die Uhrzeit als HH:MM eintragen, etwa 18:00.');
        $room = __('Dieser Raum ist unbekannt. Bitte die Seite neu laden und Räume aus der Liste wählen.');
        $format = __('Die Auswahl hat ein unbekanntes Format. Bitte die Seite neu laden.');
        $windows = __('Die Zeitfenster haben ein unbekanntes Format. Bitte die Seite neu laden und noch einmal speichern.');

        return [
            'from.required' => __('Bitte den ersten Tag eintragen.'),
            'from.date_format' => $date,
            'until.date_format' => $date,
            'label.string' => __('Bitte die Bezeichnung als Text eintragen.'),
            'label.max' => __('Die Bezeichnung darf höchstens :max Zeichen haben. Bitte kürzer fassen.'),
            'effects.present' => __('Bitte wählen, was an diesen Tagen anders ist.'),
            'effects.array' => $format,
            'effects.*.string' => __('Diese Wirkung ist unbekannt. Bitte die Seite neu laden.'),
            'effects.*.in' => $salon
                ? __('Diese Wirkung gibt es für Salons nicht. Bitte „Geschlossen“ oder „Andere Öffnungszeiten“ wählen.')
                : __('Diese Wirkung ist unbekannt. Bitte die Seite neu laden.'),
            'windows.array' => $windows,
            'windows.max' => __('Höchstens :max Zeitfenster. Bitte Fenster zusammenlegen.'),
            'windows.*.array' => __('Ein Zeitfenster hat ein unbekanntes Format. Bitte die Seite neu laden und noch einmal speichern.'),
            'windows.*.opens_at.required' => __('Bitte bei jedem Zeitfenster eintragen, wann es beginnt.'),
            'windows.*.opens_at.date_format' => $time,
            'windows.*.closes_at.required' => __('Bitte bei jedem Zeitfenster eintragen, wann es endet.'),
            'windows.*.closes_at.date_format' => $time,
            'limit_covers.integer' => __('Bitte die Zahl der :guests als ganze Zahl eintragen.', ['guests' => $guests]),
            'limit_covers.min' => __('Die Höchstzahl der :guests muss mindestens :min betragen. Bitte eine Zahl ab :min eintragen.', ['guests' => $guests]),
            'limit_covers.max' => __('Die Höchstzahl der :guests darf höchstens :max betragen. Bitte eine kleinere Zahl eintragen.', ['guests' => $guests]),
            'limit_room_id.integer' => __('Dieser Raum ist unbekannt. Bitte die Seite neu laden und einen Raum aus der Liste wählen.'),
            'limit_from.date_format' => $time,
            'limit_until.date_format' => $time,
            'lock_rooms.array' => $format,
            'lock_rooms.*.integer' => $room,
            'lock_tables.array' => $format,
            'lock_tables.*.integer' => __('Dieser Tisch ist unbekannt. Bitte die Seite neu laden und Tische aus der Liste wählen.'),
            'lock_from.date_format' => $time,
            'lock_until.date_format' => $time,
        ];
    }

    /**
     * Prüft die Felder gegeneinander und gegen den Standort. Das Ergebnis
     * nennt zu jedem Feld die erste Meldung ("until", "lock_tables",
     * "windows.1.opens_at"); leer heißt: alles in Ordnung. Felder einer nicht
     * gewählten Wirkung zählen nicht.
     *
     * - Wirkungen: mindestens eine; „Geschlossen“ steht allein.
     * - Zeitraum: Ende nicht vor dem Beginn, höchstens exceptions.max_days
     *   Tage, letzter Tag ab heute (Standortzeit, $today).
     * - Andere Öffnungszeiten: mindestens ein und höchstens
     *   exceptions.windows_max Fenster, ohne Überschneidung.
     * - Weniger Gäste: die Zahl und ein Raum dieses Standorts.
     * - Sperre: mindestens ein Raum oder Tisch dieses Standorts.
     * - Räume und Tische (Sperre, Raum bei „Weniger Gäste“) nur in den
     *   Zählweisen, in denen Swayy Tische vergibt (CapacityModes::ASSIGNING);
     *   in „Nach Personen“ kennt die Verfügbarkeit weder Räume noch Tische,
     *   und die Bestätigung wäre falsch.
     * - Uhrzeiten von Grenze und Sperre: beide oder keine, nicht gleich.
     *
     * @param  array<string, mixed>  $data  geprüfte Felder aus rules()
     * @return array<string, string>
     */
    public static function after(Location $location, array $data, bool $salon, CarbonImmutable $today): array
    {
        $errors = [];
        $effects = array_values(array_unique(array_filter((array) ($data['effects'] ?? []), 'is_string')));

        if ($effects === []) {
            $errors['effects'] = __('Bitte wählen, was an diesen Tagen anders ist.');
        } elseif (in_array('closed', $effects, true) && count($effects) > 1) {
            $errors['effects'] = __('„Geschlossen“ lässt sich mit keiner anderen Wirkung verbinden. Bitte nur „Geschlossen“ wählen oder es abwählen.');
        }

        self::checkPeriod($errors, $data, $today);

        if (in_array('hours', $effects, true)) {
            self::checkWindows($errors, $data);
        }
        if (in_array('limit', $effects, true)) {
            self::checkLimit($errors, $location, $data, $salon);
        }
        if (in_array('lock', $effects, true)) {
            self::checkLock($errors, $location, $data);
        }

        return $errors;
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $data
     */
    private static function checkPeriod(array &$errors, array $data, CarbonImmutable $today): void
    {
        $from = self::day($data['from'] ?? null);
        $until = self::day($data['until'] ?? null) ?? $from;
        if ($from === null || $until === null) {
            return;
        }

        // Ein Fehler am Ende; ohne Ende steht er am Beginn.
        $field = ($data['until'] ?? null) === null ? 'from' : 'until';
        $max = (int) config('swayy.exceptions.max_days');

        if ($until->lt($from)) {
            $errors['until'] = __('Das Ende liegt vor dem Beginn. Bitte ein späteres Datum wählen.');
        } elseif ((int) $from->diffInDays($until) + 1 > $max) {
            $errors[$field] = trans_choice(
                '{1} Eine Ausnahme umfasst höchstens :max Tag. Bitte den Zeitraum teilen.|[2,*] Eine Ausnahme umfasst höchstens :max Tage. Bitte den Zeitraum teilen.',
                $max,
                ['max' => $max],
            );
        } elseif ($until->toDateString() < $today->toDateString()) {
            $errors[$field] = __('Dieser Zeitraum liegt in der Vergangenheit. Bitte ein Datum ab heute wählen.');
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $data
     */
    private static function checkWindows(array &$errors, array $data): void
    {
        $windows = array_filter((array) ($data['windows'] ?? []), 'is_array');
        if ($windows === []) {
            $errors['windows'] = __('Bitte mindestens ein Zeitfenster eintragen.');

            return;
        }

        // Mehr Fenster als erlaubt weist schon rules() ab. Hier endet die Prüfung ebenfalls: Die Prüfung der
        // Überschneidung vergleicht Paare und darf nie über eine übergroße Liste laufen.
        $max = (int) config('swayy.exceptions.windows_max');
        if (count($windows) > $max) {
            $errors['windows'] = __('Höchstens :max Zeitfenster. Bitte Fenster zusammenlegen.', ['max' => $max]);

            return;
        }

        // Gleiche Uhrzeit und Überschneidung prüft die Öffnungszeiten-Regel auf der Zeitachse eines Datums.
        // Die Höchstzahl prüft diese Klasse mit ihrer eigenen Einstellung.
        foreach (OpeningHoursRules::after($windows, 'windows', sameDay: true, maxWindows: PHP_INT_MAX) as $field => $message) {
            $errors[$field] ??= $message;
        }
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $data
     */
    private static function checkLimit(array &$errors, Location $location, array $data, bool $salon): void
    {
        if (($data['limit_covers'] ?? null) === null) {
            $errors['limit_covers'] = $salon
                ? __('Bitte eintragen, wie viele Kunden gleichzeitig kommen dürfen.')
                : __('Bitte eintragen, wie viele Gäste gleichzeitig kommen dürfen.');
        }

        $roomId = $data['limit_room_id'] ?? null;
        if ($roomId !== null && ! CapacityModes::assignsTables($location)) {
            $errors['limit_room_id'] = __('Im Modus „:mode“ zählt Swayy die Gäste im ganzen Betrieb und vergibt keine Räume. Wähle „Ganzer Betrieb“ oder stelle unter Kapazität & Dauer auf „:tables“ um.', [
                'mode' => CapacityModes::label(CapacityModes::of($location)),
                'tables' => CapacityModes::label(CapacityModes::TABLE),
            ]);
        } elseif ($roomId !== null && ! $location->rooms()->whereKey((int) $roomId)->exists()) {
            $errors['limit_room_id'] = self::foreignRoom();
        }

        self::checkTimes($errors, $data, 'limit');
    }

    /**
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $data
     */
    private static function checkLock(array &$errors, Location $location, array $data): void
    {
        if (! CapacityModes::assignsTables($location)) {
            $errors['effects'] ??= __('Im Modus „:mode“ vergibt Swayy keine Tische. Nutze „Weniger Gäste“ für den ganzen Betrieb oder stelle unter Kapazität & Dauer auf „:tables“ um.', [
                'mode' => CapacityModes::label(CapacityModes::of($location)),
                'tables' => CapacityModes::label(CapacityModes::TABLE),
            ]);

            return;
        }

        $rooms = self::ids($data['lock_rooms'] ?? null);
        $tables = self::ids($data['lock_tables'] ?? null);

        if ($rooms === [] && $tables === []) {
            $errors['lock_rooms'] = __('Bitte mindestens einen Raum oder Tisch wählen.');
        }
        if ($rooms !== [] && $location->rooms()->whereIn('id', $rooms)->count() !== count($rooms)) {
            $errors['lock_rooms'] = self::foreignRoom();
        }
        if ($tables !== [] && $location->tables()->whereIn('id', $tables)->count() !== count($tables)) {
            $errors['lock_tables'] = __('Mindestens ein Tisch gehört nicht zu diesem Standort. Bitte die Seite neu laden und Tische aus der Liste wählen.');
        }

        self::checkTimes($errors, $data, 'lock');
    }

    /**
     * Beginn und Ende einer Grenze oder Sperre: beide oder keine, nicht gleich.
     *
     * @param  array<string, string>  $errors
     * @param  array<string, mixed>  $data
     */
    private static function checkTimes(array &$errors, array $data, string $prefix): void
    {
        $from = $data["{$prefix}_from"] ?? null;
        $until = $data["{$prefix}_until"] ?? null;

        if (($from === null) !== ($until === null)) {
            $errors[$from === null ? "{$prefix}_from" : "{$prefix}_until"] = __('Bitte beide Uhrzeiten eintragen oder beide leer lassen.');
        } elseif ($from !== null && $from === $until) {
            $errors["{$prefix}_until"] = __('Beginn und Ende sind gleich. Bitte ein anderes Ende wählen.');
        }
    }

    private static function foreignRoom(): string
    {
        return __('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.');
    }

    /** Ein Tag aus "Y-m-d" (als Datum ohne Zeitzone), sonst null. */
    private static function day(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $day = CarbonImmutable::createFromFormat('Y-m-d', $value, 'UTC');

        return $day === false ? null : $day->startOfDay();
    }
}
