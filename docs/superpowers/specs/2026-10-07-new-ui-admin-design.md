# Neue Oberfläche: Grundlage und Betriebs-Admin (new-ui, Abschnitte A+B)

Stand: 2026-10-07 · Status: Entwurf, abschnittsweise mit Mathias abgestimmt · Branch: `new-ui`

## Ziel

Der Admin von Swayy soll hochwertig und seriös wirken und sich so bedienen lassen
wie erfolgreiche Web-Apps. Farben, Schriften und die Stein-Palette bleiben. Neu
geordnet wird, **wo etwas liegt** und **wie sich Seiten verhalten**.

Anlass: Die Einstellungen sind über Seitenleiste, eine Sammelseite mit sieben Reitern
und „Mein Konto“ verteilt. Zusammengehöriges liegt getrennt (Benachrichtigungen und
ihre Texte, Tische und Tischplan, Sonderzeiten und Sperrzeiten), und eine Seite mischt
Werte für den ganzen Betrieb mit Werten je Standort.

## Umfang

Die Neuordnung umfasst alles mit Oberfläche und ist in Abschnitte geteilt. Diese Spec
deckt **A** und **B** ab.

| Abschnitt | Inhalt | Spec |
|---|---|---|
| A · Grundlage | Ordnungsregeln, Navigationsmuster, Seitenmuster, Barrierefreiheit | diese |
| B · Betriebs-Admin | Seitenleiste, Einstellungsbereich, alle Admin-Seiten | diese |
| C · Gästeseiten | Buchung, Events, Verwalten/Umbuchen, Gastportal, Feedback, Warteliste | folgt, baut auf A auf |
| D · Plattform | /saas, Anmeldung/Registrierung, Startseite, Rechtstexte | folgt |
| E · Mails | Systemmails und Vorlagen | folgt |

Eigenes Teilprojekt mit eigener Spec: **Dev-Umgebung** (Branch auf code.bcsrv.de,
GitLab-CI, VM, NetBird, Kopie der Live-Daten unter dev-swayy.bcsrv.de).

### Nicht im Umfang

- Gestaltungsschema von bright color: Swayy behält Palette, Schriften und Tokens. Von
  `bright-color-apps` gelten nur Prinzipien.
- Rückgängig nach dem Löschen (bräuchte weiches Löschen in vielen Tabellen).
- Wiederkehrende Kapazitätsgrenzen („sonntags höchstens 40 Gäste“, „Terrasse nur
  Fr–Sa online“).
- Fachliche Änderung der Gästegrenze von Sperrzeiten. Sie kommt als eigener Fix auf
  `main` (siehe Abhängigkeiten) und fließt per Merge in `new-ui`.

## Grundlagen aus Forschung und Vorbildern

1. Seiten mit geringer visueller Komplexität und hoher Prototypizität, also dem
   vertrauten Aufbau ihrer Kategorie, wirken nach 50 ms am ansprechendsten
   (Tuch et al. 2012, International Journal of Human-Computer Studies 70(11)).
2. Bei der Stanford-Studie zur Glaubwürdigkeit (2.684 Personen) begründeten 46,1 % ihr
   Urteil mit dem Design, 28,5 % mit der Struktur. Dazu der Aesthetic-Usability-Effekt
   (Kurosu & Kashimura 1995).
3. Versteckte Navigation senkt die Auffindbarkeit um rund 20 % und macht auf dem
   Desktop 39 % langsamer (Nielsen Norman Group). Flache Hierarchien mit höchstens drei
   Ebenen sind leichter zu bedienen (NN/g, Flat vs. Deep Hierarchy).
4. Shopify, Zenchef, Fresha und SevenRooms führen „Einstellungen“ als einen Eintrag
   unten in der Seitenleiste, dahinter ein eigener Bereich mit gruppierter
   Unternavigation. Shopify Polaris: Titel und Erklärung links, Einstellung als Karte
   rechts, eine Karte je Thema; Speicherleiste bei Änderungen (Contextual Save Bar).
5. Beschriftung über dem Feld ist am schnellsten (Penzo 2006). Fehlermeldungen stehen
   am Feld, sind konkret und nennen den nächsten Schritt (NN/g). Rückfragen nur bei
   endgültigen Aktionen.

