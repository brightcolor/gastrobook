# Buchbare Saisons – Umsetzungsplan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; Subagenten nur mit Freigabe von Mathias). Steps use checkbox (`- [ ]`) syntax.

**Goal:** Saisonbetriebe definieren wiederkehrende buchbare Zeiträume; außerhalb nimmt die Online-Buchung keine Reservierungen an.

**Architecture:** Neue Tabelle/Model `season_periods` (mehrere Fenster je Standort, Monat/Tag, wiederkehrend). `SeasonService` kapselt die Jahreslogik. `ReservationAvailabilityService` prüft die Saison an beiden vorhandenen Stellen. Admin-CRUD und öffentlicher Hinweis spiegeln das Sperrzeiten-Muster. Ohne Fenster keine Änderung.

**Tech Stack:** Laravel (PHP 8.4), PHPUnit (sqlite :memory:), Pint (preset laravel), PHPStan (larastan, keine Baseline), Blade + Tailwind.

**Spec:** `docs/superpowers/specs/2026-10-07-buchbare-saison-design.md`

## Global Constraints

- Deutsche Ausgaben ohne unnötige Negativabgrenzungen; echte Umlaute; verständliche Fehlermeldungen (Ursache + nächster Schritt).
- Nichts fest im Code: Werte aus Einstellungen/DB.
- Recht für die CRUD-Routen: `blackouts.manage` (mitgenutzt).
- Abwärtskompatibel: ohne Saisonfenster unverändertes Verhalten.
- Ton öffentlicher Texte nach `guest_address` (Sie/du).
- Commit-Trailer: `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`.

---

### Task 1: Datenmodell (Tabelle, Model, Relation)

**Files:**
- Create: `database/migrations/2026_10_07_200000_create_season_periods.php`
- Create: `app/Models/SeasonPeriod.php`
- Modify: `app/Models/Location.php` (Relation `seasonPeriods()`)

**Interfaces:**
- Produces: `SeasonPeriod` mit `fillable` [tenant_id, location_id, label, start_month, start_day, end_month, end_day, sort_order]; `location()`. `Location::seasonPeriods(): HasMany`.

- [ ] Migration: Tabelle `season_periods` mit Spalten laut Spec (tinyint für Monat/Tag, label nullable string(60), sort_order int default 0, FK location_id/tenant_id, timestamps).
- [ ] Model `SeasonPeriod` (BelongsToTenant, HasFactory, fillable, `location()`), casts month/day als `int`.
- [ ] `Location::seasonPeriods()` HasMany, sortiert nach sort_order/start_month/start_day.
- [ ] Commit.

### Task 2: SeasonService (Jahreslogik) – TDD

**Files:**
- Create: `app/Services/SeasonService.php`
- Test: `tests/Unit/SeasonServiceTest.php`

**Interfaces:**
- Produces: `SeasonService::isBookable(Location $l, CarbonImmutable $localDate): bool`; `SeasonService::nextOpening(Location $l, CarbonImmutable $fromLocal): ?CarbonImmutable`. Reine Logik über die geladene `seasonPeriods`-Relation; keine Zeitzonen-Umrechnung hier (Aufrufer gibt lokales Datum).

- [ ] **RED:** `SeasonServiceTest` mit Fällen: ohne Fenster → immer buchbar; normales Fenster (01.04.–31.10.) → 15.06. true, 01.01. false, Grenztage 01.04./31.10. true; Jahreswechsel-Fenster (01.12.–15.03.) → 01.01. true, 01.06. false; 29.02. in Fenster 01.02.–28.02. false, in 01.01.–01.03. true; `nextOpening` ab 01.01. bei Fenster ab 01.04. → 01.04. desselben Jahres; ab 01.11. → 01.04. Folgejahr.
- [ ] **Verify RED.**
- [ ] **GREEN:** `SeasonService` implementieren. Kern: `md = $date->month*100 + $date->day`; Fenster `[s,e]`: `s<=e ? (md>=s && md<=e) : (md>=s || md<=e)`. `isBookable` = keine Fenster oder eines trifft. `nextOpening`: ab `$fromLocal` tageweise bis zu 366 Tage vorrücken, erstes buchbares Datum zurückgeben (einfach und korrekt bei mehreren/überlappenden Fenstern).
- [ ] **Verify GREEN.** Commit.

### Task 3: Verfügbarkeit einbinden – TDD

**Files:**
- Modify: `app/Services/ReservationAvailabilityService.php` (SeasonService injizieren; Saison-Check neben Blackout in der Slot-Reason-Funktion und in `bookingBlockReason`; neue Gründe `outside_season`)
- Test: `tests/Feature/SeasonAvailabilityTest.php`

**Interfaces:**
- Consumes: `SeasonService` (Task 2). Lokales Startdatum aus `$startLocal`.
- Produces: Grund-String `outside_season` (Slot) bzw. `[false, 'outside_season', []]` (Buchung).

- [ ] **RED:** Feature-Test: Standort mit Fenster 01.04.–31.10.; `GET …/slots?date=<Januar>` → 0 Slots; `date=<Juni>` → >0 Slots; ohne Fenster → >0 (unverändert). Buchung per POST außerhalb der Saison → `assertSessionHasErrors`/keine Reservierung; innerhalb → Reservierung.
- [ ] **Verify RED.**
- [ ] **GREEN:** SeasonService in den Service injizieren; in beiden Pfaden direkt nach der Blackout-Prüfung `if (! $this->seasons->isBookable($location, $startLocal)) return …outside_season`.
- [ ] **Verify GREEN.** Commit.

