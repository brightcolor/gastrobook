# Changelog

Alle nennenswerten Änderungen an GastroBook. Das Projekt folgt
[Semantic Versioning](https://semver.org). Die aktuelle Version steht in
`config/version.php` und wird dezent in allen Admin-Oberflächen angezeigt.

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
  `php artisan gastrobook:install-legal` ausführen (oder `--force`).

## [1.7.0] – 2026-06-13

### Geändert
- **Impressum, Datenschutz, AGB jetzt als Markdown-Dateien** unter
  `storage/app/legal/*.md` (bind-gemountet, auf dem Host editierbar) statt
  fester Blade-Platzhalter.
- Der Container legt fehlende Dateien beim Start an
  (`php artisan gastrobook:install-legal`, aus Vorlagen in `resources/legal`).
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
  Fallback auf Polling; abschaltbar via `GASTROBOOK_BOARD_SSE=false` (z. B. auf
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
- Artisan-Kommando `php artisan gastrobook:create-admin` legt einen Plattform-
  Oberadmin an (interaktiv, per Optionen oder per `GASTROBOOK_ADMIN_*`-Env beim
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