## A · Grundlage

### Ordnungsregeln

1. Tagesgeschäft und Einrichtung sind getrennt: Die Seitenleiste enthält nur Arbeit,
   alles Einstellbare liegt im Einstellungsbereich.
2. Gruppen folgen dem Denkmodell der Betreiber, nicht dem Datenmodell.
3. Eine Gruppenüberschrift erscheint erst ab zwei sichtbaren Einträgen. Die Regel gilt
   je Rolle: Fehlt einem Nutzer ein Recht, kann eine Überschrift entfallen.
4. Eine Funktion ist dort erreichbar, wo Nutzer sie suchen. Liegt sie fachlich woanders,
   gibt es dort eine Aktion, die an die zentrale Stelle führt (Beispiel: „Sperren…“ am
   Tisch öffnet die Ausnahme).
5. Jede Seite hat eine eindeutige Bezeichnung; zwei Seiten heißen nie gleich oder
   zum Verwechseln ähnlich. Gruppenüberschriften dürfen sich zwischen Hauptmenü und
   Einstellungsbereich entsprechen („Buchungen“ richtet „Buchungen“ ein).

### Seitenmuster

**Seitenkopf** (jede Seite gleich): Pfad im Einstellungsbereich (Einstellungen ›
Gruppe), Titel, ein Satz zur Seite, Geltungsbereich mit Standortwechsler, rechts genau
eine Hauptaktion.

**Geltungsbereich:** Jede Einstellungsseite nennt im Kopf, wofür sie gilt: „Gilt für
alle Standorte“ oder „Gilt für Standort ‹Name› ▾“. Gewechselt wird direkt dort.

**Einstellungen als Karten mit Erklärung:** Ab 1024 px links Titel und Erklärung,
rechts die Karte. Darunter übereinander.

**Speicherleiste:** Formulare einer Seite teilen eine Speicherleiste. Sie erscheint,
sobald etwas geändert ist („Ungespeicherte Änderungen · 2 Tage geändert“, „Verwerfen“,
„Speichern“), bleibt beim Scrollen oben stehen und sitzt auf dem Smartphone unten.
Geänderte Zeilen tragen einen Punkt. Wer die Seite mit Änderungen verlässt, bekommt
einen Hinweis. Listen (Sonderzeiten, Räume, Regeln) speichern je Eintrag über die
Seitenleiste zum Anlegen.

**Anlegen und Bearbeiten** in einer Seitenleiste von rechts, auf dem Smartphone
bildschirmfüllend. Die Liste bleibt dahinter sichtbar. Escape schließt, der Fokus kehrt
zum auslösenden Knopf zurück.

**Formulare:** Beschriftung über dem Feld, Einheit im Feld (`90 | Min.`), Feldbreite nach
Inhalt, Pflichtfelder markiert, Zahlenfelder mit Grenzen.

**Hilfetexte:** Die wichtigste Aussage steht als ein Satz sichtbar unter dem Feld,
längere Erklärungen links neben der Karte. Das „?“ (heute 170 Stück im Admin) bleibt nur
für Zusatzwissen.

**Rückmeldung:**
- Gespeichert: kurzer Hinweis unten, per Live-Region angesagt.
- Fehler: Text am Feld plus Zusammenfassung oben in der Karte mit Links zu den Feldern,
  Fokus springt zum ersten Fehler. Der Text nennt Ursache und nächsten Schritt.
- Schalter, die sofort wirken, bieten im Hinweis „Rückgängig“ an.
- Löschen: Rückfrage in der Zeile, mit Objekt und Folge („Raum ‚Terrasse‘ mit 6 Tischen
  löschen?“). Kein Dialog über der ganzen Seite.

**Hinweise vor dem Speichern:** Wo eine Eingabe zulässig ist, aber Folgen hat, sagt die
Seite das sofort (Beispiel Öffnungszeiten: „Endet am Folgetag um 01:00“, „Hier finden
Gäste keine Uhrzeit“).

**Listen:** Auf dem Smartphone zweizeilig, Titel fett oben, Rest klein darunter; der
Titel ist der Link. Zustände als Icon mit Form und Farbe, das Wort im Tooltip und für
Screenreader.

