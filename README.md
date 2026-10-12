# 🍽️ Swayy

**Multi-Tenant-SaaS-Plattform für Tischreservierungen und Gästemanagement in der Gastronomie.**

Swayy ist ein eigenständiges Reservierungssystem für Restaurants, Cafés, Bars, Hotels, Event-Locations und Restaurantgruppen – mit Online-Reservierungswidget, internem Reservierungsbuch, grafischem Tischplan, automatischer Tischzuweisung, Walk-ins, Warteliste, Gäste-CRM, No-Show-Schutz (vorbereitet), Feedback-Booster, Berichten, REST-API, Webhooks, Auditlog und DSGVO-Werkzeugen.

> Laravel 13 · PHP 8.3–8.5 (Image: 8.4) · PostgreSQL/SQLite · Redis · Tailwind CSS 4 · Sanctum · PHPUnit · Larastan · Pint

---

## ⚡ Quickstart

**Quick-Install-Einzeiler (Linux/macOS, empfohlen)** — lädt alles, generiert den `APP_KEY`, findet automatisch freie Ports und startet den Stack:

```bash
curl -fsSL https://raw.githubusercontent.com/brightcolor/gastrobook/main/install.sh | bash
```

Bei privatem Repo/Image vorher Token setzen:

```bash
export GITHUB_TOKEN=<PAT mit repo + read:packages>
curl -fsSL -H "Authorization: token $GITHUB_TOKEN" https://raw.githubusercontent.com/brightcolor/gastrobook/main/install.sh | bash
```

Das Skript meldet am Ende die gewählten Ports (Standard 8080, bei Belegung automatisch der nächste freie). Zielordner per `SWAYY_DIR=meinordner` änderbar.

**Manuell mit Docker** — es wird nichts lokal gebaut, das Image kommt fertig von GitHub (GHCR):

```bash
git clone https://github.com/brightcolor/gastrobook.git && cd gastrobook
cp .env.example .env
# APP_KEY eintragen (einmalig generieren, z. B. mit: docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;")
docker login ghcr.io   # bei privatem Repo nötig (GitHub-Username + Token mit read:packages)
docker compose up -d   # zieht ghcr.io/brightcolor/gastrobook:latest, Migrationen laufen automatisch
```

→ App: **http://localhost:8080** (E-Mail-Versand: SMTP-Daten in `.env` hinterlegen, siehe [E-Mail](#e-mail))

**Oberadmin anlegen** (Produktiv – ohne Demodaten):

```bash
docker compose exec app php artisan swayy:create-admin
```

Interaktiv (oder per `--email=` / `--password=`). Alternativ **automatisch beim ersten Start**:
`SWAYY_ADMIN_EMAIL` und `SWAYY_ADMIN_PASSWORD` in der `.env` setzen – der Container legt den
Oberadmin dann an, sofern noch keiner existiert. Mit `--force` wird ein bestehendes Konto zum Oberadmin gemacht.

**Demodaten (optional, nur lokal):**

```bash
docker compose exec app php artisan db:seed
```

→ Login: `admin@swayy.test` / `password` (SaaS) bzw. `owner@demo.test` / `password` (Restaurant)
→ Demo-Buchungsseite: http://localhost:8080/book/demo/sonne

Alle Daten liegen als **Bind-Mounts** im Projektordner: `./storage` (App-Dateien), `./docker/data/postgres`, `./docker/data/redis` — einfach zu sichern, einfach zu migrieren.

**Ohne Docker (PHP 8.3–8.5 + Composer + Node 20+):**

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate --seed && npm install && npm run build
php artisan serve   # http://localhost:8000
```

Fertige Docker-Images baut die CI automatisch: `ghcr.io/brightcolor/gastrobook:latest`

---

## Inhaltsverzeichnis

0. [Quickstart](#-quickstart)
1. [Featureübersicht](#featureübersicht)
2. [Architektur](#architektur)
3. [Multi-Tenancy-Konzept](#multi-tenancy-konzept)
4. [Rollen & Rechte](#rollen--rechte)
5. [Lokales Setup](#lokales-setup)
6. [Docker Setup](#docker-setup)
7. [Erste Schritte (Superadmin → Tenant → Buchung)](#erste-schritte)
    - [Verwaltungsoberfläche](#verwaltungsoberfläche)
8. [Öffentliche Buchungsseite](#öffentliche-buchungsseite)
9. [API](#api)
10. [Webhooks](#webhooks)
    - [Kundenpanel von bright color](#kundenpanel-von-bright-color)
11. [Queue & Scheduler](#queue--scheduler)
12. [CLI-Befehle (Artisan)](#cli-befehle-artisan)
13. [E-Mails testen](#e-mails-testen)
14. [Tests & Codequalität](#tests--codequalität)
15. [Spamschutz der öffentlichen Formulare (Cap)](#spamschutz-der-öffentlichen-formulare-cap)
16. [Datenschutz (DSGVO)](#datenschutz-dsgvo)
17. [Backup, Updates, Produktion](#backup-updates-produktion)

---

## Handbuch für Anwender

Dieses README ist die technische Beschreibung. Das **Anwenderhandbuch** für
Inhaber, Leitung und Service liegt unter [`docs/handbuch/`](docs/handbuch/) –
26 Seiten in einfachem Deutsch, ohne technische Voraussetzungen, vorbereitet für
den Import in eine BookStack-Instanz (ein Ordner = ein Kapitel, eine Datei = eine
Seite; Hinweise dazu in [`docs/handbuch/00-import-hinweis.md`](docs/handbuch/00-import-hinweis.md)).

---

## Featureübersicht

| Modul | Status |
|---|---|
| Multi-Tenancy (Tenants → Standorte) mit globalem Scope + expliziten Checks | ✅ |
| SEPA-Lastschrift fürs Abo (GoCardless): jederzeit buchen/kündigen, Mail an beide | ✅ |
| Standort-Verwaltung im Admin (anlegen/umbenennen/aktivieren, Tarif-Limit) | ✅ |
| Sperrzeiten/Blackouts (pro Standort/Raum, voll gesperrt oder Gästegrenze in jedem Kapazitätsmodus) | ✅ |
| Einzelne Tische sperren (defekt/reserviert; Zeitraum + Grund, wirkt auf Auto-Vergabe, Handauswahl, Tischplan, Board) | ✅ |
| Räume & Tische editieren, Events nachträglich bearbeiten, Sonderzeiten löschen | ✅ |
| SaaS-Adminbereich: Dashboard, Mandanten-Karten, Plattform-Benutzerverwaltung, Supportzugriff (Auditlog) | ✅ |
| Tarif-/Limit-System (Trial, Starter, Professional, Multi-Location, Enterprise) | ✅ |
| Rollen & Rechte (8 Tenant-Rollen, 4 SaaS-Rollen, Standorteinschränkung) | ✅ |
| Öffentliches Buchungswidget (mobile-first, Slots-API, Honeypot, Rate Limits) | ✅ |
| Verfügbarkeitslogik (Öffnungszeiten, Sonderzeiten, Sperren, Vorlauf, Kapazitätsmodi) | ✅ |
| Automatische Tischzuweisung (kleinster passender Tisch, Prioritäten, Kombinationen) | ✅ |
| Live-Board fürs Personal (neue/anstehende Buchungen, Inline-Aktionen, Dark Mode, Vollbild, Echtzeit via SSE) | ✅ |
| Live-Board mit Tischplan-Ansicht (Tische wie im Admin angelegt, Statusfarben, mehrere Räume mit Tabs, Zoom/Einpassen, Touch-Swipe, Raumname) | ✅ |
| Tisch-Detail-Panel im Board (Tisch antippen: Belegung, Zeiten, Gast/Telefon, Statuswechsel, Walk-in platzieren, Reservierung für den Tisch anlegen) | ✅ |
| Gäste am Tisch dazubuchen (Personenzahl per ＋/− erhöhen, wenn mehr Gäste dazukommen – Tischplan & Board, bis Tischkapazität) | ✅ |
| Tisch teilen (zweite separate Gruppe an einen belegten Tisch, solange Plätze frei sind) | ✅ |
| Stammgast-Erkennung (VIP-Flag oder ab X Besuchen; ⭐-Badge in Board, Gästeliste, Reservierung) | ✅ |
| Internes Reservierungsbuch (Datumsbereich mit Schnellauswahl wie „Diese Woche / Letzte 7 Tage / Letzte 30 Tage" + frei wählbar, Filter, Suche, Schnellaktionen, Statushistorie) | ✅ |
| Interne Notizen je Reservierung (schreiben & lesen, nur fürs Team) | ✅ |
| App-weiter „Filter aktiv"-Hinweis mit „Filter löschen"-Button (Reservierungen, Gäste, Auditlog) | ✅ |
| Grafischer Tischplan (Statusfarben, Drag&Drop-Editor mit Pointer-Touch, Tisch anlegen **und bearbeiten** – Name/Kapazität/Eigenschaften per ✎ direkt am Tisch, Tischkombinationen anlegen **und bearbeiten**, Hintergrundbild pro Raum, Stühle mit Sitzplatz-/Belegungsanzeige, Drehen, Live-Refresh) | ✅ |
| Öffentlicher Tischplan auf der Buchungsseite (Verfügbarkeit live, optionale Tischwahl, Räume/Etagen) | ✅ |
| Walk-ins (freie Tische sofort, "frei bis", als Reservierung mit Quelle `walk_in`) | ✅ |
| Warteliste (online + intern, Angebote per Mail mit Ablauf, Annahme-Link) | ✅ |
| Nächste freie Termine bei ausgebuchtem Tag (Datum + Uhrzeit für die gewünschte Personenzahl, direkt anklickbar) | ✅ |
| Kurze Buchungs-URL `/book/{laden}` (1 Standort: direkt; mehrere: Standortauswahl, Slug wird angehängt) | ✅ |
| Logo pro Standort + Kontakt/Anfahrt (Adresse, Telefon, E-Mail) auf der Buchungsseite | ✅ |
| Dateien an einer Reservierung (PDF/Bild, privat gespeichert, nur fürs Team) | ✅ |
| Gäste-CRM (Dedupe, Besuchszähler, No-Show-Zähler, Tags, sensible Notizen) | ✅ |
| Dubletten zusammenführen (Vorschläge am Profil, Historie/Notizen/Einwilligungen/Zähler wandern mit, Protokoll) | ✅ |
| Stornolink / Änderungslink mit Secret-Token und Fristprüfung | ✅ |
| Online-Umbuchung durch den Gast (Frist, Re-Check Tisch/Mitarbeiter) | ✅ |
| Kundenkonto per Magic-Link (passwortlos): Termine ansehen, umbuchen, stornieren | ✅ |
| E-Mail-Bestätigung aktivierbar (Gast bestätigt **jede** Buchung per Klick; unbestätigte geben den Tisch nach 24 h wieder frei; bei Freigabe von Hand bekommt der Gast nach dem Klick eine Eingangsbestätigung) | ✅ |
| E-Mail-Vorlagen (pro Tenant/Standort überschreibbar, Platzhalter, DE/EN) | ✅ |
| Antwortadresse für Gästemails: Reply-To an den Betrieb, optional eine Swayy-eigene Adresse je Betrieb (Postal-Eingang, Zuordnung zur Buchung, Verlauf, Weiterleitung an den Betrieb) | ✅ |
| Reminder- & Feedback-Follow-up-Jobs (Scheduler) | ✅ |
| Feedback-Booster (intern erfassen, positives Feedback → externes Portal) | ✅ |
| Berichte (No-Show-Rate, Auslastung, Quellen, Covers, CSV-Exporte) | ✅ |
| REST-API v1 (Sanctum, tenant-gebundene Tokens, Scopes, Rate Limits) | ✅ |
| OpenAPI-Spezifikation unter `/api/v1/openapi.yaml` (testgeprüft gegen Routen) | ✅ |
| Webhooks (HMAC-Signatur, Retry/Backoff, Auto-Deaktivierung, Delivery-Log) | ✅ |
| Webhook-Verwaltung im Admin (anlegen, Testereignis, Secret neu erzeugen, pausieren/reaktivieren, Zustellprotokoll) | ✅ |
| Auditlog (filterbar, IP-anonymisiert, Impersonation-Kennzeichnung) | ✅ |
| Marketing-Automation (Geburtstagsgruß, Erinnerung nach dem Besuch, Win-back; nur mit Einwilligung, Abmeldelink, Testmail, Vorschau) | ✅ |
| MailWizz-Newsletter-Sync (Einwilligung → Liste, verschlüsselte Credentials) | ✅ |
| Konfigurierbare Widget-Felder (E-Mail/Telefon/Anlass/Allergien/Notiz je Standort) | ✅ |
| Einbettbares Widget (JS-Snippet → iframe mit Auto-Resize) | ✅ |
| DSGVO-Werkzeuge (Export, Anonymisierung, Einwilligungshistorie, Retention-Job) | ✅ |
| Kompletter Account-Export & -Import (Umzug/Sicherung, ohne Secrets, additiv) | ✅ |
| Stripe-Zahlungen produktiv: Event-Vorauszahlungen + Reservierungs-Deposits (Checkout, signierter Webhook) | ✅ |
| PayPal-Zahlungen (Orders v2, Capture-on-Return) – pro Mandant, parallel zu Stripe nutzbar (Gast wählt an der Kasse) | ✅ |
| No-Show-Schutz: Anzahlungsregeln per Admin-UI, Verrechnungshinweis, keine Rückerstattung bei No-Show | ✅ |
| Anzahlungs-Rückerstattung: Modus aus/manuell(Freigabe)/automatisch, sofort oder per Zeitplan, variabler %-Satz | ✅ |
| Events & Tickets (öffentl. Buchungsseite, Kapazität, Fristen, Check-in, CSV) | ✅ |
| Betriebstyp umschaltbar: Restaurant **oder** Friseur/Dienstleister (pro Mandant) | ✅ |
| Salon: Leistungen mit Dauer/Preis, Mitarbeiter (m:n), Termin-Buchung pro Mitarbeiter | ✅ |
| Salon: Terminplan als Tagesansicht (Spalte je Mitarbeiter, Arbeitszeiten/Abwesenheiten, Termine anklickbar) | ✅ |
| Salon: individuelle Mitarbeiter-Arbeitszeiten + Abwesenheiten (Urlaub/Krank) | ✅ |
| Salon: Kombi-Leistungen frei wählbar (Pills, Dauer/Preis summiert, ein Termin) | ✅ |
| Salon: Lückenoptimierer (packt „Beliebig"-Termine eng, reduziert Leerlauf) | ✅ |
| Puffer zwischen Terminen (Aufräumzeit) in der Slot-Berechnung | ✅ |
| Tischzeit je Gruppengröße (z. B. 1–2 Pers. 75 Min., 6–20 Pers. 150 Min.) | ✅ |
| Anzahlung mit Grundbetrag + Betrag pro Person, Auto-Storno pro Regel abwählbar | ✅ |
| Salon: Anzahlung pro Leistung (Regel greift nur, wenn die Leistung im Termin steckt) | ✅ |
| Event-Anzahlung (nur Teilbetrag online, Rest beim Event) und Event-Bild | ✅ |
| SMS-Erinnerungen via seven.io (deutscher Anbieter, DSGVO, verschlüsselte Credentials) | ✅ |
| WhatsApp, Telefon-/AI-Assistent (Quelle, ConversationLog, Adapterpunkte) | 🔶 vorbereitet |
| Stripe/Mollie-Billing für Tenants | 🔶 vorbereitet |
| SaaS-Website (Landingpage, Preise, FAQ, Kontaktformular, Impressum/Datenschutz/AGB) | ✅ |
| Self-Service-Registrierung (Trial-Tenant inkl. Standort, ohne Zahlungsdaten) | ✅ |
| Geführter Onboarding-Wizard nach Registrierung (Betriebstyp → Öffnungszeiten → Räume/Tische oder Mitarbeiter/Leistungen → Buchungsregeln mit üblicher Besuchsdauer → Branding → Fertig); jeder Schritt speichert nur seine Felder | ✅ |
| Erklär-Tooltips (Fragezeichen) an Feldern und Aktionen in der ganzen Verwaltung | ✅ |
| Einstellungen auf eigenen Seiten in sechs Gruppen (Betrieb, Platz & Zeiten, Buchungen, Gästekommunikation, Zahlungen, Verbindungen) mit Suche, Speicherleiste und Seitenleisten zum Anlegen und Bearbeiten | ✅ |
| Öffnungszeiten mit Vorschau „So sehen Gäste es“, Ausnahmen (Feiertage, Betriebsferien, Sperren) mit „So wirkt es“, „Tisch sperren…“ im Tischplan | ✅ |
| Eigene Fehlerseiten (402, 403, 404, 419, 429, 500, 503) mit Ursache und nächstem Schritt; „Passwort ändern“ unter Mein Konto | ✅ |
| Markenfarbe (brand_primary_color) im Admin speicherbar, Live-Vorschau, synchronisiert Widget-Farbpicker | ✅ |

---

## Architektur

```
app/
├── Enums/ReservationStatus.php        # Statusmaschine inkl. Übergangsregeln
├── Support/TenantContext.php          # Request-Singleton: aktiver Tenant/Standort
├── Models/                            # 30+ Models, BelongsToTenant-Trait
├── Services/                          # GESAMTE Businesslogik (keine Logik in Controllern)
│   ├── ReservationAvailabilityService # Slot-Prüfreihenfolge, Alternativen
│   ├── TimeSlotService                # Öffnungszeiten → Slots (inkl. über Mitternacht)
│   ├── TableAssignmentService         # kleinster passender Tisch, Kombinationen
│   ├── ReservationLifecycleService    # Anlage, Statuswechsel, Mails, Webhooks, Audit
│   ├── WaitlistService                # Einträge, Angebote, Annahme, Expiry
│   ├── GuestProfileService            # Dedupe, Einwilligungen, Besuchsstatistik
│   ├── GuestPrivacyService            # DSGVO-Export, Anonymisierung, Retention
│   ├── PaymentRequirementService      # Deposit-Regeln (Personenzahl/Zeit/Raum/Event)
│   ├── NoShowRiskService              # transparente Heuristik (0-100)
│   ├── NotificationTemplateRenderer   # Vorlagen-Auflösung + Platzhalter
│   ├── PlanLimitService               # Tariflimits (max_tables, max_users, …)
│   ├── WebhookDispatchService         # Event → signierte Deliveries
│   └── AuditLogger                    # zentrales Auditlog, IP-Minimierung
├── Jobs/                              # DeliverWebhook, Reminder, Feedback, Retention
└── Http/
    ├── Middleware/ResolveTenantContext  # Admin-Tenant-Auflösung + Membership-Check
    ├── Middleware/ResolveApiTenant      # API: tenant-gebundene Tokens
    ├── Middleware/RequirePermission     # Route-Middleware permission:xyz
    └── Controllers/{Public,Admin,Saas,Api}
