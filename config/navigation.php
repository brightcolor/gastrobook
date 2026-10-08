<?php

use App\Support\EnvSetting;

/*
|--------------------------------------------------------------------------
| Navigation der Verwaltung
|--------------------------------------------------------------------------
|
| Eine Quelle für Seitenleiste, Symbolleiste (Tablet), untere Leiste
| (Smartphone), „Mehr“ und den Einstellungsbereich. App\Support\Navigation
| liest diese Datei, filtert nach Rechten und Betriebstyp und wendet die
| Regel an: Eine Gruppenüberschrift erscheint erst ab heading_min_items
| sichtbaren Einträgen.
|
| Einstellungen der Navigation:
|   mobile_bar_max   Einträge in der unteren Leiste auf dem Smartphone (1–4),
|                    dazu kommt „Mehr“; Umgebungsvariable SWAYY_NAV_MOBILE_BAR_MAX
|   settings_routes  Routenmuster, die immer zum Einstellungsbereich gehören
|   heading_min_items  sichtbare Einträge, ab denen eine Gruppe ihre Überschrift
|                    zeigt (1–10); Umgebungsvariable SWAYY_NAV_HEADING_MIN_ITEMS
|   search_examples  zwei Begriffe, die der Hinweis „passt keine Einstellung“
|                    als Beispiel nennt
|
| Felder je Eintrag:
|   key         eindeutiger Schlüssel (Tests, data-nav-key)
|   label       Bezeichnung; label_salon ersetzt sie bei Salon-Betrieben
|   route       Routenname; fragment hängt #… an
|   icon        Name aus resources/views/components/nav-icon.blade.php
|   can         Rechte, die alle vorliegen müssen
|   can_any     Rechte, von denen mindestens eines vorliegen muss
|   roles       Rollen (config/permissions.php), denen der Eintrag vorbehalten
|               ist; Plattform-Zugänge ohne Mitgliedschaft sehen ihn nicht
|   only        'restaurant' oder 'salon'; ohne Angabe für beide
|   active      Routenmuster, bei denen der Eintrag als aktuell gilt
|               (Vorgabe: die eigene Route; [] = nie)
|   mobile_bar  true = steht in der unteren Leiste auf dem Smartphone
|   scope       nur Einstellungen: 'tenant' (alle Standorte) oder 'location'
|   keywords    nur Einstellungen: Begriffe für die Suche; 'Begriff' => 'ziel'
|               führt direkt zur Stelle #ziel auf der Seite
|
*/