**Leerzustände** sagen, wofür der Bereich da ist, und bieten genau eine Aktion.

**Ruhiger Gesamteindruck:** einheitliches Abstandsraster, drei Knopfstufen (Haupt,
Neben, Gefahr), feste Überschriftenebenen, weniger Rahmen und Schatten, Karten ohne
Verschachtelung.

**Barrierefreiheit:** WCAG 2.2 AA. Sichtbarer Fokus, Klickziele mindestens 24 × 24 px,
volle Tastaturbedienung, `aria-current` in der Navigation, Statusmeldungen per
Live-Region, Kontrast geprüft gegen jede Fläche, Zustände nie über Farbe allein.

## B · Betriebs-Admin

### Navigation im Arbeitsbereich

```
Swayy · Betriebsname
[Standort ▾]                  nur bei mehr als einem Standort

Übersicht

SERVICE
  Live-Board
  Tischplan                   (Salon: Terminplan)
  Walk-ins
  Warteliste
BUCHUNGEN
  Reservierungen              (Salon: Termine)
  Events
  Erstattungen                Liste der Rückzahlungsanfragen
GÄSTE
  Gäste                       (Salon: Kunden)
  Marketing

Berichte
─────────────
⚙ Einstellungen
[Name ▾]  Mein Konto · Abmelden · Plattform-Admin
```

- Sichtbarkeit nach Rechten wie heute (`canInTenant`). „Einstellungen“ erscheint, wenn
  mindestens eine Einstellungsseite erlaubt ist.
- „Mein Konto“ enthält nur noch Persönliches: Profil, Passwort, „Konto löschen“.

| Breite | Navigation |
|---|---|
| ab 1280 px | Seitenleiste, immer sichtbar |
| 768–1279 px | einklappbare Symbolleiste mit Beschriftung beim Überfahren; Zustand je Gerät gemerkt (localStorage, mit try/catch) |
| unter 768 px | untere Leiste: Übersicht, Live-Board, Reservierungen, Gäste, „Mehr“; „Mehr“ öffnet die volle Liste mit Gruppen und Einstellungen |

### Einstellungsbereich

Ein Klick auf „Einstellungen“ ersetzt die Seitenleiste durch die
Einstellungsnavigation: oben „← Zurück zum Betrieb“ und ein Suchfeld. Auf dem Smartphone
erscheint zuerst die Gruppenliste. Einstellungen öffnet die erste erlaubte Seite.

```
BETRIEB
  Allgemein                [B]
  Standorte                [S je Standort]
  Team & Rollen            [B]
  Abo & Rechnungen         [B]
  Protokoll                [B]
  Daten                    [B]
PLATZ & ZEITEN             (Salon: Leistungen und Mitarbeiter an Stelle von Räume & Tische)
  Öffnungszeiten           [S]
  Ausnahmen                [S]
  Räume & Tische           [S]
  Kapazität & Dauer        [S]
BUCHUNGEN
  Buchungsregeln           [S]
  Buchungsformular         [S]
  Buchungsseite & Widget   [S]
  Tags                     [B]
GÄSTEKOMMUNIKATION
  Benachrichtigungen       [S]
  Vorlagen                 [B]
  Absender & Antworten     [B]
  SMS-Versand              [B]
ZAHLUNGEN
  Zahlungsanbieter         [B]
  Anzahlungen              [S]
VERBINDUNGEN
  Newsletter               [B]
  API-Schlüssel            [B]
  Webhooks                 [B]
```

[B] = gilt für alle Standorte, [S] = gilt für den gewählten Standort. 23 Seiten in
6 Gruppen, höchstens drei Ebenen.

**Suche** findet Seitentitel und Begriffe der einzelnen Einstellungen (etwa
„Stornofrist“, „Erinnerung“, „Auditlog“) und springt zur passenden Karte. Die Begriffe
stehen mit der Navigation in `config/navigation.php`.

**Rechte:** Jede Karte behält das Recht, das ihre Route heute hat. Eine Seite ist
sichtbar, sobald mindestens eine ihrer Karten erlaubt ist. Die Rechte-Matrix in
`config/permissions.php` ändert sich nicht. Heute öffnet `tables.manage` die ganze
Sammelseite; das entfällt.

