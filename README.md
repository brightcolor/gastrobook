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
8. [Öffentliche Buchungsseite](#öffentliche-buchungsseite)
9. [API](#api)
10. [Webhooks](#webhooks)
11. [Queue & Scheduler](#queue--scheduler)
12. [E-Mails testen](#e-mails-testen)
13. [Tests & Codequalität](#tests--codequalität)
14. [Datenschutz (DSGVO)](#datenschutz-dsgvo)
15. [Backup, Updates, Produktion](#backup-updates-produktion)

---

## Featureübersicht

| Modul | Status |
|---|---|
| Multi-Tenancy (Tenants → Standorte) mit globalem Scope + expliziten Checks | ✅ |
| SaaS-Adminbereich (Mandanten, Tarife, Status, Supportzugriff mit Auditlog) | ✅ |
| Tarif-/Limit-System (Trial, Starter, Professional, Multi-Location, Enterprise) | ✅ |
| Rollen & Rechte (8 Tenant-Rollen, 4 SaaS-Rollen, Standorteinschränkung) | ✅ |
| Öffentliches Buchungswidget (mobile-first, Slots-API, Honeypot, Rate Limits) | ✅ |
| Verfügbarkeitslogik (Öffnungszeiten, Sonderzeiten, Sperren, Vorlauf, Kapazitätsmodi) | ✅ |
| Automatische Tischzuweisung (kleinster passender Tisch, Prioritäten, Kombinationen) | ✅ |
| Live-Board fürs Personal (neue/anstehende Buchungen, Inline-Aktionen, Dark Mode, Vollbild, Echtzeit via SSE) | ✅ |
| Internes Reservierungsbuch (Filter, Suche, Schnellaktionen, Statushistorie) | ✅ |
| Grafischer Tischplan (Statusfarben, Drag&Drop-Editor, Live-Refresh, Touch) | ✅ |
| Öffentlicher Tischplan auf der Buchungsseite (Verfügbarkeit live, optionale Tischwahl, Räume/Etagen) | ✅ |
| Walk-ins (freie Tische sofort, "frei bis", als Reservierung mit Quelle `walk_in`) | ✅ |
| Warteliste (online + intern, Angebote per Mail mit Ablauf, Annahme-Link) | ✅ |
| Gäste-CRM (Dedupe, Besuchszähler, No-Show-Zähler, Tags, sensible Notizen) | ✅ |
| Stornolink / Änderungslink mit Secret-Token und Fristprüfung | ✅ |
| Online-Umbuchung durch den Gast (Frist, Re-Check Tisch/Mitarbeiter) | ✅ |
| Kundenkonto per Magic-Link (passwortlos): Termine ansehen, umbuchen, stornieren | ✅ |
| E-Mail-Bestätigung aktivierbar (Gast bestätigt Adresse beim ersten Buchen) | ✅ |
| E-Mail-Vorlagen (pro Tenant/Standort überschreibbar, Platzhalter, DE/EN) | ✅ |
| Reminder- & Feedback-Follow-up-Jobs (Scheduler) | ✅ |
| Feedback-Booster (intern erfassen, positives Feedback → externes Portal) | ✅ |
| Berichte (No-Show-Rate, Auslastung, Quellen, Covers, CSV-Exporte) | ✅ |
| REST-API v1 (Sanctum, tenant-gebundene Tokens, Scopes, Rate Limits) | ✅ |
| Webhooks (HMAC-Signatur, Retry/Backoff, Auto-Deaktivierung, Delivery-Log) | ✅ |
| Auditlog (filterbar, IP-anonymisiert, Impersonation-Kennzeichnung) | ✅ |
| MailWizz-Newsletter-Sync (Einwilligung → Liste, verschlüsselte Credentials) | ✅ |
| Konfigurierbare Widget-Felder (E-Mail/Telefon/Anlass/Allergien/Notiz je Standort) | ✅ |
| Einbettbares Widget (JS-Snippet → iframe mit Auto-Resize) | ✅ |
| DSGVO-Werkzeuge (Export, Anonymisierung, Einwilligungshistorie, Retention-Job) | ✅ |
| Stripe-Zahlungen produktiv: Event-Vorauszahlungen + Reservierungs-Deposits (Checkout, signierter Webhook) | ✅ |
| PayPal-Zahlungen (Orders v2, Capture-on-Return) – pro Mandant, parallel zu Stripe nutzbar (Gast wählt an der Kasse) | ✅ |
| No-Show-Schutz: Anzahlungsregeln per Admin-UI, Verrechnungshinweis, keine Rückerstattung bei No-Show | ✅ |
| Anzahlungs-Rückerstattung: Modus aus/manuell(Freigabe)/automatisch, sofort oder per Zeitplan, variabler %-Satz | ✅ |
| Events & Tickets (öffentl. Buchungsseite, Kapazität, Fristen, Check-in, CSV) | ✅ |
| Betriebstyp umschaltbar: Restaurant **oder** Friseur/Dienstleister (pro Mandant) | ✅ |
| Salon: Leistungen mit Dauer/Preis, Mitarbeiter (m:n), Termin-Buchung pro Mitarbeiter | ✅ |
| Salon: individuelle Mitarbeiter-Arbeitszeiten + Abwesenheiten (Urlaub/Krank) | ✅ |
| Salon: Kombi-Leistungen frei wählbar (Pills, Dauer/Preis summiert, ein Termin) | ✅ |
| Salon: Lückenoptimierer (packt „Beliebig"-Termine eng, reduziert Leerlauf) | ✅ |
| Puffer zwischen Terminen (Aufräumzeit) in der Slot-Berechnung | ✅ |
| SMS-Erinnerungen via seven.io (deutscher Anbieter, DSGVO, verschlüsselte Credentials) | ✅ |
| WhatsApp, Telefon-/AI-Assistent (Quelle, ConversationLog, Adapterpunkte) | 🔶 vorbereitet |
| Stripe/Mollie-Billing für Tenants | 🔶 vorbereitet |
| SaaS-Website (Landingpage, Preise, FAQ, Kontaktformular, Impressum/Datenschutz/AGB) | ✅ |
| Self-Service-Registrierung (Trial-Tenant inkl. Standort, ohne Zahlungsdaten) | ✅ |

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

Besondere Rechte: sensible Gastnotizen (`guest_notes.sensitive.view`), manuelle Überbuchung (`overbook.manual`, wird auditiert), Anonymisierung (`guests.anonymize`).

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

1. **`APP_URL` in der `.env` auf die echte Domain setzen**, z. B. `APP_URL=https://buchung.example.com`. Daraus entstehen alle absoluten Links (Mails, Magic-Link, Zahlungs-Rücksprung).
2. Der Proxy muss `X-Forwarded-Proto`/`-Host` setzen (Standard bei Traefik/Caddy; bei nginx `proxy_set_header X-Forwarded-Proto $scheme;` etc.). Für das **Live-Board (SSE)** Pufferung aus lassen (nginx: `proxy_buffering off;` für die App, oder den Header `X-Accel-Buffering: no` durchreichen – wird von der App bereits gesetzt).

Den Host-Port am besten nur lokal binden und den Proxy davorsetzen, z. B. in der `.env`: `SWAYY_PORT=127.0.0.1:8080` (dann lauscht nur der Proxy nach außen).

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
4. **Einstellungen** → Öffnungszeiten, Buchungsregeln (Intervall, Dauer, Vorlauf, Kapazitätsmodus), Räume und Tische anlegen, optional Tischkombinationen.
5. **Buchungsseite teilen**: Link steht oben auf der Einstellungsseite (`/book/<tenant>/<standort>`), z. B. für Website, Instagram-Bio oder Google Business Profile.
6. **Benutzer einladen** unter `/admin/users` (bestehende Konten werden direkt verknüpft, neue erhalten einen Einladungslink).

---

## Öffentliche Buchungsseite

`GET /book/{tenant}/{location}` (Alias `/r/...`) – mobile-first, wenige Schritte:

Personenzahl → Datum → verfügbare Uhrzeiten (live via `/slots`-JSON) → Kontaktdaten → DSGVO-Checkbox (Pflicht) + getrennte Newsletter-Checkbox → Bestätigungsseite.

- Bei Ausbuchung: Alternativzeiten am selben Tag, alternative Tage, optional Warteliste.
- Je nach Standortregel: sofort bestätigt / als Anfrage / Zahlung erforderlich (Deposit-Regel).
- Pflichtfelder pro Standort konfigurierbar – im Adminbereich unter **Einstellungen → Formularfelder im Buchungswidget**: jedes Feld (E-Mail, Telefon, Anlass, Allergien, Anmerkung) ist auf *Ausgeblendet / Optional / Pflichtfeld* stellbar; die Validierung greift serverseitig.
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

REST-API unter `/api/v1`, Auth via Sanctum-Bearer-Token. Tokens werden im Adminbereich (`/admin/api-tokens`) erstellt, sind **tenant-gebunden** und tragen Scopes:

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

---

## Webhooks

Endpoints pro Tenant (Verwaltung über API `POST /api/v1/webhooks`). Events u. a.:

`reservation.created|confirmed|updated|cancelled|seated|completed|no_show`, `waitlist.created|offered|accepted`, `feedback.received`

- Payload signiert: Header `X-Gastrobook-Signature: sha256=<HMAC-SHA256(body, secret)>`
- Retries mit Backoff (1 min → 2 h, 5 Versuche), Delivery-Log in `webhook_deliveries`
- Automatische Deaktivierung nach 20 Fehlern in Folge
- Payload-Versionierung (`"version": "1"`)

---

## Events & Tickets

**Admin** (`/admin/events`, Recht `events.manage`): Events anlegen (Titel, Beschreibung, Datum/Uhrzeit auch über Mitternacht, Kapazität, Preis pro Person, Raum, Buchungs- und Stornofrist in Stunden vor Beginn, öffentlich/intern), Status steuern (Entwurf/Veröffentlicht/Abgesagt/Beendet), Teilnehmerliste mit **Check-in** und **CSV-Export**, Buchungen stornieren.

**Öffentlich:** `/book/{tenant}/{location}/events` listet alle veröffentlichten Events (mit Restplatz-Hinweis); die Detailseite bucht Tickets mit Kapazitäts-Recheck in der Transaktion (kein Überbuchen unter Last), Honeypot + Rate Limit, DSGVO-/Newsletter-Checkboxen (Newsletter → MailWizz-Sync). Gäste erhalten eine Bestätigungs-Mail mit sicherem Storno-Link; die Stornofrist wird serverseitig geprüft. Die Tisch-Buchungsseite verlinkt anstehende Events automatisch.

Preise werden in Minor Units gespeichert; `payment_status = required` markiert offene Zahlungen (Online-Zahlung folgt mit der Stripe-Integration).

---

## Zahlungen (Stripe)

**Konfiguration** unter *Einstellungen → Zahlungen: Stripe* (Recht `integrations.manage`): Secret Key (`sk_…`) und Webhook-Signing-Secret (`whsec_…`) eintragen — verschlüsselt gespeichert, nie wieder angezeigt. In Stripe die Webhook-URL `https://…/webhooks/stripe` mit den Events `checkout.session.completed` und `checkout.session.expired` hinterlegen. Voraussetzung: Tarif-Feature `deposits_enabled`.

**Event-Vorauszahlungen:** Eventbuchungen mit Preis erhalten in Bestätigungsmail und auf der Verwaltungsseite einen „Jetzt bezahlen"-Button → Stripe Checkout (gehostet, **keine Kartendaten im System**). Nach Zahlungseingang setzt der signierte Webhook die Buchung auf `paid`.

**Reservierungs-Deposits (No-Show-Schutz):** Unter *Einstellungen → Anzahlungsregeln* legst du fest, ab welcher Personenzahl/Uhrzeit eine Anzahlung fällig ist (Betrag pro Person, Zahlungsfrist). Online-Reservierungen, die eine Regel treffen, starten als `payment_pending` mit Zahlungslink; nach Zahlung bestätigt der Webhook die Reservierung automatisch. Unbezahlte Reservierungen laufen per Scheduler nach Fristablauf ab.

**Verrechnungs- und No-Show-Hinweis:** Gäste sehen an jeder Zahlungsstelle (Eventseite, Bestätigungs-/Verwaltungsseite, E-Mail) den Hinweis: *„Die Vorauszahlung wird bei Ihrem Besuch vollständig mit der Rechnung verrechnet. Bei Nichterscheinen (No-Show) erfolgt keine Rückerstattung."* Damit ist die Einbehaltung bei No-Show transparent vereinbart (AGB-Hinterlegung pro Tenant empfohlen).

Sicherheit: Webhook-Signaturprüfung (HMAC, Replay-Schutz ±5 min) gegen das Secret des jeweiligen Tenants, idempotente Verarbeitung, Audit-Log (`payment.checkout_started`, `payment.succeeded`), ausgehender Webhook `payment.succeeded` an Tenant-Endpoints.

---

## Newsletter (MailWizz)

Konfiguration im Adminbereich unter **Einstellungen → Newsletter: MailWizz** (Recht `integrations.manage`):

1. API-URL (z. B. `https://news.example.com/api`), API-Key und Listen-UID eintragen – die Verbindung wird beim Speichern sofort getestet.
2. Credentials werden **verschlüsselt** gespeichert (`integration_connections`, Laravel Crypt); der API-Key wird nach dem Speichern nie wieder angezeigt.
3. Ab dann: Setzt ein Gast im Buchungswidget die **getrennte Newsletter-Checkbox**, wird er nach der Buchung per Queue-Job (`SyncNewsletterSubscriber`, Retry mit Backoff) in die MailWizz-Liste übertragen (`EMAIL`, `FNAME`, `LNAME`).
4. **Double-Opt-In** steuert die Listeneinstellung in MailWizz – bei DOI-Listen verschickt MailWizz die Bestätigungsmail selbst.
5. Jede Übertragung wird in `notification_logs` (Kanal `newsletter`) protokolliert; die Einwilligung selbst liegt unabhängig davon DSGVO-konform in `guest_consents`.

Ohne konfigurierte Integration wird die Einwilligung nur gespeichert – es geht nichts verloren, die Synchronisierung kann später nachgeholt werden. Weitere Provider (Mailchimp, Brevo, CleverReach) lassen sich über das `NewsletterProvider`-Interface ergänzen.

---

## Queue & Scheduler

```bash
php artisan queue:work        # Mails, Webhooks
php artisan schedule:work     # lokal; Produktion: Cron-Eintrag jede Minute
```

Geplante Jobs: Reservierungs-Reminder (alle 15 min), Feedback-Follow-ups (stündlich), Wartelisten-Expiry (alle 10 min), unbezahlte Reservierungen abräumen (alle 10 min), DSGVO-Retention (täglich 03:30).

---

## Rechtstexte (Impressum / Datenschutz / AGB)

Liegen als **Markdown** unter `storage/app/legal/{impressum,datenschutz,agb}.md`
(bind-gemountet → direkt auf dem Host editierbar). Der Container legt beim Start
fehlende Dateien aus Vorlagen an (`php artisan swayy:install-legal`).
Der Inhalt wird **bei jedem Aufruf frisch** gelesen – Änderungen sind **sofort
ohne Neustart** wirksam (`/impressum`, `/datenschutz`, `/agb`).

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

CI: GitHub Actions (`.github/workflows/ci.yml`) mit Pint, Larastan, Tests und Frontend-Build.

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
