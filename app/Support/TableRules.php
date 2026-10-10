<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Prüfregeln, Meldungen und Feldnamen für Räume, Tische, Tischkombinationen
 * und Raummaße. „Räume & Tische“, der Tischplan, der Einrichtungsassistent
 * und bis Teil 3 die alte Sammelseite speichern über dieselben Regeln; die
 * Grenzen stehen in config/swayy.php (tables.*).
 *
 * Die Meldungen sprechen von der kleinsten und der größten Personenzahl: Die
 * Formulare der einzelnen Seiten beschriften die Felder verschieden („Ab
 * Personen“, „Min.“, „Plätze min.“), alle meinen dieselben beiden Zahlen.
 */
final class TableRules
{
    /** Schalter eines Tisches. */
    public const TABLE_FLAGS = ['online_bookable', 'joinable', 'accessible', 'high_chair_possible', 'outdoor'];

    /**
     * Name und Personenzahl eines Tisches.
     *
     * @return array<string, list<string>>
     */
    public static function table(): array
    {
        $capacity = (int) config('swayy.tables.capacity_max');

        return [
            'name' => ['required', 'string', 'max:'.(int) config('swayy.tables.name_max')],
            'min_capacity' => ['required', 'integer', 'min:1', 'max:'.$capacity],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:'.$capacity, 'gte:min_capacity'],
        ];
    }