### Zuordnung alt → neu

Jede heutige Einstellung hat genau einen Platz. Feldnamen aus
`resources/views/admin/settings/index.blade.php` (Stand v1.133.1).

| Heute | Felder | Neu |
|---|---|---|
| Allgemein › Stammdaten | `business_name`, `imprint_url`, `privacy_url`, `terms_url` | Betrieb › Allgemein |
| Allgemein › Stammdaten | `location_name`, `timezone`, `phone`, `email`, `address_line1`, `postal_code`, `city`, `public_intro` | Betrieb › Standorte (Profil) |
| Allgemein › Betriebstyp | `type` | Betrieb › Allgemein |
| Allgemein › Markenfarbe | `brand_primary_color` | Betrieb › Allgemein |
| Allgemein › Logo | `logo` | Betrieb › Standorte (Profil) |
| Allgemein › E-Mails an Gäste | `mail_from_name`, `mail_reply_to`, `mail_relay_enabled` | Gästekommunikation › Absender & Antworten |
| Allgemein › Formularfelder | `fields[…]` | Buchungen › Buchungsformular |
| Buchungsregeln (Kapazität) | `slot_interval_minutes`, `default_duration_minutes`, `buffer_minutes`, `duration_rules[]`, `capacity_mode`, `max_covers_per_slot`, `gap_optimization_enabled` | Platz & Zeiten › Kapazität & Dauer |
| Buchungsregeln (Regeln) | `min_lead_minutes`, `max_advance_days`, `cancellation_deadline_minutes`, `modification_deadline_minutes`, `min_party_online`, `max_party_online`, `large_group_email` | Buchungen › Buchungsregeln |
| Buchungsregeln › Online-Buchung | `waitlist_enabled`, `walkins_enabled` | Buchungen › Buchungsregeln |
| Buchungsregeln › Online-Buchung | `public_floorplan_enabled`, `confetti_on_booking` | Buchungen › Buchungsseite & Widget |
| Buchungsregeln › Buchungsbestätigung | `booking_confirmation_mode`, `require_email_confirmation` | Buchungen › Buchungsregeln |
| Buchungsregeln › Erinnerungen | `reminder_enabled`, `reminder_hours_before`, `sms_reminder_enabled` | Gästekommunikation › Benachrichtigungen |
| Buchungsregeln › Benachrichtigung an den Betrieb | `owner_notification_enabled`, `owner_notification_email` | Gästekommunikation › Benachrichtigungen |
| Buchungsregeln › Feedback | `feedback_enabled`, `feedback_hours_after`, `feedback_external_url`, `feedback_redirect_min_score` | Gästekommunikation › Benachrichtigungen |
| Buchungsregeln › Anrede | `guest_address` (du/Sie gegenüber Gästen) | Gästekommunikation › Benachrichtigungen (erste Karte „Anrede“) |
| Buchungsregeln › Rückerstattung der Anzahlung | `refund_mode`, `refund_percent`, `refund_processing` | Zahlungen › Anzahlungen |
| Zeiten › Öffnungszeiten | `hours[]` | Platz & Zeiten › Öffnungszeiten |
| Zeiten › Buchbare Saison | `label`, `start_day`, `start_month`, `end_day`, `end_month` | Platz & Zeiten › Öffnungszeiten (Karte „Buchbare Saison“) |
| Zeiten › Sonderöffnungszeiten | `date`, `label`, `opens_at`, `closes_at`, `closed` | Platz & Zeiten › Ausnahmen |
| Zeiten › Sperrzeiten | `starts_at`, `ends_at`, `room_id`, `reduce_covers_to`, `bo_type`, `reason` | Platz & Zeiten › Ausnahmen |
| Zeiten › Tisch sperren | `restaurant_table_id`, `starts_at`, `ends_at`, `reason` | Platz & Zeiten › Ausnahmen, dazu Aktion „Sperren…“ in Räume & Tische und im Tischplan |
| Räume & Tags › Räume, Tische, Kombinationen | Raum-, Tisch- und Kombinationsfelder | Platz & Zeiten › Räume & Tische |
| Räume & Tags › Reservierungs-Tags | Tag-Felder | Buchungen › Tags |
| Zahlungen › Stripe, PayPal | `secret_key`, `webhook_secret`, `client_id`, `secret`, `mode`, `enabled` | Zahlungen › Zahlungsanbieter |
| Zahlungen › Anzahlungsregeln | `name`, `min_party_size`, `weekdays[]`, `from_time`, `until_time`, `room_id`, `service_id`, `amount_per_person`, `flat_amount`, `payment_deadline_minutes`, `cancel_unpaid_automatically` | Zahlungen › Anzahlungen |
| Integrationen › MailWizz | `api_url`, `api_key`, `list_uid`, `enabled` | Verbindungen › Newsletter |
| Integrationen › seven.io | `api_key`, `sender_id`, `enabled` | Gästekommunikation › SMS-Versand |
| Website-Widget | Einbindung Popup, eingebettet, Direktlink | Buchungen › Buchungsseite & Widget |
| Seitenleiste › Standorte | – | Betrieb › Standorte |
| Seitenleiste › Benutzer | – | Betrieb › Team & Rollen |
| Seitenleiste › Abrechnung | – | Betrieb › Abo & Rechnungen |
| Seitenleiste › E-Mail-Vorlagen | – | Gästekommunikation › Vorlagen |
| Seitenleiste › API | – | Verbindungen › API-Schlüssel |
| Seitenleiste › Webhooks | – | Verbindungen › Webhooks |
| Seitenleiste › Auditlog | – | Betrieb › Protokoll |
| Seitenleiste › Leistungen, Mitarbeiter (Salon) | – | Platz & Zeiten (Salon) |
| Seitenleiste › Marketing | – | Hauptnavigation › Gäste › Marketing |
| Seitenleiste › Rückerstattungen | – | Hauptnavigation › Buchungen › Erstattungen |
| Mein Konto › Export, Import, Betrieb löschen | – | Betrieb › Daten |
| Mein Konto › Profil, Konto löschen | – | bleibt Mein Konto |