```

**Zeiten:** Alle Zeitstempel werden in **UTC** gespeichert. Jeder Standort hat eine eigene `timezone`; Anzeige und Slot-Berechnung erfolgen in Standortzeit (DST-sicher via Carbon).

**Snapshots:** Reservierungen speichern `guest_name/email/phone_snapshot`, damit sie auch nach Gaständerung oder Anonymisierung historisch nachvollziehbar bleiben.

**Zuordnung zum Gästeprofil:** Eine Buchung fällt dem Profil mit derselben E-Mail-Adresse zu. Buchungen des Personals und der Schnittstelle dürfen ein Profil zusätzlich über die Telefonnummer finden. Online-Buchungen, Wartelisten-Angebote und Veranstaltungen ordnen nur über die Adresse zu, denn die Nummer im öffentlichen Formular kann jeder eintippen. Passt dort nur die Nummer, entsteht ein neues Profil, das im Gästeprofil unter „Mögliche Dublette“ erscheint. Alle Mails zu einer Buchung, auch der Bestätigungslink, gehen an die Adresse aus der Buchung; der Klick bestätigt die Adresse im Profil nur, wenn sie dieselbe ist.

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_GUEST_PHONE_MATCH_SOURCES` | `manual,phone,walk_in,api,phone_assistant` | Buchungswege, die über die Telefonnummer zuordnen; `-` heißt nur über die Adresse. Erlaubt sind nur diese fünf Werte |

---

## Multi-Tenancy-Konzept

- Jede mandantenbezogene Tabelle trägt `tenant_id`, standortbezogene zusätzlich `location_id`.
- Der `BelongsToTenant`-Trait setzt einen **globalen Eloquent-Scope** auf den aktiven Tenant (aus `TenantContext`) und füllt `tenant_id` beim Erstellen automatisch.
- **Defense in depth:** Controller prüfen Ownership zusätzlich explizit (`abort_if($model->tenant_id !== …)`), Policies/Middleware prüfen Standortzugriff.
- Admin: Tenant wird aus `users.current_tenant_id` aufgelöst und **gegen die Mitgliedschaft validiert** (`ResolveTenantContext`).
- Öffentliche Buchungsseiten: Auflösung über `tenant_slug + location_slug`, nur aktive Tenants/Standorte.
- API: Jeder Sanctum-Token trägt die Ability `tenant:<id>`; `ResolveApiTenant` validiert Mitgliedschaft + Tenant-Status + `api_enabled`-Feature.
- Supportzugriff durch SaaS-Admins läuft über einen expliziten, **auditierten** Impersonation-Flow.
- Tests beweisen die Isolation (siehe `tests/Feature/TenantIsolationTest.php`).

Vorbereitet für später: Subdomain-/Custom-Domain-Auflösung (zusätzlicher Resolver im selben Middleware-Pfad), JS-Embed des Widgets.

---

## Rollen & Rechte

**SaaS-Rollen** (`users.saas_role`): `super_admin`, `support_admin`, `billing_admin`, `readonly_admin`.

**Tenant-Rollen** (`tenant_users.role`): `tenant_owner`, `tenant_admin`, `operations_manager`, `location_manager`, `host`, `staff`, `marketing_manager`, `readonly`.

Die Rolle→Rechte-Matrix liegt in [`config/permissions.php`](config/permissions.php). Benutzer können Mitglied mehrerer Tenants mit unterschiedlichen Rollen sein; optional auf einzelne Standorte eingeschränkt (`tenant_users.all_locations = false` + `location_user`-Pivot). Prüfung via `permission:`-Route-Middleware und `User::canInTenant()`.

Besondere Rechte: Gastnotizen lesen/schreiben (`guest_notes.view`) und darin die sensiblen (`guest_notes.sensitive.view`), Einwilligungshistorie (`consents.view`), Dubletten zusammenführen (`guests.merge`), manuelle Überbuchung (`overbook.manual`, wird auditiert), Anonymisierung (`guests.anonymize`).

Rollenvergabe (`App\Support\RoleAssignment`): Inhaber und SaaS-Admins vergeben jede Rolle, wer `users.roles.manage` hat, jede außer `tenant_owner`. Wer nur `users.invite` hat, vergibt nur Rollen, deren Rechte eine Teilmenge der eigenen sind; eine Rolle mit `*` vergibt so nur, wer selbst `*` hat. Die Grenze folgt allein der Matrix in `config/permissions.php`.

---

## Lokales Setup

Voraussetzungen: PHP 8.3, 8.4 oder 8.5 (intl, zip, gd, sqlite), Composer, Node 20+ — die CI testet alle drei Versionen.

```bash
git clone <repo> gastrobook && cd gastrobook
composer install
cp .env.example .env          # SQLite ist vorkonfiguriert
php artisan key:generate
php artisan migrate --seed    # Demodaten inkl. Logins (siehe unten)
npm install && npm run build
php artisan serve             # http://localhost:8000
```

**Demo-Logins (nur lokale Entwicklung!):**

| Rolle | E-Mail | Passwort |
|---|---|---|
| SaaS-Superadmin | `admin@swayy.test` | `password` |
| Tenant-Inhaberin | `owner@demo.test` | `password` |
| Host | `host@demo.test` | `password` |

Demo-Buchungsseite: `http://localhost:8000/book/demo/sonne`
Adminbereich: `http://localhost:8000/admin` · SaaS-Admin: `http://localhost:8000/saas/tenants`

---

## Docker Setup

Die Compose-Datei nutzt das **fertige Image aus der GitHub-CI** (`ghcr.io/brightcolor/gastrobook:latest`) — lokal wird nichts gebaut. Datenbank-Migrationen laufen beim Start des App-Containers automatisch (`php artisan migrate --force`).

```bash
cp .env.example .env
# In .env mindestens APP_KEY setzen
docker login ghcr.io           # bei privatem Repo nötig
docker compose up -d
docker compose exec app php artisan db:seed   # optional: Demodaten
```

Der Host-Port ist über `.env` steuerbar (`SWAYY_PORT`, Standard 8080) — das Quick-Install-Skript `install.sh` wählt automatisch einen freien Port.

Update auf die neueste Version:

```bash
docker compose pull && docker compose up -d
```

Wer das Image doch lokal bauen will: `docker build -t ghcr.io/brightcolor/gastrobook:latest .`

| Dienst | URL |
|---|---|
| App (nginx) | http://localhost:8080 |

Enthalten: PHP-FPM-App, nginx, PostgreSQL 17, Redis 7, dedizierter Queue-Worker, Scheduler-Container. E-Mail-Versand läuft über einen echten SMTP-Provider (in `.env` konfigurieren). Storage-Link bei Bedarf: `docker compose exec app php artisan storage:link`.