    /**
     * Schalter eines Tisches. Sie sind optional: Der Einrichtungsassistent und
     * die alte Sammelseite senden nicht alle, und beim Bearbeiten ändert nur,
     * was das Formular mitschickt.
     *
     * @return array<string, list<string>>
     */
    public static function tableFlags(): array
    {
        return array_fill_keys(self::TABLE_FLAGS, ['nullable', 'boolean']);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function room(): array
    {
        return [
            'name' => ['required', 'string', 'max:'.(int) config('swayy.tables.room_name_max')],
            'is_outdoor' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Name und Personenzahl einer Tischkombination; $capacityMax ist die
     * größte Zahl, die die Spalte fasst.
     *
     * @return array<string, list<string>>
     */
    public static function combination(int $capacityMax): array
    {
        return [
            'name' => ['required', 'string', 'max:'.(int) config('swayy.tables.combination_name_max')],
            'table_ids' => ['required', 'array', 'min:2'],
            'table_ids.*' => ['integer'],
            'min_capacity' => ['required', 'integer', 'min:1', 'max:'.$capacityMax],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:'.$capacityMax, 'gte:min_capacity'],
        ];
    }

    /**
     * Maße eines Raums im Tischplan: Meter oder Einheiten (1 Einheit = 1 cm).
     * Die Obergrenze kommt aus tables.room_size_max_m, höchstens so groß, wie
     * die smallint-Spalten plan_width und plan_height fassen.
     *
     * @return array<string, list<string>>
     */
    public static function roomSize(): array
    {
        $meters = (int) config('swayy.tables.room_size_max_m');
        $units = $meters * 100;

        return [
            'plan_width' => ['nullable', 'integer', 'min:200', 'max:'.$units],
            'plan_height' => ['nullable', 'integer', 'min:200', 'max:'.$units],
            'plan_width_m' => ['nullable', 'numeric', 'min:1', 'max:'.$meters],
            'plan_height_m' => ['nullable', 'numeric', 'min:1', 'max:'.$meters],
        ];
    }

    /**
     * Meldungen der Prüfung: Sie nennen die Ursache und den nächsten Schritt.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        $name = __('Bitte einen Namen eintragen.');
        $room = __('Bitte einen Raum wählen.');
        $smallest = __('Bitte die kleinste Personenzahl als ganze Zahl eintragen.');
        $largest = __('Bitte die größte Personenzahl als ganze Zahl eintragen.');
        $tables = __('Bitte mindestens zwei Tische für die Kombination wählen.');
        $width = __('Bitte die Breite in Metern als Zahl eintragen, etwa 8,5.');
        $depth = __('Bitte die Tiefe in Metern als Zahl eintragen, etwa 12,5.');

        $messages = [
            'name.required' => $name,
            'name.string' => $name,
            'name.max' => __('Der Name darf höchstens :max Zeichen lang sein. Bitte kürze ihn.'),
            'room_id.required' => $room,
            'room_id.integer' => $room,
            'min_capacity.required' => $smallest,
            'min_capacity.integer' => $smallest,
            'min_capacity.min' => __('Die kleinste Personenzahl muss mindestens :min betragen. Bitte eine größere Zahl eintragen.'),
            'min_capacity.max' => __('Die kleinste Personenzahl darf höchstens :max betragen. Bitte eine kleinere Zahl eintragen.'),
            'max_capacity.required' => $largest,
            'max_capacity.integer' => $largest,
            'max_capacity.min' => __('Die größte Personenzahl muss mindestens :min betragen. Bitte eine größere Zahl eintragen.'),
            'max_capacity.max' => __('Die größte Personenzahl darf höchstens :max betragen. Bitte eine kleinere Zahl eintragen.'),
            'max_capacity.gte' => __('Die größte Personenzahl ist kleiner als die kleinste. Bitte mindestens :value eintragen.'),
            'table_ids.required' => $tables,
            'table_ids.array' => $tables,
            'table_ids.min' => $tables,
            'table_ids.*.integer' => __('Mindestens ein gewählter Tisch ist ungültig. Bitte die Seite neu laden und die Tische neu wählen.'),
            'plan_width_m.numeric' => $width,
            'plan_width_m.min' => __('Die Breite muss mindestens :min Meter betragen. Bitte eine größere Zahl eintragen.'),
            'plan_width_m.max' => __('Die Breite darf höchstens :max Meter betragen. Bitte eine kleinere Zahl eintragen.'),
            'plan_height_m.numeric' => $depth,
            'plan_height_m.min' => __('Die Tiefe muss mindestens :min Meter betragen. Bitte eine größere Zahl eintragen.'),
            'plan_height_m.max' => __('Die Tiefe darf höchstens :max Meter betragen. Bitte eine kleinere Zahl eintragen.'),
            'plan_width.integer' => __('Die Breite im Plan muss eine ganze Zahl in Zentimetern sein.'),
            'plan_width.min' => __('Die Breite im Plan muss mindestens :min Zentimeter betragen. Bitte eine größere Zahl eintragen.'),
            'plan_width.max' => __('Die Breite im Plan darf höchstens :max Zentimeter betragen. Bitte eine kleinere Zahl eintragen.'),
            'plan_height.integer' => __('Die Tiefe im Plan muss eine ganze Zahl in Zentimetern sein.'),
            'plan_height.min' => __('Die Tiefe im Plan muss mindestens :min Zentimeter betragen. Bitte eine größere Zahl eintragen.'),
            'plan_height.max' => __('Die Tiefe im Plan darf höchstens :max Zentimeter betragen. Bitte eine kleinere Zahl eintragen.'),
        ];

        foreach ([...self::TABLE_FLAGS, 'is_outdoor', 'head_seats_enabled'] as $flag) {
            $messages["{$flag}.boolean"] = __('Bitte „:attribute“ ein- oder ausschalten.');
        }

        return $messages;
    }

    /**
     * Feldnamen, wie sie in den Meldungen stehen.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'room_id' => __('Raum'),
            'is_outdoor' => __('Draußen'),
            'outdoor' => __('Draußen'),
            'online_bookable' => __('Online buchbar'),
            'joinable' => __('Kombinierbar'),
            'accessible' => __('Barrierefrei'),
            'high_chair_possible' => __('Kinderstuhl möglich'),
            'head_seats_enabled' => __('Stühle an den Stirnseiten'),
            'plan_width_m' => __('Raumbreite (m)'),
            'plan_height_m' => __('Raumtiefe (m)'),
            'plan_width' => __('Breite im Plan'),
            'plan_height' => __('Tiefe im Plan'),
        ];
    }
}