Die Zuordnung wird beim Bau als Test geführt: Jeder Feldname der alten Sammelseite muss
auf genau einer neuen Seite vorkommen.

### Öffnungszeiten

- **Reguläre Woche:** eine Zeile je Wochentag mit Schalter offen/geschlossen,
  kompakte Zeitfelder, Name des Zeitfensters dezent daneben. Mehrere Fenster je Tag,
  Fenster über Mitternacht erlaubt (wie `TimeSlotService::makeWindow`). Je Tag
  „Zeitfenster hinzufügen“ und „Auf andere Tage übertragen“ (Mo–Fr, alle Tage, einzeln).
  Gespeichert wird im heutigen Format `opening_hours` (weekday, opens_at, closes_at,
  service_name).
- **Hinweise:** „Endet am Folgetag (‹Tag›) um ‹Uhrzeit›“ bei Fenstern über Mitternacht;
  Warnung, wenn ein Fenster kürzer ist als die Standarddauer („Hier finden Gäste keine
  Uhrzeit“).
- **Vorschau „So sehen Gäste es“:** buchbare Uhrzeiten des gerade bearbeiteten Tages,
  mit Begründung der letzten Uhrzeit. Der Server rechnet sie aus den ungespeicherten
  Zeiten mit derselben Logik wie `TimeSlotService::slotStarts` (Ende minus Dauer, Takt
  aus `slot_interval_minutes`). Gibt es Dauerregeln je Gruppengröße, nennt die Vorschau
  die abweichende letzte Uhrzeit dieser Gruppen.
- **Buchbare Saison:** dritte Karte der Seite, Verhalten wie heute.

### Ausnahmen

Ein Ort für alles, was an bestimmten Tagen anders ist. Ersetzt die Karten
„Sonderöffnungszeiten / Schließtage“, „Sperrzeiten“ und „Tisch sperren“.

- **Liste** nach Monaten, „Kommend“ und „Vergangen“ getrennt. Je Eintrag ein
  Kalenderblatt (Tag oder Zeitraum), Bezeichnung und jede Wirkung als eigene Zeile mit
  Symbol: geschlossen, geöffnet ‹von–bis›, höchstens N Gäste gleichzeitig (Standort oder
  Raum), gesperrt: ‹Räume, Tische›, optional mit Uhrzeit.
