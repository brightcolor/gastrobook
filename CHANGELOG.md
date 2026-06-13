# Changelog

Alle nennenswerten Änderungen an GastroBook. Das Projekt folgt
[Semantic Versioning](https://semver.org). Die aktuelle Version steht in
`config/version.php` und wird dezent in allen Admin-Oberflächen angezeigt.

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

[1.1.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.1.1
[1.1.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.1.0
[1.0.1]: https://github.com/brightcolor/gastrobook/releases/tag/v1.0.1
[1.0.0]: https://github.com/brightcolor/gastrobook/releases/tag/v1.0.0