### Hinter einem Reverse Proxy (Traefik / nginx / Caddy)

Funktioniert problemlos – die App vertraut `X-Forwarded-*`-Headern (in `bootstrap/app.php` konfiguriert), erzeugt also korrekte `https`-Links. Zwei Dinge beachten:

1. **`APP_URL` in der `.env` auf die echte Domain setzen**, z. B. `APP_URL=https://buchung.example.com` – **nicht** auf den lokalen Port, auf den der Proxy weiterleitet. Daraus entstehen alle absoluten Links (Mails, Magic-Link, Zahlungs-Rücksprung) **und** die Liste der erlaubten Host-Header. Steht dort `http://localhost:8081`, während die Seite unter `https://buchung.example.com` läuft, sind sämtliche Mail-Links tot. Der Container weist beim Start darauf hin.
   Für den Healthcheck des `web`-Containers zusätzlich `SWAYY_HEALTH_HOST` auf denselben Hostnamen setzen (`SWAYY_HEALTH_HOST=buchung.example.com`), sonst prüft er nur, ob nginx überhaupt antwortet.
2. **Sitzt der Proxy nicht im privaten Netz**, seine Adresse in `TRUSTED_PROXIES` eintragen (Komma-Liste, CIDR erlaubt). Voreingestellt sind Loopback und die privaten Bereiche – damit ist der mitgelieferte nginx abgedeckt, ein externer Load Balancer oder Cloudflare aber nicht. Ohne Eintrag ignoriert die App dessen `X-Forwarded-Proto` und erzeugt `http`-Links.
3. Der Proxy muss `X-Forwarded-Proto` setzen (Standard bei Traefik/Caddy; bei nginx `proxy_set_header X-Forwarded-Proto $scheme;` etc.). Für das **Live-Board (SSE)** Pufferung aus lassen (nginx: `proxy_buffering off;` für die App, oder den Header `X-Accel-Buffering: no` durchreichen – wird von der App bereits gesetzt).
4. **Keine pauschalen Frame-Header am Proxy.** Die Anwendung setzt sie selbst und unterscheidet dabei: Adminbereich und alle Seiten mit Zugriffstoken verbieten die Einbettung (`frame-ancestors 'none'`), die öffentlichen Buchungsseiten und die Widget-Skripte erlauben sie ausdrücklich – genau dafür sind sie da. Ein `add_header X-Frame-Options SAMEORIGIN always;` am Proxy legt die Einbett-Widgets (`embed.js`, `popup.js`) bei jedem Kunden still, ohne dass es irgendwo auffällt.

**Uploadgrenzen:** Die Anwendung erlaubt 8 MB je Anhang, 4 MB je Eventbild und 20 MB je Kontoimport; PHP ist im Image passend eingestellt (`docker/php.ini`), der mitgelieferte nginx auf 20 MB. Ein davorliegender Proxy braucht mindestens denselben Wert (nginx: `client_max_body_size 21m;`), sonst scheitern Uploads dort, bevor die Anwendung eine Meldung zeigen kann.

Den Host-Port am besten nur lokal binden und den Proxy davorsetzen, z. B. in der `.env`: `SWAYY_PORT=127.0.0.1:8080` (dann lauscht nur der Proxy nach außen).

**PHP-Prozesse (wichtig, wenn das Live-Board genutzt wird):** Jede offene Board-Ansicht hält über SSE dauerhaft einen PHP-FPM-Prozess. Der Pool steht deshalb auf 30 Kinder (`docker/www.conf`); zum Ändern `SWAYY_FPM_MAX_CHILDREN` in der `.env` setzen. Als Faustregel: gleichzeitige Board-Ansichten + 10. Zu klein bemessen wirkt wie ein Totalausfall – alle übrigen Anfragen warten, bis ein Board-Tab geschlossen wird. Jeder Prozess braucht grob 40–60 MB RAM.

**Bind-Mounts (alle Daten im Projektordner):**

| Host-Pfad | Container | Inhalt |
|---|---|---|
| `./storage` | app/queue/scheduler | Uploads, Logs, Cache |
| `./docker/data/postgres` | db | PostgreSQL-Datenbank |
| `./docker/data/redis` | redis | Redis-Persistenz |
| `./docker/data/public` | web (nginx) | Statische Assets, vom App-Container beim Start exportiert |

`docker/data/` ist git-ignoriert. Backup = Ordner kopieren (DB-Dump per `docker compose exec db pg_dump -U gastrobook gastrobook` bleibt der saubere Weg).

**Fertige Images (GHCR):** Die CI baut bei jedem Push auf `main` (und bei `v*`-Tags) automatisch ein Image:

```bash
docker pull ghcr.io/brightcolor/gastrobook:latest
```

Tags: `latest` (main), `sha-<commit>`, `main`, sowie `x.y.z` bei Versions-Tags.

---

## Erste Schritte

**Variante A – Self-Service:** Restaurants registrieren sich selbst unter `/register` (Landingpage `/`). Es entsteht ein Trial-Tenant (30 Tage) mit Inhaberkonto und erstem Standort – ganz ohne SaaS-Admin. Voraussetzung: Die Tarife sind eingespielt (`php artisan db:seed --class=PlanSeeder --force`; im Docker-Setup passiert das automatisch beim Start).

**Variante B – über den SaaS-Admin:**

1. **Superadmin anmelden** → `/saas/tenants`
2. **Tenant erstellen**: Name, Tarif, Inhaber-E-Mail, erster Standort. Das Initialpasswort des Inhabers wird **einmalig** angezeigt.
3. **Als Inhaber anmelden** → `/admin`
4. **Einstellungen** → Öffnungszeiten (Platz & Zeiten), Kapazität & Dauer (Takt, Dauer, Zählweise), Buchungsregeln (Vorlauf, Fristen, Bestätigung), Räume und Tische anlegen, optional Tischkombinationen.
5. **Buchungsseite teilen**: Link steht unter *Einstellungen › Buchungen › Buchungsseite & Widget* (`/book/<tenant>/<standort>`), z. B. für Website, Instagram-Bio oder Google Business Profile.
6. **Benutzer einladen** unter *Einstellungen › Betrieb › Team & Rollen* (`/admin/users`; bestehende Konten werden direkt verknüpft, neue erhalten einen Einladungslink).

---

## Verwaltungsoberfläche

Die Navigation kommt aus einer Quelle, [`config/navigation.php`](config/navigation.php): Seitenleiste am Computer, Symbolleiste am Tablet, untere Leiste auf dem Smartphone mit „Mehr“ und der Einstellungsbereich. Die Arbeitsbereiche (Übersicht, Service, Buchungen, Gäste, Berichte) stehen in der Seitenleiste; `App\Support\Navigation\Navigation` filtert nach Rechten und Betriebstyp (Restaurant oder Salon).

**Einstellungen** liegen auf eigenen Seiten in sechs Gruppen. Jede Seite speichert nur ihre Felder, zeigt ihren Geltungsbereich (alle Standorte oder ein Standort) und meldet Änderungen in einer Speicherleiste mit „Verwerfen“. Anlegen und Bearbeiten laufen in Seitenleisten, Löschen mit Rückfrage in der Zeile.

| Gruppe | Seiten |
|---|---|
| Betrieb | Allgemein, Standorte, Team & Rollen, Abo & Rechnungen, Protokoll, Daten |
| Platz & Zeiten | Öffnungszeiten, Ausnahmen, Räume & Tische (Restaurant), Leistungen und Mitarbeiter (Salon), Kapazität & Dauer |
| Buchungen | Buchungsregeln, Buchungsformular, Buchungsseite & Widget, Tags |
| Gästekommunikation (Salon: Kundenkommunikation) | Benachrichtigungen, Vorlagen, Absender & Antworten, SMS-Versand |
| Zahlungen | Zahlungsanbieter, Anzahlungen |
| Verbindungen | Newsletter, API-Schlüssel, Webhooks |

**Einstieg:** `/admin/settings` führt auf die erste Seite, die die Rolle öffnen darf (`SettingsEntryController`). `/admin/settings/menu` listet alle erlaubten Seiten samt Suche und ist auf dem Smartphone der Einstieg. Eine Rolle ohne Einstellungsseite bekommt die Fehlerseite 403 mit einem Satz zur Ursache. Alte Sprungmarken der früheren Sammelseite (`#zeiten`, `#zahlungen`, `#widget` …) leiten auf die Seite weiter, die den Bereich übernommen hat; die Zuordnung steht unter `legacy_anchors` in `config/navigation.php`.

**Fehlerseiten** (`resources/views/errors`) nennen Ursache, nächsten Schritt und den Fehlercode. Die Adresse für Rückfragen kommt aus `SWAYY_SUPPORT_EMAIL`.

**Einstellungen der Oberfläche und der Eingabegrenzen.** Alle Werte haben eine Voreinstellung und Grenzen; ein ungültiger Wert legt nichts lahm: Es gilt die Voreinstellung oder die nächste Grenze, und eine Warnung im Log nennt die Variable. Die Grenzen der Textfelder passen zu den Spalten der Datenbank. Alle Variablen stehen mit Erklärung in [`.env.example`](.env.example).

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_UI_TOAST_MS` | `3200` | Anzeigedauer von Hinweisen wie „Gespeichert“ in Millisekunden (1000 bis 20000) |
| `SWAYY_UI_RELOAD_DELAY_MS` | `1200` | Wartezeit vor dem Neuladen nach Änderungen an Navigation oder Aufbau in Millisekunden (0 bis 10000) |
| `SWAYY_UI_PREVIEW_DELAY_MS` | `400` | Wartezeit nach der letzten Eingabe, bevor die Vorschau der Öffnungszeiten neu rechnet, in Millisekunden (100 bis 3000) |
| `SWAYY_UI_DEFAULT_BRAND_COLOR` | `#0f766e` | Markenfarbe, solange ein Betrieb keine eigene gewählt hat (`#rrggbb`) |
| `SWAYY_UI_DEFAULT_STAFF_COLOR` | `#0f766e` | Farbe, die „Person hinzufügen“ im Salon vorschlägt (`#rrggbb`) |
| `SWAYY_UI_DEFAULT_LABEL_COLOR` | `#6b7280` | Farbe für neue Tags und Leistungen (`#rrggbb`) |
| `SWAYY_UI_WIDGET_LABEL_MAX` | `40` | Längster Text auf dem Buchungsknopf und dem Link für die eigene Website in Zeichen (10 bis 100) |
| `SWAYY_UI_TAG_NAME_MAX` | `40` | Längster Name eines Tags in Zeichen (10 bis 255) |
| `SWAYY_NAV_MOBILE_BAR_MAX` | `4` | Einträge in der unteren Leiste auf dem Smartphone, dazu kommt „Mehr“ (1 bis 4) |
| `SWAYY_NAV_HEADING_MIN_ITEMS` | `2` | Sichtbare Einträge, ab denen eine Gruppe der Navigation ihre Überschrift zeigt (1 bis 10) |
| `SWAYY_SUPPORT_EMAIL` | `info@swayy.de` | Adresse, die die Fehlerseiten und die Hinweise für Rückfragen nennen |
| `SWAYY_DONT_FLASH` | `api_key,secret_key,webhook_secret,client_id,secret,token,cap-token` | Felder, die nach einer abgelehnten Eingabe nie in der Sitzung landen (Komma-Liste von Namen); `password`, `password_confirmation`, `current_password` und die Zugangsdaten `api_key`, `secret_key`, `webhook_secret`, `client_id`, `secret` sind immer dabei, `-` lässt nur diese festen |
| `SWAYY_PASSWORD_MIN_LENGTH` | `10` | Mindestlänge neuer Passwörter (8 bis 64), zusätzlich Buchstaben und Ziffern; gilt bei Registrierung, Einladung, Zurücksetzen, „Mein Konto“, Plattform-Benutzern und `swayy:create-admin` |
| `SWAYY_PASSWORD_CHANGE_PER_MINUTE` | `6` | Versuche je Konto und Minute, unter „Mein Konto“ das Passwort zu ändern (3 bis 30) |
| `SWAYY_AUDIT_PER_PAGE` | `100` | Einträge je Seite im Protokoll (10 bis 500) |
| `SWAYY_AUDIT_SEARCH_MAX` | `100` | Längster Suchtext im Protokoll in Zeichen (20 bis 200) |
| `SWAYY_IMPORT_MAX_KB` | `20480` | Größte Datei für „Daten einspielen“ in KB (1024 bis 20480; mehr lassen PHP und nginx nicht durch) |
| `SWAYY_API_TOKEN_NAME_MAX` | `80` | Längster Name eines API-Schlüssels in Zeichen (10 bis 255) |

