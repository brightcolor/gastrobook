# swayy am Kundenpanel: Bereitstellungs-Schnittstelle, Version 1

Stand 08.10.2026. Grundlage: Zweig `new-ui`. Auftrag von Mathias: swayy wird
nach vipinvit.es der zweite SaaS-Dienst im Kundenpanel (panel.kundenberei.ch),
und das Panel übernimmt Registrierung, Probe, Tarif, Sperre und Kündigung
(„Alles, wie vipinvit.es“, „setz das für swayy um“).

Der Vertrag selbst steht im Skill `kundenpanel-saas` und in der Spezifikation
des Panels (`docs/specs/2026-10-05-saas-zentrale-stufe-1-design.md`,
Abschnitt 5). Diese Datei hält fest, wie swayy ihn umsetzt.

## 1. Zuordnung

| Vertrag | swayy |
|---|---|
| Konto | `Tenant` (Betrieb) |
| Inhaber | `User` mit Rolle `tenant_owner` im Betrieb, gemerkt in `tenants.owner_user_id` |
| Vertrag | `tenants.contract_ref` (eindeutig, etwa `fb-123`) |
| Zustand | `tenants.status`: `pending_approval`, `active`, `suspended`, `cancelled` |
| Einheiten | `max_locations` „Standorte“, `max_tables` „Tische“, beide `limit` |
| Tarifcode | gleich dem Schlüssel eines `Plan`, wenn es ihn gibt (`trial`, `starter`, `professional`, `multi_location`, `enterprise`) |

Benutzer sind in swayy über alle Betriebe hinweg eindeutig (E-Mail). Ein
Mensch kann Inhaber mehrerer Betriebe sein. Gilt daher wie bei vip-invites:
ein Vertrag je Kunde und Dienst.

## 2. Was sich am Betrieb ändert

Neue Spalten an `tenants`: `contract_ref`, `contract_url`, `owner_user_id`,
`approved_at`, `state_reason`, `state_before`, `tariff_code`, `tariff_label`,
`tariff_kind`, `free_place`, `tariff_ends_at`, `contract_limits` (JSON).

- **Grenzen:** Hat der Betrieb einen Vertrag, liefert `Tenant::limit()` für
  `max_locations` und `max_tables` den Wert aus `contract_limits` (`null` =
  beliebig viele). Alle übrigen Grenzen und alle Funktionen kommen weiter vom
  `Plan`. Setzt das Panel einen Tarif, dessen Code ein `Plan` ist, bekommt
  der Betrieb diesen Plan.
- **Probe:** Ein Betrieb mit Vertrag kippt nie auf `trial_expired`. Das Ende
  einer Probe regelt das Panel (`tariff` mit `force` oder `cancel`).
- **Wartet auf Freigabe:** Die Verwaltung läuft; die öffentliche Buchung gibt
  es nur für aktive Betriebe und ist damit zu.
- **Gesperrt und gekündigt:** Die Verwaltung ist gesperrt (Seite mit Grund und
  Weg ins Panel), die öffentliche Buchung ist zu, API-Tokens des Betriebs
  greifen nicht.
- **Betriebe ohne Vertrag** laufen wie bisher (eigene Probe, Abrechnungsanfrage),
  bis sie per `adopt` unter Vertrag kommen.

## 3. Schnittstelle

`/provisioning/v1` nach dem Vertrag, ohne Sitzung, Cookie und CSRF, in
`routes/provisioning.php`. Bausteine:

- `App\Provisioning\Signature` (Signatur, Rückweg), `Auth` (Prüfung samt
  Netz und Drossel), `Idempotency` (Tabelle `provisioning_requests`),
  `ProvisioningService` (Arbeit je Aufruf), `AccountState` (Übergänge),
  `LoginLinks` (Tabelle `provisioning_login_links`, nur Hash).
- `POST /accounts`: neuer Betrieb wie bei der Registrierung (Betrieb, Inhaber
  mit Zufallspasswort und bestätigter Adresse, erster Standort), Zustand nach
  `approved`. Willkommensmail mit Link „Eigenes Passwort festlegen“ (Broker
  von Laravel) nach dem Commit.
- `adopt`: Der Benutzer mit der Mailadresse muss Inhaber genau eines Betriebs
  ohne Vertrag sein; sonst 409 `already_linked`, `ambiguous` oder
  `not_adoptable` (Betreiber von swayy).
- `DELETE`: dieselbe Löschung wie „Betrieb löschen“ in der Verwaltung
  (Dateien, dann `forceDelete` mit Kaskade), zusammengezogen in
  `TenantDeletionService`.
- Anmeldelink `GET /anmelden/{token}`: meldet den Inhaber an, setzt den
  Betrieb als aktuellen, gilt einmal und `LOGIN_LINK_SECONDS`. Abgelaufen oder
  benutzt: Seite mit Erklärung und Weg in den Kundenbereich.
- Rückweg „Tarif und Rechnungen“: `GET /admin/tarif` baut den signierten Link
  für den Inhaber; die Abrechnungsseite und die Tarifhinweise eines Betriebs
  mit Vertrag führen dorthin.
- `/register` leitet auf `PANEL_ORDER_URL`, sobald sie gesetzt ist.

## 4. Einstellungen

`config/provisioning.php`, alle mit Vorgabe und Grenzen über `EnvSetting`:

| Variable | Vorgabe | Grenzen |
|---|---|---|
| `PROVISIONING_KEYS` | leer, Schnittstelle aus | ein oder zwei Schlüssel, je mindestens 32 Zeichen |
| `PROVISIONING_SERVICE_CODE` | `swayy` | `[a-z0-9-]{1,40}` |
| `PROVISIONING_MAX_SKEW_SECONDS` | 300 | 30–3600 |
| `PROVISIONING_IDEMPOTENCY_DAYS` | 7 | 1–90 |
| `PROVISIONING_ALLOWED_NETWORKS` | leer, alle | CIDR-Liste |
| `PROVISIONING_MAX_FAILURES` | 10 | 1–100, Fehlversuche je Absender bis zur Drossel |
| `PROVISIONING_LOCK_SECONDS` | 900 | 60–86400 |
| `PROVISIONING_CLEANUP_TIME` | 04:40 | `HH:MM` |
| `LOGIN_LINK_SECONDS` | 120 | 30–900 |
| `PANEL_BASE_URL` | leer | HTTPS-Adresse |
| `PANEL_ORDER_URL` | leer | HTTPS-Adresse |

## 5. Tests

Prüfwerte aus dem Skill, Signatur- und Drosselfälle, Idempotenz, jeder Aufruf
mit seinen Fehlercodes, beide Wege durch die Zustände, Grenzen mit
`check_only` und `force`, Anmeldelink, Rückweg, Registrierung mit und ohne
`PANEL_ORDER_URL`, Grenzen über `Tenant::limit()`, Einstellungen mit anderen
Werten. Danach der Prüfstand des Panels gegen die Testinstanz.
