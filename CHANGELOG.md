# Changelog

## [1.55.1] – 2026-06-29

### Behoben (CI / Build)
- **Frontend-Build (und damit der Release-Image-Build) schlug fehl** – `vite build`
  lud über `laravel-vite-plugin` zur **Build-Zeit eine Remote-Font** (`bunny('Instrument
  Sans')`) aus dem Netz; in der CI/offline führte das zu `fetch failed` / `ECONNRESET`.
  Die Font war zudem **ungenutzt** (die CSS hostet Inter/Fraunces bereits selbst via
  `@fontsource-variable/*`). Remote-Font-Config aus `vite.config.js` entfernt → der
  Build hat **keine Netzabhängigkeit** mehr und läuft zuverlässig.

## [1.55.0] – 2026-06-26

### Neu
- **README: vollständige Artisan-Befehlsreferenz** – neuer Abschnitt
  „CLI-Befehle (Artisan)" mit Tabellen (Plattform-Verwaltung, Diagnose & Betrieb,
  Lizenz) inkl. der **kompletten Befehle samt Beispiel-Optionen**, nicht nur der
  Parameter.
- **`php artisan swayy:queue-health`** – zeigt Queue-Verbindung, wartende und
  fehlgeschlagene Jobs (inkl. der letzten 5 Fehler) auf einen Blick; ergänzt
  `swayy:test-mail` für die Mail-/Queue-Diagnose.

## [1.54.0] – 2026-06-26

### Neu / Diagnose
- **`php artisan swayy:test-mail {email}`** – sendet eine Testmail **synchron
  (ohne Queue)** und zeigt Mailer/Host/From an. Trennt eindeutig ein
  SMTP-/Mail-Config-Problem von einem Queue-Worker-Problem.
- README-Abschnitt „Queue & Scheduler" um eine Troubleshooting-Checkliste
  ergänzt (`swayy:test-mail`, `queue:failed`, Container-Logs).

### Hinweis (kein Code-Bug)
- Die Queue-/Worker-/Redis-Konfiguration in `docker-compose.yml` ist korrekt
  (eigener `queue`-Container mit `queue:work`, frische Env – kein Build-Time
  `config:cache` –, Redis-Read-only-Lockup via `--stop-writes-on-bgsave-error
  no` entschärft). Fehlende Mails liegen daher i. d. R. an der **SMTP-Config**
  (`MAIL_*`) oder einem nicht laufenden `queue`-Container, nicht am App-Code.

## [1.53.2] – 2026-06-26

### Geändert
- **GoCardless-Flow vollständig verifiziert** (SEPA-Abrechnung aus v1.52.0):
  Einrichten → Mandat → Subscription → Mails an beide, Kündigen, Webhook-Events
  (Zahlung bestätigt/fehlgeschlagen, Mandat beendet) – alle 6 Feature-Tests grün.
  Webhook gibt bei ungültiger Signatur jetzt **401** zurück (statt des
  nicht-standardisierten 498).

## [1.53.1] – 2026-06-26

### Behoben
- **Passwort-Reset-Mail kam nicht an** – die Mail wurde per `queue()` versendet
  und hing damit an einem laufenden Queue-Worker/Redis (steht der oder ist Redis
  im Read-only-Lockup, geht die Mail still verloren – unabhängig von der Rolle).
  Der Passwort-Reset wird jetzt **synchron** verschickt (unabhängig von der
  Queue); Versandfehler werden geloggt statt verschluckt, die enumeration-sichere
  Erfolgsmeldung bleibt. Regressionstests ergänzt (Super-Admin + normaler Nutzer).

## [1.53.0] – 2026-06-26

### Neu / Geändert
- **SaaS-Admin überarbeitet** – aus der einzelnen scrollbaren Tabelle wurde ein
  echtes Verwaltungs-Interface mit eigenem Layout (Sidebar):
  - **Dashboard** (`/saas`) mit KPI-Karten (Mandanten, Testphasen, Benutzer,
    Reservierungen/Monat), Status-Aufschlüsselung, Tarif-Übersicht und zuletzt
    angelegten Mandanten.
  - **Mandanten** als responsive Karten statt Mini-Tabelle, mit Suche, Inline-
    Bearbeitung (Tarif/Status/Trial), Supportzugriff und einklappbarem Anlegen.
  - **Benutzerverwaltung** (`/saas/users`): Plattform-Benutzer anlegen, Plattform-
    Rolle ändern und löschen (Super-Admin); Schutz vor Selbstlöschung und vor dem
    Entfernen des letzten Super-Admins.
- **Standort-Umschalter ausgeblendet bei nur einem Standort** – statt eines
  sinnlosen Dropdowns wird der Standortname schlicht angezeigt.

## [1.52.1] – 2026-06-26