### Task 4: Öffentlicher Saison-Hinweis – TDD

**Files:**
- Modify: `app/Http/Controllers/Public/PublicBookingController.php` (im `slots`-Endpunkt: wenn keine Slots UND Saison-bedingt, `season_notice` {text, next_date} ergänzen; Text warm, Ton nach `guest_address`)
- Modify: `resources/views/public/booking.blade.php` (Slot-Bereich zeigt `season_notice` statt „keine Termine")
- Test: `tests/Feature/SeasonNoticeTest.php`

**Interfaces:**
- Consumes: `SeasonService::nextOpening`.
- Produces: JSON-Feld `season_notice` (nullable) im slots-Response.

- [ ] **RED:** Test: slots außerhalb der Saison → JSON enthält `season_notice` mit dem nächsten Saisonstart (Datum) und warmem Text; Ton du vs. Sie.
- [ ] **Verify RED.**
- [ ] **GREEN:** Controller + Blade. Text z. B. „Reservierungen nehmen wir wieder ab dem {Datum} entgegen." (Sie/du-Variante). Datum lokal formatiert (d.m.Y / „1. April").
- [ ] **Verify GREEN.** Commit.

### Task 5: Admin-CRUD (Routen, Controller, Validierung) – TDD

**Files:**
- Modify: `routes/web.php` (POST `settings/seasons` → `settings.seasons.store`; DELETE `settings/seasons/{season}` → `settings.seasons.delete`; Recht `blackouts.manage`)
- Modify: `app/Http/Controllers/Admin/SettingsController.php` (`storeSeason`, `deleteSeason`)
- Modify: `app/Http/Controllers/Admin/SettingsController.php` index: `seasons` an die View geben
- Test: `tests/Feature/SeasonAdminTest.php`

**Interfaces:**
- Consumes: `SeasonPeriod`, `Location::seasonPeriods()`.
- Produces: View-Variable `seasons`; Routen-Namen oben.

- [ ] **RED:** Test: `postJson settings/seasons` mit start/end Monat+Tag legt ein Fenster an; ungültiger Tag (31.02.) → 422 mit verständlicher Meldung; `delete` entfernt; fremder Standort → 404/abgelehnt.
- [ ] **Verify RED.**
- [ ] **GREEN:** Validierung: `start_month`/`end_month` 1–12, `start_day`/`end_day` 1–31, Kombination gültig via `checkdate($m, $d, 2024)` (Schaltjahr erlaubt 29.02.), `label` nullable max 60. Anlegen/Löschen mit Audit-Log analog Blackout. `seasons` in index laden (`$location->seasonPeriods()->get()`).
- [ ] **Verify GREEN.** Commit.

### Task 6: Admin-Oberfläche „Buchbare Saison" – TDD (Render)

**Files:**
- Modify: `resources/views/admin/settings/index.blade.php` (neuer Abschnitt neben Sperrzeiten; Formular Tag/Monat „Von"/„Bis" + Bezeichnung; Liste mit Löschen; Hinweis „Ohne Saison ist das ganze Jahr buchbar.")
- Test: ergänzt `tests/Feature/SeasonAdminTest.php` (Render)

**Interfaces:**
- Consumes: View-Variable `seasons`, `$rooms` nicht nötig.

- [ ] **RED:** Test: `GET /admin/settings` zeigt den Abschnitt (Überschrift „Buchbare Saison") und bei vorhandenem Fenster dessen Bezeichnung + „1. April" o. ä.
- [ ] **Verify RED.**
- [ ] **GREEN:** Blade-Abschnitt. Tag/Monat als zwei Selects oder `<input type="number">`; Monatsnamen deutsch. Kein `@php`-Block in dieser Datei (bekannte Kompilier-Eigenheit) — Werte aus dem Controller / inline.
- [ ] **Verify GREEN.** Commit.

### Task 7: Version, Changelog, Ausrollen

**Files:**
- Modify: `config/version.php` (→ 1.133.0), `CHANGELOG.md`

- [ ] Pint + PHPStan + volle betroffene Tests grün.
- [ ] Version 1.133.0, Changelog-Eintrag „Buchbare Saison".
- [ ] Commit, PR, Rebase-Merge, Tag `v1.133.0`, Watchtower-Deploy prüfen, Serverlauf protokollieren.

## Self-Review

- **Spec-Abdeckung:** Datenmodell (T1), Jahreslogik (T2), Verfügbarkeit beide Pfade (T3), öffentlicher Hinweis (T4), Admin-CRUD + Validierung + Recht (T5), UI (T6), Tests je Task, Version/Deploy (T7). Abwärtskompatibilität in T2/T3 (keine Fenster = buchbar). ✓
- **Platzhalter:** keine offenen TODOs. ✓
- **Typen:** `isBookable(Location, CarbonImmutable): bool`, `nextOpening(Location, CarbonImmutable): ?CarbonImmutable`, Grund `outside_season`, Feld `season_notice`, Routen `settings.seasons.store/delete` — durchgehend konsistent. ✓