**Standorte und Logo:**

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_DEFAULT_TIMEZONE` | `Europe/Berlin` | Zeitzone, die „Neuer Standort“ vorschlägt (gültige Zeitzone) |
| `SWAYY_LOCATION_INTRO_MAX` | `1000` | Längste Begrüßung auf der Buchungsseite in Zeichen (100 bis 5000) |
| `SWAYY_LOGO_MAX_KB` | `3072` | Größte Datei für ein Standort-Logo in KB (64 bis 10240) |
| `SWAYY_LOGO_TYPES` | `jpg,jpeg,png,webp` | Erlaubte Dateiendungen für Logos, durch Komma getrennt (`jpg`, `jpeg`, `png`, `webp`, `gif`) |
| `SWAYY_LOGO_DIRECTORY` | `logos` | Ordner für Logos auf dem Speicher „public“ (Kleinbuchstaben, Ziffern, `-` und `_`) |

**Öffnungszeiten und Ausnahmen:**

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_OPENING_HOURS_WINDOWS_PER_DAY_MAX` | `6` | Zeitfenster je Wochentag; die Woche nimmt höchstens das Siebenfache an (1 bis 12) |
| `SWAYY_OPENING_HOURS_NAME_MAX` | `60` | Länge der Bezeichnung eines Zeitfensters (10 bis 120; die Spalte fasst 255) |
| `SWAYY_OPENING_HOURS_SEASON_LABEL_MAX` | `60` | Länge der Bezeichnung einer Saison (10 bis 60; die Spalte fasst 60) |
| `SWAYY_OPENING_HOURS_PREVIEW_GROUPS_MAX` | `8` | Zeilen mit abweichender Dauer in der Vorschau (1 bis 30) |
| `SWAYY_EXCEPTIONS_MAX_DAYS` | `93` | Längster Zeitraum einer Ausnahme in Tagen (1 bis 366) |
| `SWAYY_EXCEPTIONS_WINDOWS_MAX` | `6` | Zeitfenster bei „Andere Öffnungszeiten“ (1 bis 12) |
| `SWAYY_EXCEPTIONS_LABEL_MAX` | `120` | Länge der Bezeichnung einer Ausnahme (20 bis 200; die Spalten fassen 255) |
| `SWAYY_EXCEPTIONS_COVERS_MAX` | `10000` | Obergrenze für „Weniger Gäste“ (1 bis 32767; die Spalte ist `smallint`) |
| `SWAYY_EXCEPTIONS_PAST_MONTHS` | `12` | Monate zurück, die die Liste „Vergangen“ zeigt (1 bis 60) |

**Kapazität, Dauer und Buchungsregeln:**

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_BOOKING_SLOT_INTERVALS` | `15,30,60` | Auswahl für den Takt der Uhrzeiten in Minuten, durch Komma getrennt (je 5 bis 240) |
| `SWAYY_BOOKING_DURATION_MIN` | `30` | Kleinste Standarddauer eines Besuchs in Minuten (5 bis 240) |
| `SWAYY_BOOKING_DURATION_MAX` | `480` | Größte Standarddauer eines Besuchs in Minuten (60 bis 1440) |
| `SWAYY_BOOKING_BUFFER_MAX` | `120` | Längster Puffer nach einer Buchung in Minuten (0 bis 480) |
| `SWAYY_BOOKING_DURATION_RULES_MAX` | `20` | Höchstzahl der Tischzeiten je Gruppengröße (1 bis 50) |
| `SWAYY_BOOKING_RULE_DURATION_MIN` | `15` | Kürzeste Tischzeit einer Regel in Minuten (5 bis 240) |
| `SWAYY_BOOKING_RULE_DURATION_MAX` | `600` | Längste Tischzeit einer Regel in Minuten (60 bis 1440) |
| `SWAYY_BOOKING_RULE_PARTY_MAX` | `100` | Größte Gruppe einer Tischzeit-Regel (1 bis 100; jeder Buchungsweg nimmt höchstens 100 Personen an) |
| `SWAYY_BOOKING_COVERS_MAX` | `2000` | Größte Zahl für „Höchstens gleichzeitig“ in Gästen (1 bis 32767) |
| `SWAYY_BOOKING_LEAD_MAX` | `10080` | Längster Mindestvorlauf in Minuten (0 bis 32767) |
| `SWAYY_BOOKING_ADVANCE_MAX` | `730` | So viele Tage im Voraus ist höchstens buchbar (1 bis 1825) |
| `SWAYY_BOOKING_DEADLINE_MAX` | `20160` | Längste Storno- und Umbuchungsfrist in Minuten (0 bis 32767) |
| `SWAYY_BOOKING_PARTY_ONLINE_MAX` | `100` | Größte Gruppe, die online buchen kann (1 bis 100) |

**Benachrichtigungen, Vorlagen und SMS:**

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_REMINDER_HOURS_MIN` | `1` | Früheste Erinnerung an Gäste, Stunden vor dem Besuch (1 bis 24) |
| `SWAYY_REMINDER_HOURS_MAX` | `168` | Späteste Erinnerung an Gäste, Stunden vor dem Besuch (24 bis 720) |
| `SWAYY_FEEDBACK_HOURS_MIN` | `1` | Früheste Feedback-Anfrage, Stunden nach dem Besuch (1 bis 24) |
| `SWAYY_FEEDBACK_HOURS_MAX` | `336` | Späteste Feedback-Anfrage, Stunden nach dem Besuch (24 bis 720) |
| `SWAYY_FEEDBACK_URL_MAX` | `255` | Längster Link zum Bewertungsportal in Zeichen (30 bis 255; die Spalte fasst 255) |
| `SWAYY_FEEDBACK_LOOKBACK_GRACE_DAYS` | `7` | Tage Luft für Ausfälle von Scheduler oder Queue: Feedback-Anfragen gehen an Besuche, die höchstens `SWAYY_FEEDBACK_HOURS_MAX` Stunden (auf ganze Tage aufgerundet) plus diese Tage zurückliegen (0 bis 30) |
| `SWAYY_OWNER_EMAIL_MAX` | `200` | Längste Adresse für die Info an den Betrieb in Zeichen (50 bis 255) |
| `SWAYY_TEMPLATE_SUBJECT_MAX` | `200` | Längster Betreff einer Mailvorlage in Zeichen (20 bis 255) |
| `SWAYY_TEMPLATE_BODY_MAX` | `5000` | Längster Text einer Mailvorlage in Zeichen (500 bis 20000) |
| `SWAYY_SMS_SENDER_ID_MAX` | `16` | Längste Absenderkennung aus Ziffern (Rufnummer) beim SMS-Versand über seven.io (3 bis 16) |
| `SWAYY_SMS_SENDER_ID_TEXT_MAX` | `11` | Längste Kennung mit Buchstaben, höchstens so lang wie die Rufnummer (3 bis 16) |
| `SWAYY_SMS_API_KEY_MAX` | `255` | Längster API-Key für seven.io in Zeichen (32 bis 1000) |

**Zahlungen und Anzahlungen:**

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_PAYMENT_SECRET_MAX` | `255` | Längster einzelner Schlüssel eines Zahlungsanbieters (Secret Key, Webhook-Secret, Client-ID, Secret) in Zeichen (32 bis 1000) |
| `SWAYY_DEPOSIT_AMOUNT_MAX` | `10000` | Höchster Betrag pro Person und höchster Grundbetrag einer Anzahlungsregel in Euro (10 bis 212622; Grundbetrag plus Betrag pro Person mal 100 Personen passt in die Spalten der Buchung) |
| `SWAYY_DEPOSIT_DEADLINE_MIN` | `10` | Kürzeste Zahlungsfrist einer Regel in Minuten (5 bis 120) |
| `SWAYY_DEPOSIT_DEADLINE_MAX` | `10080` | Längste Zahlungsfrist einer Regel in Minuten (60 bis 32767) |
| `SWAYY_DEPOSIT_DEADLINE_DEFAULT` | `60` | Zahlungsfrist ohne Angabe in Minuten (5 bis 32767, begrenzt auf den Bereich von kürzester bis längster) |
| `SWAYY_DEPOSIT_PARTY_SIZE_MAX` | `100` | Größte Personenzahl, ab der eine Regel gilt (2 bis 100) |
| `SWAYY_DEPOSIT_NAME_MAX` | `120` | Längster Name einer Anzahlungsregel in Zeichen (20 bis 255) |

**Räume, Tische, Leistungen und Mitarbeiter:**

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_TABLE_CAPACITY_MAX` | `50` | Größte Platzzahl eines Tisches (1 bis 255) |
| `SWAYY_ROOM_SIZE_MAX_M` | `300` | Größte Breite und Tiefe eines Raums im Tischplan in Metern (10 bis 327; die Spalten fassen 32767 Einheiten zu je 1 cm) |
| `SWAYY_TABLE_NAME_MAX` | `40` | Längster Name eines Tisches in Zeichen (10 bis 255) |
| `SWAYY_ROOM_NAME_MAX` | `80` | Längster Name eines Raums in Zeichen (10 bis 255) |
| `SWAYY_COMBINATION_NAME_MAX` | `80` | Längster Name einer Tischkombination in Zeichen (10 bis 255) |
| `SWAYY_SERVICE_DURATION_MIN` | `5` | Kürzeste Dauer einer Leistung im Salon in Minuten (1 bis 60) |
| `SWAYY_SERVICE_DURATION_MAX` | `480` | Längste Dauer einer Leistung im Salon in Minuten (30 bis 1440) |
| `SWAYY_SERVICE_PRICE_MAX` | `1000` | Höchster Preis einer Leistung in Euro (1 bis 100000) |
| `SWAYY_SERVICE_NAME_MAX` | `120` | Längster Name einer Leistung in Zeichen (10 bis 255) |
| `SWAYY_SERVICE_DESCRIPTION_MAX` | `1000` | Längste Beschreibung einer Leistung in Zeichen (50 bis 5000) |
| `SWAYY_STAFF_NAME_MAX` | `120` | Längster Name einer Person im Salon in Zeichen (10 bis 255) |
| `SWAYY_STAFF_BIO_MAX` | `500` | Längste Kurzvorstellung einer Person in Zeichen (50 bis 5000) |
| `SWAYY_STAFF_HOURS_MAX` | `28` | Die meisten Zeitfenster, die eine Person je Woche haben kann (7 bis 200) |
| `SWAYY_ABSENCE_REASON_MAX` | `120` | Längster Grund einer Abwesenheit in Zeichen (10 bis 120; die Spalte fasst 120) |
| `SWAYY_ABSENCE_WINDOW_YEARS` | `10` | Beginn und Ende einer Abwesenheit liegen höchstens so viele Jahre vor oder nach heute (1 bis 100) |

---

## Öffentliche Buchungsseite

`GET /book/{tenant}/{location}` (Alias `/r/...`) – mobile-first, wenige Schritte:

Personenzahl → Datum → verfügbare Uhrzeiten (live via `/slots`-JSON) → Kontaktdaten → DSGVO-Checkbox (Pflicht) + getrennte Newsletter-Checkbox → Bestätigungsseite.

- Bei Ausbuchung: Alternativzeiten am selben Tag, alternative Tage, optional Warteliste.
- Je nach Standortregel: sofort bestätigt / als Anfrage / Zahlung erforderlich (Deposit-Regel).
- Pflichtfelder pro Standort konfigurierbar – im Adminbereich unter **Einstellungen › Buchungen › Buchungsformular**: jedes Feld (E-Mail, Telefon, Anlass, Allergien, Anmerkung) ist auf *Ausgeblendet / Optional / Pflichtfeld* stellbar; die Validierung greift serverseitig.
- **Einbetten auf der eigenen Website:**
  ```html
  <div id="swayy-widget"></div>
  <script src="https://ihre-domain.de/embed/<tenant>/<standort>.js" defer></script>
  ```
  Das Snippet injiziert die Buchungsseite als iframe mit automatischer Höhenanpassung (postMessage).
- Spam-Schutz: Honeypot-Feld + Rate Limits (10/min, 50/Tag pro IP).
- Bestätigungs-Mail mit sicherem Storno-/Änderungslink (Secret-Token, `hash_equals`), Stornofrist wird serverseitig geprüft.

---

## API

REST-API unter `/api/v1`, Auth via Sanctum-Bearer-Token. Tokens werden im Adminbereich unter *Einstellungen › Verbindungen › API-Schlüssel* (`/admin/api-tokens`) erstellt, sind **tenant-gebunden** und tragen Scopes:

`reservations:read|write`, `guests:read|write`, `availability:read`, `waitlist:write`, `events:read|write`, `webhooks:manage`, `reports:read`