return [
    'mobile_bar_max' => EnvSetting::integer('SWAYY_NAV_MOBILE_BAR_MAX', default: 4, min: 1, max: 4),

    'settings_routes' => ['admin.settings.*'],

    'heading_min_items' => EnvSetting::integer('SWAYY_NAV_HEADING_MIN_ITEMS', default: 2, min: 1, max: 10),

    'search_examples' => ['Öffnungszeiten', 'Anzahlung'],

    'main' => [
        ['label' => null, 'items' => [
            ['key' => 'dashboard', 'label' => 'Übersicht', 'route' => 'admin.dashboard', 'icon' => 'dashboard', 'mobile_bar' => true],
        ]],
        ['label' => 'Service', 'items' => [
            ['key' => 'board', 'label' => 'Live-Board', 'route' => 'admin.board', 'icon' => 'board', 'can' => ['reservations.view'], 'mobile_bar' => true],
            ['key' => 'floorplan', 'label' => 'Tischplan', 'route' => 'admin.floorplan.index', 'icon' => 'floorplan', 'can' => ['reservations.view'], 'only' => 'restaurant'],
            ['key' => 'staff-calendar', 'label' => 'Terminplan', 'route' => 'admin.staff.calendar', 'icon' => 'calendar', 'can' => ['reservations.view'], 'only' => 'salon'],
            ['key' => 'walkins', 'label' => 'Walk-ins', 'route' => 'admin.walkins.index', 'icon' => 'walkins', 'can' => ['walkins.create']],
            ['key' => 'waitlist', 'label' => 'Warteliste', 'route' => 'admin.waitlist.index', 'icon' => 'waitlist', 'can' => ['waitlist.manage']],
        ]],
        ['label' => 'Buchungen', 'items' => [
            ['key' => 'reservations', 'label' => 'Reservierungen', 'label_salon' => 'Termine', 'route' => 'admin.reservations.index', 'icon' => 'reservations', 'can' => ['reservations.view'], 'mobile_bar' => true, 'active' => ['admin.reservations.*']],
            ['key' => 'events', 'label' => 'Events', 'route' => 'admin.events.index', 'icon' => 'events', 'can' => ['events.manage'], 'active' => ['admin.events.*']],
            ['key' => 'refunds', 'label' => 'Erstattungen', 'route' => 'admin.refunds.index', 'icon' => 'refunds', 'can' => ['payments.manage']],
        ]],
        ['label' => 'Gäste', 'items' => [
            ['key' => 'guests', 'label' => 'Gäste', 'label_salon' => 'Kunden', 'route' => 'admin.guests.index', 'icon' => 'guests', 'can' => ['guests.view'], 'mobile_bar' => true, 'active' => ['admin.guests.*']],
            ['key' => 'marketing', 'label' => 'Marketing', 'route' => 'admin.marketing.index', 'icon' => 'marketing', 'can' => ['marketing.manage']],
        ]],
        ['label' => null, 'items' => [
            ['key' => 'reports', 'label' => 'Berichte', 'route' => 'admin.reports.index', 'icon' => 'reports', 'can' => ['reports.view']],
        ]],
    ],

    'settings' => [
        ['label' => 'Betrieb', 'items' => [
            ['key' => 'general', 'label' => 'Allgemein', 'route' => 'admin.settings.general.show', 'scope' => 'tenant', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Betriebsname', 'Betriebstyp', 'Restaurant', 'Salon', 'Markenfarbe', 'Impressum', 'Datenschutz', 'AGB']],
            ['key' => 'locations', 'label' => 'Standorte', 'route' => 'admin.locations.index', 'scope' => 'tenant', 'can' => ['locations.manage'],
                'keywords' => ['Standort', 'Neuer Standort' => 'standort-neu', 'Adresse', 'Zeitzone', 'Telefon', 'Logo', 'Begrüßung']],
            ['key' => 'team', 'label' => 'Team & Rollen', 'route' => 'admin.users.index', 'scope' => 'tenant', 'can' => ['users.invite'],
                'keywords' => ['Benutzer', 'Person einladen' => 'einladen', 'Einladungslink' => 'einladungen', 'Rollen' => 'rollen', 'Zugang', 'Servicekraft']],
            ['key' => 'billing', 'label' => 'Abo & Rechnungen', 'route' => 'admin.billing.show', 'scope' => 'tenant', 'can' => ['billing.manage'], 'active' => ['admin.billing.*'],
                'keywords' => ['Tarif', 'Abo', 'Lastschrift', 'Rechnung']],
            ['key' => 'audit', 'label' => 'Protokoll', 'route' => 'admin.audit.index', 'scope' => 'tenant', 'can' => ['audit.view'],
                'keywords' => ['Auditlog', 'Änderungen', 'Wer hat', 'Verlauf']],
            ['key' => 'data', 'label' => 'Daten', 'route' => 'admin.account.show', 'fragment' => 'daten', 'active' => [], 'scope' => 'tenant', 'roles' => ['tenant_owner'],
                'keywords' => ['Export', 'Import', 'Sicherung', 'Betrieb löschen']],
        ]],
        ['label' => 'Platz & Zeiten', 'items' => [
            ['key' => 'opening-hours', 'label' => 'Öffnungszeiten', 'route' => 'admin.settings.index', 'fragment' => 'zeiten', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Öffnungszeiten', 'Ruhetag', 'Saison', 'Zeitfenster']],
            ['key' => 'exceptions', 'label' => 'Ausnahmen', 'route' => 'admin.settings.index', 'fragment' => 'zeiten', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Sonderzeiten', 'Feiertag', 'Schließtag', 'Sperrzeit', 'Betriebsferien', 'Tisch sperren', 'Weniger Gäste']],
            ['key' => 'rooms', 'label' => 'Räume & Tische', 'route' => 'admin.settings.index', 'fragment' => 'raeume', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'restaurant',
                'keywords' => ['Raum', 'Tisch', 'Tischkombination', 'Tischplan bearbeiten']],
            ['key' => 'services', 'label' => 'Leistungen', 'route' => 'admin.services.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon', 'active' => ['admin.services.*'],
                'keywords' => ['Leistung', 'Dauer', 'Preis']],
            ['key' => 'staff', 'label' => 'Mitarbeiter', 'route' => 'admin.staff.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon',
                'keywords' => ['Mitarbeiter', 'Arbeitszeiten', 'Abwesenheit', 'Urlaub']],
            ['key' => 'capacity', 'label' => 'Kapazität & Dauer', 'route' => 'admin.settings.index', 'fragment' => 'buchungsregeln', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Kapazität', 'Besuchsdauer', 'Puffer', 'Slot-Intervall', 'Lückenoptimierung']],
        ]],
        ['label' => 'Buchungen', 'items' => [
            ['key' => 'booking-rules', 'label' => 'Buchungsregeln', 'route' => 'admin.settings.index', 'fragment' => 'buchungsregeln', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Vorlauf', 'Vorausbuchung', 'Personen online', 'Große Gruppen', 'Stornofrist', 'Umbuchungsfrist', 'Bestätigung', 'Warteliste', 'Walk-ins']],
            ['key' => 'booking-form', 'label' => 'Buchungsformular', 'route' => 'admin.settings.index', 'fragment' => 'allgemein', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Formularfelder', 'Pflichtfeld', 'Telefon', 'Anlass', 'Allergien']],
            ['key' => 'booking-page', 'label' => 'Buchungsseite & Widget', 'route' => 'admin.settings.index', 'fragment' => 'widget', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Website-Widget', 'Einbinden', 'Direktlink', 'Popup', 'Konfetti', 'Öffentlicher Tischplan']],
            ['key' => 'tags', 'label' => 'Tags', 'route' => 'admin.settings.index', 'fragment' => 'raeume', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage'],
                'keywords' => ['Tags', 'Markierung', 'VIP', 'Allergie']],
        ]],
        ['label' => 'Gästekommunikation', 'items' => [
            ['key' => 'notifications', 'label' => 'Benachrichtigungen', 'route' => 'admin.settings.index', 'fragment' => 'buchungsregeln', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Erinnerung', 'Bestätigungsmail', 'Feedback', 'Benachrichtigung an den Betrieb', 'Anrede', 'du', 'Sie']],
            ['key' => 'templates', 'label' => 'Vorlagen', 'route' => 'admin.templates.index', 'scope' => 'tenant', 'can' => ['templates.manage'],
                'keywords' => ['Vorlagen', 'Mailtext', 'Nachrichtentext']],
            ['key' => 'sender', 'label' => 'Absender & Antworten', 'route' => 'admin.settings.index', 'fragment' => 'allgemein', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Absendername', 'Antwortadresse', 'Antwort über Swayy']],
            ['key' => 'sms', 'label' => 'SMS-Versand', 'route' => 'admin.settings.index', 'fragment' => 'integrationen', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['SMS', 'seven.io', 'Absenderkennung']],
        ]],
        ['label' => 'Zahlungen', 'items' => [
            ['key' => 'payment-providers', 'label' => 'Zahlungsanbieter', 'route' => 'admin.settings.index', 'fragment' => 'zahlungen', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['Stripe', 'PayPal']],
            ['key' => 'deposits', 'label' => 'Anzahlungen', 'route' => 'admin.settings.index', 'fragment' => 'zahlungen', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage', 'payments.manage'],
                'keywords' => ['Anzahlung', 'No-Show-Schutz', 'Rückerstattung', 'Erstattungsregeln']],
        ]],
        ['label' => 'Verbindungen', 'items' => [
            ['key' => 'newsletter', 'label' => 'Newsletter', 'route' => 'admin.settings.index', 'fragment' => 'integrationen', 'active' => [], 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['Newsletter', 'MailWizz']],
            ['key' => 'api', 'label' => 'API-Schlüssel', 'route' => 'admin.api-tokens.index', 'scope' => 'tenant', 'can' => ['api_tokens.manage'],
                'keywords' => ['API', 'Schlüssel', 'Token']],
            ['key' => 'webhooks', 'label' => 'Webhooks', 'route' => 'admin.webhooks.index', 'scope' => 'tenant', 'can' => ['webhooks.manage'],
                'keywords' => ['Webhook', 'Ereignisse']],
        ]],
    ],
];
