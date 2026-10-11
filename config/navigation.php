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
| Eine Gruppe trägt label (Überschrift, null = keine), label_salon (Überschrift
| für Salon-Betriebe) und items.
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
|   keywords_restaurant  nur Einstellungen: Begriffe wie keywords, die nur
|               Restaurants angeboten werden, weil ihr Ziel auf der Seite eines
|               Salons fehlt; Salons sehen nur keywords
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
        ['label' => 'Gäste', 'label_salon' => 'Kunden', 'items' => [
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
            ['key' => 'data', 'label' => 'Daten', 'route' => 'admin.settings.data.show', 'scope' => 'tenant', 'roles' => ['tenant_owner'],
                'keywords' => ['Export' => 'export', 'Sicherung' => 'export', 'Import' => 'import', 'Umzug' => 'import', 'Betrieb löschen' => 'betrieb-loeschen']],
        ]],
        ['label' => 'Platz & Zeiten', 'items' => [
            ['key' => 'opening-hours', 'label' => 'Öffnungszeiten', 'route' => 'admin.settings.index', 'fragment' => 'zeiten', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Öffnungszeiten', 'Ruhetag', 'Saison', 'Zeitfenster']],
            ['key' => 'exceptions', 'label' => 'Ausnahmen', 'route' => 'admin.settings.index', 'fragment' => 'zeiten', 'active' => [], 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Sonderzeiten', 'Feiertag', 'Schließtag', 'Sperrzeit', 'Betriebsferien', 'Tisch sperren', 'Weniger Gäste']],
            ['key' => 'rooms', 'label' => 'Räume & Tische', 'route' => 'admin.settings.rooms.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'restaurant',
                'keywords' => ['Raum', 'Neuer Raum' => 'raum-neu', 'Tisch', 'Tischkombination' => 'kombinationen', 'Kombination' => 'kombinationen', 'Raummaße', 'Kinderstuhl', 'Barrierefrei']],
            ['key' => 'services', 'label' => 'Leistungen', 'route' => 'admin.services.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon', 'active' => ['admin.services.*'],
                'keywords' => ['Leistung', 'Neue Leistung' => 'leistung-neu', 'Dauer', 'Preis']],
            ['key' => 'staff', 'label' => 'Mitarbeiter', 'route' => 'admin.staff.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon',
                'keywords' => ['Mitarbeiter', 'Neue Person' => 'person-neu', 'Arbeitszeiten', 'Abwesenheit', 'Urlaub']],
            ['key' => 'capacity', 'label' => 'Kapazität & Dauer', 'route' => 'admin.settings.capacity.show', 'scope' => 'location', 'can' => ['tables.manage', 'opening_hours.manage'],
                'keywords' => ['Takt' => 'takt', 'Slot-Intervall' => 'takt', 'Puffer' => 'takt', 'Lückenoptimierung' => 'takt'],
                'keywords_restaurant' => ['Kapazität' => 'kapazitaet', 'Gäste gleichzeitig' => 'kapazitaet', 'Besuchsdauer' => 'takt', 'Tischzeit' => 'tischzeiten']],
        ]],
        ['label' => 'Buchungen', 'items' => [
            ['key' => 'booking-rules', 'label' => 'Buchungsregeln', 'route' => 'admin.settings.rules.show', 'scope' => 'location', 'can' => ['tables.manage', 'opening_hours.manage'],
                'keywords' => ['Vorlauf' => 'zeitraum', 'Vorausbuchung' => 'zeitraum', 'Stornofrist' => 'fristen', 'Umbuchungsfrist' => 'fristen', 'Bestätigung' => 'bestaetigung', 'E-Mail-Bestätigung' => 'bestaetigung', 'Warteliste' => 'warteliste'],
                'keywords_restaurant' => ['Personen online' => 'gruppen', 'Große Gruppen' => 'gruppen', 'Walk-ins' => 'warteliste']],
            ['key' => 'booking-form', 'label' => 'Buchungsformular', 'route' => 'admin.settings.booking-form.show', 'scope' => 'location', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Formularfelder' => 'felder'],
                'keywords_restaurant' => ['Pflichtfeld' => 'felder', 'Telefon' => 'felder', 'Anlass' => 'felder', 'Allergien' => 'felder']],
            ['key' => 'booking-page', 'label' => 'Buchungsseite & Widget', 'route' => 'admin.settings.booking-page.show', 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Buchungsseite' => 'buchungsseite', 'Konfetti' => 'buchungsseite', 'Website-Widget' => 'popup', 'Popup' => 'popup', 'Einbinden' => 'eingebettet', 'Direktlink' => 'direktlink'],
                'keywords_restaurant' => ['Öffentlicher Tischplan' => 'buchungsseite']],
            ['key' => 'tags', 'label' => 'Tags', 'route' => 'admin.settings.tags.show', 'scope' => 'tenant', 'can' => ['tables.manage'],
                'keywords' => ['Tags', 'Markierung', 'VIP', 'Allergie', 'Neuer Tag' => 'tag-neu']],
        ]],
        ['label' => 'Gästekommunikation', 'label_salon' => 'Kundenkommunikation', 'items' => [
            ['key' => 'notifications', 'label' => 'Benachrichtigungen', 'route' => 'admin.settings.notifications.show', 'scope' => 'location', 'can' => ['tables.manage', 'opening_hours.manage'],
                'keywords' => ['Erinnerung' => 'erinnerung', 'Bestätigungsmail' => 'bestaetigung', 'Feedback' => 'feedback', 'Benachrichtigung an den Betrieb' => 'betrieb', 'Anrede' => 'anrede', 'du' => 'anrede', 'Sie' => 'anrede']],
            ['key' => 'templates', 'label' => 'Vorlagen', 'route' => 'admin.templates.index', 'scope' => 'tenant', 'can' => ['templates.manage'],
                'keywords' => ['Vorlagen', 'Mailtext', 'Nachrichtentext', 'Platzhalter' => 'platzhalter', 'Erinnerungstext' => 'vorlage-reservation_reminder', 'Bestätigungstext' => 'vorlage-reservation_confirmed', 'Feedbacktext' => 'vorlage-feedback_request', 'Text der Absage' => 'vorlage-reservation_rejected', 'Text zur Anzahlung' => 'vorlage-payment_pending']],
            ['key' => 'sender', 'label' => 'Absender & Antworten', 'route' => 'admin.settings.sender.show', 'scope' => 'tenant', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Absendername' => 'absender', 'Antwortadresse' => 'antworten', 'Antwort über Swayy' => 'antworten', 'Reply-To' => 'antworten']],
            ['key' => 'sms', 'label' => 'SMS-Versand', 'route' => 'admin.settings.sms.show', 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['SMS' => 'seven', 'seven.io' => 'seven', 'Absenderkennung' => 'seven', 'API-Key' => 'seven']],
        ]],
        ['label' => 'Zahlungen', 'items' => [
            ['key' => 'payment-providers', 'label' => 'Zahlungsanbieter', 'route' => 'admin.settings.payment-providers.show', 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['Stripe' => 'stripe', 'PayPal' => 'paypal']],
            ['key' => 'deposits', 'label' => 'Anzahlungen', 'route' => 'admin.settings.deposits.show', 'scope' => 'location', 'can' => ['tables.manage', 'payments.manage'],
                'keywords' => ['Anzahlung' => 'regeln', 'No-Show-Schutz' => 'regeln', 'Rückerstattung' => 'erstattung', 'Erstattungsregeln' => 'erstattung']],
        ]],
        ['label' => 'Verbindungen', 'items' => [
            ['key' => 'newsletter', 'label' => 'Newsletter', 'route' => 'admin.settings.newsletter.show', 'scope' => 'tenant', 'can' => ['tables.manage', 'integrations.manage'],
                'keywords' => ['Newsletter' => 'mailwizz', 'MailWizz' => 'mailwizz', 'Listen-UID' => 'mailwizz', 'API-URL' => 'mailwizz', 'Einwilligung' => 'mailwizz']],
            ['key' => 'api', 'label' => 'API-Schlüssel', 'route' => 'admin.api-tokens.index', 'scope' => 'tenant', 'can' => ['api_tokens.manage'],
                'keywords' => ['API', 'Schlüssel', 'Token', 'Neuer Schlüssel' => 'schluessel-neu', 'Rechte' => 'schluessel-neu', 'Zugriff für Programme' => 'schluessel-neu']],
            ['key' => 'webhooks', 'label' => 'Webhooks', 'route' => 'admin.webhooks.index', 'scope' => 'tenant', 'can' => ['webhooks.manage'],
                'keywords' => ['Webhook', 'Neuer Webhook' => 'webhook-neu', 'Ereignisse' => 'webhook-neu', 'Signatur' => 'webhook-neu', 'Zustellprotokoll' => 'zustellprotokoll', 'Wiederholungen' => 'wiederholungen']],
        ]],
    ],
];