```bash
# Verfügbare Slots
curl -H "Authorization: Bearer <TOKEN>" \
  "http://localhost:8000/api/v1/availability?location_id=1&date=2026-07-01&party_size=2"

# Reservierung anlegen
curl -X POST -H "Authorization: Bearer <TOKEN>" -H "Content-Type: application/json" \
  -d '{"location_id":1,"date":"2026-07-01","time":"19:00","party_size":2,"guest_name":"Max Muster","guest_email":"max@example.com"}' \
  http://localhost:8000/api/v1/reservations

# Stornieren
curl -X POST -H "Authorization: Bearer <TOKEN>" \
  http://localhost:8000/api/v1/reservations/R-ABC123/cancel
```

Rate Limit: 120 Requests/Minute pro Token. Der Telefon-/AI-Assistent (vorbereitet) bucht ausschließlich über diese Availability-API – keine ungeprüften Buchungsentscheidungen.

**OpenAPI-Spezifikation:** `GET /api/v1/openapi.yaml` (öffentlich, kein Token nötig) beschreibt alle Endpunkte, Parameter, Antwortformate, Fehlercodes und Webhook-Ereignisse maschinenlesbar – direkt einlesbar in Postman, Insomnia, Swagger UI oder einen Client-Generator. Quelle: [`resources/api/openapi.yaml`](resources/api/openapi.yaml).

---

## Webhooks

Endpoints pro Tenant. **Verwaltung im Admin unter *Einstellungen › Verbindungen › Webhooks*** (`/admin/webhooks`, Recht `webhooks.manage`): anlegen, Ereignisse auswählen, **Testereignis** senden, Secret neu erzeugen, pausieren/reaktivieren, Zustellprotokoll der letzten Versuche (Voreinstellung 25). Alternativ per REST-API (`POST /api/v1/webhooks`, Scope `webhooks:manage`) – dieselben Endpunkte.

Events: `reservation.created|confirmed|updated|cancelled|seated|completed|no_show`, `waitlist.created|offered|accepted`, `event.booking_created`, `payment.succeeded`, `feedback.received` — oder `*` für alle.