- **Anlegen/Bearbeiten** in der Seitenleiste: Von, Bis (freiwillig), Bezeichnung, „Was ist
  anders?“ als Auswahlkarten (Geschlossen · Andere Öffnungszeiten · Weniger Gäste ·
  Räume oder Tische sperren). Mehrere kombinierbar, „Geschlossen“ schließt die anderen
  aus. Grenze und Sperre optional auf Uhrzeiten einschränkbar. „So wirkt es“ steht fest
  über den Knöpfen und beschreibt in einem Satz, was Gäste erleben, samt letzter
  buchbarer Uhrzeit. Salons sehen „Räume oder Tische sperren“ nicht.
- **Speicherung** in den bestehenden Tabellen, in einer Transaktion:

  | Wirkung | Tabelle |
  |---|---|
  | geschlossen, andere Öffnungszeiten (je Datum eine Zeile) | `special_opening_hours` |
  | weniger Gäste (Standort oder Raum), Raum gesperrt | `blackout_periods` |
  | Tisch gesperrt | `table_blocks` |

- **Gruppierung:** Die drei Tabellen bekommen eine leere Spalte `exception_group`
  (UUID, nullable, Index). Alles, was eine Ausnahme anlegt, trägt dieselbe Gruppe und
  erscheint als ein Eintrag. Alte Einträge ohne Gruppe erscheinen einzeln. Die Migration
  ist idempotent (`Schema::hasColumn`).
- **Aktion am Tisch:** In Räume & Tische und im Tischplan hat jeder Tisch „Sperren…“. Sie
  öffnet dieselbe Seitenleiste mit dem Tisch ausgewählt.

### Weitere Seiten

- **Protokoll:** heutiges Auditlog. Der Verlauf einer Reservierung
  (`reservations/show.blade.php`) verlinkt auf das Protokoll, gefiltert auf diese
  Reservierung.
- **Daten:** Export, Import, „Betrieb löschen“ aus „Mein Konto“, mit den heutigen
  Prüfungen des `AccountController`.
- **Anzahlungen:** Anzahlungsregeln und „Rückerstattung der Anzahlung“ auf einer Seite.
- **Benachrichtigungen:** je Nachricht (Bestätigung, Erinnerung E-Mail/SMS, Feedback,
  Info an den Betrieb) Schalter, Zeitpunkt und Link „Text bearbeiten“ zur passenden
  Vorlage.
- **Standorte:** einzige Stelle für Standortdaten und Logo. Die doppelte Bearbeitung über
  die alte Stammdaten-Karte entfällt.

## Technik

### Branch, CI, Auslieferung

- `new-ui` entsteht aus `origin/main`, ohne Upstream auf `main` (ein Push auf `main` rollt
  über Watchtower sofort aus). Ein Entwurfs-PR nach `main` lässt die GitHub-CI (Pint,
  PHPStan, PHPUnit) bei jedem Push laufen; der Workflow startet heute nur bei Push auf
  `main`, Tags und PRs.
- `main` fließt regelmäßig per Merge ein, mindestens vor jedem Bauabschnitt. Die
  Sammelseite ist ein Brennpunkt (zuletzt „Buchbare Saison“ in v1.133.0); sie wird deshalb
  früh aufgeteilt.
- Ausgeliefert wird nach Abnahme auf dev-swayy in einem Merge, als MINOR-Version nach
  `config/version.php`.

### Bausteine

- `config/navigation.php`: Gruppen, Einträge, Route, Recht, Restaurant- und
  Salon-Bezeichnung, Suchbegriffe; ebenso die Einstellungsnavigation.
- `App\Support\Navigation`: filtert nach Rechten, wendet die Überschriftenregel an,
  liefert die Struktur für Seitenleiste, Symbolleiste, untere Leiste, „Mehr“ und
  Einstellungsnavigation.
- Blade-Bausteine: `x-page-header`, `x-settings.section`, `x-save-bar`, `x-drawer`,
  `x-field`, `x-empty-state`, `x-inline-confirm`, `x-status-icon`.
