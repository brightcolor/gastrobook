# Changelog

Alle nennenswerten Änderungen an Swayy. Das Projekt folgt
[Semantic Versioning](https://semver.org). Die aktuelle Version steht in
`config/version.php` und wird dezent in allen Admin-Oberflächen angezeigt.

## [1.19.1] – 2026-06-14

### Geändert
- **Tisch-Detail im Live-Board jetzt als Modal:** Das seitlich einfahrende Panel
  (Sidebar) ist jetzt ein zentriertes Dialogfenster – gleiches Layout/Design,
  inkl. Dark-Mode. Klick auf den Hintergrund schließt es.
- **Walk-in: Personenzahl per Button:** Im Tisch-Modal wird die Personenzahl
  über Buttons (1 bis zur Tischkapazität = „mögliche Plätze") gewählt statt über
  ein Zahlenfeld.
- **„Auschecken" statt „Fertig":** Die Abschluss-Aktion eines belegten Tisches
  heißt im Board jetzt einheitlich „Auschecken" (wie im Tischplan).

## [1.19.0] – 2026-06-14

### Neu
- **Nächste freie Termine bei ausgebuchtem Tag:** Ist am gewählten Tag für die
  gewünschte Personenzahl kein Tisch frei, zeigt die Buchungsseite jetzt die
  **nächsten freien Termine (Datum + Uhrzeit) für genau diese Personenzahl** –
  direkt als Buttons. Ein Klick übernimmt Datum & Uhrzeit, lädt die Slots des
  Tages und wählt die Zeit aus (inkl. Tischplan, falls aktiv). Die Warteliste
  wird – falls aktiv – weiterhin als Alternative angeboten.
  (slots-Endpoint liefert `next_slots`; `ReservationAvailabilityService::nextSlots`.)

## [1.18.0] – 2026-06-14

### Geändert
- **Tisch anlegen jetzt als Modal:** In den Einstellungen öffnet „＋ Tisch
  anlegen" ein Dialogfenster statt der Inline-Formularzeile. Die **Platzzahl
  wird per Button gewählt** (1–10, plus „Andere Anzahl…"); der Tisch wird
  passend zur Sitzanzahl dimensioniert.

### Neu
- **Auschecken am Tisch:** Im Tischplan-Popup eines belegten Tisches gibt es
  jetzt „✓ Auschecken (Gäste gegangen)", das die Reservierung abschließt und
  den Tisch sofort freigibt. (Im Live-Board erledigt das weiterhin „Fertig".)

## [1.17.0] – 2026-06-14

### Neu
- **Gäste am Tisch dazubuchen:** Wenn z. B. ein 2er-Walk-in an einem 4er-Tisch
  sitzt und weitere Gäste dazukommen, lässt sich die Personenzahl direkt am
  Tisch erhöhen (＋/− Stepper) – im **Tischplan** (Tisch antippen) und im
  **Live-Board** (Tisch-Detail). Die Belegung/Stuhlanzeige aktualisiert sich
  sofort. Begrenzt auf die Tischkapazität (inkl. Zusatzplätze); darüber kommt
  ein klarer Hinweis, einen größeren oder zusätzlichen Tisch zu wählen.
  (Endpoint `POST /admin/reservations/{id}/party`, Recht `reservations.update`,
  Auditlog.)

## [1.16.3] – 2026-06-14

### Geändert
- **Realistische Sitzverteilung:** Stühle werden jetzt so platziert, wie Gäste
  tatsächlich sitzen – runde Tische rundherum, lange Tische an den beiden
  Längsseiten (mit je einem Kopfplatz an den Enden bei größeren Tischen),
  nahezu quadratische Tische gleichmäßig auf allen Seiten, Paartische
  gegenüber. Keine Stühle mehr an den kurzen Enden langer Tafeln.
- **Tischgröße passt zur Platzzahl:** Tische werden anhand ihrer Sitzanzahl
  dimensioniert (runde wachsen als Kreis, lange Tische werden länger). Neue
  Tische (Editor & Einstellungen) bekommen die passende Größe automatisch.

### Migration
- **Bestehende Tische** werden einmalig auf die zur Platzzahl passende Größe
  gebracht (`resize_tables_to_capacity`).

## [1.16.2] – 2026-06-14

### Geändert
- **Tischplan-Editor optisch überarbeitet (Polish):**
  - Tische mit edler Oberfläche (Verlauf, Innenglanz, Schlagschatten),
    statusfarbigem Rand + Statuspunkt; Hover hebt den Tisch hervor.
  - Stühle als echte Stuhl-Form mit Lehne nach außen, gleichmäßig um den Tisch;
    belegte Plätze gefüllt, freie hell.
  - Aufgeräumte Toolbar (Datum/Zeit-Gruppe, Live-Indikator), Hinweis­banner im
    Bearbeiten-Modus, dezent leuchtendes Raster beim Bearbeiten.
  - Raumkopf mit Tisch-/Platz­zähler; Auswahl & Drehgriff erscheinen am Tisch.
  - **Raster-Snap** beim Verschieben für aufgeräumte Layouts.
  - Schöneres Tisch-Popup (Statusfarbe + Belegungsbalken) und Anlegen-Dialog
    (Form als Umschalter, Blur-Hintergrund).

## [1.16.1] – 2026-06-14

### Behoben
- **Tisch anlegen erzeugte einen unsichtbaren Tisch:** Beim Anlegen über den
  Editor war die zurückgegebene Tischgröße leer (DB-Defaults greifen nicht im
  Speicher) → der Tisch wurde mit 0/NaN-Größe gezeichnet. Größe wird jetzt
  explizit gesetzt – abhängig von der Platzzahl, damit Tische nicht winzig sind.
- **Tischnummer bleibt beim Drehen aufrecht:** Beschriftung wird gegen die
  Drehung des Tisches ausgeglichen; nur Tisch und Stühle drehen sich.

### Geändert
- **Größere Darstellung:** Plan-Maßstab erhöht (0,6 → 0,8), Tische und Stühle
  sind besser erkennbar.
- **Stühle gleichmäßig verteilt:** Bei eckigen Tischen werden die Plätze nun
  gleichmäßig um den gesamten Umfang (alle vier Seiten) verteilt statt nur
  oben/unten.

## [1.16.0] – 2026-06-14

### Behoben
- **Tischplan-Editor: Tische lassen sich wieder platzieren.** Das Verschieben
  per Drag & Drop wurde auf Pointer-Events (Maus + Touch, mit Pointer-Capture)
  umgestellt und der Maßstab vereinheitlicht – das bisherige Hängenbleiben beim
  Ziehen ist behoben.

### Neu
- **Tisch direkt im Editor anlegen:** „＋ Tisch" öffnet ein Formular (Name,
  Plätze min./max., eckig/rund) und platziert den Tisch sofort auf dem Plan.
- **Hintergrundbild pro Raum:** Grundriss/Foto hochladen (JPG/PNG/WebP, max.
  6 MB) und als Plan-Hintergrund anzeigen; jederzeit wieder entfernbar. Bilder
  werden tenant-geschützt über die App ausgeliefert (kein Public-Symlink nötig).
- **Sitzplätze sichtbar:** Um jeden Tisch werden die Stühle entsprechend der
  Kapazität dargestellt (eckig: oben/unten, rund: im Kreis).
- **Belegung der Plätze farblich:** Belegte Stühle werden gefüllt, freie hell
  dargestellt – man sieht die Anzahl belegter Plätze (z. B. 3/4), ohne dass ein
  konkreter Sitz zugeordnet wird.
- **Drehen** einzelner Tische im Editor (⟳-Knopf, in 45°-Schritten).

## [1.15.0] – 2026-06-14

### Behoben
- **Login-Falle bei alter Session:** Wer durch ein noch gültiges Session-Cookie
  bereits angemeldet war, wurde von `/login` auf die öffentliche Startseite
  („Hauptdomain") umgeleitet und kam scheinbar nicht mehr rein. Eingeloggte
  Besucher landen jetzt direkt im Backend (`/admin`, bzw. SaaS-Adminübersicht)
  statt auf der Marketing-Seite.

### Neu
- **Abmelde-Seite per URL:** `/abmelden` ist jederzeit direkt aufrufbar und
  zeigt einen Abmelden-Button (plus „Zum Dashboard"). So lässt sich eine alte
  Session beenden, ohne Cookies manuell löschen zu müssen. Gäste werden von dort
  zur Anmeldung geleitet.

## [1.14.1] – 2026-06-14

### Behoben
- **Login-Fehlermeldung verständlich:** Bei falschen Zugangsdaten wurde der rohe
  Schlüssel `auth.failed` angezeigt, weil die Sprachdatei fehlte. Jetzt
  erscheint „E-Mail oder Passwort ist nicht korrekt – oder das Konto ist
  deaktiviert." (neue `lang/de/auth.php` + `lang/en/auth.php`, deckt auch
  `password`/`throttle` ab). Der Hinweis auf deaktivierte Konten hilft, weil der
  Login zusätzlich ein aktives Konto (`is_active`) voraussetzt.

## [1.14.0] – 2026-06-14

### Neu
- **Tisch antippen → Detail-Panel:** Klick/Tipp auf einen Tisch im Live-Board
  öffnet eine Übersicht – Status (farblich passend), Kapazität, alle heutigen
  Buchungen mit Zeit (von–bis), Personenzahl, Gast, Telefon (Tel-Link),
  Notiz/Allergien, No-Show-Risiko und „belegt/sitzt seit". Aktualisiert sich
  live mit dem Board.
- **Aktionen direkt am Tisch:** Statuswechsel (z. B. Eingetroffen, Fertig,
  No-Show, Bestätigen) lassen sich direkt aus dem Panel auslösen.
- **Walk-in vom Plan platzieren:** Freie Tische bieten ein Schnellformular
  (Personen, optional Name/Telefon) zum sofortigen Platzieren – sichtbar nur,
  wenn Walk-ins aktiviert sind und die Berechtigung vorliegt.
- **Reservierung für einen Tisch anlegen:** Direktlink ins Buchungsformular mit
  vorausgewähltem Tisch.

## [1.13.0] – 2026-06-14

### Neu
- **Tischplan auf dem Live-Board:** Das Live-Board hat eine neue Ansicht
  „Tischplan" (umschaltbar neben „Liste"), die die Tische **genau so anzeigt,
  wie sie im Betreiber-Admin angelegt wurden** (Position, Größe, Form, Drehung)
  – farblich nach Live-Status: frei, Ankunft bald, erwartet, belegt, gesperrt.
  Belegte Tische zeigen Gast, Personenzahl und Zeit.
- **Mehrere Räume:** Räume werden als Tabs dargestellt; der Plan lässt sich in
  der Größe anpassen (Zoom −/+ sowie „Einpassen", passt den Plan automatisch in
  den verfügbaren Platz ein).
- **Touch-Bedienung:** Auf Touchdisplays kann per Wisch nach links/rechts
  zwischen den Räumen gewechselt werden.
- **Raumname** wird deutlich, aber dezent als Wasserzeichen auf dem Plan
  eingeblendet.
- Die Ansicht ist nur für tischbasierte Betriebe sichtbar; Salons sehen sie
  nicht.

## [1.12.2] – 2026-06-13

### Behoben
- **Sinnvolle Meldung bei zu großer Gruppe:** Wenn keine Tisch-/Platzkapazität
  die gewünschte Personenzahl je aufnehmen kann, wird kein Warteliste-Tipp mehr
  angezeigt, sondern ein klarer Hinweis „Für N Personen ist online keine
  Reservierung möglich – bitte direkt kontaktieren" inkl. Telefon-Link.
  Die Warteliste wird weiterhin angeboten, wenn nur der gewählte Zeitpunkt
  ausgebucht ist.

## [1.12.1] – 2026-06-13

### Geändert
- **Eingabefelder: eckiger & mehr Platz.** Radien für Bedienelemente
  (`rounded`/`-sm`/`-md`/`-lg`) global verkleinert (Karten bleiben weich),
  einheitlicher Innenabstand für Felder – keine gequetschten Inhalte mehr.
- **Öffnungszeiten:** „+ Zeitfenster hinzufügen" wählt den nächsten Wochentag
  in Reihenfolge vor (1. Zeile Mo, 2. Di, …).

## [1.12.0] – 2026-06-13

### Geändert
- **UI-Politur (Backend & Frontend)** über zentrale Stile in `app.css`:
  einheitliche, moderne Formularfelder mit Brand-Fokusring; klare Focus-States
  für Tastaturbedienung; sanfte Button-/Link-Übergänge; dezente Scrollbars;
  feinere Typografie; wiederverwendbare `.card`/`.card-hover`-Flächen und
  `.btn-brand`. Hebt das gesamte (formularlastige) Backend auf einmal.
- Admin-Erfolgs-/Fehlermeldungen mit Rahmen + Symbol vereinheitlicht.
- Landingpage: Feature-/Pricing-Karten mit dezentem Hover-Lift; gepflegte
  Rechtstext-Typografie (Markdown-Blockquote als Hinweis-Box).

## [1.11.0] – 2026-06-13

### Hinzugefügt
- **Mobiles Menü im Betreiberbereich**: Hamburger öffnet einen Drawer mit der
  vollständigen Navigation (inkl. Standortwechsel, SaaS-Admin, Abmelden) –
  vorher gab es mobil nur vier Icon-Links.
- **Verständliche Fehlermeldungen auf Deutsch**: vollständige
  `lang/de/validation.php` mit Klartext-Meldungen, sprechenden Feldnamen
  (z. B. „Personenzahl", „E-Mail-Adresse") und Hinweisen/Lösungen für die
  wichtigsten Felder. App-Locale standardmäßig `de`. Schluss mit Meldungen wie
  „maximum numeric violation".

## [1.10.0] – 2026-06-13

### Geändert
- **Preismodell: Feature-Parität.** Alle Tarife enthalten den **vollen
  Funktionsumfang** (unbegrenzte Benutzer, API/Webhooks, Zahlungen, Warteliste,
  Berichte, eigenes Branding). Tarife unterscheiden sich **nur** in den
  umsatzrelevanten Limits: **Standorte** und **Tische/Ressourcen**.
  - Starter 19 € (1 Standort, bis 15 Tische), Professional 39 € (1, bis 50),
    Multi-Location 59 € (bis 5 Standorte, bis 200), Enterprise auf Anfrage (∞).
- Landingpage-Preissektion zeigt Standorte/Tische als Differenzierer +
  „Alle Funktionen inklusive".

## [1.9.0] – 2026-06-13

### Geändert
- **Rebrand: GastroBook → Swayy.** Marke überall umbenannt (UI, Mails, Titel,
  Footer, Landingpage, Rechtstext-Vorlagen, Doku). Wordmark ohne Branchen-Emoji.
- Interne Bezeichner umbenannt: Artisan-Kommandos `swayy:create-admin` /
  `swayy:install-legal`, Config-Namespace `config/swayy.php` (`config('swayy.*')`),
  Env-Variablen `SWAYY_*` (vormals `GASTROBOOK_*`), Embed-Widget `swayy-widget` /
  `swayyHeight`.

> **Migration bestehender Installationen:** In der `.env` `GASTROBOOK_*` →
> `SWAYY_*` umbenennen (z. B. `SWAYY_ADMIN_EMAIL`, `SWAYY_BOARD_SSE`,
> `SWAYY_PORT`). GitHub-Repo und GHCR-Image heißen vorerst weiter
> `brightcolor/gastrobook` (separater Repo-Rename).

## [1.8.1] – 2026-06-13

### Geändert
- **Preisstaffel** angepasst: Top-Tarif **59 €** (Multi-Location), darunter
  Professional **39 €** und Starter **19 €**; Enterprise weiterhin auf Anfrage.
  (PlanSeeder, idempotent – greift beim nächsten Deploy/Container-Start.)

## [1.8.0] – 2026-06-13

### Geändert
- **Landingpage komplett überarbeitet** (SaaS-tauglich, professionell):
  Dual-Vertical-Hero (Restaurant **und** Salon) mit Produkt-Vorschau,
  Trust-Strip, Branchen-Sektion, **Hauptfunktionen** prominent (Online-Buchung,
  Live-Board, Zahlungen/No-Show), Sekundär-Feature-Grid, kompakte Liste „Und
  vieles mehr", aktualisierte Schritte/Preise/FAQ und stärkerer CTA.
- Marketing-Layout: Titel/Meta/Nav/Footer auf „Restaurants & Salons" angepasst.

## [1.7.1] – 2026-06-13

### Geändert
- **Vollständige Datenschutzerklärung als Vorlage** (`resources/legal/datenschutz.md`),
  abgestimmt auf die tatsächliche Verarbeitung der Anwendung: Reservierung/Termin,
  Allergien (Art. 9), Einwilligungsnachweis (gehashte IP), Gästeprofil/No-Show-Hinweis,
  Magic-Link-Konto & E-Mail-Bestätigung, Zahlungen (Stripe/PayPal, keine Kartendaten),
  E-Mail/SMS (seven.io), Newsletter, Warteliste/Events/Feedback, Cookies/Logs/Audit,
  Empfänger/Auftragsverarbeiter, Drittland, Speicherdauer, Betroffenenrechte.

### Hinweis
- Bestehende Installationen mit eigener `storage/app/legal/datenschutz.md` bleiben
  unverändert; zum Übernehmen der neuen Vorlage Datei löschen und
  `php artisan swayy:install-legal` ausführen (oder `--force`).

## [1.7.0] – 2026-06-13

### Geändert
- **Impressum, Datenschutz, AGB jetzt als Markdown-Dateien** unter
  `storage/app/legal/*.md` (bind-gemountet, auf dem Host editierbar) statt
  fester Blade-Platzhalter.
- Der Container legt fehlende Dateien beim Start an
  (`php artisan swayy:install-legal`, aus Vorlagen in `resources/legal`).
- Inhalte werden pro Request frisch gelesen → **Änderungen sofort wirksam,
  ohne Stack-Neustart**.

## [1.6.1] – 2026-06-13

### Behoben / Verbessert
- **Reverse-Proxy-Support**: App vertraut jetzt `X-Forwarded-*` (Trusted Proxies in
  `bootstrap/app.php`) → korrekte `https`-URLs in Mails, Magic-Links und Zahlungs-
  Rücksprüngen hinter Traefik/nginx/Caddy. README-Abschnitt „Hinter einem Reverse
  Proxy" (APP_URL, SSE-Pufferung, Port nur lokal binden).

## [1.6.0] – 2026-06-13

### Hinzugefügt
- **Online-Umbuchung**: Gast verschiebt seinen Termin selbst (über Verwalten-Link
  oder Kundenkonto), innerhalb der Umbuchungsfrist; Verfügbarkeit wird neu geprüft
  (Tisch beim Restaurant, Mitarbeiter beim Salon).
- **Kundenkonto per Magic-Link** (passwortlos, pro Mandant): Anmeldelink per
  E-Mail → Übersicht aller Termine mit Umbuchen/Verwalten.
- **E-Mail-Bestätigung** (opt-in pro Standort): unbestätigte Gäste bestätigen ihre
  Adresse beim ersten Buchen; die Buchung wird bis dahin als Anfrage gehalten und
  nach Bestätigung automatisch bestätigt. Einmal verifiziert = künftig kein Schritt mehr.

### Behoben
- Zeitabhängiger (flaky) Test `min_lead_time` deterministisch gemacht.

## [1.5.0] – 2026-06-13

### Hinzugefügt
- **Anzahlungs-Rückerstattung bei fristgerechtem Storno** – voll konfigurierbar
  pro Standort:
  - Modus: **aus / manuell (Freigabe durch Personal) / automatisch**
  - Ausführung: **sofort** oder **nach Zeitplan** (Sammellauf via Cron)
  - variabler **Erstattungssatz in %** (z. B. Bearbeitungsgebühr einbehalten)
- Provider-Refunds für Stripe (`/v1/refunds`) und PayPal (Capture-Refund);
  Zahlungsreferenz wird bei der Zahlung gespeichert.
- Admin-Bereich **Rückerstattungen** (Freigeben/Ablehnen/erneut versuchen);
  Hook bei Gast- und Personal-Storno; Status-Hinweis auf der Storno-Seite.
- Geplanter Job `ProcessScheduledRefunds` (alle 15 Min).

### Hinweise
- Nach Ablauf der Stornofrist und bei No-Show erfolgt **keine** Erstattung.

## [1.4.0] – 2026-06-13

### Hinzugefügt
- **PayPal als Zahlungsanbieter** (Orders v2, REST, Capture-on-Return) – jeder
  Mandant hinterlegt eigene Client-ID/Secret (verschlüsselt), Sandbox/Live-Modus.
- **Mehrere Zahlungsanbieter gleichzeitig**: Sind Stripe *und* PayPal aktiv,
  wählt der Gast an der Kasse die Zahlungsart; bei nur einem geht es direkt weiter.
- `PaymentProviderManager::available()` / `provider($key)`; Settings-UI-Karte für PayPal.

### Behoben
- Stripe-Webhook verwendet jetzt gezielt den Stripe-Provider zur Signaturprüfung
  (statt „erster verfügbarer"), wichtig wenn auch PayPal verbunden ist.

## [1.3.0] – 2026-06-13

### Hinzugefügt
- **Live-Board fürs Personal** (`/admin/board`): neue & offene Buchungen sowie
  der heutige Ablauf in zwei Spalten, mit Inline-Aktionen (Bestätigen,
  Eingetroffen, Fertig, No-Show, Storno) über den bestehenden Status-Endpoint.
- **Dark Mode** (umschaltbar, gemerkt) und **Vollbild** für den Wand-/Tresen-Einsatz.
- **Echtzeit via Server-Sent Events** (`/admin/board/stream`) mit automatischem
  Fallback auf Polling; abschaltbar via `SWAYY_BOARD_SSE=false` (z. B. auf
  dem Single-Worker-Dev-Server).
- KPIs (heute, Gäste, anwesend, Ankünfte <1h, offen, Warteliste); No-Show-Risiko-
  und Allergie-Hinweise; funktioniert für Restaurant- und Salon-Modus.

### Behoben
- Mehrdeutige Spalte `sort_order` beim Laden der Reservierungs-Leistungen
  (`orderByPivot`).

## [1.2.0] – 2026-06-13

### Hinzugefügt
- Öffentlicher **Tischplan auf der Buchungsseite** (opt-in pro Standort,
  `public_floorplan_enabled`): Gäste sehen zum gewählten Datum/Zeit/Personen-
  zahl die Verfügbarkeit aller Tische, gruppiert nach Räumen/Etagen (Tabs),
  positioniert wie im Admin-Plan – ohne Gästedaten preiszugeben.
- **Optionale Tischwahl**: Gast kann einen freien, passenden Tisch direkt
  wählen; sonst automatische Zuteilung wie bisher.
- Endpoint `GET /book/{tenant}/{location}/floorplan`.

## [1.1.1] – 2026-06-13

### Behoben
- Arbeitszeiten: Ende-vor-Beginn wird jetzt zuverlässig abgewiesen (statt
  unzuverlässigem Wildcard-`after` in der Validierung).
- Abwesenheiten: invertierte Zeiträume (Ende ≤ Beginn) werden abgewiesen –
  vorher entstand ein wirkungsloser Eintrag.

### Geändert
- Salon-Slot-Berechnung lädt die Tagesbuchungen einmal und prüft Überschneidungen
  im Speicher (vorher eine DB-Abfrage pro Slot) – deutlich weniger Queries.
- Totes Farbfeld in der Leistungs-Bearbeitung entfernt.

## [1.1.0] – 2026-06-13

### Hinzugefügt
- Artisan-Kommando `php artisan swayy:create-admin` legt einen Plattform-
  Oberadmin an (interaktiv, per Optionen oder per `SWAYY_ADMIN_*`-Env beim
  ersten Start). `--if-missing` (Boot), `--force` (bestehendes Konto hochstufen).
- Container-Start ruft das Kommando automatisch mit `--if-missing` auf – der
  erste Start kann so einen Oberadmin erzeugen, ohne den Demo-Seeder zu nutzen.
- `config/gastrobook.php` für die Bootstrap-Admin-Daten.

## [1.0.1] – 2026-06-13

### Geändert
- Mailpit aus dem Docker-Stack entfernt; E-Mail-Versand läuft jetzt über einen
  echten, in `.env` konfigurierbaren SMTP-Provider (`MAIL_*`-Gerüst in
  `.env.example`, Doku im README-Abschnitt „E-Mail").
- `install.sh` und `docker-compose.yml` ohne Mailpit/`MAILPIT_PORT`.

## [1.0.0] – 2026-06-13

Erste versionierte Veröffentlichung. Enthält den gesamten bisherigen
Funktionsumfang.

### Plattform
- Multi-Tenant-SaaS (Laravel 13), Tenant-Isolation via globalem Scope + TenantContext
- Rollen/Rechte-Matrix, Auditlog, DSGVO-Werkzeuge (Export, Anonymisierung, Retention)
- REST-API v1 (Sanctum), Webhooks (HMAC), SaaS-Website + Self-Service-Registrierung
- Docker-Image via CI nach GHCR, Quick-Install-Skript mit Autoport

### Restaurant-Modus
- Reservierungsbuch, grafischer Tischplan, Walk-ins, Warteliste
- Verfügbarkeits-Engine (Öffnungszeiten, Kapazität, Tische/Kombinationen)
- Events & Tickets, Stripe-Anzahlungen, Gäste-CRM, Berichte

### Salon-/Dienstleister-Modus
- Umschaltbarer Betriebstyp (Restaurant ⇄ Friseur/Dienstleister) pro Mandant
- Leistungen (Dauer/Preis) und Mitarbeiter (m:n), Termin-Buchung pro Mitarbeiter
- Individuelle Mitarbeiter-Arbeitszeiten und Abwesenheiten (Urlaub/Krank)
- Puffer zwischen Terminen in der Slot-Berechnung
- Kombi-Leistungen: frei per Pills kombinierbar, Dauer/Preis summiert, ein Termin
- Lückenoptimierer: packt „Beliebig"-Termine eng, reduziert Leerlauf (opt-in)

### Integrationen
- SMS-Erinnerungen via seven.io (deutscher Anbieter, DSGVO, verschlüsselte Credentials)
- MailWizz-Newsletter-Sync

[1.19.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.19.1
[1.19.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.19.0
[1.18.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.18.0
[1.17.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.17.0
[1.16.3]: https://github.com/brightcolor/gastrobook/releases/tag/v1.16.3
[1.16.2]: https://github.com/brightcolor/gastrobook/releases/tag/v1.16.2
[1.16.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.16.1
[1.16.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.16.0
[1.15.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.15.0
[1.14.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.14.1
[1.14.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.14.0
[1.13.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.13.0
[1.12.2]: https://github.com/brightcolor/gastrobook/releases/tag/v1.12.2
[1.12.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.12.1
[1.12.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.12.0
[1.11.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.11.0
[1.10.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.10.0
[1.9.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.9.0
[1.8.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.8.1
[1.8.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.8.0
[1.7.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.7.1
[1.7.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.7.0
[1.6.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.6.1
[1.6.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.6.0
[1.5.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.5.0
[1.4.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.4.0
[1.3.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.3.0
[1.2.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.2.0
[1.1.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.1.1
[1.1.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.1.0
[1.0.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.0.1
[1.0.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.0.0
