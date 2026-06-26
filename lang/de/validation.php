<?php

declare(strict_types=1);

/*
 * Deutsche, verständliche Validierungsmeldungen. Klartext statt technischer
 * Begriffe – mit sprechenden Feldnamen (siehe 'attributes') und für die
 * wichtigsten Felder zusätzlichen Hinweisen/Lösungen (siehe 'custom').
 */

return [
    'accepted' => ':attribute muss akzeptiert werden.',
    'accepted_if' => ':attribute muss akzeptiert werden, wenn :other gleich :value ist.',
    'active_url' => ':attribute ist keine gültige Internetadresse.',
    'after' => ':attribute muss ein Datum nach :date sein.',
    'after_or_equal' => ':attribute muss ein Datum nach oder gleich :date sein.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Zahlen, Binde- und Unterstriche enthalten.',
    'alpha_num' => ':attribute darf nur Buchstaben und Zahlen enthalten.',
    'array' => ':attribute muss eine Auswahl sein.',
    'before' => ':attribute muss ein Datum vor :date sein.',
    'before_or_equal' => ':attribute muss ein Datum vor oder gleich :date sein.',
    'between' => [
        'array' => ':attribute muss zwischen :min und :max Einträge haben.',
        'file' => ':attribute muss zwischen :min und :max Kilobytes groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'boolean' => ':attribute muss „ja" oder „nein" sein.',
    'confirmed' => 'Die Bestätigung für :attribute stimmt nicht überein.',
    'current_password' => 'Das Passwort ist nicht korrekt.',
    'date' => ':attribute ist kein gültiges Datum.',
    'date_equals' => ':attribute muss dem Datum :date entsprechen.',
    'date_format' => ':attribute hat ein ungültiges Format.',
    'decimal' => ':attribute muss :decimal Nachkommastellen haben.',
    'declined' => ':attribute muss abgelehnt werden.',
    'different' => ':attribute und :other müssen sich unterscheiden.',
    'digits' => ':attribute muss :digits Ziffern lang sein.',
    'digits_between' => ':attribute muss zwischen :min und :max Ziffern lang sein.',
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein (z. B. name@beispiel.de).',
    'ends_with' => ':attribute muss mit einem der folgenden Werte enden: :values.',
    'enum' => 'Der gewählte Wert für :attribute ist ungültig.',
    'exists' => 'Der gewählte Wert für :attribute existiert nicht (mehr).',
    'file' => ':attribute muss eine Datei sein.',
    'filled' => ':attribute muss ausgefüllt sein.',
    'gt' => [
        'array' => ':attribute muss mehr als :value Einträge haben.',
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss länger als :value Zeichen sein.',
    ],
    'gte' => [
        'array' => ':attribute muss mindestens :value Einträge haben.',
        'numeric' => ':attribute muss mindestens :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],
    'image' => ':attribute muss ein Bild sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'ip' => ':attribute muss eine gültige IP-Adresse sein.',
    'json' => ':attribute muss gültiges JSON sein.',
    'lt' => [
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss kürzer als :value Zeichen sein.',
    ],
    'lte' => [
        'numeric' => ':attribute darf höchstens :value sein.',
        'string' => ':attribute darf höchstens :value Zeichen lang sein.',
    ],
    'max' => [
        'array' => ':attribute darf höchstens :max Einträge haben.',
        'file' => ':attribute darf höchstens :max Kilobytes groß sein.',
        'numeric' => ':attribute darf höchstens :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],
    'min' => [
        'array' => ':attribute muss mindestens :min Einträge haben.',
        'file' => ':attribute muss mindestens :min Kilobytes groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'not_in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'numeric' => ':attribute muss eine Zahl sein.',
    'present' => ':attribute muss vorhanden sein.',
    'prohibited' => ':attribute ist nicht erlaubt.',
    'regex' => ':attribute hat ein ungültiges Format.',
    'required' => ':attribute ist ein Pflichtfeld.',
    'required_if' => ':attribute ist ein Pflichtfeld, wenn :other gleich :value ist.',
    'required_unless' => ':attribute ist ein Pflichtfeld, außer wenn :other einen der Werte :values hat.',
    'required_with' => ':attribute ist ein Pflichtfeld, wenn :values vorhanden ist.',
    'required_without' => ':attribute ist ein Pflichtfeld, wenn :values nicht vorhanden ist.',
    'same' => ':attribute und :other müssen übereinstimmen.',
    'size' => [
        'array' => ':attribute muss genau :size Einträge enthalten.',
        'file' => ':attribute muss :size Kilobytes groß sein.',
        'numeric' => ':attribute muss genau :size sein.',
        'string' => ':attribute muss genau :size Zeichen lang sein.',
    ],
    'starts_with' => ':attribute muss mit einem der folgenden Werte beginnen: :values.',
    'string' => ':attribute muss Text sein.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'url' => ':attribute muss eine gültige Internetadresse sein.',

    /*
     * Feldspezifische Meldungen mit Hinweisen/Lösungen.
     */
    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss Groß- und Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':attribute ist in einem Datenleck aufgetaucht – bitte ein anderes wählen.',
    ],

    'custom' => [
        'email' => [
            'unique' => 'Für diese E-Mail-Adresse besteht bereits ein Konto. Bitte melden Sie sich an oder verwenden Sie eine andere Adresse.',
        ],
        'password' => [
            'min' => 'Das Passwort ist zu kurz – bitte mindestens :min Zeichen verwenden.',
        ],
        'privacy_accepted' => [
            'accepted' => 'Bitte stimmen Sie den Datenschutzhinweisen zu, um fortzufahren.',
        ],
        'party_size' => [
            'max' => 'Diese Personenzahl ist online nicht buchbar. Für größere Gruppen kontaktieren Sie uns bitte direkt.',
            'min' => 'Bitte geben Sie mindestens :min Person(en) an.',
        ],
        'time' => [
            'date_format' => 'Bitte wählen Sie eine gültige Uhrzeit aus den angebotenen Zeiten.',
        ],
        'date' => [
            'date_format' => 'Bitte wählen Sie ein gültiges Datum.',
        ],
        'service_ids' => [
            'required' => 'Bitte wählen Sie mindestens eine Leistung aus.',
            'min' => 'Bitte wählen Sie mindestens eine Leistung aus.',
        ],
        'refund_percent' => [
            'max' => 'Die Erstattung kann höchstens 100 % betragen.',
        ],
    ],

    /*
     * Sprechende Feldnamen.
     */
    'attributes' => [
        'name' => 'Name',
        'restaurant_name' => 'Betriebsname',
        'owner_name' => 'Name der Inhaberin/des Inhabers',
        'email' => 'E-Mail-Adresse',
        'owner_email' => 'E-Mail-Adresse der Inhaberin/des Inhabers',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwort-Bestätigung',
        'phone' => 'Telefonnummer',
        'date' => 'Datum',
        'time' => 'Uhrzeit',
        'party_size' => 'Personenzahl',
        'service_ids' => 'Leistungen',
        'service_ids.*' => 'Leistung',
        'staff_member_id' => 'Mitarbeiter:in',
        'service_id' => 'Leistung',
        'table_id' => 'Tisch',
        'table_ids' => 'Tische',
        'duration_minutes' => 'Dauer',
        'price_minor' => 'Preis',
        'min_capacity' => 'Mindestkapazität',
        'max_capacity' => 'Höchstkapazität',
        'note' => 'Anmerkung',
        'allergies' => 'Allergien',
        'occasion' => 'Anlass',
        'reason' => 'Grund',
        'privacy_accepted' => 'Datenschutz-Zustimmung',
        'newsletter' => 'Newsletter',
        'message' => 'Nachricht',
        'location_name' => 'Standortname',
        // Mitarbeiter / Abwesenheiten
        'weekday' => 'Wochentag',
        'starts_at' => 'Beginn',
        'ends_at' => 'Ende',
        'starts_on' => 'Beginn (Datum)',
        'starts_time' => 'Beginn (Uhrzeit)',
        'ends_on' => 'Ende (Datum)',
        'ends_time' => 'Ende (Uhrzeit)',
        'bio' => 'Kurzbiografie',
        'color' => 'Farbe',
        'description' => 'Beschreibung',
        // Buchungsregeln / Einstellungen
        'slot_interval_minutes' => 'Slot-Intervall',
        'default_duration_minutes' => 'Standarddauer',
        'buffer_minutes' => 'Puffer',
        'min_lead_minutes' => 'Mindestvorlauf',
        'max_advance_days' => 'Max. Vorausbuchung',
        'min_party_online' => 'Min. Personen (online)',
        'max_party_online' => 'Max. Personen (online)',
        'max_covers_per_slot' => 'Max. Gäste pro Slot',
        'capacity_mode' => 'Kapazitätsmodus',
        'cancellation_deadline_minutes' => 'Stornofrist',
        'modification_deadline_minutes' => 'Umbuchungsfrist',
        'reminder_hours_before' => 'Erinnerung (Stunden vorher)',
        'refund_mode' => 'Rückerstattungs-Modus',
        'refund_percent' => 'Erstattung in %',
        'refund_processing' => 'Rückerstattungs-Ausführung',
        'amount_per_person' => 'Betrag pro Person',
        'payment_deadline_minutes' => 'Zahlungsfrist',
        // Integrationen
        'api_url' => 'API-URL',
        'api_key' => 'API-Key',
        'list_uid' => 'Listen-UID',
        'secret_key' => 'Secret Key',
        'webhook_secret' => 'Webhook-Secret',
        'client_id' => 'Client-ID',
        'secret' => 'Secret',
        'sender_id' => 'Absender',
        'mode' => 'Modus',
    ],
];