- JS-Modul `resources/js/admin/`: Speicherleiste, Seitenleiste, Suche, Rückfrage in der
  Zeile, Übertragen, Vorschau-Abruf. Neuer Code nutzt keine `onclick`/`onchange`/
  `onsubmit` im HTML (heute 20 in Sammelseite und Layout).
- Verhaltenswerte der Oberfläche (Anzeigedauer des Hinweises, Verzögerung der
  Vorschau-Abfrage) stehen mit Vorgaben im Abschnitt `ui` von `config/swayy.php`,
  gelesen über `App\Support\EnvSetting` mit Grenzen, und lassen sich per
  Umgebungsvariable ändern (Muster der übrigen Einstellungen dort).

### Einstellungen aufteilen

- `SettingsController` (1.418 Zeilen) und `settings/index.blade.php` (1.664 Zeilen)
  werden zu je einem Controller und einer Ansicht pro Einstellungsseite
  (`App\Http\Controllers\Admin\Settings\*`, `resources/views/admin/settings/*`).
- Seiten, die heute schon eigenständig sind (Benutzer, Standorte, Vorlagen, API,
  Webhooks, Auditlog, Abrechnung, Tags, Leistungen, Mitarbeiter), behalten ihre Adresse
  und bekommen das neue Layout.
- **Speicherwege je Seite:** `updateBookingRules` schreibt heute alle Schalter auf
  einmal; ein fehlendes Feld wird zu „aus“. Nach der Aufteilung hat jede Seite einen
  eigenen Speicherweg, der nur ihre Felder validiert und schreibt.
- **Weiterleitungen:** `/admin/settings` führt auf die erste erlaubte Seite. Die alten
  Sprungmarken (`#allgemein`, `#buchungsregeln`, `#zeiten`, `#raeume`, `#zahlungen`,
  `#integrationen`, `#widget`) übersetzt ein kleines Skript auf der Einstiegsseite.
- **Vorschau-Endpunkt:** `POST /admin/settings/opening-hours/preview` nimmt die
  ungespeicherten Fenster eines Tages und liefert die Startzeiten. `TimeSlotService`
  bekommt dafür eine Methode, die mit übergebenen Fenstern rechnet; `slotStarts` nutzt
  sie ebenfalls.

### Abhängigkeiten

- Fix der Gästegrenze von Sperrzeiten auf `main` (eigene Aufgabe, läuft): `reduce_covers_to`
  wirkt heute nur im Kapazitätsmodus „person“/„hybrid“ und nur ohne Raum. Die Ausnahmen
  setzen voraus, dass die Grenze in jedem Modus und je Raum wirkt.

## Tests

- Jede Einstellungsseite je Rolle: erlaubt 200, sonst 403; Sichtbarkeit in der
  Navigation passend dazu.
- Jeder Speicherweg schreibt nur seine Felder: Seite mit Nicht-Standardwerten speichern,
  alle anderen Werte bleiben unverändert.
- Zuordnungstest: jeder Feldname der alten Sammelseite kommt auf genau einer neuen Seite vor.
- Weiterleitungen der alten Adresse und der Sprungmarken.
- Navigation: Überschriftenregel, Salon-Bezeichnungen, Rechtefilter, auch mit
  abweichender `config/navigation.php`.
- Ausnahmen: Anlegen, Bearbeiten, Löschen gegen die drei Tabellen, Gruppierung,
  Einträge ohne Gruppe, Mandantentrennung.
- Vorschau gleich `TimeSlotService`, auch für Fenster über Mitternacht, zu kurze Fenster
  und abweichende Takte und Dauern.
- Bildschirmprüfung bei 390, 768 und 1280 px ohne seitliches Scrollen; Prüfung der
  Barrierefreiheit ohne schweren Befund; Tastaturbedienung von Speicherleiste,
  Seitenleiste und Rückfrage.

## Abnahme

1. Zuordnungstest grün: jede heutige Einstellung hat genau einen Platz.
2. Alle Tests grün, CI grün.
3. Bildschirm- und Barrierefreiheitsprüfung ohne schweren Befund.
4. Mathias klickt auf dev-swayy mit der Kopie der Live-Daten durch und gibt frei.
5. Empfohlen: kurzer Test mit 3 bis 5 echten Betrieben auf dev-swayy.