### Behoben
- **Öffentlicher Tischplan wurde nie angezeigt** – obwohl aktiviert. Die
  Tischplan-Sektion lag im `.sp-body` von Schritt 2 („Wann?"); sobald ein
  Zeit-Slot gewählt wurde, markierte das JS den Schritt als „done", was den
  `.sp-body` per CSS auf `display:none` setzt – und damit den gerade
  eingeblendeten Tischplan gleich wieder versteckte. Der Tischplan ist jetzt ein
  eigener Block zwischen Schritt 2 und 3 und bleibt sichtbar. Regressionstest
  ergänzt (Sektion liegt außerhalb des einklappenden Schritts).

## [1.52.0] – 2026-06-26

### Neu
- **SEPA-Lastschrift fürs Software-Abo (GoCardless)** – Betreiber können ihr
  Abonnement **jederzeit** per Lastschrift einrichten und **direkt im Konto wieder
  kündigen** (neuer Bereich „Abrechnung", Recht `billing.manage`):
  - Mandatserteilung über die GoCardless-Redirect-Seite, danach automatische
    monatliche Subscription in Höhe des Tarifpreises; Tenant wird auf „aktiv"
    gesetzt.
  - **E-Mail an Kunde UND Plattformbetreiber** bei Einrichtung, Kündigung sowie
    asynchronen Ereignissen (Zahlung eingegangen/fehlgeschlagen, Mandat beendet)
    via signiertem GoCardless-Webhook (`/webhooks/gocardless`).
  - Mandats-Einrichtung als „genau-einmal"-Flow per `lockForUpdate` abgesichert
    (kein Doppel-Abo bei doppeltem Rücksprung).
  - Konfiguration über `GOCARDLESS_ACCESS_TOKEN` / `_ENVIRONMENT` /
    `_WEBHOOK_SECRET`; Plattform-Mail-Empfänger `SWAYY_OWNER_EMAIL`.
  - Datenschutzerklärung um GoCardless als Zahlungsdienstleister ergänzt.

## [1.51.1] – 2026-06-26

### Behoben (Tiefen-Audit, 3. Runde)
- **Warteliste: Doppel-Annahme verhindert** – `WaitlistService::acceptOffer`
  prüfte den Angebotsstatus vor der Transaktion und ohne Sperre. Ein
  Gast-Doppelklick auf den Annehmen-Link konnte zwei Reservierungen aus einem
  einzigen Angebot erzeugen (Doppelbuchung, zwei Tische belegt). Das Angebot
  wird jetzt innerhalb der Transaktion per `lockForUpdate` gesperrt und erneut
  geprüft; nur die erste Annahme erstellt eine Reservierung.

## [1.51.0] – 2026-06-26

### Sicherheit / Behoben (Tiefen-Audit)
- **SSRF in ausgehenden Webhooks geschlossen** – Endpoint-URLs wurden nur als
  `https` geprüft. Ein Tenant-Admin konnte interne Adressen hinterlegen
  (`169.254.169.254`, `localhost`, private IPs); der Zustell-Job rief sie auf
  und speicherte die Antwort (reflektiertes SSRF). Neuer `OutboundUrlGuard`
  lehnt URLs ab, die auf private/loopback/link-local/reservierte IPs auflösen
  (sowie URLs mit eingebetteten Zugangsdaten) – geprüft **beim Anlegen und bei
  der Zustellung** (gegen DNS-Rebinding); der HTTP-Client folgt keinen Redirects
  mehr.
- **Doppel-Erstattung verhindert** – `RefundService::process()` war nur durch
  einen Status-Check geschützt und ließ sich für eine bereits laufende Erstattung
  erneut ausführen. Bei gleichzeitigem Lauf (Sofort-Verarbeitung + geplanter
  Batch, oder Wiederholen-Button + Batch) konnte der Anbieter zweimal erstatten.
  Jetzt sichert ein atomarer `approved→processing`-Compare-and-Swap, dass nur ein
  Aufruf die Anbieter-Erstattung ausführt.

## [1.50.0] – 2026-06-26

### Neu / Geschlossene Flow-Lücken
Aus dem Vollständigkeits-Audit – Funktionen, die im Datenmodell/Recht vorhanden,
aber nie über die Oberfläche bedienbar waren:

- **Sperrzeiten (Blackouts) verwalten** (`blackouts.manage`) – Die Logik war
  längst in der Verfügbarkeitsprüfung verdrahtet (Voll-Sperre + Cover-Reduktion),
  aber es gab keine UI. Jetzt: Sperrzeiten pro Standort **oder** Raum anlegen
  (voll gesperrt oder max. Gästezahl) und löschen, im Tab „Öffnungszeiten".
- **Events bearbeiten** – Bisher nur Status änderbar; jetzt vollständiges Edit
  (Titel, Beschreibung, Datum/Zeit, Kapazität, Preis, öffentlich) auf der
  Event-Detailseite. Kapazität kann nicht unter bereits verkaufte Tickets fallen;
  der Slug bleibt stabil (öffentliche Links bleiben gültig).
- **Räume umbenennen & löschen** – bisher nur anlegbar. Löschen ist gesperrt,
  solange noch Tische im Raum sind (kein versehentlicher Tisch-/Historienverlust).
- **Tische bearbeiten** – Name, Min/Max-Kapazität und Eigenschaften (online
  buchbar, kombinierbar, barrierefrei) änderbar (Tab „Räume & Tags"); Anlegen
  und Positionieren weiterhin im Tischplan.
- **Sonderöffnungszeiten löschen** – bisher nur hinzufügbar.

## [1.49.0] – 2026-06-26

### Neu
- **Standort-Verwaltung im Admin** (`/admin/locations`, Recht `locations.manage`) –
  bisher konnte ein weiterer Standort nur per SaaS-Admin oder DB angelegt werden,
  obwohl Tarif-Limit, Standort-Umschalter und pro-Standort-Einstellungen längst
  existierten (Flow-Sackgasse). Jetzt können Inhaber/Admins Standorte **anlegen,
  umbenennen/bearbeiten und aktivieren/deaktivieren**:
  - Tarif-Limit-Prüfung über `PlanLimitService` (Starter/Professional 1,
    Multi-Location 5, Enterprise unbegrenzt) inkl. Hinweis bei erreichtem Limit.
  - Beim Anlegen wird automatisch der `LocationSettings`-Datensatz erzeugt; der
    Slug ist pro Mandant eindeutig und bleibt beim Umbenennen stabil
    (Buchungslinks bleiben gültig).
  - Der letzte aktive Standort kann nicht deaktiviert werden (kein Lockout).
  - Neuer Navigationspunkt „Standorte".

## [1.48.0] – 2026-06-26

### Geändert
- **Betrieb löschen entfernt jetzt wirklich alles** – Die Inhaber-Löschung in
  „Mein Konto → Betrieb löschen" nutzte bisher Soft-Delete, wodurch abhängige
  Daten erhalten blieben. Jetzt `forceDelete`: Mandant samt Standorten,
  Reservierungen, Gästen, Personal, Einstellungen und Audit-Logs wird endgültig
  entfernt (DB-Kaskade), der Slug wird wieder frei.

### Behoben
- **Sicherheits-/Bug-Audit** (Branch `audit/bug-security-fixes`):
  - **Event-Überbuchung** bei gleichzeitigen Buchungen verhindert –
    `EventBookingService::book` serialisiert den Kapazitäts-Recheck nun per
    `pg_advisory_xact_lock` (wie der Reservierungspfad). Vorher konnten zwei
    parallele Buchungen denselben Restplatz belegen.
  - **Ungültiger Reservierungsstatus** führte zu HTTP 500 (ValueError aus
    `ReservationStatus::from`). Wird jetzt per `Rule::enum` sauber abgewiesen.
- Regressionstests ergänzt (Status-Validierung, Betrieb-Hard-Delete inkl.
  Kaskade). Gesamt 207 Tests grün.

## [1.39.0] – 2026-06-26

### Neu
- **Weitere CLI-Auflistungen** für den Plattformbetrieb:
  - `php artisan swayy:reservations` – Reservierungen über alle Mandanten
    (Filter: `--tenant`, `--date`, `--upcoming`, `--status`, `--limit`).
  - `php artisan swayy:billing-requests` – eingegangene Billing-Anfragen
    (Option `--pending` = bestätigt, aber noch nicht freigeschaltet).
  - `php artisan swayy:plans` – Tarife inkl. Mandantenzahl (`--all` für inaktive).
  - `php artisan swayy:stats` – Plattform-Überblick (Mandanten nach Status,
    Nutzer, Gäste, Reservierungen, offene Billing-Anfragen).
- **SaaS-Admin: Trial verlängern** – In der Mandantenübersicht (`/saas/tenants`)
  gibt es jetzt eine Trial-Spalte mit Ablaufdatum und einem „+ Tage"-Feld, das den
  Testzeitraum verlängert und das Konto sofort wieder aktiviert. Trial-Status
  (abgelaufen / Billing ausstehend) wird in der Status-Spalte korrekt angezeigt.

## [1.38.0] – 2026-06-26

### Neu
- **CLI: Nutzerliste** – Neuer Befehl `php artisan swayy:users` listet alle Nutzer
  über alle Mandanten hinweg: ID, Name, E-Mail, Plattform-Rolle (saas_role),
  Mandanten-Mitgliedschaften samt Rolle, angelegt. Optionen: `--tenant=ID|slug`,
  `--saas` (nur Plattform-Admins), `--search=`. Auf dem Server via
  `docker compose exec app php artisan swayy:users`.

## [1.37.0] – 2026-06-26

### Neu
- **CLI: Mandantenliste** – Neuer Befehl `php artisan swayy:tenants` zeigt deine
  Kunden (Betriebe) im Terminal: ID, Name, Slug, Typ, Status, Tarif, Trial-Ende,
  Nutzerzahl, Owner-E-Mail, angelegt. Optionen: `--status=` (z. B. active,
  trial_expired), `--search=` (Name/Slug), `--with-trashed`. Auf dem Server via
  `docker compose exec app php artisan swayy:tenants`.

## [1.36.0] – 2026-06-26

### Neu
- **CLI: Kundenliste** – Neuer Befehl `php artisan swayy:guests` zeigt angelegte
  Kunden im Terminal (Name, E-Mail, Telefon, Besuche, letzter Besuch, angelegt).
  Optionen: `--tenant=ID|slug` (nach Mandant filtern), `--search=` (Name/E-Mail/
  Telefon), `--limit=` (Standard 50, `0` = alle), `--with-anonymized`. Anonymisierte
  (gelöschte) Kunden sind standardmäßig ausgeblendet. Auf dem Server via
  `docker compose exec app php artisan swayy:guests`.

## [1.35.1] – 2026-06-26

### Behoben
- **Falsche Uhrzeiten im Auditlog:** Zeitstempel werden in UTC gespeichert, aber
  unkonvertiert angezeigt – dadurch erschienen die Zeiten 2 Stunden zu früh
  (Sommerzeit). Das Auditlog zeigt die Zeit jetzt in der Zeitzone des Standorts
  (Standard Europe/Berlin).

## [1.35.0] – 2026-06-26

### Neu
- **Tischplan skaliert mit der Raumgröße:** Das Zeichen-Canvas passt sich jetzt
  dynamisch der verfügbaren Breite und der hinterlegten Raumgröße an (1 Einheit =
  1 cm). Unter Einstellungen → Räume eingetragene Meter (Breite × Tiefe) bestimmen
  direkt die Größe und Proportion der Zeichenfläche – große Räume bekommen eine
  große Fläche, alle Tische/Zonen skalieren proportional mit.
- **Realistische, dynamische Tischmaße:** Tischgrößen folgen jetzt gastronomischen
  Standardmaßen (~60 cm pro Gedeck, Tiefe 80–90 cm; runde Tische nach Umfang) und
  wachsen unbegrenzt mit der Personenzahl. Dadurch überlappen die Stühle bei keiner
  Platzzahl mehr – vom 2er-Tisch bis zur Banketttafel. Bestehende Tische werden per
  Migration auf die neuen Maße umgerechnet.

### Behoben
- **Zonen ließen sich nicht anlegen/bearbeiten:** Das Anlegen, Ändern und Löschen
  von Flächenzonen warf serverseitig einen Fehler (falscher Audit-Log-Aufruf) und
  brach mit „Fehler 500" ab. Zonen sind jetzt voll bearbeitbar (anlegen, umbenennen,
  Farbe/Transparenz, Eckpunkte verschieben, löschen); abgesichert durch Tests.

## [1.34.3] – 2026-06-26

### Behoben
- **Betriebstyp-Umschaltung (Restaurant ↔ Friseursalon/Dienstleister) ohne
  sichtbare Wirkung:** Das Settings-Formular wird per AJAX abgeschickt und lädt
  die Seite nur neu, wenn die Antwort ein `reload`-Flag enthält. Beim Typwechsel
  fehlte dieses Flag — der Typ wurde zwar gespeichert, aber Navigation, Auswahl
  und Buchungsseite spiegelten den neuen Typ erst nach manuellem Reload wider.
  `updateTenantType` gibt jetzt `reload: true` zurück; abgedeckt durch zwei neue
  Tests.

## [1.34.2] – 2026-06-25

### Behoben
- **Redis nahm keine Schreibzugriffe mehr an / App nicht erreichbar:** Schlug ein
  RDB-Snapshot fehl (Rechte auf dem Bind-Mount `docker/data/redis`), ging Redis
  per Default in den Read-Only-Modus (`stop-writes-on-bgsave-error yes`). Da
  Cache, Session und Queue alle auf Redis laufen, fiel damit die komplette App
  aus. Redis startet jetzt mit `--dir /data --stop-writes-on-bgsave-error no`
  und einem `redis-cli ping`-Healthcheck; die App wartet via
  `condition: service_healthy` auf ein wirklich antwortendes Redis.

## [1.34.1] – 2026-06-25

### Behoben
- **Container-Restart-Schleife nach Update:** Die Trial-Migration legte Tabelle
  und Spalte in einem Schritt an. Da MySQL-DDL nicht transaktional ist, blieb
  bei einem Abbruch die Tabelle `billing_requests` bestehen, ohne dass die
  Migration als erledigt markiert wurde — der nächste Boot scheiterte an
  „table already exists" und der Container kam nicht mehr hoch. Die Migration
  ist jetzt idempotent (`hasTable`/`hasColumn`-Guards) und re-run-sicher.

## [1.34.0] – 2026-06-25

### Neu
- **Trial-Ablauf (30 Tage):** Nach Ablauf des Testzeitraums werden alle Admin-Bereiche
  gesperrt und Nutzer auf ein Upgrade-Formular weitergeleitet. Das Formular erfasst
  Kontaktdaten, Rechnungsanschrift und gewünschten Tarif — Billing erfolgt manuell
  außerhalb der Anwendung.
- **E-Mail-Bestätigungsflow:** Nach dem Absenden des Formulars erhält der Kunde eine
  Bestätigungs-E-Mail. Erst nach Klick auf den Link (72 h gültig) wird die Anfrage an den
  Plattform-Owner weitergeleitet. Der Tenant-Status wechselt auf `pending_billing`.
- **Owner-Benachrichtigung:** Erst nach E-Mail-Bestätigung durch den Kunden geht eine
  vollständige Mail an `SWAYY_OWNER_EMAIL` mit allen Rechnungsdaten und einem
  Direktlink zur Aktivierung.
- **Billing-Anfragen-Übersicht (Admin):** Neue Seite `/admin/billing-requests` listet alle
  Anfragen mit Status, Tarif, Kontaktdaten und einem „Konto freischalten"-Button.
- **5-Tage-Vorwarnung:** Der tägliche Scheduler sendet 5 Tage vor Trial-Ablauf eine
  Erinnerungs-E-Mail an alle Tenant-Owner-Nutzer sowie an den Plattform-Owner.
  Erneuter Versand wird durch `trial_warning_sent_at` verhindert.
- **Neue Umgebungsvariable:** `SWAYY_OWNER_EMAIL` steuert, an welche Adresse
  Owner-Benachrichtigungen gesendet werden.

## [1.33.0] – 2026-06-19

### Neu
- **Flächenzonen im Tischplan:** Admins können jetzt farbige Polygon-Zonen über den Tischplan
  legen – z. B. „VIP-Bereich", „Außenterrasse" oder „Standardbereich". Das Zeichentool wird
  über den neuen „Zonen"-Button in der Tischplan-Toolbar aktiviert; ein Doppelklick oder Klick
  auf den ersten Vertex schließt das Polygon. Name, Farbe und Transparenz sind frei wählbar.
- **2-stufige Gast-Buchungsansicht:** Wenn Zonen definiert sind, sehen Gäste auf der
  öffentlichen Buchungsseite zuerst eine Übersicht der Bereiche als anklickbare Karten.
  Nach der Wahl werden im Tischplan nur die Tische der gewählten Zone aktiv dargestellt;
  „Alle Bereiche" zeigt den ungefilterter Gesamtplan.
- **Raumgröße in Metern:** Pro Raum können optional reale Abmessungen (Breite/Tiefe in m)
  hinterlegt werden. Ist ein Wert gesetzt, erscheint unterhalb des Canvas ein Maßstab-Ruler.
- **Zonen-Legende:** Oberhalb der Räume wird automatisch eine Farblegende aller definierten
  Zonen eingeblendet.

## [1.32.0] – 2026-06-16

### Neu
- **Website-Widgets:** Gastronomen können die Buchungsfunktion jetzt direkt auf ihrer eigenen
  Website einbinden – in drei Varianten:
  - **Popup-Button** (`/widget/{tenant}/{location}/popup.js`) – Ein Button öffnet das
    Buchungsformular als modales Overlay. Konfigurierbar per `data-label`, `data-color` und
    `data-float` (floating-Button unten rechts). Keyboard-zugänglich (Escape schließt),
    responsiv (Bottom-Sheet auf Mobile, zentriertes Modal ab 640 px).
  - **Eingebettet (iFrame)** – Bekanntes Embed-Script als `<div id="swayy-widget"></div>`
    mit automatischer Höhenanpassung via `postMessage`.
  - **Direktlink** – Styled `<a>`-Button ohne JavaScript für maximale Kompatibilität.
- **Widget-Einstellungen im Admin:** Neue Sektion „Website-Widget" in den Einstellungen mit
  Tab-Auswahl, Live-Vorschau der Snippets und Kopier-Button. Button-Text, Farbe und
  Floating-Modus sind live konfigurierbar und generieren automatisch den passenden Code.

## [1.31.3] – 2026-06-16

### Verbessert
- **Stornierungsseite:** Sachlicherer Text ohne übertriebenes Bedauern – Überschrift zeigt
  direkt „Reservierung storniert" (bzw. „Termin storniert"), Bestätigungs-Emoji ✓ statt 👋,
  und ein ehrlicher Abschiedssatz anstelle der formelhaften Floskel.

## [1.31.2] – 2026-06-16

### Verbessert
- **Tischkombinationen als Modal:** Panel ist jetzt ein zentriertes Modal mit Backdrop
  (statt Slide-Over von rechts), konsistent mit den anderen Elementen im Tischplan.
- **Stirnseiten-Kapazität korrigiert:** Algorithmus war falsch – Tische mit zwei
  Stirnsitzen wurden ans äußere Ende der Reihe gesetzt statt in die Mitte, was zu einer
  zu optimistischen Kapazitätszahl führte. Neue Formel berechnet korrekt:
  `sub = h2×2 + h1 − max(0, h2 − mittlereSlots)` wobei h2/h1 = Anzahl Tische mit 2/1
  Stirnsitzen; Tische in Mittelpositionen können beide Enden an Verbindungsstellen abgeben.

## [1.31.1] – 2026-06-16

### Neu
- **Passwort zurücksetzen:** „Passwort vergessen?"-Link auf der Login-Seite führt zu
  `/passwort-vergessen`. Nach Eingabe der E-Mail wird ein Token-gesicherter Reset-Link
  versendet (60 min gültig). Auf der Reset-Seite wird ein neues Passwort mit
  Stärkevalidierung vergeben. User-Enumeration wird verhindert (immer gleiche Meldung).

## [1.31.0] – 2026-06-15

### Neu
- **Dashboard live KPIs:** Kacheln aktualisieren sich alle 30 Sekunden ohne Seiten-Reload.
  Bei neuen Buchungen oder Anfragen erscheint ein Toast und die betroffenen Kacheln blinken kurz auf.
  Alle Kacheln sind jetzt anklickbar und führen direkt zum jeweiligen Bereich.
- **Reservierungs-Tags:** Farbige Tags (VIP, Allergiker, Geburtstag, …) können in den
  Einstellungen angelegt und auf der Reservierungs-Detailseite zugewiesen werden.
  Tags erscheinen als farbige Punkte auf den Tischen im Tischplan und als Badges im Popup.
- **Tisch wechseln im Tischplan:** Klick auf einen belegten Tisch → „🔄 Tisch wechseln"
  im Popup aktiviert einen Reassign-Modus. Ein Banner erscheint oben; ein Klick auf den
  Zieltisch setzt die Reservierung um.
- **Export mit Datumsbereich:** Der CSV-Export im Reservierungsbuch hat jetzt ein
  Datumsbereich-Dropdown (Von / Bis), anstatt nur den aktuellen Tag zu exportieren.

## [1.30.0] – 2026-06-15

### Neu
- **Tischkombinationen im Tischplan:** Kombinationen werden jetzt direkt im Tischplan
  verwaltet (Schiebepanel über „🔗 Kombinationen"). Neue Kombinationen können per
  Checkbox-Auswahl angelegt werden; bestehende werden einzeln gelöscht.
- **Intelligente Kapazitätsberechnung:** Beim Erstellen einer Kombination wird die
  Gesamtkapazität automatisch vorgeschlagen. Bei eckigen Tischen mit Stirnsitzplätzen
  (ungerade oder ≥ 8 Plätze) werden je nach Anordnung 1–2 Plätze pro Verbindungsstelle
  abgezogen; runde Tische verlieren keinen Platz.
- **AJAX-Einstellungsseite:** Alle Speichern-Formulare übermitteln jetzt per fetch –
  kein Seiten-Reload mehr, kein Scrollen nach oben. Erfolgs- und Fehlermeldungen
  erscheinen als dezenter Toast unten rechts. Formulare, die Listen-Einträge anlegen
  (Anzahlungsregeln, Sonderöffnungszeiten), laden die Seite nach dem Speichern neu
  und kehren zur gleichen Scroll-Position zurück.

### Verbessert
- **Einstellungsseite:** Tisch-Anlegen-Bereich entfernt (Tische werden im Tischplan
  angelegt). Abschnitt „Räume & Tische" heißt jetzt schlicht „Räume" mit direktem
  Link zum Tischplan.
- **Tischkombinationen:** Aus den Einstellungen entfernt; ausschließlich über den
  Tischplan verwaltbar.

Alle nennenswerten Änderungen an Swayy. Das Projekt folgt
[Semantic Versioning](https://semver.org). Die aktuelle Version steht in
`config/version.php` und wird dezent in allen Admin-Oberflächen angezeigt.

## [1.29.1] – 2026-06-15

### Verbessert
- **Freundlichere Fehlermeldungen für Gäste:** Availability-Reason-Codes (`lead_time`,
  `too_far_ahead`, `blackout`, `covers_full`, `no_table`) werden jetzt in verständliche,
  warme Hinweistexte übersetzt statt als technisches Kürzel angezeigt.
- **Umbuchungsseite:** Fehler- und Fristablauf-Nachrichten mit Telefonnummer-Link;
  mehrere Fehler werden als Liste statt Einzelzeile angezeigt.
- **Buchungsseite:** Slot-Nicht-verfügbar-Nachrichten weicher formuliert; Wartelisten-
  Hinweis, Großgruppen-Nachricht und Netzwerkfehler-Text überarbeitet.
- **Stornierungsseite:** Wärmerer Ton, optionale Telefonnummer-Anzeige für Rückfragen.
- **Warteliste-Bestätigung:** Erklärung des Bestätigungslinks hinzugefügt.
- **Reservierung verwalten:** Stornierungsfrist lesbarer formatiert; abgelaufene Frist
  mit Telefonnummer-Link statt technischem Hinweis.

## [1.29.0] – 2026-06-15

### Neu
- **Personenanzahl beim Umbuchen änderbar:** Auf der Umbuchungsseite können Gäste
  jetzt neben Datum und Uhrzeit auch die Personenanzahl anpassen. Die Slot-Auswahl
  lädt automatisch neu wenn eine andere Personenzahl gewählt wird. Bei Salons bleibt
  die Personenanzahl unverändert auf 1. Die neue Personenzahl wird in der Reservierung
  gespeichert und im Audit-Log protokolliert.

## [1.28.6] – 2026-06-15

### Behoben
- **Bestätigungsseite 500-Error:** `@php($isSalon = $location->tenant?->isSalon())` –
  der nullsafe-Operator `?->` im einzeiligen `@php()`-Direktiv brachte Blades
  Regex-Parser durcheinander; `@section` wurde als PHP-Token gewertet → ParseError.
  Fix: auf `@php … @endphp`-Block umgestellt.

## [1.28.5] – 2026-06-15

### Behoben
- **Buchungs-URL bleibt kurz bei Einzelstandort:** `/book/{tenant}` zeigt die Buchungsseite
  direkt (kein Redirect, kein doppelter Slug in der URL). Ein eigener POST-Endpunkt
  `POST /book/{tenant}` leitet den Formular-Submit korrekt weiter; die Standort-Auflösung
  erfolgt automatisch. Mehrere Standorte zeigen weiterhin die Auswahlliste, danach
  `/book/{tenant}/{location}`.

## [1.28.0] – 2026-06-15

### Verbessert
- **Buchungsseite – progressiver Akkordeon-Checkout (Amazon/Shopify-Stil):**
  - Nur der aktive Schritt ist aufgeklappt; abgeschlossene Schritte klappen zu
    einer kompakten Zusammenfassung mit „Ändern"-Button zusammen
  - Restaurant: 3 Schritte – „Wie viele Personen?" → „Wann?" → „Ihre Angaben"
  - Salon: 3 Schritte – „Leistungen wählen" → „Wann & bei wem?" → „Ihre Angaben"
  - Automatisches Vorblättern: Party-Button-Klick öffnet Schritt 2, Slot-Klick
    öffnet Schritt 3 – kein manueller „Weiter"-Button nötig
  - Gesperrte Schritte (opacity 0.38) signalisieren den verbleibenden Weg
  - Bei Formularfehler werden alte Werte (party_size, time) automatisch
    wiederhergestellt und der korrekte Schritt geöffnet

## [1.27.0] – 2026-06-15

### Neu
- **Konfetti-Animation nach Buchung:** Auf der Bestätigungsseite feuert eine dreistufige
  Konfetti-Explosion (canvas-confetti) in der Brand-Farbe – abschaltbar pro Standort
  unter Einstellungen → Buchungsbestätigung.
- **Warme Willkommensnachricht:** Statt generischem Einleitungstext sieht der Gast nach
  einer erfolgreichen Buchung eine persönliche Begrüßung mit Datum, Uhrzeit und
  Begleitungsanzahl – party-size-aware (1 Person: keine Begleitung, 2 Personen: „deine/Ihre
  Begleitung", 3+: „deine/Ihre N-1 Begleitungen"). Du/Sie-Anrede wählbar pro Standort.
- **Setting `guest_address`** (`du` / `Sie`, Standard: `Sie`) und **`confetti_on_booking`**
  (boolean, Standard: `true`) in der Standort-Einstellungs-UI unter „Buchungsbestätigung".

## [1.26.0] – 2026-06-15

### Verbessert
- **Buchungsseite – visuelles Redesign (v2):**
  - Gradient-Hero-Header mit Brand-Farbe ersetzt die flache 6px-Linie; Logo in
    Glasmorphismus-Rahmen, Standortname in weißer Bold-Schrift
  - Zeitslots gruppiert nach Tageszeit (Vormittag / Mittag / Nachmittag / Abend)
    mit beschrifteten Kategorien – sofort erkennbar statt endlose identische Liste
  - Booking-Summary-Strip erscheint sobald Personenzahl + Datum + Uhrzeit gewählt:
    zeigt kompakte Auswahl vor dem Kontaktformular ("2 Personen · Mi 18.06 · 19:30 Uhr")
  - Reveal-Animation (fade + slide-up) wenn Schritte sichtbar werden
  - Pfeil-Icon im Submit-Button
  - Party-Buttons mit `hover:shadow-md hover:shadow-brand/10` und `active:scale-95`

## [1.25.0] – 2026-06-15

### Verbessert
- **Buchungsseite (großes UI-Polish):** Komplette visuelle Überarbeitung des öffentlichen Buchungsflows.
  - Nummerierte Schritt-Badges (①②③④) neben jeder Sektion – Nutzer sehen auf einen Blick, wo sie sind.
  - Personenzahl-Buttons (Restaurant) jetzt größer mit `Pers.`-Label und Press-Animation.
  - Alle Formularfelder mit Brand-farbenen Fokus-Ringen (`.public-input`-Klasse, definiert im Layout).
  - Datenschutz-Checkboxen mit `accent-[var(--brand)]` – Brand-Farbe statt Standard-Blau.
  - Lade-Zustände der Zeitslots animiert (Pulse-Animation statt statischem Text).
  - Fehlermeldungen server-seitig: roter Banner oben + inline `@error`-Nachrichten pro Feld.
  - Kontaktkarte unten mit Icon-Boxes statt reinen Text-Emojis.
  - Salon-Service-Pills: korrekte Textfarbe beim de-selektieren.
- **Manage-Seite (komplettes Redesign):** Selbes Card-Design wie Buchung/Bestätigung (rounded-3xl, shadow-xl, Marken-Streifen). Status-Badge mit kontextabhängiger Farbe (grün/amber/blau/rot). Details-Liste mit Trennlinien. Stornierungsbereich mit poliertem Input-Field.
- **Bestätigungsseite (Polish):** Details-Tabelle mit `divide-y`-Trennlinien. Dynamisches Status-Icon passend zum Reservierungsstatus. `font-mono tracking-wide` für Reservierungsnummer. Salon-spezifische Texte.

## [1.24.0] – 2026-06-15

### Neu
- **Self-hosted Lizenzmodell:** Swayy kann selbst gehostet werden, erfordert aber
  eine gültige Lizenz. Aktivierung per `SWAYY_SELF_HOSTED=true` in der `.env`.
  - Lizenzdatei `storage/license.json` — JSON mit Ed25519-Signatur (canonical,
    sorted-key encoding).
  - Signaturverifizierung via `sodium_crypto_sign_verify_detached` mit im Source
    eingebettetem Public Key (kein runtime-swapping möglich).
  - **14-Tage Kulanzfrist** nach Ablauf: Admin weiterhin erreichbar, aber roter
    Banner mit Erneuerungshinweis.
  - **Widerruf (Revocation):** optionaler HTTP-Check gegen `license.swayy.de/v1/revoked/{id}`,
    gecacht 7 Tage; Netzwerkfehler ungüldet die Lizenz *nicht*.
  - Bei hartem Lock (abgelaufen + Grace überschritten oder widerrufen): Admin
    gibt HTTP 402 zurück, öffentliche Buchungsseite bleibt erreichbar.
  - Admin-Banner 30 Tage vor Ablauf (gelb), während Grace-Period (rot).
  - Artisan-Commands: `license:validate [--fresh]`, `license:keygen`,
    `license:sign` (für internen Lizenzserver).
  - Hosted-SaaS-Betrieb (swayy.de selbst) ist komplett unberührt — ohne
    `SWAYY_SELF_HOSTED` bleibt alles so wie bisher.
- **8 neue Tests** für Lizenzvalidierung, Middleware-Verhalten, Grace Period,
  Booking-Seite bleibt bei Lock erreichbar.

## [1.23.2] – 2026-06-15

### Sicherheit (Audit)
- **SVG-Logos nicht mehr erlaubt:** Ein SVG von der eigenen Domain könnte
  eingebettetes JavaScript ausführen (Stored-XSS). Logo-Upload akzeptiert jetzt
  nur noch PNG/JPG/WebP; abgesichert per Test.
- **Härtere Auslieferung von Medien:** Logo- und Hintergrund-Endpoints senden
  `X-Content-Type-Options: nosniff` (Logo zusätzlich eine restriktive CSP/Sandbox).
- Vollständiges Audit dokumentiert: Mandanten-Isolation (Global Scope +
  explizite Ownership-Checks), SaaS-Bereich (`isSaasAdmin`-Pflicht je Aktion),
  Token-Flows (`hash_equals`), Stripe-Webhook (Signaturprüfung), keine
  unsicheren Roh-SQL/Mass-Assignment-Stellen, kein ungeschütztes Ausgeben von
  Gästedaten. Keine weiteren offenen Befunde im Code.

## [1.23.1] – 2026-06-15

### Geändert
- **Edles, stylisches Design der öffentlichen Seiten:** Buchungsseite,
  Standortauswahl und Bestätigung mit hochwertigem Look – sanfter, marken­farbiger
  Hintergrund-Verlauf, größere abgerundete Karten mit weichem Schatten,
  feinere Typografie, Markenakzent. Ausgewählte Datums-/Uhrzeit-/Personen-Buttons
  werden jetzt in der Markenfarbe gefüllt (statt nur umrandet).

## [1.23.0] – 2026-06-15

### Sicherheit
- **Kein Debug-Modus mehr in Produktion:** `.env.example` und `install.sh`
  setzen jetzt `APP_ENV=production` und `APP_DEBUG=false` als Standard.
  Zuvor konnte ein frischer Install im Debug-Modus laufen und im Fehlerfall
  Stacktraces inkl. Datenbank-Bindings (potenziell Gästedaten) anzeigen.
- **Regressionstest gegen Datenlecks:** Der öffentliche Tischplan und der
  Slots-Endpoint geben nachweislich **keine Gästedaten** (Name/E-Mail/Telefon)
  aus – per Test abgesichert.
- Audit der öffentlichen Token-Flows (Reservierung, Event, Warteliste, Zahlung,
  Gästekonto): alle nutzen konstantzeitige Token-Prüfung (`hash_equals`) und
  sind mandanten-/sitzungsgebunden.

### Geändert
- **Mehr Polish:** Hochwertigere Brand-Buttons (sanfter Verlauf, weicher
  Schatten, dezentes Anheben/Drücken) – wirkt auf Buchungs-CTA und im Backend.

## [1.22.0] – 2026-06-15

### Neu
- **Stammgast-Erkennung:** Gäste gelten automatisch als Stammgast (manuelles
  VIP-Flag **oder** ab X gezählten Besuchen, konfigurierbar via
  `SWAYY_REGULAR_AFTER_VISITS`, Standard 5). Ein ⭐-Badge erscheint im
  Live-Board (Karten + Tisch-Detail), in der Gästeliste und in der
  Reservierungs-Detailansicht.
- **Tisch teilen (zwei Gruppen an einem Tisch):** Ist ein Tisch belegt, aber es
  sind noch Plätze frei, lässt sich im Board eine **weitere, separate Gruppe**
  setzen („Tisch teilen"). Begrenzt auf die freien Plätze; darüber kommt ein
  klarer Hinweis.
- **Mindestbelegung pro Tisch:** Beim Anlegen eines Tisches lässt sich jetzt
  zusätzlich zur Platzzahl (max.) eine **Mindestpersonenzahl** wählen.

### Geändert
- **Mobile-Feinschliff im Backend:** Datentabellen scrollen auf schmalen
  Displays sauber horizontal (Mindestbreite + Scrollcontainer) statt zu
  quetschen; Tische-Tabelle in den Einstellungen ebenfalls.

### Behoben
- Walk-in ohne Namen führte zu einem Fehler – jetzt wird sauber „Walk-in"
  eingesetzt.

## [1.21.2] – 2026-06-14

### Geändert
- **Tabellen-Politur (durchgehend):** Einheitliche Spaltenköpfe (kräftigere,
  gesperrte Versalien) und dezenter **Zeilen-Hover** in allen Admin-Tabellen
  (Reservierungen, Gäste, Nutzer, Events, Refunds, Auditlog, Tische). Auch die
  Tabellen-Karten ohne Innenabstand haben jetzt den einheitlichen Rahmen.

## [1.21.1] – 2026-06-14

### Geändert
- **Backend-Politur (durchgehender Pass):** Alle Karten in allen Admin-Bereichen
  (Dashboard, Reservierungen, Gäste, Reports, Warteliste, Walk-ins, Services,
  Mitarbeiter, Nutzer, Events, Refunds, Auditlog, API-Tokens, Einstellungen)
  erhalten einen dezenten Rahmen (Ring) für ein ruhigeres, einheitlicheres Bild.

## [1.21.0] – 2026-06-14

### Neu
- **Logo pro Standort:** In den Einstellungen lässt sich je Standort ein Logo
  hochladen (PNG/JPG/WebP/SVG, max. 3 MB) und wieder entfernen; es erscheint
  oben auf der Buchungsseite. Logos werden tenant-sicher über die App
  ausgeliefert (eigener `/brand/...`-Endpoint, kein Public-Symlink nötig –
  behebt auch die zuvor nicht angezeigten Logos via `asset('storage/…')`).
- **Kontakt & Anfahrt auf der Buchungsseite:** Adresse, Telefon (Tel-Link) und
  E-Mail (Mail-Link) des Standorts werden unter dem Buchungsformular angezeigt.

### Geändert
- **Politur der Buchungsseite:** Markenakzent, größeres Logo, klarere Typografie,
  Kontaktkarte.
- **Politur im Backend (erster Durchgang):** Dashboard mit Icon-Kacheln, Hover
  und ruhigeren Karten (Ring statt nur Schatten); Logo-Bereich in den
  Einstellungen.

## [1.20.0] – 2026-06-14

### Neu / Geändert
- **Kürzere Buchungs-URL `/book/{laden}`:**
  - Bei **nur einem Standort** öffnet diese URL direkt die Buchungsseite – der
    Ladenname steht also nur **einmal** in der Adresse (kein doppelter Slug mehr).
  - Bei **mehreren Standorten** erscheint eine **Auswahlseite**; nach der Wahl
    wird der **Standortname an die URL angehängt** (`/book/{laden}/{standort}`).
  - Die Einstellungen zeigen automatisch die passende (kurze) öffentliche URL.
  - Die bisherige URL `/book/{laden}/{standort}` funktioniert weiterhin.

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

[1.23.2]: https://github.com/brightcolor/gastrobook/releases/tag/v1.23.2
[1.23.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.23.1
[1.23.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.23.0
[1.22.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.22.0
[1.21.2]: https://github.com/brightcolor/gastrobook/releases/tag/v1.21.2
[1.21.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.21.1
[1.21.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.21.0
[1.20.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.20.0
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