- Payload signiert: Header `X-Gastrobook-Signature: sha256=<HMAC-SHA256(body, secret)>`
- Retries mit Backoff (Voreinstellung 1 min → 2 h, 5 Versuche), Delivery-Log in `webhook_deliveries`
- Automatische Deaktivierung nach 20 gescheiterten Ereignissen in Folge (Voreinstellung; Reaktivierung im Admin setzt den Fehlerzähler zurück)
- Nur öffentlich erreichbare https-Ziele (SSRF-Guard, erneut geprüft beim Zustellen, siehe [Zielprüfung](#zielprüfung-für-ausgehende-anfragen)). Lehnt die Prüfung die Adresse ab, schaltet sich der Endpunkt sofort ab; lässt sich der Servername nur gerade nicht auflösen, zählt das als Fehlversuch und wird wiederholt. Der Grund steht im Zustellprotokoll.
- Payload-Versionierung (`"version": "1"`)
- Voraussetzung: Tarif-Feature `webhooks_enabled`

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_WEBHOOK_TIMEOUT` | `10` | Wartezeit je Zustellung in Sekunden (1 bis 60) |
| `SWAYY_WEBHOOK_TRIES` | `5` | Versuche je Ereignis (1 bis 10) |
| `SWAYY_WEBHOOK_BACKOFF` | `60,300,1800,7200` | Wartezeiten zwischen den Versuchen in Sekunden; der letzte Wert gilt für alle weiteren (je 1 bis 86400) |
| `SWAYY_WEBHOOK_DISABLE_AFTER` | `20` | Gescheiterte Ereignisse in Folge, nach denen sich ein Endpunkt abschaltet (1 bis 1000) |
| `SWAYY_WEBHOOK_RESPONSE_LIMIT` | `2000` | Zeichen der Antwort einer Gegenstelle, die das Zustellprotokoll aufbewahrt (0 bis 10000) |
| `SWAYY_WEBHOOK_LOG_ENTRIES` | `25` | Einträge, die das Zustellprotokoll auf der Webhook-Seite zeigt (1 bis 500) |
| `SWAYY_WEBHOOK_URL_MAX` | `255` | Längste Adresse eines Endpunkts in Zeichen (30 bis 255; die Spalte fasst 255) |

Ein ungültiger Wert legt nichts lahm: Es gilt die Voreinstellung oder die nächste Grenze, und eine Warnung im Log nennt die Variable.

### Zielprüfung für ausgehende Anfragen

Webhook-Endpunkte und die [MailWizz-API-URL](#newsletter-mailwizz) trägt jeder Betrieb selbst ein, aufgerufen werden sie vom Server. Beide laufen durch dieselbe Prüfung (`App\Support\OutboundUrlGuard`):

- Die Adresse beginnt mit `https://`, enthält keine Zugangsdaten, und ihr Servername besteht aus den Zeichen eines DNS-Namens (Umlaute als Punycode).
- Der Name wird aufgelöst; **jede** Adresse dahinter muss global geroutet sein. Loopback, private, Link-Local- und andere Netze für besondere Zwecke sind gesperrt.
- Geprüft wird beim Speichern und vor jedem Aufruf. Der Aufruf geht genau an die geprüfte Adresse (`CURLOPT_RESOLVE`), damit ein Name nicht zwischen Prüfung und Aufruf umschwenken kann, und folgt keiner Weiterleitung.
- Die Meldung beim Speichern nennt den Grund und den nächsten Schritt.

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_OUTBOUND_ALLOWED_NETWORKS` | leer | Netze, die trotzdem erreichbar sein dürfen, etwa eine MailWizz-Installation im internen Netz einer Selbstinstallation. Komma-Liste aus IP-Adressen und CIDR-Netzen (`10.20.0.0/16,192.168.5.10`). Ein Eintrag gilt für alle Betriebe der Installation; ungültige Einträge bleiben unberücksichtigt und stehen als Warnung im Log |

---

## Events & Tickets

**Admin** (`/admin/events`, Recht `events.manage`): Events anlegen (Titel, Beschreibung, Datum/Uhrzeit auch über Mitternacht, Kapazität, Preis pro Person, Raum, Buchungs- und Stornofrist in Stunden vor Beginn, öffentlich/intern), Status steuern (Entwurf/Veröffentlicht/Abgesagt/Beendet), Teilnehmerliste mit **Check-in** und **CSV-Export**, Buchungen stornieren.

**Öffentlich:** `/book/{tenant}/{location}/events` listet alle veröffentlichten Events (mit Restplatz-Hinweis); die Detailseite bucht Tickets mit Kapazitäts-Recheck in der Transaktion (kein Überbuchen unter Last), Honeypot + Rate Limit, DSGVO-/Newsletter-Checkboxen (Newsletter → MailWizz-Sync). Gäste erhalten eine Bestätigungs-Mail mit sicherem Storno-Link; die Stornofrist wird serverseitig geprüft. Die Tisch-Buchungsseite verlinkt anstehende Events automatisch.

Preise werden in Minor Units gespeichert; `payment_status = required` markiert offene Zahlungen (Online-Zahlung folgt mit der Stripe-Integration).

---

## Zahlungen (Stripe)

**Konfiguration** unter *Einstellungen › Zahlungen › Zahlungsanbieter* (Recht `integrations.manage`): Secret Key (`sk_…`) und Webhook-Signing-Secret (`whsec_…`) eintragen — verschlüsselt gespeichert, nie wieder angezeigt. In Stripe die Webhook-URL `https://…/webhooks/stripe` mit den Events `checkout.session.completed` und `checkout.session.expired` hinterlegen. Voraussetzung: Tarif-Feature `deposits_enabled`.

**Zwei Wege zur Bestätigung.** Der Rücksprung von Stripe (`/pay/stripe/return/{intent}?session_id=…`) fragt die Sitzung über die API ab und schließt die Zahlung selbst ab; der Webhook ist die zweite Spur für den Fall, dass der Gast den Tab vorher schließt. `handlePaid()` ist idempotent, doppelte Meldungen sind also harmlos. Ob der Endpunkt in Stripe wirklich existiert, kann die Anwendung nicht abfragen — deshalb protokolliert der Webhook-Handler `payment.webhook_received` im Auditlog, und die Einstellungsseite zeigt an, wann sich der Webhook zuletzt gemeldet hat (oder dass noch nie einer ankam). Bei PayPal erledigt der Rücksprung die Erfassung ohnehin allein.

**Event-Vorauszahlungen:** Eventbuchungen mit Preis erhalten in Bestätigungsmail und auf der Verwaltungsseite einen „Jetzt bezahlen"-Button → Stripe Checkout (gehostet, **keine Kartendaten im System**). Nach Zahlungseingang setzt der signierte Webhook die Buchung auf `paid`.

**Reservierungs-Deposits (No-Show-Schutz):** Unter *Einstellungen › Zahlungen › Anzahlungen* legst du fest, ab welcher Personenzahl/Uhrzeit eine Anzahlung fällig ist (Betrag pro Person, Zahlungsfrist). Online-Reservierungen, die eine Regel treffen, starten als `payment_pending` mit Zahlungslink; nach Zahlung bestätigt der Webhook die Reservierung automatisch.

Den offenen Zahlungen widmet sich `App\Jobs\ExpireUnpaidReservations` (Scheduler, alle 5 Minuten):

| Zeitpunkt | Was passiert | Vorlage |
|---|---|---|
| direkt nach der Buchung | Zahlungsaufforderung mit Betrag, Link und Frist | `payment_pending` |
| nach der halben Frist | Erinnerung | `payment_reminder` |
| nach Fristablauf | `payment_status` → `expired`, Status → `Expired`, Tisch frei | `payment_expired` |

Regeln mit `cancel_unpaid_automatically = false` halten die Buchung offen — der Betrieb entscheidet selbst. Ein **begonnener Checkout schützt die Buchung 15 Minuten über die Frist hinaus** (`CHECKOUT_GRACE_MINUTES`), damit niemand seinen Tisch verliert, während er beim Zahlungsanbieter steht. Trifft eine Zahlung dennoch auf eine bereits verfallene Reservierung, löst der Webhook eine automatische Erstattung aus statt sie wiederzubeleben.

Neue Platzhalter für eigene Vorlagen: `{payment_link}`, `{payment_amount}`, `{payment_deadline}`.

### Zahlungen im Anbieterkonto wiederfinden

Ein PayPal- oder Stripe-Konto wird selten nur für Swayy genutzt. Damit sich von der Kontoseite her sagen lässt, welche Zahlung aus dem Buchungssystem stammt, geht mit jedem Vorgang eine Kennung mit — gebaut in `App\Support\PaymentReference`, damit sie über beide Anbieter hinweg gleich aussieht:

| Feld | Inhalt | Wo es auftaucht |
|---|---|---|
| PayPal `custom_id`, Stripe `metadata[reference]` | `swayy:{mandant}:res:{code}` bzw. `:event:{code}` | Transaktionsbericht, API, Stripe-Dashboard-Suche |
| PayPal `invoice_id` | `SWAYY-{vorgangs-id}` | PayPal-Oberfläche, Berichte |
| PayPal `soft_descriptor` | Standortname, 22 Zeichen | Kontoauszug des Gastes |
| Stripe `metadata[source]` | `swayy` | Dashboard-Filter |

**Alles aus Swayy findest du also über den Präfix `swayy:`.** Der Reservierungscode in der Kennung führt direkt zur Buchung.

Zur `invoice_id`: PayPal erzwingt deren Eindeutigkeit und weist eine zweite Zahlung zum selben Vorgang ab — genau das ist der Zweck, eine Anzahlung soll einmal fliessen. Meldet PayPal `DUPLICATE_INVOICE_ID`, wirft `PayPalProvider` eine `PaymentAlreadySettledException`; der Gast landet mit einem Hinweis zurueck auf seiner Verwaltungsseite und der Betrieb bekommt `payment.already_settled_at_provider` ins Auditlog. **Nicht** ohne Rechnungsnummer wiederholen — das liesse den Gast doppelt zahlen.

**Verrechnungs- und No-Show-Hinweis:** Gäste sehen an jeder Zahlungsstelle (Eventseite, Bestätigungs-/Verwaltungsseite, E-Mail) den Hinweis: *„Die Vorauszahlung wird bei Ihrem Besuch vollständig mit der Rechnung verrechnet. Bei Nichterscheinen (No-Show) erfolgt keine Rückerstattung."* Damit ist die Einbehaltung bei No-Show transparent vereinbart (AGB-Hinterlegung pro Tenant empfohlen).

Sicherheit: Webhook-Signaturprüfung (HMAC, Replay-Schutz ±5 min) gegen das Secret des jeweiligen Tenants, idempotente Verarbeitung, Audit-Log (`payment.checkout_started`, `payment.succeeded`), ausgehender Webhook `payment.succeeded` an Tenant-Endpoints.

---

## Kundenpanel von bright color

Neue Betriebe bestellen, testen und kündigen swayy im Kundenpanel von bright
color (panel.kundenberei.ch), wie vipinvit.es. Das Panel führt Kunde, Vertrag,
Tarif und Rechnungen; swayy führt den Betrieb. Dazwischen liegt die
**Bereitstellungs-Schnittstelle** unter `/provisioning/v1`: signierte
JSON-Aufrufe ohne Sitzung und ohne CSRF (`routes/provisioning.php`,
`app/Provisioning/`). Vertrag und Prüfwerte stehen im Skill `kundenpanel-saas`,
die Umsetzung in `docs/superpowers/specs/2026-10-08-kundenpanel-anbindung-design.md`.

**Einschalten:** `PROVISIONING_KEYS` in der `.env` setzen (ein oder zwei
Schlüssel, je mindestens 32 Zeichen) und denselben Schlüssel im Panel unter
SaaS-Dienste eintragen. `PANEL_BASE_URL` öffnet den Rückweg „Tarif und
Rechnungen“, `PANEL_ORDER_URL` leitet `/register` auf die Bestellseite des
Panels. Alle Einstellungen mit Vorgaben und Grenzen stehen in `.env.example`
und `config/provisioning.php`:

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `PROVISIONING_KEYS` | leer | Ein oder zwei Schlüssel, je mindestens 32 Zeichen, durch Komma; der erste signiert auch den Rückweg ins Panel. Ohne Schlüssel ist die Schnittstelle aus |
| `PROVISIONING_SERVICE_CODE` | `swayy` | Name dieses Dienstes im Panel (`a–z`, `0–9`, `-`; höchstens 40 Zeichen) |
| `PROVISIONING_MAX_SKEW_SECONDS` | `300` | Erlaubte Abweichung des Zeitstempels in Sekunden (30 bis 3600) |
| `PROVISIONING_IDEMPOTENCY_DAYS` | `7` | So viele Tage merkt sich Swayy die Antwort auf einen Aufruf (1 bis 90) |
| `PROVISIONING_ALLOWED_NETWORKS` | leer | IP-Netze, aus denen das Panel aufrufen darf (CIDR, Komma); leer erlaubt alle |
| `PROVISIONING_MAX_FAILURES` | `10` | Fehlversuche mit falscher Signatur je Absender, danach gilt die Sperre (1 bis 100) |
| `PROVISIONING_LOCK_SECONDS` | `900` | Dauer der Sperre in Sekunden (60 bis 86400) |
| `PROVISIONING_CLEANUP_TIME` | `04:40` | Uhrzeit für das tägliche Aufräumen von Aufrufkennungen und Anmeldelinks (`HH:MM`) |
| `LOGIN_LINK_SECONDS` | `120` | Gültigkeit des Anmeldelinks aus „Zum Dienst“ in Sekunden (30 bis 900) |
| `PANEL_BASE_URL` | leer | Adresse des Kundenpanels (`https://…`) für den Rückweg „Tarif und Rechnungen“ |
| `PANEL_ORDER_URL` | leer | Bestellseite des Panels; ist sie gesetzt, leitet `/register` dorthin, leer bleibt die eigene Registrierung |

**Was sich für einen Betrieb mit Vertrag ändert:**

- Zustand aus dem Panel: *wartet auf Freigabe* (Verwaltung offen, Buchungsseiten
  zu), *aktiv*, *gesperrt* und *gekündigt* (Verwaltung zeigt eine Seite mit
  Grund und Weg in den Kundenbereich, Buchungsseiten und API zu).
- Die Grenzen „Standorte“ (`max_locations`) und „Tische“ (`max_tables`) kommen
  aus dem Tarif im Panel; Funktionen und übrige Grenzen vom Plan gleichen
  Namens (`trial`, `starter`, `professional`, `multi_location`, `enterprise`).
- Eine eigene Probefrist gibt es nicht mehr; das Panel beendet Probe und
  Freiplatz.
- „Zum Dienst“ im Kundenbereich meldet den Inhaber mit einem einmaligen Link
  unter `/anmelden/<token>` an (`LOGIN_LINK_SECONDS`, Vorgabe 120 Sekunden).
  Ein eigenes Passwort legt er über „Passwort vergessen“ fest.
- `/admin/tarif` führt den Inhaber mit einem signierten Link ins Panel.

**Bestehende Betriebe** kommen unter Vertrag, indem im Panel eine Bestellung
mit der Mailadresse des Inhabers angelegt und dort „Vorhandene Organisation
übernehmen“ gewählt wird (`adopt`). Betriebe ohne Vertrag laufen bis dahin wie
bisher mit Probefrist und Abrechnungsanfrage.

**Prüfstand:** Vor dem Live-Anschluss läuft im Repo des Panels
`SAAS_PRUEFSTAND_KEY=… php tests/saas/pruefstand.php --url https://<testinstanz> --service swayy`
gegen eine Testinstanz.

## Abo-Abrechnung per SEPA-Lastschrift (GoCardless)

> **Wichtig:** Das ist die **SaaS-Abrechnung** – also wie deine *Kunden (Betreiber)* ihr **Software-Abo** an *dich (Plattformbetreiber)* zahlen. Nicht zu verwechseln mit Stripe/PayPal oben, die Anzahlungen von *Gästen* an *Betreiber* abwickeln.

Betreiber richten ihr Abo unter **Abrechnung → „Per Lastschrift einrichten"** (Recht `billing.manage`) selbst ein und können dort **jederzeit wieder kündigen**. Technisch ist das **kein Dauerauftrag**, sondern ein **SEPA-Lastschriftmandat**: der Kunde autorisiert einmalig, danach zieht GoCardless den Tarifpreis monatlich automatisch ein (Pull). Bei Einrichtung, Kündigung und jedem Zahlungsereignis geht eine E-Mail an **Kunde *und* Plattformbetreiber**.

### Ablauf
1. **Mandat** – Klick → Weiterleitung zur gehosteten GoCardless-Seite, Kunde gibt IBAN ein und bestätigt das Mandat (Redirect Flow).
2. **Abo** – nach Rückkehr legt Swayy automatisch eine GoCardless-*Subscription* in Höhe von `plan.price_monthly_minor` an (`day_of_month: 1`), Tenant-Status → `active`.
3. **Einzüge** – GoCardless bucht monatlich ab und meldet jedes Ereignis per Webhook; Swayy verschickt die Benachrichtigungen.
4. **Kündigung** – „Lastschrift kündigen" storniert Subscription + Mandat; keine weiteren Einzüge.

**Es werden keine Bankdaten in Swayy gespeichert** – nur Referenzen (`gocardless_customer_id`, `_mandate_id`, `_subscription_id`, Status) auf `billing_profiles`. IBAN/Mandat liegen ausschließlich bei GoCardless.

### Konfiguration (`.env`)

| Variable | Pflicht | Bedeutung |
|----------|---------|-----------|
| `GOCARDLESS_ACCESS_TOKEN` | ja | Access-Token aus dem GoCardless-Dashboard. Sandbox-Tokens beginnen mit `sandbox_…`, Live mit `live_…`. Leer = der „Per Lastschrift einrichten"-Button erscheint nicht. |
| `GOCARDLESS_ENVIRONMENT` | nein | `sandbox` (Default) → API `api-sandbox.gocardless.com`; `live` → `api.gocardless.com`. Bestimmt allein über Test- vs. Echtbetrieb. |
| `GOCARDLESS_WEBHOOK_SECRET` | empfohlen | Secret des in GoCardless angelegten Webhook-Endpoints. Wird zur HMAC-SHA256-Prüfung eingehender Events genutzt. Ohne Secret werden Webhooks abgelehnt (Einrichten/Kündigen funktioniert trotzdem, nur die asynchronen Zahlungs-Mails fehlen). |
| `SWAYY_OWNER_EMAIL` | empfohlen | Empfänger der Plattform-Benachrichtigungen (z. B. deine Adresse). Fällt zurück auf `SUPPORT_EMAIL`, dann `MAIL_FROM_ADDRESS`. |

Nach Änderungen `php artisan config:clear` (bzw. Container neu starten).

### Sandbox zuerst (empfohlen)
1. Auf **manage-sandbox.gocardless.com** registrieren → *Developers → Access tokens* → `sandbox_…`-Token erzeugen.
2. `.env`: `GOCARDLESS_ACCESS_TOKEN=sandbox_…`, `GOCARDLESS_ENVIRONMENT=sandbox`. Zum reinen Durchklicken `MAIL_MAILER=log` (Mails landen im Log).
3. „Abrechnung → Per Lastschrift einrichten" → auf der Sandbox-Seite die dort angezeigten **Test-Bankdaten** verwenden.
4. **Webhook (optional):** *Developers → Webhook endpoints* → URL `https://DEINE-DOMAIN/webhooks/gocardless`, Secret nach `GOCARDLESS_WEBHOOK_SECRET`. Lokal braucht es eine öffentliche URL (z. B. ngrok). Im Sandbox-Dashboard kannst du Zahlungen manuell auf `confirmed`/`failed` setzen, um die Webhook-Mails zu testen.

**Umstieg auf Live:** nur Live-Token (`live_…`) eintragen und `GOCARDLESS_ENVIRONMENT=live` – sonst nichts.

### Webhook-Events
`POST /webhooks/gocardless` (CSRF-exempt, HMAC-signaturgeprüft). Verarbeitet: `payments.confirmed` (→ Status `active` + Mail „Zahlung eingegangen"), `payments.failed` (→ `past_due` + Mail), `mandates.cancelled|failed|expired` (→ Abo beendet + Mail). Zuordnung zum Tenant über die `mandate`-Referenz im Event.

### Hinweise zum SEPA-Betrieb
- Geld fließt zuerst an GoCardless und wird gebündelt **ausgezahlt (Payout)** – SEPA-Einzüge sind **nicht sofort** „confirmed“ (mehrere Werktage Vorlauf).
- **Rücklastschriften** (bis zu 8 Wochen) kommen als `payments.failed` → Status `past_due` + Mail an beide.
- Gebühren je Einzug regelt GoCardless (anbieterseitig, nicht in Swayy).
- Voraussetzung: der Tarif hat einen Preis (`price_monthly_minor > 0`); bei kostenlosen/Trial-Tarifen erscheint kein Lastschrift-Button.

Sicherheit: Mandats-Einrichtung als „genau-einmal“-Flow per `lockForUpdate` (kein Doppel-Abo bei doppeltem Rücksprung), HMAC-Webhook-Prüfung, Fehler externer Aufrufe werden abgefangen (keine internen Details an Nutzer), Audit-Log (`billing.directdebit.activated|cancelled|webhook`).

---

## Newsletter (MailWizz)

Konfiguration im Adminbereich unter **Einstellungen › Verbindungen › Newsletter** (Recht `integrations.manage`):

1. API-URL (z. B. `https://news.example.com/api`), API-Key und Listen-UID eintragen – die Verbindung wird beim Speichern sofort getestet.
2. Credentials werden **verschlüsselt** gespeichert (`integration_connections`, Laravel Crypt); der API-Key wird nach dem Speichern nie wieder angezeigt.
3. Ab dann: Setzt ein Gast im Buchungswidget die **getrennte Newsletter-Checkbox**, wird er nach der Buchung per Queue-Job (`SyncNewsletterSubscriber`, Retry mit Backoff) in die MailWizz-Liste übertragen (`EMAIL`, `FNAME`, `LNAME`).
4. **Double-Opt-In** steuert die Listeneinstellung in MailWizz – bei DOI-Listen verschickt MailWizz die Bestätigungsmail selbst.
5. Jede Übertragung wird in `notification_logs` (Kanal `newsletter`) protokolliert; die Einwilligung selbst liegt unabhängig davon DSGVO-konform in `guest_consents`.
6. Die API-URL läuft durch dieselbe [Zielprüfung](#zielprüfung-für-ausgehende-anfragen) wie Webhook-Endpunkte: beim Speichern, beim Verbindungstest und vor jeder Übertragung. Lehnt die Prüfung eine gespeicherte Adresse ab, steht die Anbindung auf „Fehler“, die Einstellungsseite nennt den Grund, und weitere Übertragungen ruhen bis zum nächsten Speichern. Ein Name, der sich vorübergehend nicht auflösen lässt, führt zu einem späteren Versuch.

Ohne konfigurierte Integration wird die Einwilligung nur gespeichert – es geht nichts verloren, die Synchronisierung kann später nachgeholt werden. Weitere Provider (Mailchimp, Brevo, CleverReach) lassen sich über das `NewsletterProvider`-Interface ergänzen.

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_NEWSLETTER_TIMEOUT` | `10` | Wartezeit je Anfrage an MailWizz in Sekunden, auch beim Verbindungstest (1 bis 60) |
| `SWAYY_NEWSLETTER_TRIES` | `3` | Versuche je Übertragung eines Gastes (1 bis 10) |
| `SWAYY_NEWSLETTER_BACKOFF` | `60,600` | Wartezeiten zwischen den Versuchen in Sekunden; der letzte Wert gilt für alle weiteren (je 1 bis 86400) |
| `SWAYY_NEWSLETTER_API_URL_MAX` | `255` | Längste API-URL der Seite „Newsletter“ in Zeichen (30 bis 2000) |
| `SWAYY_NEWSLETTER_API_KEY_MAX` | `255` | Längster API-Key in Zeichen (32 bis 1000) |
| `SWAYY_NEWSLETTER_LIST_UID_MAX` | `64` | Längste Listen-UID in Zeichen (8 bis 255); die drei Angaben liegen verschlüsselt in einem Textfeld der Datenbank, die Höchstwerte passen zusammen hinein |

Ein ungültiger Wert legt nichts lahm: Eine Zahl außerhalb der Grenzen gilt als nächste Grenze, alles andere als Voreinstellung, und eine Warnung im Log nennt die Variable.

---

## Queue & Scheduler

```bash
php artisan queue:work        # Mails, Webhooks
php artisan schedule:work     # lokal; Produktion: Cron-Eintrag jede Minute
```

Geplante Jobs: Reservierungs-Reminder (alle 15 min), Feedback-Follow-ups (stündlich), Wartelisten-Expiry (alle 10 min), offene Anzahlungen erinnern und abräumen (alle 5 min, `ExpireUnpaidReservations`), unbestätigte Buchungen abräumen (stündlich, `ExpireUnconfirmedReservations`), Marketing-Kampagnen (täglich 09:00), DSGVO-Retention (täglich 03:30).

Im Docker-Setup laufen `queue` und `scheduler` als **eigene Container** (`docker-compose.yml`). Die meisten Mails (Buchungsbestätigung, Erinnerung, Billing-Benachrichtigung) werden **über die Queue** verschickt – ohne laufenden `queue`-Container kommen sie nicht an. **Ausnahme:** der Passwort-Reset wird bewusst **synchron** versendet (unabhängig von der Queue).

**Mail kommt nicht an? Mail-Config vs. Queue trennen:**
```bash
# 1) Sendet SYNCHRON (ohne Queue) – isoliert das SMTP-Problem
docker compose exec app php artisan swayy:test-mail you@example.com
# 2) Laufen die Worker?  3) Stecken Jobs als "failed" fest?
docker compose ps queue
docker compose logs --tail=100 queue
docker compose exec app php artisan queue:failed
```
Klappt `swayy:test-mail`, aber echte Mails fehlen → Queue-Container/Worker prüfen.
Schlägt schon `swayy:test-mail` fehl → `MAIL_*` in der `.env` korrigieren (`smtp.example.com` ist nur ein Platzhalter!).

---

## CLI-Befehle (Artisan)

> Im Docker-Betrieb mit `docker compose exec app <befehl>` ausführen, z. B.
> `docker compose exec app php artisan swayy:stats`. Lokal genügt `php artisan …`.
> Optionen lassen sich beliebig kombinieren; `--limit=0` heißt „alle Zeilen".

### Plattform-Verwaltung

**`php artisan swayy:create-admin`** — Legt einen Plattform-Oberadmin (`saas_role=super_admin`) an, der den SaaS-Adminbereich (`/saas`) und alle Mandanten verwalten darf. Ohne Optionen interaktiv (fragt E-Mail/Passwort/Name ab).
- `--email=` / `--password=` / `--name=` — Werte direkt mitgeben (nicht-interaktiv, z. B. für Skripte).
- `--force` — einen **bestehenden** Account zum Oberadmin hochstufen bzw. dessen Passwort zurücksetzen.
- `--if-missing` — nur anlegen, wenn noch **kein** Oberadmin existiert. Genau das läuft beim Container-Start, damit beim ersten Hochfahren ein Admin da ist.
```bash
php artisan swayy:create-admin --email=du@firma.de --password=GEHEIM1234 --name="Chef"
```

**`php artisan swayy:stats`** — Schneller Plattform-Überblick im Terminal: Anzahl Mandanten (nach Status), Nutzer, Gäste und Reservierungen. Gut für einen Gesundheits-/Wachstums-Check ohne ins UI zu gehen.

**`php artisan swayy:tenants`** — Listet deine Mandanten (zahlende Kunden/Betriebe) mit Tarif, Status und Standortzahl.
- `--status=` — nur ein Status: `active`, `trial_expired`, `pending_billing`, `suspended`, `cancelled`.
- `--search=` — nach Name oder Slug filtern.
- `--with-trashed` — auch soft-gelöschte Mandanten einbeziehen.
```bash
php artisan swayy:tenants --status=active --search=müller --with-trashed
```

**`php artisan swayy:users`** — Listet alle Login-Nutzer über alle Mandanten hinweg, samt Mandanten-Mitgliedschaften und Plattform-Rolle.
- `--tenant=` — nur Nutzer eines Mandanten (ID oder Slug).
- `--saas` — nur Plattform-Admins (Nutzer mit gesetzter `saas_role`).
- `--search=` — nach Name oder E-Mail filtern.

**`php artisan swayy:guests`** — Listet die **Kunden/Gäste** (die bei den Betrieben reservieren), nicht die Login-Nutzer.
- `--tenant=` — nur Gäste eines Mandanten.
- `--search=` — Name, E-Mail oder Telefon.
- `--limit=` — Zeilenzahl (Standard 50, `0` = alle).
- `--with-anonymized` — auch DSGVO-anonymisierte (gelöschte) Gäste anzeigen.
```bash
php artisan swayy:guests --tenant=demo --search=meier --limit=0 --with-anonymized
```

**`php artisan swayy:reservations`** — Listet Reservierungen über alle Mandanten.
- `--tenant=` — auf einen Mandanten beschränken.
- `--date=` — nur ein Tag (`Y-m-d`).
- `--upcoming` — nur künftige.
- `--status=` — z. B. `confirmed`, `requested`, `cancelled_by_guest`.
- `--limit=` — Zeilenzahl (Standard 50, `0` = alle).
```bash
php artisan swayy:reservations --tenant=demo --date=2026-07-01 --upcoming --status=confirmed --limit=0
```

**`php artisan swayy:plans`** — Listet die Tarife (Pläne) mit Preis, Limits und Mandantenzahl. Mit `--all` auch deaktivierte Tarife.

**`php artisan swayy:billing-requests`** — Zeigt eingegangene Billing-Anfragen (entstehen, wenn ein Trial ausläuft und der Betreiber zahlen will). Mit `--pending` nur die, die der Kunde bestätigt hat, du aber noch nicht freigeschaltet hast.

**`php artisan swayy:install-legal`** — Legt Impressum/Datenschutz/AGB als Markdown unter `storage/app/private/legal/` an, falls sie fehlen (läuft beim Container-Start). `--force` überschreibt vorhandene Dateien mit den Vorlagen.

### Diagnose & Betrieb

**`php artisan swayy:test-mail du@firma.de`** — Verschickt **synchron** (an der Queue vorbei) eine Testmail und zeigt Mailer/Host/Absender. Damit trennst du eindeutig ein **SMTP-Problem** von einem **Queue-Problem**: kommt die Testmail an, ist SMTP ok und die Ursache liegt im Queue-Worker; schlägt sie schon hier fehl, stimmt die `MAIL_*`-Konfiguration nicht.

**`php artisan swayy:queue-health`** — Zeigt die aktive Queue-Verbindung, die Zahl **wartender** Jobs und **fehlgeschlagener** Jobs (inkl. der letzten 5 Fehlermeldungen). Erste Anlaufstelle, wenn Mails/Webhooks nicht rausgehen. `--queue=` für einen bestimmten Queue-Namen (Standard `default`).

**`php artisan queue:failed`** — Listet alle in `failed_jobs` gelandeten Jobs (nach 3 Fehlversuchen) mit ID und Fehler. **`queue:retry all`** stellt sie erneut in die Queue (z. B. nachdem du die SMTP-Daten korrigiert hast); **`queue:flush`** verwirft sie endgültig.

**`php artisan queue:work --sleep=3 --tries=3 --max-time=3600`** — Der Worker, der Jobs (Mails, Webhooks) abarbeitet. Läuft im Docker-Setup als eigener `queue`-Container — manuell nur nötig, wenn du ohne diesen Container arbeitest.

**`php artisan schedule:run`** — Führt fällige geplante Jobs aus (Reminder, Wartelisten-Expiry, Retention …). Läuft im `scheduler`-Container minütlich; muss sonst per Cron jede Minute aufgerufen werden.

**`php artisan migrate --force`** — Spielt ausstehende Datenbank-Migrationen ein (`--force` = ohne Rückfrage, für Produktion). Der App-Container macht das beim Start automatisch.

**`php artisan config:cache` / `config:clear`** — Baut bzw. leert den Config-Cache. **Nach jeder `.env`-Änderung** nötig (bzw. Container neu starten), sonst greifen neue Werte nicht.

**`php artisan db:seed --class=PlanSeeder --force`** — Spielt die Standard-Tarife (neu) ein. Idempotent — läuft auch beim Container-Start.

### Lizenz (Self-Hosting)

**`php artisan license:keygen`** — Erzeugt ein Ed25519-Schlüsselpaar zum Signieren/Prüfen von Self-Hosted-Lizenzen. Den **privaten** Schlüssel sicher aufbewahren, den öffentlichen in die App-Config legen.

**`php artisan license:sign …`** — Erstellt und signiert eine Lizenzdatei für einen Lizenznehmer.
- `--id=` Lizenz-ID, `--licensee=` Name, `--email=` Kontakt, `--plan=` (`starter|professional|enterprise`).
- `--max-tenants=` / `--max-locations=` / `--max-tables=` / `--max-users=` — Limits.
- `--expires=` Ablaufdatum (`YYYY-MM-DD`, leer = unbegrenzt), `--features=*` freigeschaltete Features, `--secret=` privater Key (base64), `--out=` Zieldatei.
```bash
php artisan license:sign --id=lic_abc123 --licensee="Firma GmbH" --email=du@firma.de \
  --plan=professional --max-locations=5 --expires=2027-12-31 --secret=<base64-key> --out=license.json
```

**`php artisan license:validate`** — Prüft die installierte Lizenz und zeigt ihren Status. `--fresh` umgeht den Cache und prüft sofort neu.

---

## Rechtstexte (Impressum / Datenschutz / AGB)

Liegen als **Markdown** unter `storage/app/private/legal/{impressum,datenschutz,agb}.md`
(bind-gemountet → direkt auf dem Host editierbar). Der Container legt beim Start
fehlende Dateien aus Vorlagen an (`php artisan swayy:install-legal`).
Der Inhalt wird **bei jedem Aufruf frisch** gelesen – Änderungen sind **sofort
ohne Neustart** wirksam (`/impressum`, `/datenschutz`, `/agb`).

### „Noch nicht in Betrieb"-Hinweis

Solange ein Rechtstext noch die mitgelieferte Vorlage enthält, zeigen **alle
Plattformseiten** (Startseite, Kontakt, Rechtstexte, Anmeldung, Registrierung)
einen Hinweis, dass hier noch nichts in Betrieb ist und keine Verträge zustande
kommen. Im Plattform-Dashboard unter `/saas` steht dazu, welche Dateien noch
offen sind.

Der Hinweis **verschwindet von selbst**, sobald die Texte gepflegt sind – es
gibt bewusst keinen Schalter zum Wegklicken. Erkannt wird die Vorlage an ihren
Musterangaben (`Musterfirma`, `Max Mustermann`, `kontakt@example.com`,
`[Firmenname]` …) und am Warnkasten `> **Bitte anpassen.**` am Dateikopf.
Bei der AGB gibt es keine Musterdaten – dort gilt das Entfernen des Warnkastens
als bewusstes „geprüft und übernommen".

**Die Buchungsseiten der Betriebe sind ausgenommen.** Dort tritt der jeweilige
Gastronom als Anbieter auf, nicht die Plattform; ein „nicht in Betrieb" wäre
gegenüber echten Gästen schlicht falsch. Zwei Dinge halten das auseinander:

1. Plattform- und Buchungsseiten teilen **kein Layout**
   (`layouts/marketing.blade.php` gegen `layouts/public.blade.php`).
2. Gästeseiten verlinken **ausschließlich auf die Rechtstexte des Betriebs**
   (`tenant.privacy_url`), nie auf `/impressum`, `/datenschutz` oder `/agb` –
   sonst landet der Gast mit einem Klick doch im Plattform-Layout. Ein Test
   hält beides fest.

## E-Mail

Produktiv: echten SMTP-Provider in `.env` eintragen (Postmark, Amazon SES, SMTP2GO, Mailjet …):

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=tls          # tls = Port 587 (STARTTLS), smtps = Port 465 (SSL)
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS="no-reply@example.com"
MAIL_FROM_NAME="Swayy"
```

**Antworten von Gästen:** Alle Mails an Gäste gehen mit `MAIL_FROM_ADDRESS` als Absender (SPF/DKIM bleiben passend), zeigen aber den Namen des Betriebs und tragen dessen Antwortadresse als Reply-To. Antwortet ein Gast, landet die Mail direkt beim Betrieb, ohne eigenes SMTP je Betrieb. Der Betrieb pflegt Absendername und Antwortadresse unter *Einstellungen › Gästekommunikation › Absender & Antworten*. Ohne eigene Angabe gelten die Ersatzlisten:

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_GUEST_MAIL_REPLY_TO_FALLBACKS` | `location_email,owner_notification_email` | Ersatz für die Antwortadresse, in dieser Reihenfolge; `-` für keinen |
| `SWAYY_GUEST_MAIL_FROM_NAME_FALLBACKS` | `location_name,tenant_name` | Ersatz für den Absendernamen; ohne Treffer gilt `MAIL_FROM_NAME` |
| `SWAYY_GUEST_MAIL_OWNER_REPLY_TO_GUEST` | `true` | Benachrichtigung über eine neue Buchung: Antworten des Betriebs gehen direkt an den Gast |
| `SWAYY_GUEST_MAIL_FROM_NAME_MAX` | `100` | Längster Absendername in Zeichen (10 bis 255; die Spalte fasst 255) |
| `SWAYY_GUEST_MAIL_REPLY_TO_MAX` | `200` | Längste Antwortadresse in Zeichen (30 bis 255; die Spalte fasst 255) |

**Gestaltung der Mails:** Alle Mails teilen einen Rahmen mit Überschrift, Tabelle für Angaben, Knöpfen und Fußbereich (`mail:vorschau` zeigt sie an). Diese Einstellungen steuern ihn und die Gültigkeit der Links:

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `SWAYY_MAIL_OWNER_ADDRESS` | `du` | Anrede in Mails an Betriebe und in Swayy-Mails: `du` oder `Sie` |
| `SWAYY_MAIL_POWERED_BY` | `true` | „Reserviert mit Swayy“ im Fußbereich der Gast-Mails: `true` oder `false` |
| `SWAYY_MAIL_LOGO_MAX_KB` | `200` | Größte Logodatei, die in eine Mail eingebettet wird, in KB (16 bis 1024) |
| `SWAYY_MAIL_LOGO_HEIGHT` | `44` | Anzeigehöhe des Logos in px (24 bis 96) |
| `SWAYY_MAIL_CONTRAST_MIN` | `4.5` | Mindestkontrast für Knopftext und Links (3,0 bis 7,0) |
| `SWAYY_MAIL_DETAILS_MIN_ROWS` | `2` | Zeilen „Bezeichnung: Wert“, ab denen ein Absatz zur Tabelle wird (1 bis 6) |
| `SWAYY_MAIL_LABEL_MAX` | `40` | Längste Bezeichnung in einer solchen Zeile in Zeichen (10 bis 80) |
| `SWAYY_MAIL_BUTTON_LABEL_MAX` | `60` | Längste Knopfbeschriftung in Zeichen (20 bis 120) |
| `SWAYY_GUEST_LOGIN_LINK_MINUTES` | `60` | Gültigkeit des Anmeldelinks für Gäste in Minuten (10 bis 1440) |
| `SWAYY_GUEST_VERIFY_LINK_MINUTES` | `1440` | Gültigkeit des Bestätigungslinks einer Buchung in Minuten (60 bis 10080) |
| `SWAYY_BILLING_CONFIRM_HOURS` | `72` | Gültigkeit des Bestätigungslinks einer Tarifanfrage in Stunden (1 bis 336) |

Zum lokalen Testen ohne echten Versand: `MAIL_MAILER=log` → Mails landen in `storage/logs/laravel.log`. Vorlagen liegen als Defaults im `NotificationTemplateRenderer` und sind pro Tenant/Standort über die Tabelle `notification_templates` überschreibbar (Platzhalter: `{guest_name}`, `{reservation_date}`, `{cancel_link}`, …).

---

## Tests & Codequalität

```bash
php artisan test                                   # 32 Tests, u. a.:
#  - Tenant-Isolation (Scope, Admin-Routen, API-Tokens)
#  - Verfügbarkeit (Öffnungszeiten, Sperren, Vorlauf, Doppelbuchung,
#    kleinster Tisch, Kombinationen, Alternativen, Personenmodus)
#  - Öffentlicher Buchungsflow inkl. Mail, Storno-Link, Fristen, Honeypot
#  - Rollen/Rechte inkl. Standorteinschränkung + Impersonation-Audit
#  - Warteliste (Angebot → Annahme → Reservierung, Expiry)

vendor/bin/pint                                    # Code-Style
vendor/bin/phpstan analyse --memory-limit=1G       # Statische Analyse (0 Fehler)
```

`php artisan test` nutzt SQLite im Speicher. Dieselbe Suite gegen PostgreSQL,
die Datenbank von swayy.de, läuft mit `phpunit.pgsql.xml`. Sie braucht eine
eigene, leere Testdatenbank, denn `RefreshDatabase` leert sie bei jedem Lauf.
Vorgabe ist `gastrobook_test` auf `127.0.0.1:5432` mit Benutzer und Passwort
`gastrobook`; `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME` und
`DB_PASSWORD` aus der Umgebung überschreiben das.

```bash
docker run -d --rm --name gastrobook-pgtest -p 127.0.0.1:5432:5432 \
  -e POSTGRES_DB=gastrobook_test -e POSTGRES_USER=gastrobook -e POSTGRES_PASSWORD=gastrobook \
  postgres:17-alpine
vendor/bin/phpunit -c phpunit.pgsql.xml
```

Der Aufruf läuft über `vendor/bin/phpunit`, weil `php artisan test` selbst
`phpunit.xml` als Konfiguration anhängt; eine zweite Angabe beendet den Lauf
mit einer Warnung und Exit-Code 1.

CI: GitHub Actions (`.github/workflows/ci.yml`) mit Pint, Larastan, Tests unter
SQLite (PHP 8.3 bis 8.5) und PostgreSQL 17 (PHP 8.4, Job `tests-pgsql`) und dem
Frontend-Build. Das Docker-Image entsteht erst, wenn beide Testjobs und der
Frontend-Build grün sind.

**Fremdcode im Build:** Jede Action im Workflow steht auf einem festen Commit, die Version steht als Kommentar dahinter; Dependabot hebt beides gemeinsam an. Der `GITHUB_TOKEN` hat im Workflow Leserechte auf den Code, der Docker-Job zusätzlich das Schreibrecht für die Registry. Neue Paketversionen kommen mit einer Wartezeit von sieben Tagen: Dependabot über `cooldown` in `.github/dependabot.yml` (Sicherheitsupdates kommen sofort), npm ab Version 11.10 über `min-release-age` in `.npmrc`. `npm ci` installiert immer genau die Versionen aus `package-lock.json`. Ein dringendes Update auf eine jüngere Version geht mit `npm install <paket>@<version> --min-release-age=0`. `tests/Unit/SupplyChainSettingsTest.php` prüft diese Regeln.

---

## Spamschutz der öffentlichen Formulare (Cap)

Die öffentlich erreichbaren Formulare können mit [Cap](https://capjs.js.org)
gegen automatisierte Eingaben geschützt werden — einem selbst gehosteten
Proof-of-Work-CAPTCHA. Der Browser des Gastes rechnet eine kleine Aufgabe;
Bilderrätsel und Verhaltensauswertung entfallen.

Geschützt werden Reservierung und Terminbuchung, Eventbuchung, Warteliste,
Anmeldelink für das Gastkonto, Kontakt, Registrierung, Anmeldung und
„Passwort vergessen“. Formulare, die nur über einen Einmal-Token aus einer
Mail erreichbar sind (Stornierung, Umbuchung, Feedback, Einladung,
Passwort-Reset), bleiben bewusst ohne CAPTCHA.

### Einrichtung

1. Einen Cap-Server betreiben (Cap Standalone, Anleitung unter
   https://capjs.js.org) und dort einen Schlüssel anlegen. Man erhält einen
   **Site Key** und ein **Secret**.
2. In der `.env` eintragen und die Container neu starten:

```env
CAP_ENABLED=true
CAP_SERVER_URL=https://cap.example.com
CAP_SITE_KEY=abc123
CAP_SECRET_KEY=sk-...
```

Fehlt eine der drei Angaben, bleibt der Schutz aus — ein halb eingerichteter
CAPTCHA soll kein Formular blockieren.

### Schalter

| Variable | Voreinstellung | Wirkung |
|---|---|---|
| `CAP_TIMEOUT` | `8` | Wartezeit für die Rückfrage beim Cap-Server, in Sekunden |
| `CAP_FAIL_OPEN` | `false` | Notausgang: Antwortet der Cap-Server gar nicht, läuft die Anfrage ungeprüft durch — mit einem Fehlereintrag im Log bei jedem Mal. Ein Token, den Cap ausdrücklich abgelehnt hat, bleibt auch dann abgelehnt |
| `CAP_PROTECT_BOOKING` | `true` | Reservierung, Termin, Eventbuchung |
| `CAP_PROTECT_WAITLIST` | `true` | Warteliste |
| `CAP_PROTECT_CONTACT` | `true` | Kontaktformular |
| `CAP_PROTECT_REGISTER` | `true` | Registrierung eines Betriebs |
| `CAP_PROTECT_LOGIN` | `true` | Anmeldung |
| `CAP_PROTECT_PASSWORD` | `true` | Passwort vergessen |
| `CAP_PROTECT_PORTAL` | `true` | Anmeldelink für das Gastkonto |

### Eigene Formulare nachrüsten

Die Route bekommt die Wache, die Seite das Widget — beide mit demselben Namen
aus `config/cap.php`:

```php
Route::post('/mein-formular', [MeinController::class, 'store'])
    ->middleware('cap:contact');
```

```blade
<x-cap-widget form="contact" />
```

Ein Test wacht darüber, dass beide Seiten zusammenpassen und dass kein neues
öffentliches Formular ohne Schutz dazukommt
(`tests/Feature/CapCaptchaTest.php`).

### Datenschutz

Widget und WebAssembly lädt der Browser vom eigenen Cap-Server, den
pako-Entpacker aus `public/vendor/cap/`. An ein fremdes CDN geht dabei keine
Anfrage. Der Cap-Server selbst erhält beim Abholen der Aufgabe die IP-Adresse
des Besuchers — Abschnitt 13a der Datenschutzerklärung
(`resources/legal/datenschutz.md`) beschreibt das und wartet auf die Angaben
zum eigenen Server.

---

## Datenschutz (DSGVO)

- Einwilligungen werden **getrennt** erfasst (Reservierung ≠ Newsletter ≠ Marketing) mit Historie (`guest_consents`), Kanal und gehashter, gekürzter IP.
- Gastdaten-Export (Art. 15/20) als JSON, Anonymisierung (Art. 17) inkl. Reservierungs-Snapshots – Statistiken bleiben aggregiert erhalten.
- Aufbewahrungsfrist je Tenant (`guest_retention_months`, Default 36) mit täglichem Anonymisierungsjob.
- IP-Adressen werden im Auditlog anonymisiert (letztes Oktett genullt / IPv6 gekürzt).
- Sensible Gastnotizen mit gesondertem Recht; No-Show-Risiko ist eine transparente, dokumentierte Heuristik, nur Hinweis fürs Personal (kein automatisierter Ausschluss, Art. 22).
- **Keine Kreditkartendaten** im System – nur Provider-Referenzen (PaymentIntent-IDs).
- Für den Produktivbetrieb: AVV/DPA mit Hosting- und Mail-/SMS-Providern abschließen; Impressums-/Datenschutz-Links pro Tenant hinterlegbar.

---

## Backup, Updates, Produktion

- **Backups:** PostgreSQL-Dumps (`pg_dump`) + `storage/`-Volume sichern; vor Migrationen immer Backup.
- **Updates:** `composer install && php artisan migrate --force && npm run build && php artisan config:cache route:cache view:cache`; Queue-Worker neu starten (`php artisan queue:restart`).
- **Produktion:** `APP_ENV=production`, `APP_DEBUG=false`, HTTPS erzwingen, Redis für Cache/Session/Queue, Horizon optional (`composer require laravel/horizon`), Log-Aggregation, Health-Check unter `/up`.
- EU-Hosting empfohlen (personenbezogene Gästedaten).

---

## Lizenz / Hinweis

Eigenständiges Produkt. Marktreferenzen (Teburio, resmio, OpenTable u. a.) dienten ausschließlich als funktionale Orientierung – keine Übernahme von Designs, Texten oder geschützten Oberflächen.