## Tree-Test mit digitalen Twins (07.10.2026)

**Methode:** Erstklick-Test auf den Menübaum als Text, ohne Gestaltung. 8 Twins der
neuroflash-Zielgruppe „Pragmatische Inhaber:innen kleiner KMUs“ (4 Frauen, 4 Männer,
36–52 Jahre) in der Rolle „Inhaber:in eines Restaurants mit 60 Plätzen“. Jeder Twin
einzeln, heutiger und neuer Baum in getrennten Anfragen, flaches Antwortschema mit
Nummer, Bezeichnung und Sicherheit 1–5. Ziele vorab festgelegt.

| Aufgabe | heute | neu (1. Lauf) | neu (nach Änderung) |
|---|---|---|---|
| 1 Heiligabend 12–15 Uhr | 88 % | 100 % | 100 % |
| 2 Erinnerungsmail einschalten | 0 % | 100 % | 100 % |
| 3 Zugang für Servicekraft | 100 % | 100 % | 100 % |
| 4 Stornofrist 2 Stunden | 100 % | 100 % | 100 % |
| 5 Tisch 7 eine Woche sperren | 0 % | 0 % | 100 % (Aktion „Sperren…“ in Räume & Tische, alle 8 klickten dort) |
| 6 Wer hat eine Reservierung gelöscht? | 100 % | 0 % | 100 % („Protokoll“) |
| 7 Text der Bestätigungsmail | 100 % | 100 % | 100 % |
| 8 Reservierungs-Knopf auf eigene Website | 100 % | 100 % (unsicher) | 100 % („Buchungsseite & Widget“) |
| 9 Anzahlung ab 8 Personen | 100 % | 100 % | 100 % |
| 10 Anzahlung zurück | 100 % | 88 % | 100 % |
| **Gesamt** | **79 %** | **79 %** | **100 %** |

Änderungen nach dem ersten Lauf: „Protokoll“ als eigene Seite (Export/Import/Löschen
hieß im Nachtest „Daten & Konto“ und heißt nach der Selbstprüfung „Daten“, damit es
sich von „Mein Konto“ abhebt; Ziel einer Aufgabe war die Seite nicht), „Sperren…“
direkt am Tisch, „Erstattungsregeln“ in „Anzahlungen“ aufgegangen, „Buchungsseite“
heißt „Buchungsseite & Widget“. Der Nachtest lief für die Aufgaben 6, 8 und 10 mit
denselben 8 Twins; Aufgabe 5 gilt als Treffer, weil die Funktion an den Ort kommt, an
dem alle geklickt haben.

**Einordnung:** Twins geben geäußerte Meinungen wieder. Die Zielgruppe ist allgemein
KMU-Inhaber, nicht Gastronomie (das Kontingent von drei Zielgruppen war belegt). Die
Kommentare im Nachtest fielen teils in die KMU-Rollen zurück. Der Test mit echten
Betrieben bleibt deshalb empfohlen.

## Musterseiten

Unter `docs/superpowers/mockups/2026-10-07-new-ui/`, im Browser zu öffnen (laden
Inter von Google Fonts, „Vorher“ lädt Tailwind über jsDelivr):

- `muster-oeffnungszeiten-v2.html`: Vorher/Nachher, Speicherleiste, Woche, Übertragen,
  Vorschau, Hinweise, Fehler, Leerzustand, Erklärungen mit Quellen. Der Teil
  „Sonderzeiten“ dort ist durch „Ausnahmen“ ersetzt.
- `muster-ausnahmen.html`: Liste, Anlegen und Bearbeiten. Bekannte Mängel des Musters:
  Zeitfelder in der Seitenleiste schneiden die Uhrzeit ab; „So wirkt es“ steht unten im
  Formular, laut Spec gehört es fest über die Knöpfe.

Stand der Musterseiten vor dem Tree-Test: Sie zeigen noch „Daten & Sicherheit“,
„Buchungsseite“ und „Erstattungen“ als Einstellungsseite. Maßgeblich ist der Baum in
dieser Spec.
