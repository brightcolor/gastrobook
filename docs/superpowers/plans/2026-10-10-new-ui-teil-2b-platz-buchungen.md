# Neue Oberfläche, Teil 2b: Platz & Buchungen – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Einstellungsseiten der Gruppen „Platz & Zeiten“ (Räume & Tische, Leistungen, Mitarbeiter, Kapazität & Dauer) und „Buchungen“ (Buchungsregeln, Buchungsformular, Buchungsseite & Widget, Tags) bekommen das neue Seitenmuster mit eigenem Speicherweg je Seite; die Speicherleiste zählt Änderungen und markiert geänderte Zeilen; die Reservierungsliste passt ohne seitliches Scrollen aufs Smartphone; die offenen Punkte aus 2a an Bausteinen und Betriebsseiten sind erledigt.

**Architecture:** Teil 1 und 2a haben Navigation, Layout, Seitenbausteine (`x-page-header`, `x-settings.section`, `x-field`, `x-save-bar`, `x-drawer`, `x-inline-confirm`, `x-empty-state`, `x-status-icon`) und die Module `forms.js`, `savebar.js`, `drawer.js`, `inline-confirm.js`, `copy.js`, `nav.js`, `toast.js` gebaut. 2b ergänzt die Module `rows.js` (Zeilen hinzufügen und entfernen, „Verwerfen“ stellt sie wieder her), `reveal.js` (Hinweise je nach Eingabe), `minutes.js` („entspricht 2 Std.“), `widget.js` (Code für die eigene Website), die Bausteine `x-checkbox` und `x-change-dot` und eine gemeinsame Klasse `App\Support\BookingSettingRules` mit den Prüfregeln der Buchungseinstellungen; die Grenzen stehen in `config/swayy.php`. Jede neue Seite hat einen Controller unter `App\Http\Controllers\Admin\Settings\` und einen eigenen Speicherweg, der nur ihre Felder prüft und schreibt. Bestehende Speicherwege für Räume, Tische, Kombinationen, Formularfelder, Tags, Leistungen und Mitarbeiter behalten Adresse und Routennamen, weil Onboarding, Tischplan und Reservierungsdetails sie nutzen. Die alte Sammelseite bleibt bis Teil 3 bedienbar.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Tailwind CSS 4 (Vite), Vanilla-JS als ES-Module, Vitest + jsdom, PHPUnit (SQLite und PostgreSQL 17), Pint, PHPStan (Larastan Level 2).

**Spec:** `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`

## Fahrplan Teil 2

| Plan | Inhalt |
|---|---|
| 2a (fertig) | Vitest, Bausteine für Listen, Betriebsseiten Standorte, Team & Rollen, Abo & Rechnungen, Protokoll, Daten |
| **2b (dieser)** | Platz & Buchungen: Räume & Tische, Leistungen, Mitarbeiter, Kapazität & Dauer, Buchungsregeln, Buchungsformular, Buchungsseite & Widget, Tags; Zähler „n geändert“ und Punkt an geänderten Zeilen; Reservierungsliste am Smartphone; Restpunkte aus 2a (siehe unten) |
| 2c | Gästekommunikation, Zahlungen, Verbindungen: Benachrichtigungen (mit Anrede), Vorlagen, Absender & Antworten, SMS-Versand, Zahlungsanbieter, Anzahlungen, Newsletter, API-Schlüssel, Webhooks; eigene Fehlerseiten mit nächstem Schritt; „Mein Konto“: Passwort ändern; Restpunkte Protokoll (siehe unten) |

Teil 3 bleibt wie im Gesamtfahrplan (Öffnungszeiten, Vorschau, Ausnahmen, „Sperren…“ am Tisch, Abbau der Sammelseite samt `SettingsController::updateBookingRules`, Zuordnungstest, Weiterleitungen).

## Frage an Mathias vor dem Start

**Abgleich mit `main` vor 2b?** `main` steht bei 1.134.6 und hat sechs Commits, die `new-ui` fehlen. Zwei davon liegen hier schon als Cherry-Pick (GoCardless-Doppelprüfung mit `phpunit.pgsql.xml` und CI-Job `tests-pgsql`; `tests-pgsql` über `vendor/bin/phpunit`), dazu die Versions-Commits. Inhaltlich neu ist 142c579 „Rollenvergabe beim Einladen auf eigene Rechte begrenzt“ (`App\Support\RoleAssignment`, `App\Rules\AssignableRole`, `RoleAssignmentTest`). Er ändert `UserManagementController::invite()` und `updateRole()`, die 2a für Team & Rollen umgebaut hat und die Task 13 dieses Plans noch einmal berührt.

Empfehlung: **ja, als Task 0**, per Merge-Commit wie bei 1.134.2 und 1.134.3. Gründe: Die Rechteprüfung ist ein Sicherheitsfix; der Konflikt in `UserManagementController` ist heute klein und wächst mit jeder weiteren Änderung; Task 13 baut dann auf dem Stand auf, der später ausgerollt wird. Der Weg zum Pull Request (GitHub erlaubt für gastrobook nur Rebase-Merges) bleibt eine Entscheidung für Teil 3, wie im Protokoll 2a festgehalten.

Antwortet Mathias „nein“, entfällt Task 0; alle übrigen Tasks gelten unverändert.

## Entscheidungen in diesem Plan (bitte beim Lesen prüfen)

| Entscheidung | Begründung | Folge bei Irrtum |
|---|---|---|
| Leistungen und Mitarbeiter mit kommenden Terminen lassen sich nicht löschen; die Meldung nennt Zahl und Weg („umbuchen oder pausieren“) | Heute verlieren solche Termine still Leistung bzw. Person (`nullOnDelete`). Tische verhalten sich schon so | Wer bewusst löschen will, pausiert zuerst; eine Zeile im Controller zurück |
| Räume mit Tischen bleiben unlöschbar; die Rückfrage erscheint nur bei leeren Räumen, die Meldung nennt die Zahl der Tische | Ein Raum löscht seine Tische samt Verknüpfung zu Reservierungen in der Datenbank mit | Spec-Beispiel „Raum ‚Terrasse‘ mit 6 Tischen löschen?“ wäre ein eigener Plan mit Schutz für kommende Reservierungen |
| Preis einer Leistung in Euro mit Cent-Stellen; der Einrichtungsassistent schickt weiter `price_minor` | Spec: Einheit im Feld; „2500 Cent“ ist fehleranfällig | keine |
| Schalter auf den neuen Seiten senden ein verstecktes 0 (`x-checkbox`) und sind Pflichtfelder; ein fehlender Schalter ergibt 422 | Spec: Speicherwege schreiben nur ihre Felder, ein fehlendes Feld wird nicht still „aus“ | keine |
| Tisch bearbeiten ändert nur die mitgeschickten Schalter; die alte Sammelseite bekommt dafür versteckte 0-Felder an ihren vier Kästchen | Heute setzt jedes Speichern dort „Draußen“ auf aus, und „Kinderstuhl“ wird nie gespeichert | keine |
| Neue Tische aus „Räume & Tische“ stehen im Tischplan oben links (wie bisher bei `admin.settings.tables.store`) | Anordnen ist Sache des Tischplans | keine |
| `storeRoom` antwortet zusätzlich mit `room_id` | Der Einrichtungsassistent liest `room_id` und fiel bisher auf die ID 1 zurück, der zweite Schritt scheiterte dann bei fast jedem neuen Betrieb | keine |
| Grenzen der Buchungseinstellungen, Tischgrößen und Leistungen kommen aus `config/swayy.php`; die alte Sammelseite nutzt dieselben Regeln (`BookingSettingRules`) | Vorgabe „Nichts fest im Code“; eine Quelle für beide Wege bis Teil 3 | keine |

## Offene Punkte aus 2a: was 2b übernimmt

| Punkt (Protokoll 2a, „Offen für Plan 2b“) | in 2b | Task |
|---|---|---|
| `forms.js`: zweiter Versand mit anderen Daten während einer Anfrage wird verworfen | ja | 1 |
| `forms.js`: Capture-Handler und „Mehr“-Blatt prüfen `defaultPrevented` nicht | ja | 1 |
| `forms.js`: `data-reset-on-error` setzt auch bei unklarer Antwort zurück | ja | 1 |
| Speicherleiste: `inert` auf dem ganzen Seitenkopf | ja, enger auf Standortwahl und Aktionen | 2 |
| Seitenleiste: offene Rückfragen schließen nicht mit; Drawer außerhalb des Stapels; „Neu“ zeigt alte Werte; Klick auf den Hintergrund verwirft still | ja | 1 |
| `x-inline-confirm`: `role="group"` auf `<form>` | ja, innerer Wrapper | 1 |
| Team: Fehler „mindestens ein Standort“ ohne Text am Feld | ja (allgemein für Gruppen ohne ID in `forms.js`) | 1 |
| Team: E-Mail ohne `max`, Rollenbeschreibungen, Meldung „in der Liste ändern“ für die Betriebsleitung | ja | 13 |
| Standorte: Zeitzone ohne Pflichtmarkierung, „1000 Zeichen“ doppelt, alte Logodatei zu früh gelöscht, „2 von 1 Standorten“ | ja | 13 |
| Abo: „Aktiv“ doppelt angesagt; Tests für aktive Lastschrift | ja | 2, 13 |
| Daten: Fokus auf die wieder geöffnete Frage „Betrieb löschen“ | ja (allgemein für offene Rückfragen beim Laden) | 1 |
| Testlücken: Toast, Statussymbol-Formen, Knopfstufen, Navigation für Support und Lesezugang | ja | 2 |
| Protokoll: Bereiche für auth/support/provisioning, Rohschlüssel bei fehlendem Kürzel, Werte zu `type` als Code, Freitextsuche nur per Adresse | **nein → 2c** | – |
| Ruling 2a: Inline-Handler in `reservations/show.blade.php` außerhalb von „Verlauf“ gehören zu 2b | ja (Anhang löschen) | 12 |

Begründung für Protokoll → 2c: Die vier Punkte erweitern Filter und Anzeige einer einzelnen Seite; keiner betrifft die Bausteine, auf denen 2b aufbaut, und keiner ist ohne geänderte Konfiguration erreichbar.

## Global Constraints

- Farben, Schriften und Tokens von Swayy bleiben (Stein-Palette, Teal-Akzent `--brand`, Inter). Kein Gestaltungsschema von `bright-color-apps`.
- Rechte unverändert: `config/permissions.php` wird nicht geändert; jede bestehende Route behält ihre `permission:`-Middleware. Neue Seiten nutzen das Recht ihrer Karten: Kapazität & Dauer und Buchungsregeln `tables.manage` + `opening_hours.manage`, Buchungsformular `tables.manage` + `tenant.settings.manage`, Buchungsseite & Widget und Tags `tables.manage` (Speichern der Schalter zusätzlich `opening_hours.manage`), Räume & Tische `tables.manage` (Raumaktionen zusätzlich `rooms.manage`).
- Code und Bezeichner englisch, Oberflächentexte deutsch mit echten Umlauten, Anrede „du“. Texte im Stil „Sie“ auf den berührten Seiten werden zu „du“.
- Oberflächentexte ohne Negativabgrenzungen („X statt Y“, „kein X, sondern Y“, „X – ohne Y“); eine Verneinung nur, wenn sie selbst die Information ist.
- Fehlermeldungen nennen Ursache und nächsten Schritt. Statuscode oder Kennung dürfen als Zusatz dastehen; Pfade, Stacktraces und Zugangsdaten nie.
- Nichts fest im Code: Verhaltenswerte stehen mit Vorgabe und Grenzen in `config/swayy.php` (über `App\Support\EnvSetting`), dazu ein Eintrag in `.env.example`; die Navigation in `config/navigation.php`. Tests prüfen auch mit anderen Werten als der Vorgabe. Fest im Code bleibt, was ein Format vorgibt (Spaltengrößen wie `unsignedTinyInteger` = 255, Statuscodes, Feldnamen).
- Neuer und berührter Code nutzt keine `onclick`/`onchange`/`onsubmit`-Attribute.
- Formulare mit `data-savebar` enthalten keine Dateifelder.
- Schalter auf neuen Seiten: `x-checkbox` (verstecktes `0` vor dem Kästchen), Prüfregel `required|boolean`.
- Antworten bestehender JSON-Endpunkte behalten ihre Felder (Onboarding, Tischplan, Tag-Auswahl in den Reservierungsdetails); neue Felder wie `message` kommen hinzu.
- Mandantentrennung: jede Abfrage mit `location_id` bzw. `tenant_id`; Einträge anderer Standorte → 404, fremde IDs in Auswahllisten werden verworfen oder mit Grund abgelehnt.
- Die alte Sammelseite `/admin/settings` bleibt bis Teil 3 bedienbar; ihre Formulare speichern weiter über dieselben Routen.
- 2b ändert keine CSP, legt keine Migration an und ändert keine Datenverarbeitung; `resources/legal/datenschutz.md` bleibt. Ändert ein Task doch eine Datenverarbeitung, gehört die Datenschutzerklärung in denselben Commit.
- Barrierefreiheit WCAG 2.2 AA: sichtbarer Fokus, Klickziele ≥ 24 × 24 px, Zustände nie über Farbe allein, Live-Region für Hinweise, Fokus nie unter der Speicherleiste (2.4.11), Grafiken mit Bedeutung ≥ 3:1 Kontrast.
- Tests PHP: `declare(strict_types=1)`, `use CreatesTenants, RefreshDatabase;`, vor Requests `$this->clearTenantContext()`. Keine echten Namen oder Adressen in Testdaten.
- Tests JS: Vitest mit jsdom unter `tests/js/*.test.js`, `npm test`.
- PHP: Imports alphabetisch, keine vollqualifizierten Klassennamen in Code oder Docblocks (Pint `fully_qualified_strict_types`).
- Blade: Auf `</x-slot:…>` folgt nie direkt Text; immer Zeilenumbruch oder Tag dazwischen. Komponenten-Attribute mit Variablen über `:name="…"`, nie `name="…{{ }}…"`. Eine Ansicht nutzt entweder nur `@php … @endphp`-Blöcke oder nur die Kurzform `@php(…)` (gemischt zieht Blade sie zusammen, `BladePhpBlockTest`). Vor einer Direktive steht nie ein Buchstabe (`Personen@if` bleibt Text); dann einen Ausdruck in `{{ }}` nehmen.
- Jede neu protokollierte Aktion bekommt eine deutsche Bezeichnung in `AuditLog::ACTION_LABELS` (`AuditLabelTest`).
- Arbeitskopie: Worktree `C:\Users\brigh\Claude Workingdir\gastrobook-new-ui`, Branch `new-ui` ohne Upstream. Nie auf `main` pushen (Watchtower rollt `main` sofort aus). Push von `new-ui` und Entwurfs-PR erst nach Mathias' Freigabe. Teil 2b wird nicht ausgerollt.
- Befehle laufen in Git Bash im Worktree. Tests mit `PAO_DISABLE=1 php artisan test …`. PostgreSQL-Lauf mit eigenem Wegwerf-Container `gastrobook-pgtest-2b` auf Port 55433 (55432 nutzen andere Sitzungen); zwei Suiten nie gleichzeitig im selben Worktree.
- Commit-Nachrichten enden mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Vor dem späteren Merge nach `main` (Teil 3): Eintrag `gastrobook-new-ui` aus `.claude/launch.json` entfernen; Changelog-Hinweis Farbwechsel `#0d9488` → `#0f766e`.

## Review Focus

1. **„Verwerfen“ nach Zeilenänderungen:** Auf „Kapazität & Dauer“ eine Tischzeit hinzufügen, eine entfernen, eine ändern, dann „Verwerfen“: Die Zeilen stehen wieder wie gespeichert, Zähler und Punkte verschwinden, die Leiste blendet aus. Dasselbe beim Schließen der Seitenleiste eines Mitarbeiters nach geänderten Arbeitszeiten. Getestet in Task 2 (`rows.test.js`, `savebar.test.js`).
2. **Eine Seite speichert nur ihre Felder:** Speichern auf „Kapazität & Dauer“ lässt Buchungsregeln, Benachrichtigungen und Erstattung unverändert, und umgekehrt; ein Salon speichert „Kapazität & Dauer“, ohne Standarddauer, Tischzeiten und Zählweise zu verändern; ein fehlender Schalter ergibt 422 mit Feldname. Getestet in Task 4, 5 und 7.
3. **Löschen von etwas, das noch gebraucht wird:** Tisch mit kommender Reservierung, Raum mit Tischen, Leistung oder Person mit kommenden Terminen → 422 mit Zahl und nächstem Schritt in der Zeile; Tag an Reservierungen → die Rückfrage nennt die Zahl. Getestet in Task 8, 9, 10 und 11.
4. **Tag mit vorhandenem Namen:** Anlegen mit einem vorhandenen Namen übernimmt den Tag und sagt das; Umbenennen auf einen vorhandenen Namen → 422 mit Grund (bisher 500 durch den Unique-Index). Getestet in Task 8.
5. **Code für die eigene Website mit Sonderzeichen:** Ein Knopftext mit Anführungszeichen oder spitzen Klammern ergibt gültigen HTML-Code (maskiert), der Kopierknopf liefert genau den angezeigten Code. Getestet in Task 7 (`widget.test.js`).

## Dateien

| Datei | Aufgabe |
|---|---|
| `resources/js/admin/forms.js`, `drawer.js`, `inline-confirm.js`, `nav.js`; `components/drawer.blade.php`, `components/inline-confirm.blade.php` | Restpunkte Formulare, Seitenleiste, Rückfrage (Task 1) |
| `resources/js/admin/savebar.js`, `rows.js`, `reveal.js`, `minutes.js`, `index.js`; `components/save-bar.blade.php`, `page-header.blade.php`, `field.blade.php`, `status-icon.blade.php`, `checkbox.blade.php`, `change-dot.blade.php` | Zähler, Zeilen, Hinweise, Bausteine (Task 2) |
| `config/swayy.php`, `.env.example`, `app/Support/BookingSettingRules.php`, `SettingsController::updateBookingRules` | Grenzen und Prüfregeln (Task 3) |
| `Settings\CapacityController`, `admin/settings/capacity.blade.php`, `admin/settings/partials/duration-rule.blade.php` | Kapazität & Dauer (Task 4) |
| `Settings\BookingRulesController`, `admin/settings/rules.blade.php` | Buchungsregeln (Task 5) |
| `Settings\BookingFormController`, `admin/settings/booking-form.blade.php` | Buchungsformular (Task 6) |
| `Settings\BookingPageController`, `admin/settings/booking-page.blade.php`, `resources/js/admin/widget.js` | Buchungsseite & Widget (Task 7) |
| `Settings\TagSettingsController`, `TagController`, `Tag`, `admin/settings/tags.blade.php` | Tags (Task 8) |
| `Settings\RoomsController`, `App\Support\PlanLimitMessage`, `FloorPlanController`, `admin/settings/rooms.blade.php` | Räume & Tische (Task 9) |
| `ServiceController`, `admin/services/*` | Leistungen (Task 10) |
| `StaffMemberController`, `admin/staff/index.blade.php`, `admin/staff/partials/*` | Mitarbeiter (Task 11) |
| `admin/reservations/index.blade.php`, `admin/reservations/show.blade.php` | Reservierungen (Task 12) |
| `UserManagementController`, `lang/de/roles.php`, `LocationController`, `LocationLogoController`, `GeneralController`, `admin/locations/*`, `admin/billing/index.blade.php` | Nacharbeiten Betrieb (Task 13) |
| `config/navigation.php`, `routes/web.php`, `app/Models/AuditLog.php` | je Seite der eigene Eintrag (Task 4–11) |

---

### Task 0: Abgleich mit `main` (nur nach „ja“ von Mathias)

Diese Aufgabe führt die steuernde Sitzung selbst aus.

**Files:**
- Modify (Merge): alle Dateien aus `git diff --stat new-ui...main`, darunter `app/Http/Controllers/Admin/UserManagementController.php`, `README.md`, `CHANGELOG.md`, `config/version.php`, `.github/workflows/ci.yml`
- Create (aus `main`): `app/Rules/AssignableRole.php`, `app/Support/RoleAssignment.php`, `tests/Feature/RoleAssignmentTest.php`
- Modify: `docs/superpowers/plans/2026-10-08-new-ui-teil-2a-protokoll.md` (Abschnitt „Offen für Teil 3“, Abgleich vermerken)

**Interfaces:**
- Consumes: lokaler Branch `main` (1.134.6).
- Produces: `new-ui` enthält `main` 1.134.6; `UserManagementController::invite()` und `updateRole()` prüfen die Rolle mit `new AssignableRole($request->user(), $tenant)`; `assignableRoles()` nutzt `RoleAssignment::assignable()`.

- [ ] **Step 1: Stand prüfen**

Run:
```bash
git status --short
git log --oneline new-ui..main
```
Expected: Arbeitskopie sauber; sechs Commits von 3f13e34 bis 1da08f1. Steht `main` weiter, die zusätzlichen Commits ansehen und Mathias vor dem Merge nennen.

- [ ] **Step 2: Mergen**

Run:
```bash
git merge --no-ff main -m "Merge main (1.134.6) in new-ui

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```
Expected: Konflikt in `app/Http/Controllers/Admin/UserManagementController.php`, eventuell in `README.md` und `CHANGELOG.md`. Die Cherry-Picks 1dda878 und c12678a sind inhaltsgleich mit 3f13e34 und 04df63f und ergeben keinen Konflikt.

- [ ] **Step 3: Konflikte lösen**

`UserManagementController.php`: Von `main` übernehmen: die Imports `App\Rules\AssignableRole` und `App\Support\RoleAssignment`, die Regel `'role' => ['required', new AssignableRole($request->user(), $tenant)]` in `invite()` und `updateRole()`, den Rumpf von `assignableRoles()` mit `RoleAssignment::assignable($user, $tenant)`. Von `new-ui` behalten: alle Meldungen für `email.*` und `role.required`, die Antworten über `RespondsToSettingsForms` (`failed`, `saved`), die Standortprüfung. Die Meldung `role.in` entfällt, weil `AssignableRole` eigene Meldungen liefert. `README.md` und `CHANGELOG.md`: beide Seiten behalten, Reihenfolge wie auf `main`. `config/version.php`: Wert von `main`.

- [ ] **Step 4: Tests**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='RoleAssignmentTest|TeamPageTest|UserLocationAccessTest|PermissionsTest'
PAO_DISABLE=1 php artisan test
```
Expected: alles grün. Schlägt ein Test aus `TeamPageTest` an einer Rollenmeldung fehl, gilt die Meldung aus `AssignableRole` (Sicherheitsfix von `main`); den Test auf diese Meldung anpassen.

- [ ] **Step 5: PostgreSQL-Lauf**

Run:
```bash
MSYS_NO_PATHCONV=1 docker run -d --name gastrobook-pgtest-2b -p 127.0.0.1:55433:5432 -e POSTGRES_USER=gastrobook -e POSTGRES_PASSWORD=gastrobook -e POSTGRES_DB=gastrobook_test --tmpfs /var/lib/postgresql/data postgres:17-alpine
PAO_DISABLE=1 DB_PORT=55433 vendor/bin/phpunit -c phpunit.pgsql.xml
docker rm -f gastrobook-pgtest-2b
```
Expected: Exit-Code 0 (nie hinter einer Pipe prüfen).

- [ ] **Step 6: Protokoll 2a nachziehen und committen**

Im Protokoll 2a unter „Offen für Teil 3“ die Zeile „Stand 10.10.2026: `main` ist bei 1.134.6 …“ ergänzen um „Abgeglichen per Merge-Commit <hash> vor Teil 2b.“ und unter „Außerhalb von new-ui“ beim Punkt Betriebsleitung „übernommen mit dem Abgleich vor 2b“ ergänzen.

```bash
git add docs/superpowers/plans/2026-10-08-new-ui-teil-2a-protokoll.md
git commit -m "docs: Abgleich mit main 1.134.6 im Protokoll 2a

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 1: Formulare, Seitenleiste und Rückfrage

**Files:**
- Modify: `resources/js/admin/forms.js`, `resources/js/admin/drawer.js`, `resources/js/admin/inline-confirm.js`, `resources/js/admin/nav.js`, `resources/views/components/drawer.blade.php`, `resources/views/components/inline-confirm.blade.php`
- Create: `tests/js/more-sheet.test.js`
- Test: `tests/js/forms.test.js`, `tests/js/drawer.test.js`, `tests/js/inline-confirm.test.js`, `tests/Feature/AdminComponentsTest.php`

**Interfaces:**
- Consumes: `snapshot`, `savedSnapshot`, `submitForm`, `initAjaxForms` (forms.js), `focusables`, `page` (dom.js), `showToast` (toast.js).
- Produces:
  - `forms.js`: vor jeder Anfrage blubbert `swayy:sending` am Formular. Attribute am Formular: `data-reset-after-save` (nach Erfolg zurück zum Ausgangsstand), `data-reload-after-save` (nach Erfolg neu laden wie bei `reload: true`), `data-reset-on-error` (nur bei Ablehnung durch den Server, Status ≥ 400). Ein zweiter Versand mit geänderten Daten während einer Anfrage folgt nach deren Ende. Fehler zu Feldern ohne ID erscheinen in der umgebenden `<fieldset>` (ID wird bei Bedarf gesetzt: `fieldsetN`), mit `aria-describedby` an der Gruppe.
  - `drawer.js`: Öffnen schreibt `#{id}` in die Adresse; Klick auf `[data-drawer-backdrop]` schließt nur ohne ungespeicherte Eingaben, sonst Hinweis; Schließen schließt offene Rückfragen darin; Seitenleisten an beliebiger Stelle im Dokument sperren die Geschwister auf jeder Ebene bis `<body>`; ein `input`-Ereignis am Formular selbst zählt als Änderung.
  - `inline-confirm.js`: `export function closeInlineConfirm(root, { focus = true } = {})`; beim Start bekommt eine sichtbare, offene Frage den Fokus.
  - `x-inline-confirm`: neues Prop `reload` (bool) → `data-reload-after-save` am Formular; `role="group"` sitzt auf `<div data-inline-confirm-group>` im Formular.
  - `x-drawer`: Hintergrund trägt `data-drawer-backdrop`.
  - `nav.js`: Escape im „Mehr“-Blatt übergeht Ereignisse mit `defaultPrevented`.

- [ ] **Step 1: Failing tests – `tests/js/forms.test.js` ergänzen**

Am Ende des `describe`-Blocks einfügen:
```js
    it('schicken während einer Anfrage geänderte Daten danach noch einmal', async () => {
        const resolvers = [];
        const fetchMock = vi.fn(() => new Promise((r) => { resolvers.push(r); }));
        vi.stubGlobal('fetch', fetchMock);
        mount(form());
        const el = document.getElementById('f');
        const input = document.getElementById('f-name');

        input.value = 'Erster Stand';
        el.requestSubmit();
        input.value = 'Zweiter Stand';
        el.requestSubmit();
        el.requestSubmit();
        resolvers[0](jsonResponse({ message: 'Gespeichert.' }));

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[0][1].body.toString()).toContain('name=Erster+Stand');
        expect(fetchMock.mock.calls[1][1].body.toString()).toContain('name=Zweiter+Stand');
        resolvers[1](jsonResponse({ message: 'Gespeichert.' }));
        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
    });

    it('lassen Absendungen liegen, die ein früherer Handler schon abgefangen hat', () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        mount(form());
        const stop = (e) => e.preventDefault();
        window.addEventListener('submit', stop, true);
        try {
            document.getElementById('f').requestSubmit();
        } finally {
            window.removeEventListener('submit', stop, true);
        }

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('lassen data-reset-on-error-Formulare bei unklarer Antwort auf der Eingabe stehen', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => htmlResponse(200)));
        mount(`
            <form id="r" method="POST" action="/admin/users/1/role" data-ajax data-reset-on-error>
                <select id="role-1" name="role">
                    <option value="tenant_owner" selected>Inhaber</option>
                    <option value="staff">Service</option>
                </select>
            </form>`);
        const select = document.getElementById('role-1');
        select.value = 'staff';

        document.getElementById('r').requestSubmit();

        await vi.waitFor(() => expect(document.querySelector('#r [data-form-problems]')?.textContent)
            .toBe('Unklar, ob gespeichert wurde. Bitte die Seite neu laden und prüfen.'));
        expect(select.value).toBe('staff');
    });

    it('zeigen Fehler zu Kästchen ohne eigene ID in ihrer Gruppe', async () => {
        const text = 'Bitte mindestens einen Standort auswählen oder „Alle Standorte“ anhaken.';
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: text, errors: { location_ids: [text] } }, 422)));
        mount(`
            <form id="i" method="POST" action="/admin/users/invite" data-ajax>
                <fieldset>
                    <legend>Standorte</legend>
                    <label><input type="checkbox" name="location_ids[]" value="1"> Mitte</label>
                    <label><input type="checkbox" name="location_ids[]" value="2"> Hafen</label>
                </fieldset>
            </form>`);

        document.getElementById('i').requestSubmit();

        const group = document.querySelector('#i fieldset');
        await vi.waitFor(() => expect(group.querySelector('[data-ajax-error]')?.textContent).toBe(text));
        expect(group.id).not.toBe('');
        expect(group.getAttribute('aria-describedby')).toBe(`${group.id}-error`);
        expect(document.querySelector('#i [data-form-problems] a').getAttribute('href')).toBe(`#${group.id}`);
        expect(document.activeElement).toBe(document.querySelector('[name="location_ids[]"]'));
    });

    it('kehren mit data-reset-after-save nach dem Speichern zum Ausgangsstand zurück', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Angelegt.' })));
        mount(form('data-reset-after-save'));
        document.getElementById('f-name').value = 'Terrasse';

        document.getElementById('f').requestSubmit();

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Angelegt.'));
        expect(document.getElementById('f-name').value).toBe('Alt');
    });

    it('laden mit data-reload-after-save nach dem Speichern neu', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Gespeichert.' })));
        mount(form('data-reload-after-save'));

        document.getElementById('f').requestSubmit();

        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
    });

    it('melden den Versand vorab als swayy:sending', async () => {
        const order = [];
        vi.stubGlobal('fetch', vi.fn(async () => {
            order.push('fetch');
            return jsonResponse({ message: 'Gespeichert.' });
        }));
        mount(`<div id="wrap">${form()}</div>`);
        document.getElementById('wrap').addEventListener('swayy:sending', () => order.push('sending'));

        document.getElementById('f').requestSubmit();

        await vi.waitFor(() => expect(order).toEqual(['sending', 'fetch']));
    });
```

- [ ] **Step 2: Tests laufen lassen**

Run: `npm test -- tests/js/forms.test.js`
Expected: FAIL in den sieben neuen Tests (zweiter Versand fehlt, Gruppe ohne Fehlertext, kein `swayy:sending` …).

- [ ] **Step 3: `resources/js/admin/forms.js` ersetzen**

```js
// Formulare mit data-ajax senden per fetch und melden Ergebnis und Fehler an
// Ort und Stelle. Texte für Antworten ohne JSON (Proxy-Fehlerseite,
// Weiterleitung, abgebrochene Verbindung) kommen aus
// App\Support\AdminJsonErrors::forClient() über window.SwayyAdmin.problemTexts.
//
// Ein Handler am Dokument (Capture-Phase) übernimmt alle Formulare mit
// data-ajax, auch solche, die später erscheinen (Seitenleisten, Rückfragen in
// der Zeile). Der Bestätigungsdialog des Layouts und der Doppelklickschutz in
// app.js sehen das Ereignis danach als erledigt (defaultPrevented).
//
// Attribute am Formular:
//   data-reset-on-error     lehnt der Server ab, kehrt das Formular zum
//                           gespeicherten Stand zurück (etwa eine Rollenauswahl)
//   data-reset-after-save   nach dem Speichern zurück zum Ausgangsstand
//                           (Seitenleisten zum Anlegen)
//   data-reload-after-save  nach dem Speichern neu laden, wie reload in der
//                           Antwort (Listen)
//
// Vor jeder Anfrage blubbert swayy:sending, nach dem Speichern swayy:saved.

import { page } from './dom.js';
import { showToast } from './toast.js';

let problemCount = 0;
let groupCount = 0;
let inFlight = 0;
let reloadRequested = false;
let installed = false;
const pending = new WeakMap();
const queued = new WeakMap();
const sending = new WeakMap();
const sentSnapshots = new WeakMap();

function texts() {
    return window.SwayyAdmin?.problemTexts ?? {};
}

// Stand eines Formulars als Text; die Speicherleiste vergleicht damit.
export const snapshot = (form) => new URLSearchParams(new FormData(form)).toString();

// Stand beim letzten erfolgreichen Absenden. Eingaben während der Anfrage
// gehören nicht dazu und bleiben danach ungespeichert.
export function savedSnapshot(form) {
    return sentSnapshots.get(form);
}

async function readJson(res) {
    if (!(res.headers.get('Content-Type') || '').includes('json')) return null;
    try {
        return await res.json();
    } catch {
        return null;
    }
}

function problemsOf(res, json) {
    if (json?.errors) return Object.values(json.errors).flat();
    if (json?.message) return [json.message];
    const t = texts();
    const text = t[res.status] || (res.status >= 500 ? t[500] : t.default) || '';
    return [text.replace(':status', String(res.status))];
}

function describedBy(el, add = null, remove = () => false) {
    const ids = (el.getAttribute('aria-describedby') || '').split(' ').filter((id) => id && !remove(id));
    if (add && !ids.includes(add)) ids.push(add);
    if (ids.length) {
        el.setAttribute('aria-describedby', ids.join(' '));
    } else {
        el.removeAttribute('aria-describedby');
    }
}

function clearProblems(form) {
    form.querySelector('[data-form-problems]')?.remove();
    form.querySelectorAll('[data-ajax-error]').forEach((el) => el.remove());
    form.querySelectorAll('[data-ajax-invalid]').forEach((el) => {
        el.removeAttribute('aria-invalid');
        el.removeAttribute('data-ajax-invalid');
        describedBy(el, null, (id) => id.startsWith('formProblems') || id === `${el.id}-error`);
    });
}

// Wo der Text am Feld steht: am Feld selbst oder, bei Feldern ohne eigene ID
// (Kästchen, Optionsfelder), an der umgebenden Gruppe.
function errorTarget(field) {
    if (field.id) return field;
    const group = field.closest('fieldset');
    if (!group) return null;
    if (!group.id) group.id = `fieldset${++groupCount}`;
    return group;
}

// Fehlertext direkt am Feld, mit derselben ID wie bei x-field ({id}-error):
// unter dem Feld in seinem Block (Beschriftung, Feld, Hilfesatz), bei einer
// Gruppe am Ende der Gruppe.
function showFieldError(form, target, text) {
    const errorId = `${target.id}-error`;
    let note = document.getElementById(errorId);
    if (!note) {
        note = document.createElement('p');
        note.id = errorId;
        note.className = 'text-[0.8125rem] text-red-700';
        const block = target instanceof HTMLFieldSetElement
            ? target
            : form.querySelector(`label[for="${CSS.escape(target.id)}"]`)?.parentElement ?? target.parentElement;
        block.append(note);
    }
    note.dataset.ajaxError = '';
    note.textContent = text;
    describedBy(target, errorId);
    if (target instanceof HTMLFieldSetElement) target.setAttribute('data-ajax-invalid', '');
}

// Eintrag der Zusammenfassung: mit Feld als Link, der den Fokus dorthin setzt.
function summaryEntry({ message, field, anchor }) {
    if (!field || !anchor?.id) return document.createTextNode(message);
    const link = document.createElement('a');
    link.href = `#${anchor.id}`;
    link.className = 'underline underline-offset-2';
    link.textContent = message;
    link.addEventListener('click', (e) => {
        e.preventDefault();
        field.focus();
    });
    return link;
}

// Zusammenfassung oben im Formular mit Links zu den Feldern, dazu der Text am
// Feld und aria-invalid ("hours.0.opens_at" heißt im Formular
// "hours[0][opens_at]"). Der Fokus springt zum ersten gemeldeten Feld.
function showProblems(form, json, messages) {
    const box = document.createElement('div');
    box.id = `formProblems${++problemCount}`;
    box.dataset.formProblems = '';
    box.setAttribute('role', 'alert');
    box.tabIndex = -1;
    box.className = 'rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800';

    const entries = [];
    let first = null;
    Object.entries(json?.errors ?? {}).forEach(([key, list]) => {
        const [head, ...rest] = key.split('.');
        const name = head + rest.map((part) => `[${part}]`).join('');
        const fields = [...form.querySelectorAll(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`)]
            .filter((el) => el.type !== 'hidden');
        const text = [].concat(list).join(' ');
        const field = fields[0] ?? null;
        fields.forEach((el) => {
            el.setAttribute('aria-invalid', 'true');
            el.setAttribute('data-ajax-invalid', '');
        });
        const anchor = field ? errorTarget(field) : null;
        if (anchor) showFieldError(form, anchor, text);
        fields.forEach((el) => {
            // Felder ohne eigene ID und ohne Gruppe verweisen auf die Zusammenfassung.
            if (!el.id && !anchor) describedBy(el, box.id);
        });
        first ??= field;
        entries.push({ message: text, field, anchor });
    });
    if (entries.length === 0) messages.forEach((message) => entries.push({ message, field: null, anchor: null }));

    if (entries.length === 1) {
        box.append(summaryEntry(entries[0]));
    } else {
        const list = document.createElement('ul');
        list.className = 'list-disc space-y-1 pl-4';
        entries.forEach((entry) => {
            const item = document.createElement('li');
            item.append(summaryEntry(entry));
            list.append(item);
        });
        box.append(list);
    }

    form.prepend(box);
    (first ?? box).focus();
}

// rejected: Der Server hat geantwortet und abgelehnt. Nur dann kehrt ein
// Formular mit data-reset-on-error zum gespeicherten Stand zurück; bei einer
// unklaren Antwort oder ohne Verbindung bleibt die Eingabe stehen.
function fail(form, json, messages, rejected) {
    showToast(messages.join(' · '), { error: true });
    if (rejected && form.hasAttribute('data-reset-on-error')) form.reset();
    showProblems(form, json, messages);
}

// Werte beim Absenden. Nach dem Speichern werden sie zur Vorgabe, auf die
// „Verwerfen“ (form.reset) zurückkehrt.
function readValues(form) {
    const values = new Map();
    for (const el of form.elements) {
        if (el instanceof HTMLInputElement) {
            values.set(el, el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value);
        } else if (el instanceof HTMLTextAreaElement) {
            values.set(el, el.value);
        } else if (el instanceof HTMLSelectElement) {
            values.set(el, [...el.options].map((option) => option.selected));
        }
    }
    return values;
}

function commitDefaults(values) {
    values.forEach((value, el) => {
        if (el instanceof HTMLSelectElement) {
            [...el.options].forEach((option, i) => {
                option.defaultSelected = value[i] ?? false;
            });
        } else if (el.type === 'checkbox' || el.type === 'radio') {
            el.defaultChecked = value;
        } else if (el.type !== 'file') {
            el.defaultValue = value;
        }
    });
}

// Neu geladen wird erst, wenn keine Anfrage mehr läuft und kein Formular
// ungespeicherte Änderungen trägt (data-dirty, savebar.js), und nur einmal je
// Anforderung. Bleibt etwas offen, lädt die nächste erfolgreiche Speicherung
// oder „Verwerfen“ neu. Die Wartezeit kommt aus config/swayy.php
// (ui.reload_delay_ms), damit „Gespeichert“ lesbar bleibt.
function reloadWhenIdle() {
    setTimeout(() => {
        if (inFlight === 0 && reloadRequested && !document.querySelector('form[data-dirty]')) {
            reloadRequested = false;
            page.reload();
        }
    }, window.SwayyAdmin?.ui?.reloadDelayMs);
}

async function send(form) {
    clearProblems(form);
    form.dispatchEvent(new CustomEvent('swayy:sending', { bubbles: true }));
    // Knöpfe in der Fußzeile einer Seitenleiste stehen außerhalb des Formulars (form="…").
    const submitter = form.querySelector('[type="submit"]')
        ?? (form.id ? document.querySelector(`[type="submit"][form="${CSS.escape(form.id)}"]`) : null);
    submitter?.setAttribute('aria-busy', 'true');
    const values = readValues(form);
    const sent = snapshot(form);
    sending.set(form, sent);
    inFlight++;

    try {
        const multipart = form.enctype === 'multipart/form-data';
        const body = multipart ? new FormData(form) : new URLSearchParams(new FormData(form));
        const headers = { Accept: 'application/json' };
        if (!multipart) headers['Content-Type'] = 'application/x-www-form-urlencoded';

        const res = await fetch(form.action || window.location.href, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            headers,
            body,
            credentials: 'same-origin',
        });
        const json = await readJson(res);

        if (!res.ok) {
            fail(form, json, problemsOf(res, json), true);
            return false;
        }

        // Gespeichert meldet nur, wer es bestätigt. Eine Antwort ohne JSON
        // (etwa nach einer Weiterleitung) lässt offen, ob die Änderung ankam.
        if (!json || typeof json.message !== 'string') {
            fail(form, null, [texts().unclear || ''], false);
            return false;
        }

        if (form.hasAttribute('data-reset-after-save')) {
            form.reset();
        } else {
            commitDefaults(values);
        }
        sentSnapshots.set(form, sent);
        form.dispatchEvent(new CustomEvent('swayy:saved', { detail: json, bubbles: true }));
        showToast(json.message);
        if (json.reload || form.hasAttribute('data-reload-after-save')) reloadRequested = true;
        return true;
    } catch {
        fail(form, null, [texts().network || ''], false);
        return false;
    } finally {
        submitter?.removeAttribute('aria-busy');
        sending.delete(form);
        inFlight--;
        if (reloadRequested) reloadWhenIdle();
    }
}

// Ein zweiter Aufruf während der Anfrage (Doppelklick, Enter und Speicherleiste)
// bekommt dieselbe Anfrage zurück, solange die Eingaben gleich sind. Wurde
// inzwischen weitergetippt, folgt genau eine weitere Anfrage mit dem neuesten
// Stand, sobald die erste fertig ist.
export function submitForm(form) {
    const running = pending.get(form);
    if (!running) {
        const run = send(form).finally(() => pending.delete(form));
        pending.set(form, run);
        return run;
    }
    if (snapshot(form) === sending.get(form)) return running;
    if (!queued.has(form)) {
        queued.set(form, running.then(() => {
            queued.delete(form);
            return submitForm(form);
        }));
    }
    return queued.get(form);
}

export function initAjaxForms() {
    if (installed) return;
    installed = true;

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (e.defaultPrevented || !(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) return;
        e.preventDefault();
        submitForm(form);
    }, true);

    // Zurückgesetzt („Verwerfen“, Schließen einer Seitenleiste) gilt der
    // gespeicherte Stand; Fehlerhinweise zu den verworfenen Eingaben
    // verschwinden mit ihnen.
    document.addEventListener('reset', (e) => {
        const form = e.target;
        if (form instanceof HTMLFormElement && form.hasAttribute('data-ajax')) clearProblems(form);
    }, true);

    // Nach „Verwerfen“ kann ein zurückgestelltes Neuladen nachgeholt werden.
    document.addEventListener('swayy:discarded', () => {
        if (reloadRequested) reloadWhenIdle();
    });
}
```

- [ ] **Step 4: Tests laufen lassen**

Run: `npm test -- tests/js/forms.test.js`
Expected: PASS, alle Tests der Datei.

- [ ] **Step 5: Failing tests – `tests/js/drawer.test.js` ergänzen**

Import am Dateianfang ergänzen: `import { initInlineConfirms } from '../../resources/js/admin/inline-confirm.js';` und im `beforeAll` zusätzlich `initInlineConfirms();` aufrufen. Am Ende des `describe`-Blocks einfügen:
```js
    it('schreibt das Ziel beim Öffnen in die Adresse und nimmt es beim Schließen heraus', () => {
        mount(layout);
        initDrawers();

        document.getElementById('opener').click();
        expect(window.location.hash).toBe('#standort-neu');

        escape();
        expect(window.location.hash).toBe('');
    });

    it('schließt über den Hintergrund nur ohne ungespeicherte Eingaben', () => {
        mount(layout.replace('<div data-drawer-close></div>', '<div data-drawer-close data-drawer-backdrop id="backdrop"></div>'));
        initDrawers();
        document.getElementById('opener').click();
        const name = document.getElementById('f-neu-name');
        name.value = 'Halb getippt';
        name.dispatchEvent(new Event('input', { bubbles: true }));

        document.getElementById('backdrop').click();

        expect(document.getElementById('standort-neu').hidden).toBe(false);
        expect(name.value).toBe('Halb getippt');
        expect(document.getElementById('swayyToast').textContent)
            .toBe('Die Eingaben sind noch nicht gespeichert. Speichern oder mit „Abbrechen“ verwerfen.');

        document.getElementById('close').click();
        expect(document.getElementById('standort-neu').hidden).toBe(true);
    });

    it('schließt offene Rückfragen in der Seitenleiste mit', () => {
        mount(`
            <div id="app"><button type="button" id="opener" data-drawer-open="d">Öffnen</button></div>
            <div id="d" data-drawer hidden><div role="dialog"><div data-drawer-body>
                <div data-inline-confirm>
                    <button type="button" id="ask" data-inline-confirm-open>Entfernen</button>
                    <form id="c" data-inline-confirm-panel hidden method="POST" action="/x" data-ajax>
                        <button type="submit">Entfernen</button>
                        <button type="button" data-inline-confirm-cancel>Abbrechen</button>
                    </form>
                </div>
                <button type="button" id="close" data-drawer-close>Schließen</button>
            </div></div></div>`);
        initDrawers();
        document.getElementById('opener').click();
        document.getElementById('ask').click();

        document.getElementById('close').click();

        expect(document.getElementById('c').hidden).toBe(true);
        expect(document.getElementById('ask').hidden).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('opener'));
    });

    it('sperrt bei einer Seitenleiste im Inhalt die Geschwister auf jeder Ebene', () => {
        mount(`
            <div id="app">
                <nav id="side"><a href="/admin">Übersicht</a></nav>
                <main id="m">
                    <p id="text"><button type="button" id="opener" data-drawer-open="d">Öffnen</button></p>
                    <div id="d" data-drawer hidden><div role="dialog"><div data-drawer-body><input id="f-x" name="x"></div></div></div>
                </main>
            </div>`);
        initDrawers();

        document.getElementById('opener').click();

        expect(document.getElementById('side').hasAttribute('inert')).toBe(true);
        expect(document.getElementById('text').hasAttribute('inert')).toBe(true);
        expect(document.getElementById('m').hasAttribute('inert')).toBe(false);
        expect(document.getElementById('app').hasAttribute('inert')).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('f-x'));

        escape();
        expect(document.getElementById('side').hasAttribute('inert')).toBe(false);
        expect(document.getElementById('text').hasAttribute('inert')).toBe(false);
    });

    it('zählt ein input-Ereignis am Formular selbst als Änderung', () => {
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();
        const formEl = document.getElementById('form-neu');
        document.getElementById('f-neu-name').value = 'Neue Zeile';

        formEl.dispatchEvent(new Event('input', { bubbles: true }));

        expect(formEl.hasAttribute('data-dirty')).toBe(true);
        escape();
    });
```

- [ ] **Step 6: Failing tests – `tests/js/inline-confirm.test.js` ergänzen**

Am Ende des `describe`-Blocks einfügen:
```js
    it('fokussiert eine beim Laden offene Frage', () => {
        mount(`
            <div data-inline-confirm>
                <button type="button" data-inline-confirm-open hidden>Betrieb löschen …</button>
                <form data-inline-confirm-panel method="POST" action="/admin/account/tenant">
                    <input id="confirm-tenant-name" name="confirm">
                    <button type="submit">Betrieb endgültig löschen</button>
                </form>
            </div>`);

        initInlineConfirms();

        expect(document.activeElement).toBe(document.getElementById('confirm-tenant-name'));
    });

    it('übergeht offene Fragen in verborgenen Bereichen', () => {
        mount(`
            <div hidden><div data-inline-confirm>
                <form data-inline-confirm-panel method="POST" action="/x"><input id="hidden-input" name="x"></form>
            </div></div>`);

        initInlineConfirms();

        expect(document.activeElement).not.toBe(document.getElementById('hidden-input'));
    });
```

- [ ] **Step 7: `tests/js/more-sheet.test.js` anlegen**

```js
import { describe, expect, it } from 'vitest';
import { initMoreSheet } from '../../resources/js/admin/nav.js';
import { mount } from './helpers.js';

const layout = `
    <main id="main-content"><button type="button" data-more-open aria-expanded="false">Mehr</button></main>
    <div id="swayyMore" hidden>
        <div data-more-close></div>
        <div role="dialog"><a href="/admin">Übersicht</a></div>
    </div>`;

const escape = () => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

describe('„Mehr“ auf dem Smartphone', () => {
    it('bleibt offen, wenn ein Dialog darüber Escape schon verbraucht hat', () => {
        mount(layout);
        initMoreSheet();
        document.querySelector('[data-more-open]').click();

        const consume = (e) => { if (e.key === 'Escape') e.preventDefault(); };
        document.addEventListener('keydown', consume, true);
        try {
            escape();
            expect(document.getElementById('swayyMore').hidden).toBe(false);
        } finally {
            document.removeEventListener('keydown', consume, true);
        }

        escape();
        expect(document.getElementById('swayyMore').hidden).toBe(true);
    });
});
```

- [ ] **Step 8: Tests laufen lassen**

Run: `npm test -- tests/js/drawer.test.js tests/js/inline-confirm.test.js tests/js/more-sheet.test.js`
Expected: FAIL in den neuen Tests.

- [ ] **Step 9: `resources/js/admin/inline-confirm.js` ersetzen**

```js
// Rückfrage in der Zeile (x-inline-confirm): Der Knopf weicht der Frage mit
// Bestätigen und „Abbrechen“. Escape und „Abbrechen“ schließen sie und setzen
// ihr Formular zurück, der Fokus kehrt zum Knopf zurück. Gesendet wird über
// das Formular der Frage, mit data-ajax per fetch (forms.js); eine Ablehnung
// des Servers erscheint dann in der Zeile. Steht eine Frage beim Laden schon
// offen (nach einer Ablehnung mit Weiterleitung), bekommt sie den Fokus.

let installed = false;

function parts(root) {
    return {
        opener: root.querySelector('[data-inline-confirm-open]'),
        panel: root.querySelector('[data-inline-confirm-panel]'),
    };
}

function open(root) {
    const { opener, panel } = parts(root);
    opener.hidden = true;
    panel.hidden = false;
    (panel.querySelector('input:not([type="hidden"]), select, textarea') ?? panel.querySelector('[type="submit"]'))?.focus();
}

export function closeInlineConfirm(root, { focus = true } = {}) {
    const { opener, panel } = parts(root);
    if (!opener || !panel) return;
    panel.hidden = true;
    panel.reset();
    opener.hidden = false;
    if (focus) opener.focus();
}

function focusOpenPanel() {
    const panel = [...document.querySelectorAll('[data-inline-confirm-panel]')]
        .find((el) => !el.closest('[hidden]'));
    (panel?.querySelector('input:not([type="hidden"]), select, textarea') ?? panel?.querySelector('[type="submit"]'))?.focus();
}

export function initInlineConfirms() {
    focusOpenPanel();
    if (installed) return;
    installed = true;

    document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        const opener = e.target.closest('[data-inline-confirm-open]');
        if (opener) {
            open(opener.closest('[data-inline-confirm]'));
            return;
        }
        const cancel = e.target.closest('[data-inline-confirm-cancel]');
        if (cancel) closeInlineConfirm(cancel.closest('[data-inline-confirm]'));
    });

    // Capture-Phase: Escape schließt zuerst die Frage; eine umgebende
    // Seitenleiste bleibt offen (drawer.js prüft defaultPrevented).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !(e.target instanceof Element)) return;
        const root = e.target.closest('[data-inline-confirm]');
        if (!root || parts(root).panel.hidden) return;
        e.preventDefault();
        closeInlineConfirm(root);
    }, true);
}
```

- [ ] **Step 10: `resources/js/admin/drawer.js` ersetzen**

```js
// Seitenleiste zum Anlegen und Bearbeiten (x-drawer): von rechts, auf dem
// Smartphone bildschirmfüllend. Ein Element mit data-drawer-open="{id}"
// öffnet sie, ebenso die Adresse mit #{id} (Suche in den Einstellungen).
// Solange sie offen ist, steht #{id} in der Adresse; ein Neuladen öffnet sie
// so wieder. Escape, [data-drawer-close] und erfolgreiches Speichern des
// Hauptformulars schließen sie; Formulare und Rückfragen darin kehren zum
// gespeicherten Stand zurück, der Fokus zum öffnenden Element. Ein Klick auf
// den abgedunkelten Hintergrund (data-drawer-backdrop) schließt nur, solange
// nichts ungespeichert ist. Solange sie offen ist, ist alles andere gesperrt
// (inert), ausgenommen Ebenen mit data-overlay-layer (Hinweis,
// Bestätigungsdialog).
//
// Hauptformular ist das Formular, auf das ein Knopf mit form="…" in der
// Seitenleiste zeigt (Fußzeile). Weitere Formulare darin (etwa das Logo)
// speichern für sich, die Seitenleiste bleibt dann offen. Geänderte
// Formulare in der offenen Seitenleiste tragen data-dirty; ein angefordertes
// Neuladen (forms.js) wartet, bis sie gespeichert oder verworfen sind.

import { focusables } from './dom.js';
import { snapshot } from './forms.js';
import { closeInlineConfirm } from './inline-confirm.js';
import { showToast } from './toast.js';

let installed = false;
let current = null;
const baselines = new WeakMap();

function closesOnSave(drawer, form) {
    const primary = [...drawer.querySelectorAll('button[type="submit"][form]')].map((button) => button.getAttribute('form'));
    return primary.length === 0 || primary.includes(form.id);
}

// Ein input-Ereignis kommt vom Feld oder, nach „Zeile entfernen“ (rows.js),
// vom Formular selbst.
function formOf(target) {
    if (target instanceof HTMLFormElement) return target;
    return target?.form ?? null;
}

function trackChange(e) {
    const form = formOf(e.target);
    if (!current || !(form instanceof HTMLFormElement) || !current.drawer.contains(form)) return;
    form.toggleAttribute('data-dirty', snapshot(form) !== baselines.get(form));
}

// Sperrt alles außer der Seitenleiste: auf jeder Ebene von ihr bis zum
// <body> die Geschwister, ausgenommen Skripte und Ebenen mit data-overlay-layer.
function cover(drawer) {
    const covered = [];
    for (let node = drawer; node.parentElement && node !== document.body; node = node.parentElement) {
        [...node.parentElement.children].forEach((el) => {
            if (el !== node && !el.matches('script, [data-overlay-layer]') && !el.hasAttribute('inert')) {
                el.setAttribute('inert', '');
                covered.push(el);
            }
        });
    }
    return covered;
}

function open(drawer, opener = null) {
    if (current?.drawer === drawer) return;
    if (current) close();

    const covered = cover(drawer);
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
    drawer.querySelectorAll('form').forEach((form) => baselines.set(form, snapshot(form)));
    current = { drawer, opener, covered };
    if (window.location.hash !== `#${drawer.id}`) {
        history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${drawer.id}`);
    }

    const dialog = drawer.querySelector('[role="dialog"]') ?? drawer;
    const body = drawer.querySelector('[data-drawer-body]') ?? dialog;
    const target = drawer.querySelector('[autofocus]') ?? focusables(body)[0] ?? dialog;
    if (target === dialog) dialog.tabIndex = -1;
    target.focus();
}

function close() {
    if (!current) return;
    const { drawer, opener, covered } = current;
    current = null;

    drawer.hidden = true;
    drawer.querySelectorAll('[data-inline-confirm]').forEach((root) => closeInlineConfirm(root, { focus: false }));
    drawer.querySelectorAll('form').forEach((form) => {
        form.reset();
        form.removeAttribute('data-dirty');
    });
    covered.forEach((el) => el.removeAttribute('inert'));
    document.body.style.overflow = '';
    if (window.location.hash === `#${drawer.id}`) {
        history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    // Ein Neuladen, das auf diese Seitenleiste gewartet hat, darf jetzt laufen.
    drawer.dispatchEvent(new CustomEvent('swayy:discarded', { bubbles: true }));

    if (opener?.isConnected) {
        opener.focus();
        return;
    }
    const heading = document.querySelector('[data-page-header] h1');
    if (heading) {
        heading.tabIndex = -1;
        heading.focus();
    }
}

function openFromHash() {
    let id = '';
    try {
        id = decodeURIComponent(window.location.hash.slice(1));
    } catch {
        // Kaputte Kodierung in der Adresse (etwa „#%“): keine Seitenleiste.
        return;
    }
    const drawer = id ? document.getElementById(id) : null;
    if (drawer?.hasAttribute('data-drawer')) open(drawer);
}

export function initDrawers() {
    openFromHash();
    if (installed) return;
    installed = true;

    document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        const opener = e.target.closest('[data-drawer-open]');
        if (opener) {
            const drawer = document.getElementById(opener.dataset.drawerOpen);
            if (drawer?.hasAttribute('data-drawer')) {
                e.preventDefault();
                open(drawer, opener);
            }
            return;
        }
        if (!current || !current.drawer.contains(e.target)) return;
        if (e.target.closest('[data-drawer-backdrop]') && current.drawer.querySelector('form[data-dirty]')) {
            showToast('Die Eingaben sind noch nicht gespeichert. Speichern oder mit „Abbrechen“ verwerfen.');
            return;
        }
        if (e.target.closest('[data-drawer-close]')) close();
    });

    // Eine offene Rückfrage in der Zeile schließt Escape zuerst
    // (inline-confirm.js setzt dann defaultPrevented).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && current && !e.defaultPrevented) close();
    });

    document.addEventListener('input', trackChange);
    document.addEventListener('change', trackChange);

    document.addEventListener('swayy:saved', (e) => {
        const form = e.target;
        if (!current || !(form instanceof HTMLFormElement) || !current.drawer.contains(form)) return;
        if (closesOnSave(current.drawer, form)) {
            close();
            return;
        }
        baselines.set(form, snapshot(form));
        form.removeAttribute('data-dirty');
    });

    window.addEventListener('hashchange', openFromHash);
}
```

- [ ] **Step 11: `nav.js` – Escape im „Mehr“-Blatt**

In `initMoreSheet()` die Zeile
```js
        if (e.key === 'Escape' && !sheet.hidden) close();
```
ersetzen durch
```js
        if (e.key === 'Escape' && !sheet.hidden && !e.defaultPrevented) close();
```

- [ ] **Step 12: Bausteine anpassen**

`resources/views/components/drawer.blade.php`, Zeile 6:
```blade
    <div data-drawer-close data-drawer-backdrop class="absolute inset-0 bg-stone-900/40" aria-hidden="true"></div>
```

`resources/views/components/inline-confirm.blade.php` vollständig ersetzen:
```blade
@props([
    'id',
    'action',
    'label',
    'question',
    'confirm',
    'method' => 'POST',
    'tone' => 'danger',
    'ajax' => true,
    'open' => false,
    'subject' => null,
    'reload' => false,
])
{{-- Rückfrage in der Zeile (resources/js/admin/inline-confirm.js): Der Knopf weicht der Frage mit Objekt und Folge.
     Der Standard-Slot nimmt weitere Felder auf (etwa eine Eingabe zur Bestätigung). :ajax="false" sendet klassisch,
     :open="true" zeigt die Frage gleich (nach einer Ablehnung, die mit Weiterleitung zurückkam), :reload="true" lädt
     nach dem Bestätigen neu (Listen, deren Antwort kein reload sendet).
     subject="Kim" hängt das Objekt für Screenreader an den Knopf (gehört: "Entfernen „Kim“"); in Listen mit mehreren
     gleichlautenden Knöpfen immer setzen. Die Frage benennt den Inhalt des Formulars als Gruppe und beschreibt den
     Bestätigen-Knopf. --}}
@php
    $verb = strtoupper($method);
    $opener = $tone === 'danger' ? 'btn-quiet-danger' : 'btn-quiet';
    $confirmClass = $tone === 'danger' ? 'btn btn-danger' : 'btn btn-primary';
@endphp
<div data-inline-confirm {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <button type="button" data-inline-confirm-open @if($open) hidden @endif class="btn {{ $opener }}">{{ $label }}@if($subject)<span class="sr-only"> „{{ $subject }}“</span>@endif</button>
    <form id="{{ $id }}" data-inline-confirm-panel @unless($open) hidden @endunless method="POST" action="{{ $action }}" @if($ajax) data-ajax @endif @if($reload) data-reload-after-save @endif
          class="rounded-lg border border-stone-300 bg-stone-50 p-3 text-left">
        @csrf
        @if($verb !== 'POST')
            @method($verb)
        @endif
        <div data-inline-confirm-group role="group" aria-labelledby="{{ $id }}-question" class="space-y-3">
            <p id="{{ $id }}-question" class="text-sm font-semibold text-stone-900">{{ $question }}</p>
            {{ $slot }}
            <div class="flex flex-wrap gap-2">
                <button type="submit" aria-describedby="{{ $id }}-question" class="{{ $confirmClass }}">{{ $confirm }}</button>
                <button type="button" data-inline-confirm-cancel class="btn btn-secondary">Abbrechen</button>
            </div>
        </div>
    </form>
</div>
```

- [ ] **Step 13: `tests/Feature/AdminComponentsTest.php` ergänzen**

In `test_drawer_renders_a_labelled_modal_panel()` vor der letzten Zusicherung einfügen:
```php
        $this->assertStringContainsString('data-drawer-close data-drawer-backdrop', $html);
```
In `test_inline_confirm_ties_the_question_to_its_buttons()` am Ende einfügen:
```php
        $this->assertMatchesRegularExpression('/<div data-inline-confirm-group role="group"\s+aria-labelledby="remove-7-question"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<form[^>]*role="group"/', $html);
```
Neuer Test:
```php
    public function test_inline_confirm_can_reload_the_list_after_confirming(): void
    {
        $with = Blade::render(<<<'BLADE'
            <x-inline-confirm id="tag-7" action="/admin/tags/7" method="DELETE" label="Löschen"
                question="„VIP“ löschen?" confirm="Tag löschen" :reload="true" />
            BLADE);
        $without = Blade::render(<<<'BLADE'
            <x-inline-confirm id="tag-7" action="/admin/tags/7" method="DELETE" label="Löschen"
                question="„VIP“ löschen?" confirm="Tag löschen" />
            BLADE);

        $this->assertStringContainsString('data-reload-after-save', $with);
        $this->assertStringNotContainsString('data-reload-after-save', $without);
    }
```

- [ ] **Step 14: Tests laufen lassen**

Run:
```bash
npm test
PAO_DISABLE=1 php artisan test --filter='AdminComponentsTest|DataPageTest|TeamPageTest|LocationManagementTest'
```
Expected: alles grün.

- [ ] **Step 15: Commit**

```bash
git add resources/js/admin/forms.js resources/js/admin/drawer.js resources/js/admin/inline-confirm.js resources/js/admin/nav.js resources/views/components/drawer.blade.php resources/views/components/inline-confirm.blade.php tests/js tests/Feature/AdminComponentsTest.php
git commit -m "feat(admin): Formulare senden nachgereichte Eingaben, Seitenleiste schließt Rückfragen und schützt Eingaben

Fehler zu Kästchengruppen stehen an der Gruppe; data-reset-on-error nur bei
Ablehnung; data-reset-after-save und data-reload-after-save; offene
Rückfragen beim Laden fokussiert; role=\"group\" im Formular.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---
### Task 2: Speicherleiste mit Zähler, Zeilen, Hinweise und Bausteine

**Files:**
- Modify: `resources/js/admin/savebar.js`, `resources/js/admin/index.js`, `resources/views/components/save-bar.blade.php`, `resources/views/components/page-header.blade.php`, `resources/views/components/field.blade.php`, `resources/views/components/status-icon.blade.php`
- Create: `resources/js/admin/rows.js`, `resources/js/admin/reveal.js`, `resources/js/admin/minutes.js`, `resources/views/components/checkbox.blade.php`, `resources/views/components/change-dot.blade.php`, `tests/js/rows.test.js`, `tests/js/reveal.test.js`, `tests/js/minutes.test.js`
- Test: `tests/js/savebar.test.js`, `tests/js/toast.test.js`, `tests/Feature/PageComponentsTest.php`, `tests/Feature/AdminComponentsTest.php`, `tests/Feature/NavigationTest.php`

**Interfaces:**
- Consumes: `snapshot`, `savedSnapshot`, `submitForm`, Ereignisse `swayy:sending`, `swayy:saved`, `swayy:rebase` (forms.js, Task 1).
- Produces:
  - `x-save-bar` Props `one` (Vorgabe `1 Einstellung geändert`) und `many` (Vorgabe `:count Einstellungen geändert`) → `data-count-one`, `data-count-many`; Zählertext in `[data-save-bar-count]` als ` · 2 Einstellungen geändert`.
  - Zeilen für Zähler und Punkt: `data-change-item="{fester Schlüssel}"`, darin `<x-change-dot />` (`[data-change-dot]`, `hidden`, wird bei Änderung sichtbar); geänderte Zeilen tragen `data-changed`.
  - `x-page-header`: Standortwahl und Aktionen stehen in `[data-page-header-controls]`; nur dieser Teil wird bei sichtbarer Leiste ab 768 px `inert`.
  - `rows.js`: `initRows()`; Aufbau `[data-rows]` (optional `data-rows-max`) › `[data-rows-list]` mit Zeilen `[data-row]`, `<template data-rows-template>` mit `__INDEX__`, Knopf `[data-rows-add]`; in der Zeile `[data-rows-remove]`. Neue Kennungen haben die Form `n1`, `n2` … Zurücksetzen des Formulars stellt die Zeilen des gespeicherten Stands wieder her.
  - `reveal.js`: `initReveals()`, `applyReveals(root = document)`; Attribut `data-show-if="feld:wert1,wert2;feld2:wert"`.
  - `minutes.js`: `describeMinutes(value): string`, `initMinuteHints()`; Feld mit `data-minutes-hint`, Hinweis in `[data-minutes-hint-for="{id}"]`.
  - `x-field` Prop `minutes` (bool): setzt `data-minutes-hint` am Feld und einen Hinweis `{id}-minutes` in `aria-describedby` (Reihenfolge: unit, minutes, help, error).
  - `x-checkbox` Props `name`, `label`, `checked` (bool), `help`, `id`, `value` (Vorgabe `1`); rendert `<input type="hidden" name="{name}" value="0">` vor dem Kästchen; weitere Attribute gehen an das Kästchen.
  - `x-status-icon` Prop `announce` (Vorgabe `true`); bei `false` fehlt der Text für Screenreader (wenn das Wort sichtbar daneben steht).

- [ ] **Step 1: Failing tests – `tests/js/savebar.test.js` ersetzen**

```js
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initSaveBar } from '../../resources/js/admin/savebar.js';
import { jsonResponse, mount, setWide } from './helpers.js';

const bar = `
    <div data-save-bar hidden data-count-one="1 Einstellung geändert" data-count-many=":count Einstellungen geändert">
        <span data-save-bar-text>Ungespeicherte Änderungen</span><span data-save-bar-count></span>
        <button type="button" data-save-bar-discard>Verwerfen</button>
        <button type="button" data-save-bar-save>Speichern</button>
    </div>
    <p data-save-bar-status></p>`;

const page = `
    <div data-page-header><h1>Allgemein</h1><div data-page-header-controls><select id="scope"><option>Mitte</option></select></div></div>
    ${bar}
    <form id="g" method="POST" action="/admin/settings/general" data-ajax data-savebar>
        <input id="f-name" name="name" value="Alt">
    </form>`;

const counted = `
    <div data-page-header><h1>Kapazität &amp; Dauer</h1></div>
    ${bar}
    <form id="k" method="POST" action="/admin/settings/capacity" data-ajax data-savebar>
        <input id="f-buffer" name="buffer_minutes" value="0">
        <input id="f-duration" name="default_duration_minutes" value="120">
        <div data-change-item="rule-0">
            <input id="r0-min" name="duration_rules[0][min_party]" value="1">
            <input id="r0-max" name="duration_rules[0][max_party]" value="4">
            <span data-change-dot hidden></span>
        </div>
    </form>`;

function type(id, value) {
    const input = document.getElementById(id);
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

const counter = () => document.querySelector('[data-save-bar-count]').textContent;

beforeAll(() => initAjaxForms());

describe('Speicherleiste', () => {
    it('erscheint bei Änderungen und sperrt ab 768 px Standortwahl und Aktionen', () => {
        setWide(true);
        mount(page);
        initSaveBar();

        type('f-name', 'Neu');

        expect(document.querySelector('[data-save-bar]').hidden).toBe(false);
        expect(document.querySelector('[data-page-header-controls]').hasAttribute('inert')).toBe(true);
        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(false);
        expect(document.getElementById('g').hasAttribute('data-dirty')).toBe(true);
    });

    it('lässt den Seitenkopf unter 768 px bedienbar', () => {
        setWide(false);
        mount(page);
        initSaveBar();

        type('f-name', 'Neu');

        expect(document.querySelector('[data-page-header-controls]').hasAttribute('inert')).toBe(false);
    });

    it('gibt den Seitenkopf nach Verwerfen frei und meldet swayy:discarded am Dokument', () => {
        setWide(true);
        mount(page);
        initSaveBar();
        const discarded = vi.fn();
        document.addEventListener('swayy:discarded', discarded, { once: true });

        type('f-name', 'Neu');
        document.querySelector('[data-save-bar-discard]').click();

        expect(document.getElementById('f-name').value).toBe('Alt');
        expect(document.querySelector('[data-save-bar]').hidden).toBe(true);
        expect(document.querySelector('[data-page-header-controls]').hasAttribute('inert')).toBe(false);
        expect(discarded).toHaveBeenCalledTimes(1);
    });

    it('zählt geänderte Felder und Zeilen und zeigt den Punkt an der Zeile', () => {
        mount(counted);
        initSaveBar();

        type('f-buffer', '15');
        expect(counter()).toBe(' · 1 Einstellung geändert');

        type('r0-min', '2');
        type('r0-max', '6');
        expect(counter()).toBe(' · 2 Einstellungen geändert');
        const row = document.querySelector('[data-change-item]');
        expect(row.hasAttribute('data-changed')).toBe(true);
        expect(row.querySelector('[data-change-dot]').hidden).toBe(false);
    });

    it('zählt hinzugefügte und entfernte Zeilen mit', () => {
        mount(counted);
        initSaveBar();
        const form = document.getElementById('k');

        document.querySelector('[data-change-item="rule-0"]').remove();
        form.dispatchEvent(new Event('input', { bubbles: true }));
        expect(counter()).toBe(' · 1 Einstellung geändert');

        form.insertAdjacentHTML('beforeend', '<div data-change-item="rule-n1"><input name="duration_rules[n1][min_party]" value="7"></div>');
        form.dispatchEvent(new Event('input', { bubbles: true }));
        expect(counter()).toBe(' · 2 Einstellungen geändert');
    });

    it('setzt Zähler und Punkt beim Verwerfen zurück', () => {
        mount(counted);
        initSaveBar();
        type('r0-min', '2');

        document.querySelector('[data-save-bar-discard]').click();

        expect(counter()).toBe('');
        expect(document.querySelector('[data-change-item]').hasAttribute('data-changed')).toBe(false);
        expect(document.querySelector('[data-change-dot]').hidden).toBe(true);
    });

    it('zählt nach dem Speichern nur, was während der Anfrage dazukam', async () => {
        let resolve;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((r) => { resolve = r; })));
        mount(counted);
        initSaveBar();

        type('f-buffer', '15');
        document.querySelector('[data-save-bar-save]').click();
        await vi.waitFor(() => expect(resolve).toBeTypeOf('function'));
        type('f-duration', '90');
        resolve(jsonResponse({ message: 'Gespeichert.' }));

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        await vi.waitFor(() => expect(counter()).toBe(' · 1 Einstellung geändert'));
        expect(document.querySelector('[data-save-bar]').hidden).toBe(false);
    });
});
```

- [ ] **Step 2: Failing tests – `tests/js/rows.test.js` anlegen**

```js
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initRows } from '../../resources/js/admin/rows.js';
import { jsonResponse, mount } from './helpers.js';

const rows = (max = '') => `
    <form id="cap" method="POST" action="/admin/settings/capacity" data-ajax>
        <div data-rows ${max ? `data-rows-max="${max}"` : ''}>
            <div data-rows-list>
                <div data-row data-change-item="rule-0">
                    <input id="rule-0-min" name="duration_rules[0][min_party]" value="1">
                    <button type="button" data-rows-remove>Entfernen</button>
                </div>
            </div>
            <template data-rows-template>
                <div data-row data-change-item="rule-__INDEX__">
                    <input id="rule-__INDEX__-min" name="duration_rules[__INDEX__][min_party]" value="">
                    <button type="button" data-rows-remove>Entfernen</button>
                </div>
            </template>
            <button type="button" data-rows-add>Tischzeit hinzufügen</button>
        </div>
    </form>`;

const allRows = () => document.querySelectorAll('[data-rows-list] [data-row]');

beforeAll(() => initAjaxForms());

describe('Zeilen', () => {
    it('hängt eine Zeile mit eigener Kennung an, fokussiert sie und meldet die Änderung', () => {
        mount(rows());
        initRows();
        const changed = vi.fn();
        document.getElementById('cap').addEventListener('input', changed);

        document.querySelector('[data-rows-add]').click();

        const added = allRows()[1];
        expect(added.dataset.changeItem).toMatch(/^rule-n\d+$/);
        expect(added.querySelector('input').name).toMatch(/^duration_rules\[n\d+\]\[min_party\]$/);
        expect(added.querySelector('input').id).toMatch(/^rule-n\d+-min$/);
        expect(document.activeElement).toBe(added.querySelector('input'));
        expect(changed).toHaveBeenCalledTimes(1);
    });

    it('entfernt eine Zeile und setzt den Fokus auf „Hinzufügen“', () => {
        mount(rows());
        initRows();
        const changed = vi.fn();
        document.getElementById('cap').addEventListener('input', changed);

        document.querySelector('[data-rows-remove]').click();

        expect(allRows()).toHaveLength(0);
        expect(document.activeElement).toBe(document.querySelector('[data-rows-add]'));
        expect(changed).toHaveBeenCalledTimes(1);
    });

    it('sperrt „Hinzufügen“ bei der Höchstzahl und gibt es nach dem Entfernen frei', () => {
        mount(rows('2'));
        initRows();
        const add = document.querySelector('[data-rows-add]');

        add.click();
        expect(add.disabled).toBe(true);

        allRows()[1].querySelector('[data-rows-remove]').click();
        expect(add.disabled).toBe(false);
    });

    it('stellt beim Zurücksetzen den gespeicherten Stand wieder her', () => {
        mount(rows());
        initRows();
        document.querySelector('[data-rows-remove]').click();
        document.querySelector('[data-rows-add]').click();

        document.getElementById('cap').reset();

        expect(allRows()).toHaveLength(1);
        expect(allRows()[0].querySelector('input').value).toBe('1');
    });

    it('übernimmt nach dem Speichern den neuen Stand', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Gespeichert.' })));
        mount(rows());
        initRows();
        document.querySelector('[data-rows-add]').click();
        document.activeElement.value = '5';

        document.getElementById('cap').requestSubmit();
        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        document.getElementById('cap').reset();

        expect(allRows()).toHaveLength(2);
        expect(allRows()[1].querySelector('input').value).toBe('5');
    });
});
```

- [ ] **Step 3: Failing tests – `tests/js/reveal.test.js` und `tests/js/minutes.test.js` anlegen**

`tests/js/reveal.test.js`:
```js
import { describe, expect, it } from 'vitest';
import { applyReveals, initReveals } from '../../resources/js/admin/reveal.js';
import { mount } from './helpers.js';

describe('Hinweise je nach Eingabe', () => {
    it('zeigt ein Feld nur bei passenden Werten einer Auswahl', () => {
        mount(`
            <form>
                <label><input type="radio" name="capacity_mode" value="table" checked> Tische</label>
                <label><input type="radio" name="capacity_mode" value="person"> Personen</label>
                <div id="covers" data-show-if="capacity_mode:person,hybrid">Höchstzahl</div>
            </form>`);
        initReveals();
        expect(document.getElementById('covers').hidden).toBe(true);

        const person = document.querySelector('[value="person"]');
        person.checked = true;
        person.dispatchEvent(new Event('change', { bubbles: true }));

        expect(document.getElementById('covers').hidden).toBe(false);
    });

    it('verlangt bei mehreren Bedingungen alle', () => {
        mount(`
            <form>
                <select name="fields[email]"><option value="optional" selected>Freiwillig</option><option value="hidden">Ausgeblendet</option></select>
                <select name="fields[phone]"><option value="optional">Freiwillig</option><option value="hidden" selected>Ausgeblendet</option></select>
                <p id="warn" data-show-if="fields[email]:hidden;fields[phone]:hidden">Hinweis</p>
            </form>`);
        applyReveals();
        expect(document.getElementById('warn').hidden).toBe(true);

        document.querySelector('[name="fields[email]"]').value = 'hidden';
        applyReveals();

        expect(document.getElementById('warn').hidden).toBe(false);
    });

    it('liest bei einem Kästchen mit verstecktem Feld die 0, solange der Haken fehlt', () => {
        mount(`
            <form>
                <input type="hidden" name="waitlist_enabled" value="0">
                <input type="checkbox" name="waitlist_enabled" value="1">
                <p id="on" data-show-if="waitlist_enabled:1">An</p>
                <p id="off" data-show-if="waitlist_enabled:0">Aus</p>
            </form>`);
        applyReveals();

        expect(document.getElementById('on').hidden).toBe(true);
        expect(document.getElementById('off').hidden).toBe(false);
    });
});
```

`tests/js/minutes.test.js`:
```js
import { describe, expect, it } from 'vitest';
import { describeMinutes, initMinuteHints } from '../../resources/js/admin/minutes.js';
import { mount } from './helpers.js';

const field = `
    <form id="f">
        <input id="f-lead" name="min_lead_minutes" value="120" data-minutes-hint>
        <p data-minutes-hint-for="f-lead"></p>
    </form>`;

const hint = () => document.querySelector('[data-minutes-hint-for="f-lead"]').textContent;

function type(value) {
    const input = document.getElementById('f-lead');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

describe('Minuten in Worten', () => {
    it('rechnet in Tage, Stunden und Minuten um', () => {
        expect(describeMinutes('45')).toBe('45 Min.');
        expect(describeMinutes('120')).toBe('2 Std.');
        expect(describeMinutes('150')).toBe('2 Std. 30 Min.');
        expect(describeMinutes('1440')).toBe('1 Tag');
        expect(describeMinutes('1530')).toBe('1 Tag 1 Std. 30 Min.');
        expect(describeMinutes('10080')).toBe('7 Tage');
        expect(describeMinutes('0')).toBe('0 Min.');
        expect(describeMinutes('')).toBe('');
        expect(describeMinutes('-5')).toBe('');
    });

    it('schreibt den Hinweis ab einer Stunde unter das Feld und folgt der Eingabe', () => {
        mount(field);
        initMinuteHints();
        expect(hint()).toBe('entspricht 2 Std.');

        type('30');
        expect(hint()).toBe('');

        type('1440');
        expect(hint()).toBe('entspricht 1 Tag');
    });

    it('zeigt nach dem Zurücksetzen wieder den gespeicherten Wert', async () => {
        mount(field);
        initMinuteHints();
        type('30');

        document.getElementById('f').reset();
        await Promise.resolve();

        expect(hint()).toBe('entspricht 2 Std.');
    });
});
```

- [ ] **Step 4: Test – `tests/js/toast.test.js` ergänzen**

Am Ende des `describe`-Blocks:
```js
    it('ruft Rückgängig auf und blendet den Hinweis aus', () => {
        mount('');
        const undo = vi.fn();

        showToast('Raum gelöscht', { undo });
        document.querySelector('#swayyToast button').click();

        expect(undo).toHaveBeenCalledTimes(1);
        expect(document.getElementById('swayyToast').classList.contains('opacity-0')).toBe(true);
    });
```

- [ ] **Step 5: Tests laufen lassen**

Run: `npm test`
Expected: FAIL (Module `rows.js`, `reveal.js`, `minutes.js` fehlen; Zähler und `data-page-header-controls` fehlen). Der Toast-Test ist schon grün; er schließt eine Testlücke aus 2a.

- [ ] **Step 6: `resources/js/admin/savebar.js` ersetzen**

```js
// Speicherleiste (x-save-bar): erscheint, sobald ein Formular mit
// data-savebar geändert ist. „Speichern“ schickt die geänderten Formulare
// nacheinander ab, „Verwerfen“ setzt sie auf den gespeicherten Stand zurück.
// Wer die Seite mit Änderungen verlässt, bekommt die Rückfrage des Browsers.
// Formulare mit data-savebar enthalten keine Dateifelder; Uploads speichern
// sofort über eigene Formulare.
//
// Die Leiste nennt, wie viel geändert ist. Jede Zeile mit data-change-item
// (Wert: fester Schlüssel der Zeile) zählt einmal, jedes Feld außerhalb
// solcher Zeilen nach seinem Namen; hinzugefügte und entfernte Zeilen zählen
// mit. Geänderte Zeilen tragen data-changed und zeigen ihren Punkt
// ([data-change-dot], x-change-dot). Die Wörter stehen an der Leiste:
// data-count-one („1 Tag geändert“) und data-count-many („:count Tage geändert“).

import { keyboardFocused } from './dom.js';
import { savedSnapshot, snapshot, submitForm } from './forms.js';
import { showToast } from './toast.js';

// Erstes bedienbares Feld eines Formulars.
const firstField = (form) =>
    [...form.elements].find(
        (el) => el.matches('input:not([type="hidden"]), select, textarea') && !el.disabled && el.getClientRects().length > 0,
    );

function valueOf(el) {
    if (el.type === 'checkbox' || el.type === 'radio') return `${el.value}:${el.checked}`;
    if (el instanceof HTMLSelectElement) return [...el.selectedOptions].map((option) => option.value).join(',');
    return el.value;
}

// Stand der Zählteile eines Formulars: Zeilen nach Schlüssel, übrige Felder nach Name.
function partsOf(form) {
    const parts = new Map();
    form.querySelectorAll('[data-change-item]').forEach((item) => {
        const values = [...item.querySelectorAll('input, select, textarea')]
            .filter((el) => el.name)
            .map((el) => `${el.name}=${valueOf(el)}`);
        parts.set(`item:${item.dataset.changeItem}`, values.join('&'));
    });
    for (const el of form.elements) {
        if (!el.name || el.type === 'submit' || el.type === 'button' || el.closest('[data-change-item]')) continue;
        const key = `field:${el.name}`;
        parts.set(key, `${parts.get(key) ?? ''}|${valueOf(el)}`);
    }
    return parts;
}

function changedKeys(base, now) {
    const keys = new Set([...base.keys(), ...now.keys()]);
    return new Set([...keys].filter((key) => base.get(key) !== now.get(key)));
}

export function initSaveBar() {
    const bar = document.querySelector('[data-save-bar]');
    const forms = [...document.querySelectorAll('form[data-savebar]')];
    if (!bar || forms.length === 0) return;

    const base = new Map(forms.map((form) => [form, snapshot(form)]));
    const baseParts = new Map(forms.map((form) => [form, partsOf(form)]));
    const sendingParts = new Map();
    const dirtyForms = () => forms.filter((form) => snapshot(form) !== base.get(form));
    const status = document.querySelector('[data-save-bar-status]');
    const count = bar.querySelector('[data-save-bar-count]');
    let wasDirty = false;
    let saving = false;

    const words = (n) => (n === 1
        ? bar.dataset.countOne ?? ''
        : (bar.dataset.countMany ?? '').replace(':count', String(n)));

    // Ab 768 px (md) liegt die Leiste über dem Seitenkopf. Solange sie steht,
    // sind dessen Bedienelemente (Standortwahl, Hauptaktion) gesperrt, damit
    // der Tastaturfokus nie unter der Leiste verschwindet (WCAG 2.4.11). Titel
    // und Beschreibung bleiben für Screenreader lesbar.
    const controls = document.querySelector('[data-page-header-controls]');
    const wide = window.matchMedia('(min-width: 768px)');
    const syncHeader = () => controls?.toggleAttribute('inert', !bar.hidden && wide.matches);
    wide.addEventListener('change', syncHeader);

    const render = () => {
        const dirty = dirtyForms();
        let changed = 0;
        forms.forEach((form) => {
            const keys = changedKeys(baseParts.get(form), partsOf(form));
            changed += keys.size;
            form.querySelectorAll('[data-change-item]').forEach((item) => {
                const on = keys.has(`item:${item.dataset.changeItem}`);
                item.toggleAttribute('data-changed', on);
                item.querySelectorAll('[data-change-dot]').forEach((dot) => {
                    dot.hidden = !on;
                });
            });
            form.toggleAttribute('data-dirty', dirty.includes(form));
        });
        bar.hidden = dirty.length === 0;
        if (count) count.textContent = dirty.length > 0 && changed > 0 ? ` · ${words(changed)}` : '';
        syncHeader();

        // Angesagt wird nur der Wechsel, damit der Screenreader nicht bei jedem Tastendruck spricht.
        if (status && (dirty.length > 0) !== wasDirty) {
            wasDirty = dirty.length > 0;
            status.textContent = wasDirty ? (bar.querySelector('[data-save-bar-text]')?.textContent ?? '') : '';
        }
    };

    // Verschwindet die Leiste mit dem fokussierten Knopf, wandert der Fokus
    // ins erste Feld des Formulars. Gescrollt wird nur bei Bedienung per
    // Tastatur; nach einem Klick bleibt die Ansicht stehen.
    const moveFocusOut = (form) => {
        const active = document.activeElement;
        if (form && dirtyForms().length === 0 && bar.contains(active)) {
            firstField(form)?.focus({ preventScroll: !keyboardFocused(active) });
        }
    };

    forms.forEach((form) => {
        form.addEventListener('input', render);
        form.addEventListener('change', render);
        form.addEventListener('swayy:sending', () => sendingParts.set(form, partsOf(form)));
        form.addEventListener('swayy:saved', () => {
            base.set(form, savedSnapshot(form) ?? snapshot(form));
            baseParts.set(form, sendingParts.get(form) ?? partsOf(form));
            sendingParts.delete(form);
            moveFocusOut(form);
            render();
        });
        form.addEventListener('swayy:rebase', () => {
            base.set(form, snapshot(form));
            baseParts.set(form, partsOf(form));
            render();
        });
    });

    bar.querySelector('[data-save-bar-discard]')?.addEventListener('click', () => {
        if (saving) return;
        const dirty = dirtyForms();
        dirty.forEach((form) => form.reset());
        moveFocusOut(dirty[0]);
        render();
        // Erst nach render: Die Formulare tragen kein data-dirty mehr, ein
        // zurückgestelltes Neuladen (forms.js) darf jetzt laufen.
        dirty.forEach((form) => form.dispatchEvent(new CustomEvent('swayy:discarded', { bubbles: true })));
        showToast('Änderungen verworfen');
    });

    // Speichern prüft zuerst die Eingaben im Browser (Pflichtfelder, Zahlenbereiche),
    // wie beim Absenden mit Enter. Solange die Anfragen laufen, nehmen beide
    // Knöpfe keine weiteren Klicks an.
    bar.querySelector('[data-save-bar-save]')?.addEventListener('click', async () => {
        if (saving) return;
        saving = true;
        bar.setAttribute('aria-busy', 'true');
        try {
            for (const form of dirtyForms()) {
                if (!form.reportValidity()) break;
                if (!(await submitForm(form))) break;
            }
        } finally {
            saving = false;
            bar.removeAttribute('aria-busy');
        }
        render();
    });

    window.addEventListener('beforeunload', (e) => {
        if (dirtyForms().length > 0) {
            e.preventDefault();
            e.returnValue = '';
        }
    });
}
```

- [ ] **Step 7: `resources/js/admin/rows.js` anlegen**

```js
// Zeilen hinzufügen und entfernen (Tischzeiten, Arbeitszeiten). Aufbau:
//   <div data-rows data-rows-max="20">
//     <div data-rows-list> … Zeilen mit data-row … </div>
//     <template data-rows-template> eine Zeile mit __INDEX__ </template>
//     <button type="button" data-rows-add>Hinzufügen</button>
//   </div>
// „Hinzufügen“ hängt eine Kopie der Vorlage an und ersetzt __INDEX__ durch
// eine neue Kennung (n1, n2 …), „Entfernen“ (data-rows-remove in der Zeile)
// nimmt die Zeile heraus. Danach meldet ein input-Ereignis am Formular die
// Änderung (Speicherleiste, Seitenleiste). Beim Zurücksetzen des Formulars
// („Verwerfen“, Schließen der Seitenleiste) kehren die Zeilen zum
// gespeicherten Stand zurück; nach dem Speichern gilt der neue Stand.

let installed = false;
let counter = 0;
const saved = new WeakMap();

const listOf = (root) => root.querySelector('[data-rows-list]');

// Der gespeicherte Stand wird vor der ersten Änderung festgehalten; die
// Attribute im HTML tragen dann noch die Werte vom Server.
function remember(root) {
    const list = listOf(root);
    if (list && !saved.has(list)) saved.set(list, list.innerHTML);
}

function sync(root) {
    const add = root.querySelector('[data-rows-add]');
    const max = Number.parseInt(root.dataset.rowsMax ?? '', 10);
    if (add && Number.isFinite(max)) add.disabled = listOf(root).querySelectorAll('[data-row]').length >= max;
}

function notify(root) {
    (root.closest('form') ?? root).dispatchEvent(new Event('input', { bubbles: true }));
}

function addRow(root) {
    remember(root);
    const template = root.querySelector('template[data-rows-template]');
    const holder = document.createElement('div');
    holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', `n${++counter}`).trim();
    const row = holder.firstElementChild;
    listOf(root).append(row);
    sync(root);
    notify(root);
    row.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
}

function removeRow(button) {
    const root = button.closest('[data-rows]');
    remember(root);
    button.closest('[data-row]').remove();
    sync(root);
    notify(root);
    root.querySelector('[data-rows-add]')?.focus();
}

export function initRows() {
    document.querySelectorAll('[data-rows]').forEach(sync);
    if (installed) return;
    installed = true;

    document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        const add = e.target.closest('[data-rows-add]');
        if (add) {
            addRow(add.closest('[data-rows]'));
            return;
        }
        const remove = e.target.closest('[data-rows-remove]');
        if (remove) removeRow(remove);
    });

    // reset läuft vor dem Zurücksetzen: Die wiederhergestellten Zeilen tragen
    // ihre gespeicherten Werte als Vorgabe, das Zurücksetzen bestätigt sie.
    document.addEventListener('reset', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        form.querySelectorAll('[data-rows]').forEach((root) => {
            const list = listOf(root);
            if (saved.has(list)) list.innerHTML = saved.get(list);
            sync(root);
        });
    }, true);

    // Nach dem Speichern hat forms.js die gesendeten Werte als Vorgabe gesetzt.
    document.addEventListener('swayy:saved', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        form.querySelectorAll('[data-rows]').forEach((root) => saved.set(listOf(root), listOf(root).innerHTML));
    });
}
```

- [ ] **Step 8: `resources/js/admin/reveal.js` und `resources/js/admin/minutes.js` anlegen**

`reveal.js`:
```js
// Hinweise und Felder, die nur bei bestimmten Eingaben gelten.
// data-show-if="capacity_mode:person,hybrid" zeigt das Element, solange das
// Feld capacity_mode einen dieser Werte hat. Mehrere Bedingungen, getrennt
// durch „;“, müssen alle zutreffen: "fields[email]:hidden;fields[phone]:hidden".
// Gesucht wird im Formular des Elements, sonst im ganzen Dokument. Felder in
// verborgenen Bereichen werden weiter gesendet.

let installed = false;

// Wert eines Feldes nach Name: gewählte Option, Haken (sonst das versteckte
// 0-Feld von x-checkbox) oder Eingabe.
function currentValue(scope, name) {
    const fields = [...scope.querySelectorAll(`[name="${CSS.escape(name)}"]`)];
    const choices = fields.filter((el) => el.type === 'radio' || el.type === 'checkbox');
    if (choices.length > 0) {
        const picked = choices.find((el) => el.checked);
        if (picked) return picked.value;
        return fields.find((el) => el.type === 'hidden')?.value ?? '';
    }
    return fields.at(-1)?.value ?? '';
}

function matches(el) {
    const scope = el.closest('form') ?? document;
    return (el.dataset.showIf || '').split(';').filter(Boolean).every((condition) => {
        const at = condition.lastIndexOf(':');
        const name = condition.slice(0, at);
        const allowed = condition.slice(at + 1).split(',');
        return allowed.includes(currentValue(scope, name));
    });
}

export function applyReveals(root = document) {
    root.querySelectorAll('[data-show-if]').forEach((el) => {
        el.hidden = !matches(el);
    });
}

export function initReveals() {
    applyReveals();
    if (installed) return;
    installed = true;
    document.addEventListener('input', () => applyReveals());
    document.addEventListener('change', () => applyReveals());
    // reset läuft vor dem Zurücksetzen; die Hinweise folgen danach.
    document.addEventListener('reset', () => queueMicrotask(() => applyReveals()), true);
}
```

`minutes.js`:
```js
// Minutenfelder mit data-minutes-hint zeigen darunter, was die Zahl bedeutet
// („entspricht 2 Std. 30 Min.“), im Element mit
// data-minutes-hint-for="{ID des Feldes}" (x-field mit :minutes="true").
// Unter einer Stunde bleibt der Hinweis leer; die Einheit steht am Feld.

let installed = false;

export function describeMinutes(value) {
    const total = Number.parseInt(String(value), 10);
    if (!Number.isFinite(total) || total < 0) return '';
    const days = Math.floor(total / 1440);
    const hours = Math.floor((total % 1440) / 60);
    const minutes = total % 60;
    const parts = [];
    if (days > 0) parts.push(days === 1 ? '1 Tag' : `${days} Tage`);
    if (hours > 0) parts.push(`${hours} Std.`);
    if (minutes > 0 || parts.length === 0) parts.push(`${minutes} Min.`);
    return parts.join(' ');
}

function update(input) {
    const hint = document.querySelector(`[data-minutes-hint-for="${CSS.escape(input.id)}"]`);
    if (!hint) return;
    hint.textContent = Number.parseInt(input.value, 10) >= 60 ? `entspricht ${describeMinutes(input.value)}` : '';
}

export function initMinuteHints() {
    document.querySelectorAll('input[data-minutes-hint]').forEach(update);
    if (installed) return;
    installed = true;
    document.addEventListener('input', (e) => {
        if (e.target instanceof HTMLInputElement && e.target.hasAttribute('data-minutes-hint')) update(e.target);
    });
    // reset läuft vor dem Zurücksetzen; der Hinweis folgt danach.
    document.addEventListener('reset', (e) => {
        const form = e.target;
        if (form instanceof HTMLFormElement) {
            queueMicrotask(() => form.querySelectorAll('input[data-minutes-hint]').forEach(update));
        }
    }, true);
}
```

- [ ] **Step 9: `resources/js/admin/index.js` ersetzen**

```js
// Verwaltung: alle Bausteine starten, sobald die Seite steht.
import { initCopyButtons } from './copy.js';
import { initDrawers } from './drawer.js';
import { initAjaxForms } from './forms.js';
import { initInlineConfirms } from './inline-confirm.js';
import { initMinuteHints } from './minutes.js';
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';
import { initReveals } from './reveal.js';
import { initRows } from './rows.js';
import { initSaveBar } from './savebar.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
    initAjaxForms();
    initRows();
    initReveals();
    initMinuteHints();
    initSaveBar();
    initInlineConfirms();
    initDrawers();
    initCopyButtons();
}
```

- [ ] **Step 10: JS-Tests laufen lassen**

Run: `npm test`
Expected: PASS, alle Dateien.

- [ ] **Step 11: Failing tests – Blade-Bausteine und Testlücken**

`tests/Feature/PageComponentsTest.php`, neue Tests am Ende der Klasse:
```php
    public function test_save_bar_names_the_unit_of_its_counter(): void
    {
        $this->blade('<x-save-bar one="1 Tag geändert" many=":count Tage geändert" />')
            ->assertSee('data-count-one="1 Tag geändert"', false)
            ->assertSee('data-count-many=":count Tage geändert"', false)
            ->assertSee('data-save-bar-count', false);

        $this->blade('<x-save-bar />')
            ->assertSee('data-count-one="1 Einstellung geändert"', false)
            ->assertSee('data-count-many=":count Einstellungen geändert"', false);
    }

    public function test_page_header_groups_scope_and_actions_for_the_save_bar(): void
    {
        $this->blade('<x-page-header title="Tags" scope="tenant"><x-slot:actions><button type="button">Tag hinzufügen</button></x-slot:actions></x-page-header>')
            ->assertSeeInOrder(['data-page-header-controls', 'Gilt für alle Standorte', 'Tag hinzufügen'], false);
    }

    public function test_field_can_explain_minutes_in_hours(): void
    {
        $this->blade('<x-field name="buffer_minutes" label="Puffer" type="number" unit="Min." :minutes="true" value="90" />')
            ->assertSee('data-minutes-hint', false)
            ->assertSee('<p id="f-buffer_minutes-minutes" data-minutes-hint-for="f-buffer_minutes"', false)
            ->assertSee('aria-describedby="f-buffer_minutes-unit f-buffer_minutes-minutes"', false);
    }

    public function test_checkbox_sends_zero_without_a_tick_and_links_its_help(): void
    {
        $this->blade('<x-checkbox name="waitlist_enabled" label="Warteliste anbieten" :checked="true" help="Gäste tragen sich ein, wenn alles belegt ist." />')
            ->assertSeeInOrder([
                '<input type="hidden" name="waitlist_enabled" value="0">',
                'id="f-waitlist_enabled" type="checkbox" name="waitlist_enabled" value="1"',
            ], false)
            ->assertSee('checked', false)
            ->assertSee('aria-describedby="f-waitlist_enabled-help"', false)
            ->assertSee('<label for="f-waitlist_enabled"', false);

        $this->blade('<x-checkbox name="walkins_enabled" label="Walk-ins erfassen" :checked="false" />')
            ->assertDontSee('checked', false)
            ->assertDontSee('aria-describedby', false);
    }

    public function test_change_dot_starts_hidden_and_names_the_state(): void
    {
        $this->blade('<x-change-dot />')
            ->assertSee('data-change-dot hidden', false)
            ->assertSee('<span class="sr-only">Geändert</span>', false);
    }
```

`tests/Feature/AdminComponentsTest.php`, neue Tests:
```php
    public function test_status_icon_gives_each_state_its_own_shape(): void
    {
        $shapes = [];
        foreach (['ok', 'off', 'warn', 'error'] as $state) {
            $html = Blade::render('<x-status-icon :state="$state" label="X" />', ['state' => $state]);
            preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $html, $match);
            $shapes[$state] = $match[1] ?? '';
        }

        $this->assertCount(4, array_unique($shapes));
        $this->assertStringContainsString('<circle', $shapes['ok']);
        $this->assertStringContainsString('<path d="M8 1.5l7 12.5H1z"', $shapes['warn']);
        $this->assertStringContainsString('<rect', $shapes['error']);
    }

    public function test_status_icon_leaves_the_word_to_visible_text_when_asked(): void
    {
        $html = Blade::render('<x-status-icon state="ok" label="Aktiv" :announce="false" />');

        $this->assertStringContainsString('title="Aktiv"', $html);
        $this->assertStringNotContainsString('sr-only', $html);
    }

    public function test_button_levels_exist_with_large_enough_targets(): void
    {
        $css = (string) file_get_contents(resource_path('css/app.css'));

        foreach (['.btn-primary {', '.btn-secondary {', '.btn-danger {', '.btn-quiet {', '.btn-quiet-danger {'] as $rule) {
            $this->assertStringContainsString($rule, $css, $rule);
        }
        $this->assertMatchesRegularExpression('/\.btn \{[^}]*min-height: 2\.5rem/', $css);
        $this->assertMatchesRegularExpression('/\.btn-quiet, \.btn-quiet-danger \{ min-height: 2\.25rem/', $css);
    }
```

`tests/Feature/NavigationTest.php`, neue Tests:
```php
    public function test_platform_support_sees_every_settings_page_except_data(): void
    {
        $setup = $this->createTenantSetup();
        $support = User::factory()->create(['saas_role' => 'support_admin']);

        $keys = array_merge(...array_column($this->shape(
            Navigation::fromConfig()->settings($support, $setup['tenant'], $setup['location'], null),
        ), 1));

        $this->assertContains('general', $keys);
        $this->assertContains('capacity', $keys);
        $this->assertContains('tags', $keys);
        $this->assertNotContains('data', $keys);
    }

    public function test_platform_read_access_sees_only_pages_for_viewing(): void
    {
        $setup = $this->createTenantSetup();
        $reader = User::factory()->create(['saas_role' => 'readonly_admin']);
        $nav = Navigation::fromConfig();

        $this->assertSame([[null, ['audit']]], $this->shape($nav->settings($reader, $setup['tenant'], $setup['location'], null)));
        $this->assertSame(
            ['dashboard', 'board', 'floorplan', 'reservations', 'guests', 'reports'],
            array_merge(...array_column($this->shape($nav->main($reader, $setup['tenant'], $setup['location'], null)), 1)),
        );
    }
```

- [ ] **Step 12: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='PageComponentsTest|AdminComponentsTest|NavigationTest'`
Expected: FAIL bei Zähler, Seitenkopf, Minuten, `x-checkbox`, `x-change-dot`, `announce`. Statussymbol, Knopfstufen und Navigation sind schon grün (Testlücken aus 2a).

- [ ] **Step 13: Blade-Bausteine ändern und anlegen**

`resources/views/components/save-bar.blade.php` ersetzen:
```blade
@props(['one' => '1 Einstellung geändert', 'many' => ':count Einstellungen geändert'])
{{-- Speicherleiste: erscheint bei Änderungen in Formularen mit data-savebar (resources/js/admin/savebar.js).
     Am Smartphone über der unteren Leiste, ab 768 px oben im Inhalt und beim Scrollen stehend.
     Die Hülle hat ab 768 px weder Höhe noch Außenabstand: Die Leiste legt sich über den Seitenkopf, der Inhalt darunter bleibt an seinem Platz.
     one/many: Wörter für den Zähler (":count" wird ersetzt), etwa one="1 Tag geändert" many=":count Tage geändert". --}}
<div class="md:sticky md:top-6 md:z-40 md:-mx-6 md:h-0">
    <p class="sr-only" role="status" data-save-bar-status></p>
    <div data-save-bar hidden role="region" aria-label="Ungespeicherte Änderungen" data-count-one="{{ $one }}" data-count-many="{{ $many }}"
         class="fixed inset-x-0 bottom-14 z-40 flex flex-wrap items-center justify-between gap-3 bg-stone-900 px-4 py-3 text-white shadow-[0_-6px_16px_rgba(28,25,23,0.18)] md:relative md:-top-6 md:px-6 md:shadow-[0_6px_16px_rgba(28,25,23,0.18)]">
        <p class="flex items-center gap-2.5 text-sm font-semibold">
            <span class="h-2 w-2 shrink-0 rounded-full bg-amber-400" aria-hidden="true"></span>
            <span><span data-save-bar-text>Ungespeicherte Änderungen</span><span data-save-bar-count class="font-normal text-stone-300"></span></span>
        </p>
        <div class="flex w-full gap-2 sm:w-auto">
            <button type="button" data-save-bar-discard
                    class="min-h-11 flex-1 rounded-md border border-stone-600 px-3 text-sm font-semibold hover:bg-stone-800 focus-visible:outline-white sm:min-h-9 sm:flex-none">Verwerfen</button>
            <button type="button" data-save-bar-save
                    class="min-h-11 flex-1 rounded-md bg-white px-3 text-sm font-semibold text-stone-900 hover:bg-stone-100 focus-visible:outline-white sm:min-h-9 sm:flex-none">Speichern</button>
        </div>
    </div>
</div>
```

`resources/views/components/page-header.blade.php`: Kommentar in Zeile 2 ersetzen durch
```blade
{{-- Seitenkopf: Pfad, Titel, ein Satz, Geltungsbereich, eine Hauptaktion (Slot "actions").
     Standortwahl und Aktionen stehen in data-page-header-controls; die Speicherleiste sperrt nur diesen Teil. --}}
```
und die Zeile
```blade
            <div class="flex flex-wrap items-center gap-2.5">
```
ersetzen durch
```blade
            <div data-page-header-controls class="flex flex-wrap items-center gap-2.5">
```

`resources/views/components/field.blade.php` ersetzen:
```blade
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'unit' => null,
    'required' => false,
    'id' => null,
    'minutes' => false,
])
{{-- Feld mit Beschriftung darüber, Hilfesatz und Fehler am Feld. Der Standard-Slot ersetzt das Eingabefeld (Auswahl, Textfeld).
     Ein Steuerelement im Slot setzt id="{id}", aria-describedby ("{id}-help", "{id}-error") und den alten Wert selbst;
     die Komponente liefert Beschriftung, Hilfesatz und Fehler mit diesen IDs.
     :minutes="true" zeigt unter einem Minutenfeld, was die Zahl bedeutet („entspricht 2 Std.“, resources/js/admin/minutes.js). --}}
@php
    $id = $id ?? 'f-'.trim((string) preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $dotted = str_replace(['[', ']'], ['.', ''], $name);
    // $errors teilt die Middleware ShareErrorsFromSession; außerhalb einer
    // Anfrage (Komponententest, Mail) fehlt die Variable.
    $bag = isset($errors) ? $errors : new \Illuminate\Support\ViewErrorBag();
    $error = $bag->first($dotted);
    $describedBy = implode(' ', array_filter([
        $unit ? $id.'-unit' : null,
        $minutes ? $id.'-minutes' : null,
        $help ? $id.'-help' : null,
        $error ? $id.'-error' : null,
    ]));
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'space-y-1.5']) }}>
    <label for="{{ $id }}" class="block text-sm font-semibold text-stone-800">
        {{ $label }}@if($required)<span class="text-red-700" aria-hidden="true"> *</span>@endif
    </label>
    @if($slot->isNotEmpty())
        {{ $slot }}
    @else
        <div class="flex items-stretch">
            <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($dotted, $value) }}"
                   @if($required) required aria-required="true" @endif
                   @if($minutes) data-minutes-hint @endif
                   @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                   @if($error) aria-invalid="true" @endif
                   {{ $attributes->except(['class'])->merge(['class' => 'w-full min-w-0'.($unit ? ' rounded-r-none' : '')]) }}>
            @if($unit)
                <span id="{{ $id }}-unit" class="inline-flex items-center rounded-r border border-l-0 border-stone-300 bg-stone-50 px-3 text-sm text-stone-600">{{ $unit }}</span>
            @endif
        </div>
    @endif
    @if($minutes)
        <p id="{{ $id }}-minutes" data-minutes-hint-for="{{ $id }}" class="text-[0.8125rem] text-stone-600"></p>
    @endif
    @if($help)
        <p id="{{ $id }}-help" class="text-[0.8125rem] text-stone-500">{{ $help }}</p>
    @endif
    @if($error)
        <p id="{{ $id }}-error" class="text-[0.8125rem] text-red-700">{{ $error }}</p>
    @endif
</div>
```

`resources/views/components/status-icon.blade.php` ersetzen:
```blade
@props(['state', 'label', 'announce' => true])
{{-- Zustand als Symbol mit eigener Form und Farbe; das Wort steht im Tooltip und für Screenreader.
     ok = gefüllter Kreis mit Haken, off = leerer Kreis, warn = Dreieck, error = Quadrat mit Kreuz.
     :announce="false", wenn das Wort sichtbar daneben steht (sonst hört der Screenreader es doppelt). --}}
@php
    $shapes = [
        'ok' => ['text-emerald-700', '<circle cx="8" cy="8" r="7" fill="currentColor"/><path d="M4.8 8.2l2.1 2.1 4.3-4.6" fill="none" stroke="var(--color-white)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'],
        'off' => ['text-stone-500', '<circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.6"/>'],
        'warn' => ['text-amber-700', '<path d="M8 1.5l7 12.5H1z" fill="currentColor"/><path d="M8 6v3.6" stroke="var(--color-white)" stroke-width="1.6" stroke-linecap="round"/><circle cx="8" cy="11.6" r=".9" fill="var(--color-white)"/>'],
        'error' => ['text-red-700', '<rect x="1.5" y="1.5" width="13" height="13" rx="2.5" fill="currentColor"/><path d="M5.5 5.5l5 5m0-5l-5 5" stroke="var(--color-white)" stroke-width="1.6" stroke-linecap="round"/>'],
    ];
    [$color, $shape] = $shapes[$state] ?? $shapes['off'];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center '.$color]) }} title="{{ $label }}">
    <svg viewBox="0 0 16 16" class="h-4 w-4" aria-hidden="true" focusable="false">{!! $shape !!}</svg>
    @if($announce)<span class="sr-only">{{ $label }}</span>@endif
</span>
```

`resources/views/components/checkbox.blade.php` anlegen:
```blade
@props(['name', 'label', 'checked' => false, 'help' => null, 'id' => null, 'value' => '1'])
{{-- Schalter als Kästchen mit Beschriftung und Hilfesatz. Das versteckte Feld davor sendet 0, solange der Haken fehlt:
     So kommt jeder Schalter beim Server an, und ein fehlender Schalter ist ein Fehler. Weitere Attribute gehen an das Kästchen. --}}
@php
    $id = $id ?? 'f-'.trim((string) preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $isChecked = (bool) old(str_replace(['[', ']'], ['.', ''], $name), $checked);
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'flex items-start gap-3']) }}>
    <input type="hidden" name="{{ $name }}" value="0">
    <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($isChecked)
           @if($help) aria-describedby="{{ $id }}-help" @endif
           {{ $attributes->except(['class'])->merge(['class' => 'mt-0.5 h-5 w-5 shrink-0']) }}>
    <div class="min-w-0">
        <label for="{{ $id }}" class="block text-sm font-semibold text-stone-800">{{ $label }}</label>
        @if($help)
            <p id="{{ $id }}-help" class="text-[0.8125rem] text-stone-600">{{ $help }}</p>
        @endif
    </div>
</div>
```

`resources/views/components/change-dot.blade.php` anlegen:
```blade
{{-- Punkt an einer geänderten Zeile; resources/js/admin/savebar.js zeigt ihn, solange die Zeile ungespeichert ist.
     Gefüllter Kreis (Kontrast ≥ 3:1 auf Weiß), dazu das Wort für Screenreader und im Tooltip. --}}
<span data-change-dot hidden title="Geändert" {{ $attributes->merge(['class' => 'inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-amber-600']) }}>
    <span class="sr-only">Geändert</span>
</span>
```

- [ ] **Step 14: Tests laufen lassen**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='PageComponentsTest|AdminComponentsTest|NavigationTest|SettingsGeneralPageTest|LocationManagementTest'
npm test
npm run build
```
Expected: alles grün, Build ohne Fehler.

- [ ] **Step 15: Commit**

```bash
git add resources/js/admin resources/views/components tests/js tests/Feature/PageComponentsTest.php tests/Feature/AdminComponentsTest.php tests/Feature/NavigationTest.php
git commit -m "feat(admin): Speicherleiste zählt Änderungen und markiert Zeilen, Bausteine für Zeilen, Hinweise und Schalter

Zeilen hinzufügen und entfernen mit Rückkehr beim Verwerfen (rows.js),
Hinweise je nach Eingabe (reveal.js), Minuten in Worten (minutes.js),
x-checkbox mit verstecktem 0-Feld, x-change-dot; die Leiste sperrt nur
Standortwahl und Aktionen. Testlücken aus 2a geschlossen.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Grenzen und Prüfregeln der Buchungseinstellungen

**Files:**
- Create: `app/Support/BookingSettingRules.php`, `tests/Feature/BookingSettingRulesTest.php`
- Modify: `config/swayy.php`, `.env.example`, `app/Http/Controllers/Admin/SettingsController.php`
- Test: `tests/Feature/DurationRulesTest.php`, `tests/Feature/SettingsFormErrorsTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `App\Support\EnvSetting::integer()`, `::integerList()`, `::hexColor()`; `App\Models\LocationSettings`.
- Produces:
  - `config('swayy.booking.*')`: `slot_intervals` (list<int>), `duration_min`, `duration_max`, `buffer_max`, `duration_rules_max`, `rule_duration_min`, `rule_duration_max`, `rule_party_max`, `covers_max`, `lead_max_minutes`, `advance_max_days`, `deadline_max_minutes`, `party_online_max` (int).
  - `config('swayy.tables.capacity_max')` (int), `config('swayy.salon.service_duration_min')`, `config('swayy.salon.service_duration_max')`, `config('swayy.salon.service_price_max')` (int, Euro).
  - `config('swayy.ui.default_staff_color')`, `config('swayy.ui.default_label_color')` (`#rrggbb`).
  - `App\Support\BookingSettingRules`:
    - `slotIntervals(?LocationSettings $settings = null): list<int>`
    - `capacity(?LocationSettings $settings, bool $salon): array<string, list<mixed>>`
    - `booking(): array<string, list<mixed>>`
    - `messages(): array<string, string>`, `attributes(): array<string, string>`
    - `durationRules(array $rows, bool $atField = true): list<array{min_party: int, max_party: int, duration: int}>` (wirft `ValidationException`; Schlüssel `duration_rules.{zeile}.{feld}` bzw. mit `atField: false` `duration_rules`)

- [ ] **Step 1: Failing test – `tests/Feature/BookingSettingRulesTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\LocationSettings;
use App\Support\BookingSettingRules;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Grenzen und Prüfregeln der Buchungseinstellungen kommen aus
 * config/swayy.php und gelten für die neuen Seiten wie für die alte
 * Sammelseite.
 */
class BookingSettingRulesTest extends TestCase
{
    public function test_the_new_settings_have_their_defaults(): void
    {
        $this->assertSame([15, 30, 60], config('swayy.booking.slot_intervals'));
        $this->assertSame(30, config('swayy.booking.duration_min'));
        $this->assertSame(480, config('swayy.booking.duration_max'));
        $this->assertSame(120, config('swayy.booking.buffer_max'));
        $this->assertSame(20, config('swayy.booking.duration_rules_max'));
        $this->assertSame(15, config('swayy.booking.rule_duration_min'));
        $this->assertSame(600, config('swayy.booking.rule_duration_max'));
        $this->assertSame(200, config('swayy.booking.rule_party_max'));
        $this->assertSame(2000, config('swayy.booking.covers_max'));
        $this->assertSame(10080, config('swayy.booking.lead_max_minutes'));
        $this->assertSame(730, config('swayy.booking.advance_max_days'));
        $this->assertSame(20160, config('swayy.booking.deadline_max_minutes'));
        $this->assertSame(100, config('swayy.booking.party_online_max'));
        $this->assertSame(50, config('swayy.tables.capacity_max'));
        $this->assertSame(5, config('swayy.salon.service_duration_min'));
        $this->assertSame(480, config('swayy.salon.service_duration_max'));
        $this->assertSame(1000, config('swayy.salon.service_price_max'));
        $this->assertSame('#0f766e', config('swayy.ui.default_staff_color'));
        $this->assertSame('#6b7280', config('swayy.ui.default_label_color'));
    }

    public function test_the_interval_choice_keeps_a_stored_value_outside_the_list(): void
    {
        config(['swayy.booking.slot_intervals' => [30, 15]]);

        $this->assertSame([15, 30], BookingSettingRules::slotIntervals());
        $this->assertSame([15, 20, 30], BookingSettingRules::slotIntervals(new LocationSettings(['slot_interval_minutes' => 20])));
    }

    public function test_salons_get_interval_buffer_and_gap_optimisation(): void
    {
        $this->assertSame(
            ['slot_interval_minutes', 'buffer_minutes', 'gap_optimization_enabled'],
            array_keys(BookingSettingRules::capacity(null, salon: true)),
        );
        $restaurant = BookingSettingRules::capacity(null, salon: false);
        $this->assertArrayHasKey('capacity_mode', $restaurant);
        $this->assertArrayHasKey('duration_rules.*.duration', $restaurant);
        $this->assertArrayNotHasKey('gap_optimization_enabled', $restaurant);
    }

    public function test_limits_follow_the_settings(): void
    {
        config(['swayy.booking.buffer_max' => 30, 'swayy.booking.party_online_max' => 12, 'swayy.booking.deadline_max_minutes' => 600]);

        $this->assertContains('max:30', BookingSettingRules::capacity(null, salon: true)['buffer_minutes']);
        $this->assertContains('max:12', BookingSettingRules::booking()['max_party_online']);
        $this->assertContains('max:600', BookingSettingRules::booking()['cancellation_deadline_minutes']);
    }

    public function test_duration_rules_are_sorted_and_empty_rows_dropped(): void
    {
        $rules = BookingSettingRules::durationRules([
            'n2' => ['min_party' => '5', 'max_party' => '8', 'duration' => '150'],
            0 => ['min_party' => '1', 'max_party' => '4', 'duration' => '90'],
            'n3' => ['min_party' => '', 'max_party' => '', 'duration' => ''],
        ]);

        $this->assertSame([
            ['min_party' => 1, 'max_party' => 4, 'duration' => 90],
            ['min_party' => 5, 'max_party' => 8, 'duration' => 150],
        ], $rules);
    }

    public function test_an_overlap_is_reported_at_the_later_row(): void
    {
        try {
            BookingSettingRules::durationRules([
                0 => ['min_party' => '1', 'max_party' => '4', 'duration' => '90'],
                'n1' => ['min_party' => '3', 'max_party' => '6', 'duration' => '120'],
            ]);
            $this->fail('Die Überschneidung ging durch.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['duration_rules.n1.min_party' => ['Die Gruppengrößen überschneiden sich: „1–4“ und „3–6“. Jede Gruppengröße darf nur in einer Regel vorkommen.']],
                $e->errors(),
            );
        }
    }

    public function test_a_reversed_range_names_the_fix_and_the_old_page_keeps_one_key(): void
    {
        try {
            BookingSettingRules::durationRules([0 => ['min_party' => '4', 'max_party' => '2', 'duration' => '90']], atField: false);
            $this->fail('Die vertauschte Spanne ging durch.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['duration_rules' => ['Bei „4 bis 2 Personen“ ist die Bis-Zahl kleiner als die Ab-Zahl. Bitte die Zahlen tauschen.']],
                $e->errors(),
            );
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=BookingSettingRulesTest`
Expected: FAIL („Class App\Support\BookingSettingRules not found“, Einstellungen fehlen).

- [ ] **Step 3: `config/swayy.php` ergänzen**

Nach dem Abschnitt `'locations' => [...]` einfügen:
```php
    /*
    |--------------------------------------------------------------------------
    | Buchungseinstellungen eines Standorts
    |--------------------------------------------------------------------------
    |
    | Grenzen der Seiten „Kapazität & Dauer“ und „Buchungsregeln“ (bis Teil 3
    | auch der alten Sammelseite), gelesen über App\Support\BookingSettingRules.
    | Die Seiten zeigen sie als Feldgrenzen, die Prüfung beim Speichern nutzt
    | dieselben Werte.
    |
    | slot_intervals: Auswahl für den Takt der Uhrzeiten in Minuten (je 5–240).
    | duration_min, duration_max: Standarddauer eines Besuchs in Minuten.
    | buffer_max: längster Puffer nach einer Buchung in Minuten.
    | duration_rules_max: höchstens so viele Tischzeiten je Gruppengröße.
    | rule_duration_min, rule_duration_max: Grenzen einer Tischzeit in Minuten.
    | rule_party_max: größte Gruppe in einer Tischzeit-Regel.
    | covers_max: größte Zahl für „Höchstens gleichzeitig“ (Gäste).
    | lead_max_minutes: längster Mindestvorlauf in Minuten.
    | advance_max_days: so viele Tage im Voraus ist höchstens buchbar.
    | deadline_max_minutes: längste Storno- und Umbuchungsfrist in Minuten.
    | party_online_max: größte Gruppe, die online buchen kann.
    |
    */
    'booking' => [
        'slot_intervals' => EnvSetting::integerList('SWAYY_BOOKING_SLOT_INTERVALS', default: [15, 30, 60], min: 5, max: 240),
        'duration_min' => EnvSetting::integer('SWAYY_BOOKING_DURATION_MIN', default: 30, min: 5, max: 240),
        'duration_max' => EnvSetting::integer('SWAYY_BOOKING_DURATION_MAX', default: 480, min: 60, max: 1440),
        'buffer_max' => EnvSetting::integer('SWAYY_BOOKING_BUFFER_MAX', default: 120, min: 0, max: 480),
        'duration_rules_max' => EnvSetting::integer('SWAYY_BOOKING_DURATION_RULES_MAX', default: 20, min: 1, max: 50),
        'rule_duration_min' => EnvSetting::integer('SWAYY_BOOKING_RULE_DURATION_MIN', default: 15, min: 5, max: 240),
        'rule_duration_max' => EnvSetting::integer('SWAYY_BOOKING_RULE_DURATION_MAX', default: 600, min: 60, max: 1440),
        'rule_party_max' => EnvSetting::integer('SWAYY_BOOKING_RULE_PARTY_MAX', default: 200, min: 1, max: 1000),
        'covers_max' => EnvSetting::integer('SWAYY_BOOKING_COVERS_MAX', default: 2000, min: 1, max: 100000),
        'lead_max_minutes' => EnvSetting::integer('SWAYY_BOOKING_LEAD_MAX', default: 10080, min: 0, max: 43200),
        'advance_max_days' => EnvSetting::integer('SWAYY_BOOKING_ADVANCE_MAX', default: 730, min: 1, max: 1825),
        'deadline_max_minutes' => EnvSetting::integer('SWAYY_BOOKING_DEADLINE_MAX', default: 20160, min: 0, max: 43200),
        'party_online_max' => EnvSetting::integer('SWAYY_BOOKING_PARTY_ONLINE_MAX', default: 100, min: 1, max: 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tische
    |--------------------------------------------------------------------------
    |
    | capacity_max: größte Platzzahl eines Tisches (1–255; mehr fasst die
    | Spalte in der Datenbank nicht). Gilt für „Räume & Tische“ und den Tischplan.
    |
    */
    'tables' => [
        'capacity_max' => EnvSetting::integer('SWAYY_TABLE_CAPACITY_MAX', default: 50, min: 1, max: 255),
    ],

    /*
    |--------------------------------------------------------------------------
    | Salon
    |--------------------------------------------------------------------------
    |
    | service_duration_min, service_duration_max: Dauer einer Leistung in Minuten.
    | service_price_max: höchster Preis einer Leistung in Euro.
    |
    */
    'salon' => [
        'service_duration_min' => EnvSetting::integer('SWAYY_SERVICE_DURATION_MIN', default: 5, min: 1, max: 60),
        'service_duration_max' => EnvSetting::integer('SWAYY_SERVICE_DURATION_MAX', default: 480, min: 30, max: 1440),
        'service_price_max' => EnvSetting::integer('SWAYY_SERVICE_PRICE_MAX', default: 1000, min: 1, max: 100000),
    ],
```

Im Abschnitt `'ui'` den Kommentar um zwei Zeilen ergänzen:
```php
    | default_staff_color: Kalenderfarbe, die „Person hinzufügen“ im Salon
    | vorschlägt. default_label_color: Farbe für neue Tags und Leistungen.
```
und im Array nach `default_brand_color` einfügen:
```php
        'default_staff_color' => EnvSetting::hexColor('SWAYY_UI_DEFAULT_STAFF_COLOR', default: '#0f766e'),
        'default_label_color' => EnvSetting::hexColor('SWAYY_UI_DEFAULT_LABEL_COLOR', default: '#6b7280'),
```

- [ ] **Step 4: `.env.example` ergänzen**

Nach `# SWAYY_AUDIT_PER_PAGE=100` einfügen:
```dotenv
# Farbe, die „Person hinzufügen“ im Salon vorschlägt (#rrggbb)
# SWAYY_UI_DEFAULT_STAFF_COLOR=#0f766e
# Farbe für neue Tags und Leistungen (#rrggbb)
# SWAYY_UI_DEFAULT_LABEL_COLOR=#6b7280
# Takt der Uhrzeiten zur Auswahl, Minuten durch Komma (je 5–240)
# SWAYY_BOOKING_SLOT_INTERVALS=15,30,60
# Standarddauer eines Besuchs: kleinster (5–240) und größter Wert (60–1440) in Minuten
# SWAYY_BOOKING_DURATION_MIN=30
# SWAYY_BOOKING_DURATION_MAX=480
# Längster Puffer nach einer Buchung in Minuten (0–480)
# SWAYY_BOOKING_BUFFER_MAX=120
# Höchstzahl der Tischzeiten je Gruppengröße (1–50)
# SWAYY_BOOKING_DURATION_RULES_MAX=20
# Tischzeit: kürzeste (5–240) und längste (60–1440) in Minuten, größte Gruppe einer Regel (1–1000)
# SWAYY_BOOKING_RULE_DURATION_MIN=15
# SWAYY_BOOKING_RULE_DURATION_MAX=600
# SWAYY_BOOKING_RULE_PARTY_MAX=200
# Größte Zahl für „Höchstens gleichzeitig“ in Gästen (1–100000)
# SWAYY_BOOKING_COVERS_MAX=2000
# Längster Mindestvorlauf in Minuten (0–43200)
# SWAYY_BOOKING_LEAD_MAX=10080
# So viele Tage im Voraus ist höchstens buchbar (1–1825)
# SWAYY_BOOKING_ADVANCE_MAX=730
# Längste Storno- und Umbuchungsfrist in Minuten (0–43200)
# SWAYY_BOOKING_DEADLINE_MAX=20160
# Größte Gruppe, die online buchen kann (1–1000)
# SWAYY_BOOKING_PARTY_ONLINE_MAX=100
# Größte Platzzahl eines Tisches (1–255)
# SWAYY_TABLE_CAPACITY_MAX=50
# Dauer einer Leistung im Salon: kürzeste (1–60) und längste (30–1440) in Minuten
# SWAYY_SERVICE_DURATION_MIN=5
# SWAYY_SERVICE_DURATION_MAX=480
# Höchster Preis einer Leistung in Euro (1–100000)
# SWAYY_SERVICE_PRICE_MAX=1000
```

- [ ] **Step 5: `app/Support/BookingSettingRules.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LocationSettings;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Prüfregeln der Buchungseinstellungen eines Standorts, gemeinsam für die
 * Seiten „Kapazität & Dauer“ und „Buchungsregeln“ und bis Teil 3 für die
 * alte Sammelseite. Die Grenzen stehen in config/swayy.php (booking.*).
 */
final class BookingSettingRules
{
    /**
     * Takt der Uhrzeiten: die Auswahl aus den Einstellungen und der
     * gespeicherte Wert, damit ein Standort mit altem Takt weiter speichert.
     *
     * @return list<int>
     */
    public static function slotIntervals(?LocationSettings $settings = null): array
    {
        $intervals = array_map('intval', (array) config('swayy.booking.slot_intervals'));
        if ($settings !== null && $settings->slot_interval_minutes !== null) {
            $intervals[] = (int) $settings->slot_interval_minutes;
        }
        $intervals = array_values(array_unique($intervals));
        sort($intervals);

        return $intervals;
    }

    /**
     * Kapazität & Dauer. Restaurants: Takt, Standarddauer, Puffer,
     * Tischzeiten, Zählweise und Höchstzahl. Salons: Takt, Puffer und
     * Lückenoptimierung; Dauer und Plätze ergeben sich aus Leistungen und
     * Personen.
     *
     * @return array<string, list<mixed>>
     */
    public static function capacity(?LocationSettings $settings, bool $salon): array
    {
        $limits = (array) config('swayy.booking');
        $rules = [
            'slot_interval_minutes' => ['required', 'integer', Rule::in(self::slotIntervals($settings))],
            'buffer_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['buffer_max']],
        ];

        if ($salon) {
            return $rules + ['gap_optimization_enabled' => ['required', 'boolean']];
        }

        return $rules + [
            'default_duration_minutes' => ['required', 'integer', 'min:'.$limits['duration_min'], 'max:'.$limits['duration_max']],
            'capacity_mode' => ['required', 'in:table,person,hybrid'],
            'max_covers_per_slot' => ['nullable', 'integer', 'min:1', 'max:'.$limits['covers_max']],
            'duration_rules' => ['nullable', 'array', 'max:'.$limits['duration_rules_max']],
            'duration_rules.*.min_party' => ['nullable', 'integer', 'min:1', 'max:'.$limits['rule_party_max']],
            'duration_rules.*.max_party' => ['nullable', 'integer', 'min:1', 'max:'.$limits['rule_party_max']],
            'duration_rules.*.duration' => ['nullable', 'integer', 'min:'.$limits['rule_duration_min'], 'max:'.$limits['rule_duration_max']],
        ];
    }

    /**
     * Buchungsregeln: Vorlauf, Vorausbuchung, Gruppengröße online, Fristen,
     * Bestätigung, Warteliste und Walk-ins. Die Schalter sind Pflichtfelder;
     * x-checkbox sendet ohne Haken eine 0.
     *
     * @return array<string, list<mixed>>
     */
    public static function booking(): array
    {
        $limits = (array) config('swayy.booking');

        return [
            'min_lead_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['lead_max_minutes']],
            'max_advance_days' => ['required', 'integer', 'min:1', 'max:'.$limits['advance_max_days']],
            'min_party_online' => ['required', 'integer', 'min:1', 'max:'.$limits['party_online_max']],
            'max_party_online' => ['required', 'integer', 'min:1', 'max:'.$limits['party_online_max'], 'gte:min_party_online'],
            'large_group_email' => ['nullable', 'email:rfc', 'max:200'],
            'cancellation_deadline_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['deadline_max_minutes']],
            'modification_deadline_minutes' => ['required', 'integer', 'min:0', 'max:'.$limits['deadline_max_minutes']],
            'booking_confirmation_mode' => ['required', 'in:auto,manual'],
            'require_email_confirmation' => ['required', 'boolean'],
            'waitlist_enabled' => ['required', 'boolean'],
            'walkins_enabled' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'duration_rules.max' => __('Höchstens :max Tischzeiten sind möglich. Bitte Gruppengrößen zusammenfassen.'),
            'duration_rules.*.duration.min' => __('Eine Tischzeit muss mindestens :min Minuten betragen.'),
            'duration_rules.*.duration.max' => __('Eine Tischzeit darf höchstens :max Minuten betragen.'),
            'max_party_online.gte' => __('Die größte Gruppe ist kleiner als die kleinste. Bitte bei „Größte Gruppe“ mindestens :value eintragen.'),
        ];
    }

    /**
     * Feldnamen, wie sie auf den neuen Seiten stehen.
     *
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return [
            'slot_interval_minutes' => __('Takt der Uhrzeiten'),
            'default_duration_minutes' => __('Standarddauer'),
            'buffer_minutes' => __('Puffer nach jeder Buchung'),
            'capacity_mode' => __('Zählweise'),
            'max_covers_per_slot' => __('Höchstens gleichzeitig'),
            'gap_optimization_enabled' => __('Lückenoptimierung'),
            'duration_rules.*.min_party' => __('Ab Personen'),
            'duration_rules.*.max_party' => __('Bis Personen'),
            'duration_rules.*.duration' => __('Tischzeit'),
            'min_lead_minutes' => __('Mindestvorlauf'),
            'max_advance_days' => __('Vorausbuchung'),
            'min_party_online' => __('Kleinste Gruppe'),
            'max_party_online' => __('Größte Gruppe'),
            'large_group_email' => __('Adresse für größere Gruppen'),
            'cancellation_deadline_minutes' => __('Stornofrist'),
            'modification_deadline_minutes' => __('Umbuchungsfrist'),
            'booking_confirmation_mode' => __('Online-Buchungen'),
            'require_email_confirmation' => __('E-Mail-Adresse bestätigen lassen'),
            'waitlist_enabled' => __('Warteliste anbieten'),
            'walkins_enabled' => __('Walk-ins erfassen'),
        ];
    }

    /**
     * Tischzeiten je Gruppengröße („2–4 Personen → 90 Minuten“), sortiert
     * nach Gruppengröße. LocationSettings::durationFor() nimmt die erste
     * passende Regel; Überschneidungen werden deshalb abgelehnt, sonst griffe
     * eine Regel weiter unten still nie. Leere Zeilen fallen weg.
     *
     * $atField: Der Fehler nennt das Feld der Zeile („duration_rules.n2.min_party“),
     * sonst steht er unter „duration_rules“ (alte Sammelseite).
     *
     * @param  array<int|string, mixed>  $rows
     * @return list<array{min_party: int, max_party: int, duration: int}>
     */
    public static function durationRules(array $rows, bool $atField = true): array
    {
        $rules = [];

        foreach ($rows as $key => $row) {
            $row = is_array($row) ? $row : [];
            $min = (int) ($row['min_party'] ?? 0);
            $max = (int) ($row['max_party'] ?? 0);
            $duration = (int) ($row['duration'] ?? 0);

            if ($min < 1 && $max < 1 && $duration < 1) {
                continue;
            }

            if ($min < 1 || $max < 1 || $duration < 1) {
                $field = $min < 1 ? 'min_party' : ($max < 1 ? 'max_party' : 'duration');
                self::fail($atField ? "duration_rules.{$key}.{$field}" : 'duration_rules',
                    __('Bitte bei jeder Tischzeit „Ab“, „Bis“ und Minuten ausfüllen oder die Zeile entfernen.'));
            }

            if ($max < $min) {
                self::fail($atField ? "duration_rules.{$key}.max_party" : 'duration_rules',
                    __('Bei „:min bis :max Personen“ ist die Bis-Zahl kleiner als die Ab-Zahl. Bitte die Zahlen tauschen.', ['min' => $min, 'max' => $max]));
            }

            $rules[] = ['key' => (string) $key, 'min_party' => $min, 'max_party' => $max, 'duration' => $duration];
        }

        usort($rules, fn (array $a, array $b) => $a['min_party'] <=> $b['min_party']);

        foreach ($rules as $i => $rule) {
            $previous = $rules[$i - 1] ?? null;
            if ($previous !== null && $rule['min_party'] <= $previous['max_party']) {
                self::fail($atField ? "duration_rules.{$rule['key']}.min_party" : 'duration_rules', __(
                    'Die Gruppengrößen überschneiden sich: „:a“ und „:b“. Jede Gruppengröße darf nur in einer Regel vorkommen.',
                    ['a' => $previous['min_party'].'–'.$previous['max_party'], 'b' => $rule['min_party'].'–'.$rule['max_party']],
                ));
            }
        }

        return array_map(
            fn (array $rule) => ['min_party' => $rule['min_party'], 'max_party' => $rule['max_party'], 'duration' => $rule['duration']],
            $rules,
        );
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
```

- [ ] **Step 6: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=BookingSettingRulesTest`
Expected: PASS, 7 Tests.

- [ ] **Step 7: Alte Sammelseite auf dieselben Regeln umstellen**

In `app/Http/Controllers/Admin/SettingsController.php`:
- Import `use App\Support\BookingSettingRules;` ergänzen, `use Illuminate\Validation\ValidationException;` entfernen.
- In `updateBookingRules()` den Aufruf `$request->validate([...], [...])` samt der Zeile `$validated['duration_rules'] = $this->durationRules($request);` ersetzen durch:
```php
        $validated = $request->validate(array_merge(
            BookingSettingRules::capacity($location->effectiveSettings(), salon: false),
            BookingSettingRules::booking(),
            [
                // Die alte Sammelseite schickt Kästchen ohne verstecktes Feld; fehlt eines, heißt es „aus“.
                'waitlist_enabled' => ['nullable', 'boolean'],
                'walkins_enabled' => ['nullable', 'boolean'],
                'require_email_confirmation' => ['nullable', 'boolean'],
                'gap_optimization_enabled' => ['nullable', 'boolean'],
                'reminder_enabled' => ['nullable', 'boolean'],
                'reminder_hours_before' => ['required', 'integer', 'min:1', 'max:168'],
                'sms_reminder_enabled' => ['nullable', 'boolean'],
                'public_floorplan_enabled' => ['nullable', 'boolean'],
                'refund_mode' => ['required', 'in:off,manual,auto'],
                'refund_percent' => ['required', 'integer', 'min:0', 'max:100'],
                'refund_processing' => ['required', 'in:immediate,scheduled'],
                'confetti_on_booking' => ['nullable', 'boolean'],
                'owner_notification_enabled' => ['nullable', 'boolean'],
                'owner_notification_email' => ['nullable', 'email:rfc', 'max:200'],
                'guest_address' => ['required', 'in:du,Sie'],
                'feedback_enabled' => ['nullable', 'boolean'],
                'feedback_hours_after' => ['required', 'integer', 'min:1', 'max:336'],
                'feedback_redirect_min_score' => ['required', 'integer', 'min:1', 'max:5'],
                'feedback_external_url' => ['nullable', 'url:https', 'max:300'],
            ],
        ), BookingSettingRules::messages());

        $validated['duration_rules'] = BookingSettingRules::durationRules((array) $request->input('duration_rules', []), atField: false);
```
- Die private Methode `durationRules()` samt Docblock löschen.

Die Grenzen für Erinnerung, Feedback und Erstattung zieht 2c mit den Seiten „Benachrichtigungen“ und „Anzahlungen“ in die Einstellungen.

- [ ] **Step 8: Bestehende Tests der Sammelseite**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='DurationRulesTest|SettingsFormErrorsTest|BookingRuleModificationDeadlineTest|FeedbackSettingsTest|GuestAddressTest|EmailConfirmationRequiredTest|LargeGroupNoticeTest|OwnerNotificationTest|OnboardingTest'
vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: alles grün, PHPStan ohne Fehler.

- [ ] **Step 9: Commit**

```bash
git add config/swayy.php .env.example app/Support/BookingSettingRules.php app/Http/Controllers/Admin/SettingsController.php tests/Feature/BookingSettingRulesTest.php
git commit -m "feat(settings): Grenzen der Buchungseinstellungen, Tische und Leistungen in config/swayy.php

BookingSettingRules bündelt die Prüfregeln für Kapazität & Dauer und
Buchungsregeln; die alte Sammelseite nutzt dieselben Regeln. Fehler einer
Tischzeit nennen das Feld der Zeile.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Seite „Kapazität & Dauer“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/CapacityController.php`, `resources/views/admin/settings/capacity.blade.php`, `resources/views/admin/settings/partials/duration-rule.blade.php`, `tests/Feature/CapacityPageTest.php`
- Modify: `routes/web.php`, `config/navigation.php` (Eintrag `capacity`), `app/Models/AuditLog.php` (`ACTION_LABELS`)

**Interfaces:**
- Consumes: `BookingSettingRules` (Task 3); `x-save-bar`, `x-checkbox`, `x-change-dot`, `x-field :minutes`, `data-rows`, `data-show-if` (Task 2); `RespondsToSettingsForms`.
- Produces: Routen `admin.settings.capacity.show` (GET `/admin/settings/capacity`) und `admin.settings.capacity` (PUT); Sprungziele `#takt`, `#tischzeiten`, `#kapazitaet`; Protokollaktion `location.capacity_updated`; Teilansicht `admin.settings.partials.duration-rule` mit `$key`, `$rule`, `$limits`.

- [ ] **Step 1: Failing test – `tests/Feature/CapacityPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class CapacityPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function restaurant(array $override = []): array
    {
        return array_merge([
            'slot_interval_minutes' => 15,
            'default_duration_minutes' => 105,
            'buffer_minutes' => 10,
            'capacity_mode' => 'hybrid',
            'max_covers_per_slot' => 60,
            'duration_rules' => [
                'n1' => ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
                0 => ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
            ],
        ], $override);
    }

    public function test_owner_sees_the_page_with_its_sections(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'duration_rules' => json_encode([['min_party' => 1, 'max_party' => 2, 'duration' => 75]]),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/capacity')->assertOk();

        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('Gilt für Standort')
            ->assertSee('data-ajax data-savebar', false)
            ->assertSee('id="takt"', false)
            ->assertSee('id="tischzeiten"', false)
            ->assertSee('id="kapazitaet"', false)
            ->assertSee('name="duration_rules[0][min_party]"', false)
            ->assertSee('data-rows-template', false)
            ->assertSee('name="duration_rules[__INDEX__][duration]"', false)
            ->assertSee('<option value="15"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="capacity"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_roles_without_settings_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/capacity')->assertForbidden();
        $this->actingAs($host)->putJson('/admin/settings/capacity', $this->restaurant())->assertForbidden();
    }

    public function test_saving_writes_only_capacity_fields(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'min_lead_minutes' => 45,
            'cancellation_deadline_minutes' => 300,
            'reminder_enabled' => false,
            'refund_mode' => 'manual',
            'confetti_on_booking' => false,
            'public_floorplan_enabled' => true,
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant())
            ->assertOk()
            ->assertExactJson(['message' => 'Kapazität und Dauer gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame(15, $settings->slot_interval_minutes);
        $this->assertSame(105, $settings->default_duration_minutes);
        $this->assertSame(10, $settings->buffer_minutes);
        $this->assertSame('hybrid', $settings->capacity_mode);
        $this->assertSame(60, $settings->max_covers_per_slot);
        $this->assertSame([
            ['min_party' => 1, 'max_party' => 2, 'duration' => 75],
            ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
        ], $settings->duration_rules);
        $this->assertSame(45, $settings->min_lead_minutes);
        $this->assertSame(300, $settings->cancellation_deadline_minutes);
        $this->assertFalse($settings->reminder_enabled);
        $this->assertSame('manual', $settings->refund_mode);
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertTrue($settings->public_floorplan_enabled);
        $this->assertTrue(AuditLog::where('action', 'location.capacity_updated')->exists());
    }

    public function test_a_missing_field_is_named(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->restaurant();
        unset($payload['capacity_mode']);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.capacity_mode.0', 'Zählweise ist ein Pflichtfeld.');
    }

    public function test_an_overlapping_rule_points_to_its_row(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant([
            'duration_rules' => [
                0 => ['min_party' => 1, 'max_party' => 4, 'duration' => 90],
                'n1' => ['min_party' => 3, 'max_party' => 6, 'duration' => 120],
            ],
        ]))->assertStatus(422);

        $this->assertSame(
            'Die Gruppengrößen überschneiden sich: „1–4“ und „3–6“. Jede Gruppengröße darf nur in einer Regel vorkommen.',
            $response->json('errors')['duration_rules.n1.min_party'][0] ?? null,
        );
    }

    public function test_salons_save_interval_buffer_and_gap_optimisation_only(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update([
            'default_duration_minutes' => 120,
            'capacity_mode' => 'table',
            'duration_rules' => json_encode([['min_party' => 1, 'max_party' => 2, 'duration' => 75]]),
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/capacity')
            ->assertOk()
            ->assertSee('name="gap_optimization_enabled"', false)
            ->assertDontSee('name="capacity_mode"', false)
            ->assertDontSee('name="default_duration_minutes"', false);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', [
            'slot_interval_minutes' => 30,
            'buffer_minutes' => 15,
            'gap_optimization_enabled' => '1',
        ])->assertOk();

        $settings = $setup['location']->settings()->first();
        $this->assertTrue($settings->gap_optimization_enabled);
        $this->assertSame(15, $settings->buffer_minutes);
        $this->assertSame(120, $settings->default_duration_minutes);
        $this->assertSame('table', $settings->capacity_mode);
        $this->assertCount(1, $settings->duration_rules);
    }

    public function test_limits_come_from_the_settings(): void
    {
        config(['swayy.booking.buffer_max' => 30]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/capacity')->assertOk()->assertSee('max="30"', false);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant(['buffer_minutes' => 45]))
            ->assertStatus(422)
            ->assertJsonPath('errors.buffer_minutes.0', 'Puffer nach jeder Buchung darf höchstens 30 sein.');
    }

    public function test_a_stored_interval_outside_the_list_stays_selectable(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['slot_interval_minutes' => 20]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/capacity')
            ->assertOk()
            ->assertSee('<option value="20" selected', false);

        $this->actingAs($owner)->putJson('/admin/settings/capacity', $this->restaurant(['slot_interval_minutes' => 20]))
            ->assertOk();
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=CapacityPageTest`
Expected: FAIL (404 auf `/admin/settings/capacity`).

- [ ] **Step 3: Controller anlegen – `app/Http/Controllers/Admin/Settings/CapacityController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\BookingSettingRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Kapazität & Dauer: Takt der Uhrzeiten,
 * Dauer eines Besuchs, Puffer, Tischzeiten je Gruppengröße und wie Swayy die
 * Plätze zählt. Salons stellen Takt, Puffer und Lückenoptimierung ein; Dauer
 * und Plätze ergeben sich dort aus Leistungen und Personen. Gilt für den
 * gewählten Standort.
 */
class CapacityController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $settings = $location->effectiveSettings();

        return view('admin.settings.capacity', [
            'settings' => $settings,
            'salon' => $this->context->tenant()->isSalon(),
            'intervals' => BookingSettingRules::slotIntervals($settings),
            'limits' => (array) config('swayy.booking'),
            'durationRules' => (array) ($settings->duration_rules ?? []),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $salon = $this->context->tenant()->isSalon();
        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);

        $validated = $request->validate(
            BookingSettingRules::capacity($settings, $salon),
            BookingSettingRules::messages(),
            BookingSettingRules::attributes(),
        );

        $values = [
            'slot_interval_minutes' => (int) $validated['slot_interval_minutes'],
            'buffer_minutes' => (int) $validated['buffer_minutes'],
        ];
        if ($salon) {
            $values['gap_optimization_enabled'] = $request->boolean('gap_optimization_enabled');
        } else {
            $values += [
                'default_duration_minutes' => (int) $validated['default_duration_minutes'],
                'capacity_mode' => $validated['capacity_mode'],
                'max_covers_per_slot' => isset($validated['max_covers_per_slot']) ? (int) $validated['max_covers_per_slot'] : null,
                'duration_rules' => BookingSettingRules::durationRules((array) $request->input('duration_rules', [])),
            ];
        }

        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('location.capacity_updated', $settings, $old, $values);

        return $this->saved($request, __('Kapazität und Dauer gespeichert.'));
    }
}
```

- [ ] **Step 4: Ansichten anlegen**

`resources/views/admin/settings/partials/duration-rule.blade.php`:
```blade
{{-- Eine Tischzeit-Regel auf „Kapazität & Dauer“ (Zeilen: resources/js/admin/rows.js).
     $key ist der Schlüssel der Zeile, in der Vorlage __INDEX__; $limits kommt aus der Seite. --}}
@php($p = 'rule-'.$key)
<div data-row data-change-item="{{ $p }}" class="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-lg border border-stone-200 p-3">
    <x-field :name="'duration_rules['.$key.'][min_party]'" :id="$p.'-min'" label="Ab Personen" type="number" class="w-28"
             :value="$rule['min_party'] ?? ''" min="1" :max="$limits['rule_party_max']" />
    <x-field :name="'duration_rules['.$key.'][max_party]'" :id="$p.'-max'" label="Bis Personen" type="number" class="w-28"
             :value="$rule['max_party'] ?? ''" min="1" :max="$limits['rule_party_max']" />
    <x-field :name="'duration_rules['.$key.'][duration]'" :id="$p.'-duration'" label="Tischzeit" type="number" unit="Min." class="w-36"
             :value="$rule['duration'] ?? ''" :min="$limits['rule_duration_min']" :max="$limits['rule_duration_max']" />
    <div class="flex items-center gap-2 pb-1">
        <x-change-dot />
        <button type="button" data-rows-remove class="btn btn-quiet-danger">Entfernen<span class="sr-only"> diese Tischzeit</span></button>
    </div>
</div>
```

`resources/views/admin/settings/capacity.blade.php`:
```blade
@extends('layouts.admin')
@section('title', 'Kapazität & Dauer')

@section('content')
<x-save-bar />
<x-page-header title="Kapazität & Dauer" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               :description="$salon
                   ? 'In welchem Takt Gäste Termine wählen und wie viel Zeit nach jedem Termin frei bleibt.'
                   : 'In welchem Takt Gäste Uhrzeiten wählen, wie lange ein Besuch dauert und wie Swayy die Plätze zählt.'" />

<form id="formCapacity" method="POST" action="{{ route('admin.settings.capacity') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')

    <x-settings.section id="takt" title="Takt und Dauer">
        <x-slot:description>
            <p>Der Takt bestimmt, welche Uhrzeiten die Buchungsseite anbietet: bei 30 Minuten etwa 18:00, 18:30 und 19:00.</p>
            @unless($salon)
                <p>Die Standarddauer gilt für jede Buchung, zu der unten keine Tischzeit passt.</p>
            @endunless
            <p>Der Puffer hält nach jeder Buchung Zeit frei, etwa zum Abräumen und Eindecken.</p>
        </x-slot:description>
        <x-field name="slot_interval_minutes" label="Takt der Uhrzeiten" required class="max-w-48">
            <div class="flex items-stretch">
                <select id="f-slot_interval_minutes" name="slot_interval_minutes" required aria-describedby="f-slot_interval_minutes-unit" class="w-full min-w-0 rounded-r-none">
                    @foreach($intervals as $interval)
                        <option value="{{ $interval }}" @selected((int) $settings->slot_interval_minutes === $interval)>{{ $interval }}</option>
                    @endforeach
                </select>
                <span id="f-slot_interval_minutes-unit" class="inline-flex items-center rounded-r border border-l-0 border-stone-300 bg-stone-50 px-3 text-sm text-stone-600">Min.</span>
            </div>
        </x-field>
        @unless($salon)
            <x-field name="default_duration_minutes" label="Standarddauer" type="number" unit="Min." :minutes="true" required class="max-w-48"
                     :value="$settings->default_duration_minutes" :min="$limits['duration_min']" :max="$limits['duration_max']"
                     help="So lange ist ein Tisch je Buchung belegt." />
        @endunless
        <x-field name="buffer_minutes" label="Puffer nach jeder Buchung" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->buffer_minutes" min="0" :max="$limits['buffer_max']" />
        @if($salon)
            <x-checkbox name="gap_optimization_enabled" label="Lückenoptimierung" :checked="(bool) $settings->gap_optimization_enabled"
                        help="Lässt ein Gast die Wahl der Person offen, wählt Swayy die Person, deren Kalender dadurch am dichtesten wird." />
        @endif
    </x-settings.section>

    @unless($salon)
        <x-settings.section id="tischzeiten" title="Tischzeiten je Gruppengröße">
            <x-slot:description>
                <p>Große Runden bleiben länger als Paare. Eine Regel legt fest, wie lange der Tisch für eine Gruppengröße belegt ist; für alle übrigen gilt die Standarddauer.</p>
                <p>Beispiel: 1–2 Personen 75 Minuten, 3–5 Personen 105 Minuten, 6–20 Personen 150 Minuten.</p>
            </x-slot:description>
            <div data-rows data-rows-max="{{ $limits['duration_rules_max'] }}" class="space-y-3">
                <div data-rows-list class="space-y-3">
                    @foreach($durationRules as $key => $rule)
                        @include('admin.settings.partials.duration-rule', ['key' => $key, 'rule' => (array) $rule])
                    @endforeach
                </div>
                <template data-rows-template>
                    @include('admin.settings.partials.duration-rule', ['key' => '__INDEX__', 'rule' => []])
                </template>
                <button type="button" data-rows-add class="btn btn-secondary">Tischzeit hinzufügen</button>
                <p class="text-[0.8125rem] text-stone-600">Jede Gruppengröße gehört zu höchstens einer Regel, bis zu {{ $limits['duration_rules_max'] }} Regeln.</p>
            </div>
        </x-settings.section>

        <x-settings.section id="kapazitaet" title="Wie Swayy die Plätze zählt">
            <x-slot:description>
                <p>Nach Tischen: Eine Buchung braucht einen freien Tisch oder eine Kombination, die zur Gruppe passt.</p>
                <p>Nach Personen: Swayy zählt die Gäste, die gleichzeitig da sind, bis zur Höchstzahl.</p>
                <p>Beides: Es braucht einen passenden Tisch, und die Höchstzahl gilt zusätzlich.</p>
            </x-slot:description>
            <fieldset class="grid gap-3 sm:grid-cols-3">
                <legend class="mb-2 text-sm font-semibold text-stone-800">Zählweise</legend>
                @foreach([
                    'table' => ['Nach Tischen', 'Für Betriebe mit festen Tischen.'],
                    'person' => ['Nach Personen', 'Für Plätze ohne feste Tische, etwa an einer Bar.'],
                    'hybrid' => ['Beides', 'Tische und eine Höchstzahl für den ganzen Standort.'],
                ] as $mode => [$modeLabel, $modeSummary])
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                        <input type="radio" name="capacity_mode" value="{{ $mode }}" @checked($settings->capacity_mode === $mode) class="mt-1">
                        <span class="min-w-0 [overflow-wrap:anywhere]">
                            <span class="block font-semibold">{{ $modeLabel }}</span>
                            <span class="block text-[0.8125rem] text-stone-500">{{ $modeSummary }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
            <div data-show-if="capacity_mode:person,hybrid" @unless(in_array($settings->capacity_mode, ['person', 'hybrid'], true)) hidden @endunless>
                <x-field name="max_covers_per_slot" label="Höchstens gleichzeitig" type="number" unit="Gäste" class="max-w-56"
                         :value="$settings->max_covers_per_slot" min="1" :max="$limits['covers_max']"
                         help="Ohne Eintrag gilt keine Höchstzahl." />
            </div>
        </x-settings.section>
    @endunless
</form>
@endsection
```

- [ ] **Step 5: Route, Navigation, Protokollbezeichnung**

`routes/web.php`: Import `use App\Http\Controllers\Admin\Settings\CapacityController;` bei den übrigen `Settings\…`-Imports alphabetisch einfügen. In der Gruppe `Route::middleware('permission:tables.manage')->group(...)` der Einstellungen nach der Route `settings.guest-mail` einfügen:
```php
        Route::get('/settings/capacity', [CapacityController::class, 'show'])
            ->middleware('permission:opening_hours.manage')->name('settings.capacity.show');
        Route::put('/settings/capacity', [CapacityController::class, 'update'])
            ->middleware('permission:opening_hours.manage')->name('settings.capacity');
```

`config/navigation.php`, Eintrag `capacity` ersetzen:
```php
            ['key' => 'capacity', 'label' => 'Kapazität & Dauer', 'route' => 'admin.settings.capacity.show', 'scope' => 'location', 'can' => ['tables.manage', 'opening_hours.manage'],
                'keywords' => ['Kapazität' => 'kapazitaet', 'Gäste gleichzeitig' => 'kapazitaet', 'Takt' => 'takt', 'Slot-Intervall' => 'takt', 'Besuchsdauer' => 'takt', 'Puffer' => 'takt', 'Tischzeit' => 'tischzeiten', 'Lückenoptimierung' => 'takt']],
```

`app/Models/AuditLog.php`, in `ACTION_LABELS` nach `'location.settings_updated'`:
```php
        'location.capacity_updated' => 'Standort: Kapazität und Dauer geändert',
```

- [ ] **Step 6: Tests laufen lassen**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='CapacityPageTest|NavigationTest|SettingsMenuTest|AuditLabelTest|AdminRoutesSmokeTest'
npm test
```
Expected: alles grün.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/Settings/CapacityController.php resources/views/admin/settings/capacity.blade.php resources/views/admin/settings/partials/duration-rule.blade.php routes/web.php config/navigation.php app/Models/AuditLog.php tests/Feature/CapacityPageTest.php
git commit -m "feat(settings): Seite „Kapazität & Dauer“ mit eigenem Speicherweg

Takt, Standarddauer, Puffer, Tischzeiten als Zeilen mit Punkt und Zähler,
Zählweise; Salons stellen Takt, Puffer und Lückenoptimierung ein.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Seite „Buchungsregeln“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/BookingRulesController.php`, `resources/views/admin/settings/rules.blade.php`, `tests/Feature/BookingRulesPageTest.php`
- Modify: `routes/web.php`, `config/navigation.php` (Eintrag `booking-rules`), `app/Models/AuditLog.php`

**Interfaces:**
- Consumes: `BookingSettingRules::booking()`, `::messages()`, `::attributes()` (Task 3); `x-checkbox`, `x-field :minutes` (Task 2); `LocationSettings::freigabeVonHand()`.
- Produces: Routen `admin.settings.rules.show` (GET `/admin/settings/rules`) und `admin.settings.rules` (PUT); Sprungziele `#zeitraum`, `#gruppen`, `#fristen`, `#bestaetigung`, `#warteliste`; Protokollaktion `location.booking_rules_updated`.

- [ ] **Step 1: Failing test – `tests/Feature/BookingRulesPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingRulesPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function rules(array $override = []): array
    {
        return array_merge([
            'min_lead_minutes' => 90,
            'max_advance_days' => 60,
            'min_party_online' => 2,
            'max_party_online' => 12,
            'large_group_email' => 'gruppen@beispiel.test',
            'cancellation_deadline_minutes' => 1440,
            'modification_deadline_minutes' => 240,
            'booking_confirmation_mode' => 'manual',
            'require_email_confirmation' => '1',
            'waitlist_enabled' => '0',
            'walkins_enabled' => '1',
        ], $override);
    }

    public function test_owner_sees_the_page_with_its_sections(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['auto_confirm' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/rules')->assertOk();

        $response->assertSee('Einstellungen › Buchungen')
            ->assertSee('id="zeitraum"', false)
            ->assertSee('id="fristen"', false)
            ->assertSee('id="bestaetigung"', false)
            ->assertSee('<input type="hidden" name="waitlist_enabled" value="0">', false)
            ->assertSee('data-minutes-hint', false);
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/name="booking_confirmation_mode" value="manual" checked/', $html);
        $this->assertMatchesRegularExpression('/data-nav-key="booking-rules"[^>]*aria-current="page"/', $html);
    }

    public function test_roles_without_settings_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($staff)->get('/admin/settings/rules')->assertForbidden();
        $this->actingAs($staff)->putJson('/admin/settings/rules', $this->rules())->assertForbidden();
    }

    public function test_saving_writes_only_booking_rules(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update([
            'buffer_minutes' => 25,
            'capacity_mode' => 'person',
            'reminder_hours_before' => 6,
            'confetti_on_booking' => false,
            'guest_address' => 'du',
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules())
            ->assertOk()
            ->assertExactJson(['message' => 'Buchungsregeln gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame(90, $settings->min_lead_minutes);
        $this->assertSame(60, $settings->max_advance_days);
        $this->assertSame(2, $settings->min_party_online);
        $this->assertSame(12, $settings->max_party_online);
        $this->assertSame('gruppen@beispiel.test', $settings->large_group_email);
        $this->assertSame(1440, $settings->cancellation_deadline_minutes);
        $this->assertSame(240, $settings->modification_deadline_minutes);
        $this->assertFalse($settings->auto_confirm);
        $this->assertFalse($settings->request_only);
        $this->assertTrue($settings->require_email_confirmation);
        $this->assertFalse($settings->waitlist_enabled);
        $this->assertTrue($settings->walkins_enabled);
        $this->assertSame(25, $settings->buffer_minutes);
        $this->assertSame('person', $settings->capacity_mode);
        $this->assertSame(6, $settings->reminder_hours_before);
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertSame('du', $settings->guest_address);
        $this->assertTrue(AuditLog::where('action', 'location.booking_rules_updated')->exists());
    }

    public function test_a_missing_switch_is_an_error(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $payload = $this->rules();
        unset($payload['waitlist_enabled']);

        $this->actingAs($owner)->putJson('/admin/settings/rules', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors.waitlist_enabled.0', 'Warteliste anbieten ist ein Pflichtfeld.');
    }

    public function test_the_largest_group_must_not_be_smaller_than_the_smallest(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules(['min_party_online' => 6, 'max_party_online' => 4]))
            ->assertStatus(422)
            ->assertJsonPath('errors.max_party_online.0', 'Die größte Gruppe ist kleiner als die kleinste. Bitte bei „Größte Gruppe“ mindestens 6 eintragen.');
    }

    public function test_limits_come_from_the_settings(): void
    {
        config(['swayy.booking.advance_max_days' => 30]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/rules', $this->rules(['max_advance_days' => 60]))
            ->assertStatus(422)
            ->assertJsonPath('errors.max_advance_days.0', 'Vorausbuchung darf höchstens 30 sein.');
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=BookingRulesPageTest`
Expected: FAIL (404).

- [ ] **Step 3: Controller anlegen – `app/Http/Controllers/Admin/Settings/BookingRulesController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\BookingSettingRules;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Buchungsregeln: Vorlauf, Vorausbuchung,
 * Gruppengröße online, Fristen für Gäste, Bestätigung, Warteliste und
 * Walk-ins. Gilt für den gewählten Standort.
 */
class BookingRulesController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        return view('admin.settings.rules', [
            'settings' => $location->effectiveSettings(),
            'salon' => $this->context->tenant()->isSalon(),
            'limits' => (array) config('swayy.booking'),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(
            BookingSettingRules::booking(),
            BookingSettingRules::messages(),
            BookingSettingRules::attributes(),
        );

        $values = [
            'min_lead_minutes' => (int) $validated['min_lead_minutes'],
            'max_advance_days' => (int) $validated['max_advance_days'],
            'min_party_online' => (int) $validated['min_party_online'],
            'max_party_online' => (int) $validated['max_party_online'],
            'large_group_email' => ($validated['large_group_email'] ?? null) ?: null,
            'cancellation_deadline_minutes' => (int) $validated['cancellation_deadline_minutes'],
            'modification_deadline_minutes' => (int) $validated['modification_deadline_minutes'],
            'auto_confirm' => $validated['booking_confirmation_mode'] === 'auto',
            'request_only' => false,
            'require_email_confirmation' => $request->boolean('require_email_confirmation'),
            'waitlist_enabled' => $request->boolean('waitlist_enabled'),
            'walkins_enabled' => $request->boolean('walkins_enabled'),
        ];

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('location.booking_rules_updated', $settings, $old, $values);

        return $this->saved($request, __('Buchungsregeln gespeichert.'));
    }
}
```

- [ ] **Step 4: Ansicht anlegen – `resources/views/admin/settings/rules.blade.php`**

```blade
@extends('layouts.admin')
@section('title', 'Buchungsregeln')

@section('content')
<x-save-bar />
<x-page-header title="Buchungsregeln" :crumb="['Einstellungen', 'Buchungen']" scope="location"
               description="Wann und in welcher Gruppengröße Gäste online buchen, welche Fristen gelten und wie Buchungen bestätigt werden." />

<form id="formRules" method="POST" action="{{ route('admin.settings.rules') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')

    <x-settings.section id="zeitraum" title="Wann Gäste buchen können">
        <x-slot:description>
            <p>Der Mindestvorlauf gibt dir Zeit zur Vorbereitung: Bei 60 Minuten bucht um 18:30 niemand mehr für 19:00.</p>
        </x-slot:description>
        <x-field name="min_lead_minutes" label="Mindestvorlauf" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->min_lead_minutes" min="0" :max="$limits['lead_max_minutes']"
                 help="So lange vor der Uhrzeit muss eine Buchung spätestens eingehen." />
        <x-field name="max_advance_days" label="Vorausbuchung" type="number" unit="Tage" required class="max-w-48"
                 :value="$settings->max_advance_days" min="1" :max="$limits['advance_max_days']"
                 help="So weit im Voraus zeigt die Buchungsseite freie Tage." />
    </x-settings.section>

    <x-settings.section id="gruppen" title="Gruppengröße online">
        <x-slot:description>
            <p>Gruppen über der größten Zahl sehen auf der Buchungsseite die Adresse, an die sie schreiben können.</p>
        </x-slot:description>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-field name="min_party_online" label="Kleinste Gruppe" type="number" unit="Pers." required
                     :value="$settings->min_party_online" min="1" :max="$limits['party_online_max']" />
            <x-field name="max_party_online" label="Größte Gruppe" type="number" unit="Pers." required
                     :value="$settings->max_party_online" min="1" :max="$limits['party_online_max']" />
        </div>
        <x-field name="large_group_email" label="Adresse für größere Gruppen" type="email" maxlength="200"
                 :value="$settings->large_group_email" placeholder="gruppen@dein-betrieb.de"
                 help="Ohne Adresse entfällt der Hinweis auf der Buchungsseite." />
    </x-settings.section>

    <x-settings.section id="fristen" title="Fristen für Gäste">
        <x-slot:description>
            <p>Bis zur Frist storniert oder verschiebt ein Gast selbst, über den Link in seiner Bestätigung. Danach meldet er sich bei dir.</p>
        </x-slot:description>
        <x-field name="cancellation_deadline_minutes" label="Stornofrist" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->cancellation_deadline_minutes" min="0" :max="$limits['deadline_max_minutes']"
                 help="So lange vor der Uhrzeit endet das Stornieren über den Link." />
        <x-field name="modification_deadline_minutes" label="Umbuchungsfrist" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->modification_deadline_minutes" min="0" :max="$limits['deadline_max_minutes']"
                 help="So lange vor der Uhrzeit endet das Umbuchen über den Link." />
    </x-settings.section>

    <x-settings.section id="bestaetigung" title="Bestätigung">
        <x-slot:description>
            <p>Beim Freigeben von Hand landet jede Online-Buchung als Anfrage im Reservierungsbuch. Der Gast bekommt zuerst eine Eingangsbestätigung und die Zusage, sobald du freigibst.</p>
        </x-slot:description>
        <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-semibold text-stone-800">Online-Buchungen</legend>
            @foreach([
                'auto' => ['Sofort bestätigen', 'Der Gast bekommt die Zusage direkt nach dem Buchen.'],
                'manual' => ['Von Hand freigeben', 'Du prüfst jede Buchung und gibst sie frei oder lehnst sie ab.'],
            ] as $mode => [$modeLabel, $modeSummary])
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                    <input type="radio" name="booking_confirmation_mode" value="{{ $mode }}" @checked(($settings->freigabeVonHand() ? 'manual' : 'auto') === $mode) class="mt-1">
                    <span class="min-w-0 [overflow-wrap:anywhere]">
                        <span class="block font-semibold">{{ $modeLabel }}</span>
                        <span class="block text-[0.8125rem] text-stone-500">{{ $modeSummary }}</span>
                    </span>
                </label>
            @endforeach
        </fieldset>
        <x-checkbox name="require_email_confirmation" label="E-Mail-Adresse bestätigen lassen" :checked="(bool) $settings->require_email_confirmation"
                    help="Eine Online-Buchung gilt erst, wenn der Gast den Link in seiner E-Mail öffnet. Das schützt vor falschen Adressen und Buchungen auf Vorrat." />
    </x-settings.section>

    <x-settings.section id="warteliste" title="Warteliste und Walk-ins">
        <x-checkbox name="waitlist_enabled" label="Warteliste anbieten" :checked="(bool) $settings->waitlist_enabled"
                    help="Ist alles belegt, tragen sich Gäste ein und bekommen ein Angebot, sobald ein Platz frei wird." />
        <x-checkbox name="walkins_enabled" label="Walk-ins erfassen" :checked="(bool) $settings->walkins_enabled"
                    :help="$salon ? 'Dein Team trägt Kundschaft, die spontan kommt, direkt ein.' : 'Dein Team setzt Gäste, die spontan kommen, im Live-Board an einen freien Tisch.'" />
    </x-settings.section>
</form>
@endsection
```

- [ ] **Step 5: Route, Navigation, Protokollbezeichnung**

`routes/web.php`: Import `use App\Http\Controllers\Admin\Settings\BookingRulesController;` alphabetisch einfügen. Nach den Routen von Task 4 einfügen:
```php
        Route::get('/settings/rules', [BookingRulesController::class, 'show'])
            ->middleware('permission:opening_hours.manage')->name('settings.rules.show');
        Route::put('/settings/rules', [BookingRulesController::class, 'update'])
            ->middleware('permission:opening_hours.manage')->name('settings.rules');
```

`config/navigation.php`, Eintrag `booking-rules` ersetzen:
```php
            ['key' => 'booking-rules', 'label' => 'Buchungsregeln', 'route' => 'admin.settings.rules.show', 'scope' => 'location', 'can' => ['tables.manage', 'opening_hours.manage'],
                'keywords' => ['Vorlauf' => 'zeitraum', 'Vorausbuchung' => 'zeitraum', 'Personen online' => 'gruppen', 'Große Gruppen' => 'gruppen', 'Stornofrist' => 'fristen', 'Umbuchungsfrist' => 'fristen', 'Bestätigung' => 'bestaetigung', 'E-Mail-Bestätigung' => 'bestaetigung', 'Warteliste' => 'warteliste', 'Walk-ins' => 'warteliste']],
```

`app/Models/AuditLog.php`, `ACTION_LABELS`:
```php
        'location.booking_rules_updated' => 'Standort: Buchungsregeln geändert',
```

- [ ] **Step 6: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='BookingRulesPageTest|NavigationTest|AuditLabelTest|AdminRoutesSmokeTest'`
Expected: alles grün.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/Settings/BookingRulesController.php resources/views/admin/settings/rules.blade.php routes/web.php config/navigation.php app/Models/AuditLog.php tests/Feature/BookingRulesPageTest.php
git commit -m "feat(settings): Seite „Buchungsregeln“ mit eigenem Speicherweg

Vorlauf, Vorausbuchung, Gruppengröße, Fristen mit Angabe in Stunden und
Tagen, Bestätigung als Auswahlkarten, Warteliste und Walk-ins.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Seite „Buchungsformular“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/BookingFormController.php`, `resources/views/admin/settings/booking-form.blade.php`, `tests/Feature/BookingFormPageTest.php`
- Modify: `routes/web.php`, `config/navigation.php` (Eintrag `booking-form`), `app/Http/Controllers/Admin/SettingsController.php` (`updateFieldRules` entfernen)
- Test: `tests/Feature/FieldRulesTest.php`, `tests/Feature/SettingsFormErrorsTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `x-save-bar one/many`, `x-change-dot`, `data-show-if` (Task 2); `LocationSettings::fieldRule()`.
- Produces: Route `admin.settings.booking-form.show` (GET `/admin/settings/booking-form`); `admin.settings.field-rules` (PUT `/admin/settings/field-rules`) zeigt auf `BookingFormController::update`; Konstanten `BookingFormController::FIELDS` und `::CHOICES`; Sprungziel `#felder`.

- [ ] **Step 1: Failing test – `tests/Feature/BookingFormPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Support\Navigation\Navigation;
use App\Support\Navigation\NavItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingFormPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_every_field_with_its_current_rule(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['field_rules' => json_encode(['phone' => 'hidden'])]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/booking-form')->assertOk();

        $response->assertSee('Einstellungen › Buchungen')
            ->assertSee('data-count-many=":count Felder geändert"', false)
            ->assertSee('data-change-item="feld-email"', false)
            ->assertSee('name="fields[allergies]"', false)
            ->assertSee('Den Namen fragt das Formular immer ab.');
        $html = (string) $response->getContent();
        $this->assertMatchesRegularExpression('/name="fields\[phone\]"[\s\S]*?<option value="hidden" selected>/', $html);
        $this->assertMatchesRegularExpression('/data-nav-key="booking-form"[^>]*aria-current="page"/', $html);
    }

    public function test_the_warning_appears_when_email_and_phone_are_hidden(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->getContent();
        $this->assertMatchesRegularExpression('/data-show-if="fields\[email\]:hidden;fields\[phone\]:hidden" role="note"\s+hidden/', $html);

        $setup['location']->settings()->update(['field_rules' => json_encode(['email' => 'hidden', 'phone' => 'hidden'])]);
        $html = (string) $this->actingAs($owner)->get('/admin/settings/booking-form')->getContent();
        $this->assertMatchesRegularExpression('/data-show-if="fields\[email\]:hidden;fields\[phone\]:hidden" role="note"\s+class=/', $html);
    }

    public function test_roles_without_business_settings_neither_see_nor_open_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/settings/booking-form')->assertForbidden();

        $keys = [];
        foreach (Navigation::fromConfig()->settings($manager, $setup['tenant'], $setup['location'], null) as $group) {
            array_push($keys, ...array_map(fn (NavItem $item) => $item->key, $group->items));
        }
        $this->assertNotContains('booking-form', $keys);
        $this->assertContains('booking-rules', $keys);
    }

    public function test_saving_stores_the_rules_and_answers_with_a_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/field-rules', ['fields' => [
            'email' => 'required', 'phone' => 'optional', 'occasion' => 'hidden', 'allergies' => 'required', 'note' => 'optional',
        ]])->assertOk()->assertExactJson(['message' => 'Formularfelder gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertSame('hidden', $settings->fieldRule('occasion'));
        $this->assertSame('required', $settings->fieldRule('allergies'));
        $this->assertTrue(AuditLog::where('action', 'location.field_rules_updated')->exists());
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=BookingFormPageTest`
Expected: FAIL (404).

- [ ] **Step 3: Controller anlegen – `app/Http/Controllers/Admin/Settings/BookingFormController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Buchungsformular: welche Felder Gäste beim
 * Buchen sehen und welche davon Pflicht sind. Den Namen fragt das Formular
 * immer ab. Gilt für den gewählten Standort.
 */
class BookingFormController extends Controller
{
    use RespondsToSettingsForms;

    /** Felder der Buchungsseite, die der Betrieb einstellt (LocationSettings::fieldRule). */
    public const FIELDS = ['email', 'phone', 'occasion', 'allergies', 'note'];

    /** @var array<string, string> Wert => Bezeichnung */
    public const CHOICES = ['hidden' => 'Ausgeblendet', 'optional' => 'Freiwillig', 'required' => 'Pflichtfeld'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        return view('admin.settings.booking-form', [
            'settings' => $location->effectiveSettings(),
            'fields' => self::FIELDS,
            'choices' => self::CHOICES,
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate(
            collect(self::FIELDS)
                ->mapWithKeys(fn (string $field) => ["fields.{$field}" => ['required', 'in:'.implode(',', array_keys(self::CHOICES))]])
                ->all()
        );

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->field_rules;
        $settings->update(['field_rules' => $validated['fields']]);

        $this->audit->log('location.field_rules_updated', $settings, ['field_rules' => $old], $validated['fields']);

        return $this->saved($request, __('Formularfelder gespeichert.'));
    }
}
```

- [ ] **Step 4: Ansicht anlegen – `resources/views/admin/settings/booking-form.blade.php`**

```blade
@extends('layouts.admin')
@section('title', 'Buchungsformular')

@section('content')
@php
    $texts = [
        'email' => ['E-Mail', 'Für Bestätigung, Erinnerung und den Link, mit dem Gäste ihre Buchung verwalten.'],
        'phone' => ['Telefon', 'Für Rückfragen und SMS-Erinnerungen.'],
        'occasion' => ['Anlass', 'Geburtstag, Jubiläum, Geschäftsessen: So kannst du dich vorbereiten.'],
        'allergies' => ['Allergien und Unverträglichkeiten', 'Hinweise für die Küche, bevor der Gast kommt.'],
        'note' => ['Anmerkung', 'Platz für Wünsche, etwa einen Tisch am Fenster.'],
    ];
    $bothHidden = $settings->fieldRule('email') === 'hidden' && $settings->fieldRule('phone') === 'hidden';
@endphp
<x-save-bar one="1 Feld geändert" many=":count Felder geändert" />
<x-page-header title="Buchungsformular" :crumb="['Einstellungen', 'Buchungen']" scope="location"
               description="Welche Angaben Gäste beim Buchen machen und welche davon Pflicht sind." />

<form id="formFields" method="POST" action="{{ route('admin.settings.field-rules') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')
    <x-settings.section id="felder" title="Felder">
        <x-slot:description>
            <p>Den Namen fragt das Formular immer ab. Für jedes weitere Feld wählst du, ob es erscheint und ob Gäste es ausfüllen müssen.</p>
        </x-slot:description>
        <ul class="divide-y divide-stone-200">
            @foreach($fields as $field)
                @php
                    $id = 'f-fields-'.$field;
                @endphp
                <li data-change-item="feld-{{ $field }}" class="grid gap-2 py-3 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center">
                    <div class="min-w-0">
                        <label for="{{ $id }}" class="flex items-center gap-2 text-sm font-semibold text-stone-800">
                            {{ $texts[$field][0] }}
                            <x-change-dot />
                        </label>
                        <p id="{{ $id }}-help" class="text-[0.8125rem] text-stone-600">{{ $texts[$field][1] }}</p>
                    </div>
                    <select id="{{ $id }}" name="fields[{{ $field }}]" aria-describedby="{{ $id }}-help" class="w-full">
                        @foreach($choices as $value => $choiceLabel)
                            <option value="{{ $value }}" @selected($settings->fieldRule($field) === $value)>{{ $choiceLabel }}</option>
                        @endforeach
                    </select>
                </li>
            @endforeach
        </ul>
        <p data-show-if="fields[email]:hidden;fields[phone]:hidden" role="note" @unless($bothHidden) hidden @endunless class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            Mit E-Mail und Telefon ausgeblendet bekommt der Gast keine Bestätigung, und Swayy ordnet die Buchung keinem Gästeprofil zu.
        </p>
    </x-settings.section>
</form>
@endsection
```

- [ ] **Step 5: Routen, Navigation, Sammelseite**

`routes/web.php`: Import `use App\Http\Controllers\Admin\Settings\BookingFormController;`. Die Route
```php
        Route::put('/settings/field-rules', [SettingsController::class, 'updateFieldRules'])
            ->middleware('permission:tenant.settings.manage')->name('settings.field-rules');
```
ersetzen durch
```php
        Route::get('/settings/booking-form', [BookingFormController::class, 'show'])
            ->middleware('permission:tenant.settings.manage')->name('settings.booking-form.show');
        Route::put('/settings/field-rules', [BookingFormController::class, 'update'])
            ->middleware('permission:tenant.settings.manage')->name('settings.field-rules');
```

`app/Http/Controllers/Admin/SettingsController.php`: Methode `updateFieldRules()` samt Docblock löschen (die alte Sammelseite sendet weiter an `admin.settings.field-rules`).

`config/navigation.php`, Eintrag `booking-form` ersetzen:
```php
            ['key' => 'booking-form', 'label' => 'Buchungsformular', 'route' => 'admin.settings.booking-form.show', 'scope' => 'location', 'can' => ['tables.manage', 'tenant.settings.manage'],
                'keywords' => ['Formularfelder' => 'felder', 'Pflichtfeld' => 'felder', 'Telefon' => 'felder', 'Anlass' => 'felder', 'Allergien' => 'felder']],
```

- [ ] **Step 6: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='BookingFormPageTest|FieldRulesTest|SettingsFormErrorsTest|NavigationTest|SettingsMenuTest|AdminRoutesSmokeTest'`
Expected: alles grün.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/Settings/BookingFormController.php app/Http/Controllers/Admin/SettingsController.php resources/views/admin/settings/booking-form.blade.php routes/web.php config/navigation.php tests/Feature/BookingFormPageTest.php
git commit -m "feat(settings): Seite „Buchungsformular“

Je Feld eine Zeile mit Punkt bei Änderung, Hinweis bei ausgeblendeter
E-Mail und ausgeblendetem Telefon; Recht wie die Route (tenant.settings.manage).

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Seite „Buchungsseite & Widget“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/BookingPageController.php`, `resources/views/admin/settings/booking-page.blade.php`, `resources/views/admin/settings/partials/widget-code.blade.php`, `resources/js/admin/widget.js`, `tests/js/widget.test.js`, `tests/Feature/BookingPageSettingsTest.php`
- Modify: `resources/js/admin/index.js`, `routes/web.php`, `config/navigation.php` (Eintrag `booking-page`), `app/Models/AuditLog.php`, `resources/views/admin/onboarding/index.blade.php` (Link „Website-Widget einrichten“)

**Interfaces:**
- Consumes: `x-checkbox`, `x-field` (Task 2), `copy.js` (`data-copy`, `data-copy-message`).
- Produces: Routen `admin.settings.booking-page.show` (GET `/admin/settings/booking-page`) und `admin.settings.booking-page` (PUT); `widget.js`: `attr(value): string`, `buildSnippets({ popupSrc, embedSrc, bookingUrl, label, color, float, linkLabel, linkColor }): { popup, inline, link }`, `initWidgetBuilder()`; Einstellungen am Element `[data-widget-builder]` (`data-popup-src`, `data-embed-src`, `data-booking-url`, `data-default-label`); Eingaben `[data-widget-input="label|color|float|link-label|link-color"]`, Ausgaben `[data-widget-snippet="popup|inline|link"]`, Kopierknöpfe `[data-widget-copy="…"]`; Sprungziele `#buchungsseite`, `#popup`, `#eingebettet`, `#direktlink`; Protokollaktion `location.booking_page_updated`.

- [ ] **Step 1: Failing test – `tests/js/widget.test.js` anlegen**

```js
import { describe, expect, it } from 'vitest';
import { buildSnippets, initWidgetBuilder } from '../../resources/js/admin/widget.js';
import { mount } from './helpers.js';

const sources = {
    popupSrc: 'https://swayy.test/widget/demo/popup.js',
    embedSrc: 'https://swayy.test/embed/demo.js',
    bookingUrl: 'https://swayy.test/book/demo',
};

describe('Code für die eigene Website', () => {
    it('baut Knopf, eingebettetes Formular und Link', () => {
        const code = buildSnippets({ ...sources, label: 'Tisch reservieren', color: '#0f766e', float: true, linkLabel: 'Jetzt reservieren', linkColor: '#123456' });

        expect(code.popup).toBe('<script src="https://swayy.test/widget/demo/popup.js" data-label="Tisch reservieren" data-color="#0f766e" data-float="1" defer></script>');
        expect(code.inline).toBe('<div id="swayy-widget"></div>\n<script src="https://swayy.test/embed/demo.js" defer></script>');
        expect(code.link).toContain('<a href="https://swayy.test/book/demo" target="_blank" rel="noopener"');
        expect(code.link).toContain('background:#123456;');
        expect(code.link.endsWith('\n  Jetzt reservieren\n</a>')).toBe(true);
    });

    it('maskiert Anführungszeichen und spitze Klammern im Text', () => {
        const code = buildSnippets({ ...sources, label: 'Tisch "jetzt" <b>', color: '#0f766e', float: false, linkLabel: 'Reservieren & <mehr>', linkColor: '#0f766e' });

        expect(code.popup).toContain('data-label="Tisch &quot;jetzt&quot; &lt;b&gt;"');
        expect(code.popup).not.toContain('data-float');
        expect(code.link).toContain('Reservieren &amp; &lt;mehr&gt;');
    });

    it('füllt Code und Kopierknopf und folgt den Eingaben', () => {
        mount(`
            <div data-widget-builder hidden data-popup-src="${sources.popupSrc}" data-embed-src="${sources.embedSrc}" data-booking-url="${sources.bookingUrl}" data-default-label="Jetzt reservieren"></div>
            <section>
                <input id="w-label" data-widget-input="label" value="">
                <input id="w-color" type="color" data-widget-input="color" value="#0f766e">
                <input id="w-float" type="checkbox" data-widget-input="float">
                <pre data-widget-snippet="popup"></pre>
                <button type="button" data-copy="" data-widget-copy="popup">Code kopieren</button>
            </section>`);
        initWidgetBuilder();

        const pre = document.querySelector('[data-widget-snippet="popup"]');
        const copy = document.querySelector('[data-widget-copy="popup"]');
        expect(pre.textContent).toContain('data-label="Jetzt reservieren"');
        expect(copy.dataset.copy).toBe(pre.textContent);

        const label = document.getElementById('w-label');
        label.value = 'Termin buchen';
        label.dispatchEvent(new Event('input', { bubbles: true }));

        expect(pre.textContent).toContain('data-label="Termin buchen"');
        expect(copy.dataset.copy).toBe(pre.textContent);
    });
});
```

- [ ] **Step 2: Test laufen lassen**

Run: `npm test -- tests/js/widget.test.js`
Expected: FAIL (Modul fehlt).

- [ ] **Step 3: `resources/js/admin/widget.js` anlegen und in `index.js` starten**

```js
// Code für die eigene Website (Einstellungen › Buchungsseite & Widget):
// Knopf mit Buchungsfenster, eingebettetes Formular und einfacher Link. Die
// Adressen stehen am Element [data-widget-builder]; Eingaben mit
// data-widget-input ändern den Code sofort, die Knöpfe „Code kopieren“
// (copy.js) bekommen den aktuellen Code in data-copy.

// Text für ein HTML-Attribut: &, ", < und > maskiert.
export function attr(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// Text zwischen zwei Tags.
function text(value) {
    return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

export function buildSnippets({ popupSrc, embedSrc, bookingUrl, label, color, float, linkLabel, linkColor }) {
    return {
        popup: `<script src="${attr(popupSrc)}" data-label="${attr(label)}" data-color="${attr(color)}"${float ? ' data-float="1"' : ''} defer></script>`,
        inline: `<div id="swayy-widget"></div>\n<script src="${attr(embedSrc)}" defer></script>`,
        link: `<a href="${attr(bookingUrl)}" target="_blank" rel="noopener"\n`
            + `   style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:${attr(linkColor)};color:#fff;border-radius:10px;font-family:inherit;font-size:15px;font-weight:600;text-decoration:none;">\n`
            + `  ${text(linkLabel)}\n</a>`,
    };
}

export function initWidgetBuilder() {
    const config = document.querySelector('[data-widget-builder]');
    if (!config) return;
    const input = (name) => document.querySelector(`[data-widget-input="${name}"]`);
    const fallback = config.dataset.defaultLabel || '';

    const render = () => {
        const snippets = buildSnippets({
            popupSrc: config.dataset.popupSrc,
            embedSrc: config.dataset.embedSrc,
            bookingUrl: config.dataset.bookingUrl,
            label: input('label')?.value.trim() || fallback,
            color: input('color')?.value || '',
            float: input('float')?.checked ?? false,
            linkLabel: input('link-label')?.value.trim() || fallback,
            linkColor: input('link-color')?.value || '',
        });
        Object.entries(snippets).forEach(([kind, code]) => {
            document.querySelectorAll(`[data-widget-snippet="${kind}"]`).forEach((el) => {
                el.textContent = code;
            });
            document.querySelectorAll(`[data-widget-copy="${kind}"]`).forEach((el) => {
                el.dataset.copy = code;
            });
        });
    };

    const onChange = (e) => {
        if (e.target instanceof Element && e.target.matches('[data-widget-input]')) render();
    };
    document.addEventListener('input', onChange);
    document.addEventListener('change', onChange);
    render();
}
```

In `resources/js/admin/index.js` den Import `import { initWidgetBuilder } from './widget.js';` alphabetisch ergänzen und in `initAdmin()` als letzte Zeile `initWidgetBuilder();` aufrufen.

- [ ] **Step 4: JS-Tests laufen lassen**

Run: `npm test -- tests/js/widget.test.js`
Expected: PASS, 3 Tests.

- [ ] **Step 5: Failing test – `tests/Feature/BookingPageSettingsTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TenantType;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BookingPageSettingsTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_the_address_switches_and_widget_sources(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['brand_primary_color' => '#aa3300']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $landing = route('booking.landing', $setup['tenant']->slug);

        $response = $this->actingAs($owner)->get('/admin/settings/booking-page')->assertOk();

        $response->assertSee('data-copy="'.$landing.'"', false)
            ->assertSee('data-popup-src="'.route('booking.widget.popup.single', $setup['tenant']->slug).'"', false)
            ->assertSee('data-embed-src="'.route('booking.embed.single', $setup['tenant']->slug).'"', false)
            ->assertSee('data-default-label="Jetzt reservieren"', false)
            ->assertSee('value="#aa3300"', false)
            ->assertSee('name="public_floorplan_enabled"', false)
            ->assertSee('name="confetti_on_booking"', false)
            ->assertSee('id="direktlink"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="booking-page"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_salons_get_their_own_label_and_no_floor_plan_switch(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertSee('data-default-label="Termin buchen"', false)
            ->assertDontSee('name="public_floorplan_enabled"', false);
    }

    public function test_a_location_without_online_booking_is_named(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->update(['online_booking_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/booking-page')
            ->assertOk()
            ->assertSee($setup['location']->name.' nimmt gerade keine Online-Buchungen an.');
    }

    public function test_roles_without_settings_rights_are_turned_away(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/booking-page')->assertForbidden();
    }

    public function test_saving_writes_only_the_two_switches(): void
    {
        $setup = $this->createTenantSetup();
        $setup['location']->settings()->update(['confetti_on_booking' => true, 'public_floorplan_enabled' => false, 'buffer_minutes' => 20, 'waitlist_enabled' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '0', 'public_floorplan_enabled' => '1'])
            ->assertOk()
            ->assertExactJson(['message' => 'Buchungsseite gespeichert.']);

        $settings = $setup['location']->settings()->first();
        $this->assertFalse($settings->confetti_on_booking);
        $this->assertTrue($settings->public_floorplan_enabled);
        $this->assertSame(20, $settings->buffer_minutes);
        $this->assertFalse($settings->waitlist_enabled);
        $this->assertTrue(AuditLog::where('action', 'location.booking_page_updated')->exists());
    }

    public function test_a_salon_saves_confetti_and_keeps_the_floor_plan_switch(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['location']->settings()->update(['public_floorplan_enabled' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['confetti_on_booking' => '1'])->assertOk();

        $this->assertTrue($setup['location']->settings()->first()->public_floorplan_enabled);
    }

    public function test_a_missing_switch_is_named(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson('/admin/settings/booking-page', ['public_floorplan_enabled' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.confetti_on_booking.0', 'Konfetti nach dem Buchen ist ein Pflichtfeld.');
    }

    public function test_the_setup_assistant_links_to_the_page(): void
    {
        $source = (string) file_get_contents(resource_path('views/admin/onboarding/index.blade.php'));

        $this->assertStringContainsString("route('admin.settings.booking-page.show') }}#popup", $source);
        $this->assertStringNotContainsString("route('admin.settings.index') }}#widget", $source);
    }
}
```

- [ ] **Step 6: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=BookingPageSettingsTest`
Expected: FAIL (404).

- [ ] **Step 7: Controller anlegen – `app/Http/Controllers/Admin/Settings/BookingPageController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Buchungsseite & Widget: Adresse der
 * Buchungsseite, Tischplan und Konfetti auf der Buchungsseite und der Code
 * für die eigene Website. Gilt für den gewählten Standort.
 */
class BookingPageController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function show(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $tenant = $this->context->tenant();

        // Mit einem buchbaren Standort reicht die kurze Adresse des Betriebs;
        // bei mehreren wählt der Gast dort zuerst den Standort.
        $bookable = $tenant->locations()->where('is_active', true)->where('online_booking_enabled', true)->count();
        $short = $location->slug === $tenant->slug || $bookable <= 1;

        return view('admin.settings.booking-page', [
            'location' => $location,
            'settings' => $location->effectiveSettings(),
            'salon' => $tenant->isSalon(),
            'acceptsBookings' => $location->is_active && $location->online_booking_enabled,
            'severalLocations' => $bookable > 1,
            'landingUrl' => route('booking.landing', $tenant->slug),
            'bookingUrl' => $bookable <= 1
                ? route('booking.landing', $tenant->slug)
                : route('booking.show', [$tenant->slug, $location->slug]),
            'embedSrc' => $short
                ? route('booking.embed.single', $tenant->slug)
                : route('booking.embed', [$tenant->slug, $location->slug]),
            'popupSrc' => $short
                ? route('booking.widget.popup.single', $tenant->slug)
                : route('booking.widget.popup', [$tenant->slug, $location->slug]),
            'brandColor' => $tenant->brand_primary_color ?: config('swayy.ui.default_brand_color'),
            'defaultLabel' => $tenant->isSalon() ? __('Termin buchen') : __('Jetzt reservieren'),
        ]);
    }

    public function update(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $rules = ['confetti_on_booking' => ['required', 'boolean']];
        if (! $this->context->tenant()->isSalon()) {
            $rules['public_floorplan_enabled'] = ['required', 'boolean'];
        }
        $request->validate($rules, [], [
            'confetti_on_booking' => __('Konfetti nach dem Buchen'),
            'public_floorplan_enabled' => __('Tischplan zeigen'),
        ]);

        $values = [];
        foreach (array_keys($rules) as $field) {
            $values[$field] = $request->boolean($field);
        }

        $settings = $location->settings()->firstOrCreate(['tenant_id' => $location->tenant_id]);
        $old = $settings->only(array_keys($values));
        $settings->update($values);

        $this->audit->log('location.booking_page_updated', $settings, $old, $values);

        return $this->saved($request, __('Buchungsseite gespeichert.'));
    }
}
```

- [ ] **Step 8: Ansichten anlegen**

`resources/views/admin/settings/partials/widget-code.blade.php`:
```blade
{{-- Code zum Kopieren; resources/js/admin/widget.js füllt ihn. $kind: popup, inline oder link. --}}
<div class="space-y-2">
    <pre data-widget-snippet="{{ $kind }}" class="max-h-56 overflow-auto whitespace-pre-wrap rounded-md bg-stone-50 p-3 text-xs text-stone-800 ring-1 ring-stone-200 [overflow-wrap:anywhere]"></pre>
    <button type="button" data-copy="" data-widget-copy="{{ $kind }}" data-copy-message="{{ $message }}" class="btn btn-secondary">Code kopieren</button>
</div>
```

`resources/views/admin/settings/booking-page.blade.php`:
```blade
@extends('layouts.admin')
@section('title', 'Buchungsseite & Widget')

@section('content')
<x-save-bar />
<x-page-header title="Buchungsseite & Widget" :crumb="['Einstellungen', 'Buchungen']" scope="location"
               description="Wie Gäste zu dir finden: über deine Buchungsseite oder einen Knopf auf deiner Website.">
    <x-slot:actions>
        <a href="{{ $bookingUrl }}" target="_blank" rel="noopener" class="btn btn-secondary">Buchungsseite ansehen<span class="sr-only"> (öffnet in einem neuen Tab)</span></a>
    </x-slot:actions>
</x-page-header>

<x-settings.section id="buchungsseite" title="Buchungsseite">
    <x-slot:description>
        <p>Diese Adresse teilst du überall: auf Instagram, bei Google oder in einer Mail.</p>
        @if($severalLocations)
            <p>Unter {{ $landingUrl }} wählen Gäste zuerst den Standort.</p>
        @endif
    </x-slot:description>
    @unless($acceptsBookings)
        <p role="note" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            {{ $location->name }} nimmt gerade keine Online-Buchungen an.
            @unless($location->is_active)
                Aktivieren kannst du den Standort unter <a href="{{ route('admin.locations.index') }}" class="font-semibold underline underline-offset-2">Standorte</a>.
            @endunless
        </p>
    @endunless
    <div class="flex flex-wrap items-center gap-2">
        <code class="min-w-0 flex-1 rounded-md bg-stone-50 px-3 py-2 text-sm text-stone-800 ring-1 ring-stone-200 [overflow-wrap:anywhere]">{{ $bookingUrl }}</code>
        <button type="button" data-copy="{{ $bookingUrl }}" data-copy-message="Adresse der Buchungsseite kopiert." class="btn btn-secondary">Adresse kopieren</button>
    </div>
    <form id="formBookingPage" method="POST" action="{{ route('admin.settings.booking-page') }}" data-ajax data-savebar class="space-y-4 border-t border-stone-200 pt-5">
        @csrf
        @method('PUT')
        @unless($salon)
            <x-checkbox name="public_floorplan_enabled" label="Tischplan zeigen" :checked="(bool) $settings->public_floorplan_enabled"
                        help="Gäste sehen den Grundriss und wählen ihren Tisch selbst." />
        @endunless
        <x-checkbox name="confetti_on_booking" label="Konfetti nach dem Buchen" :checked="(bool) $settings->confetti_on_booking"
                    help="Ein kurzer Konfettiregen auf der Bestätigungsseite." />
    </form>
</x-settings.section>

{{-- Einstellungen für resources/js/admin/widget.js --}}
<div data-widget-builder hidden data-popup-src="{{ $popupSrc }}" data-embed-src="{{ $embedSrc }}"
     data-booking-url="{{ $bookingUrl }}" data-default-label="{{ $defaultLabel }}"></div>

<x-settings.section id="popup" title="Knopf mit Buchungsfenster">
    <x-slot:description>
        <p>Ein Knopf auf deiner Website öffnet das Buchungsformular als Fenster über der Seite. Das passt zu jeder Website.</p>
        <p>Den Code fügst du vor dem schließenden &lt;/body&gt; ein, etwa in WordPress, Squarespace oder Webflow.</p>
    </x-slot:description>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="widget_label" id="w-label" label="Text auf dem Knopf" :value="$defaultLabel" maxlength="40" data-widget-input="label" />
        <x-field name="widget_color" id="w-color" label="Farbe" help="Vorgabe ist deine Markenfarbe.">
            <input id="w-color" type="color" value="{{ $brandColor }}" data-widget-input="color" aria-describedby="w-color-help"
                   class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
        </x-field>
    </div>
    <x-checkbox name="widget_float" id="w-float" label="Knopf schwebt unten rechts" help="Er bleibt beim Scrollen sichtbar." data-widget-input="float" />
    @include('admin.settings.partials.widget-code', ['kind' => 'popup', 'message' => 'Code für den Knopf kopiert.'])
</x-settings.section>

<x-settings.section id="eingebettet" title="Eingebettetes Formular">
    <x-slot:description>
        <p>Das Buchungsformular erscheint direkt auf deiner Seite, etwa auf einer Unterseite „Reservieren“.</p>
        <p>Es erscheint an der Stelle von &lt;div id="swayy-widget"&gt;.</p>
    </x-slot:description>
    @include('admin.settings.partials.widget-code', ['kind' => 'inline', 'message' => 'Code für das Formular kopiert.'])
</x-settings.section>

<x-settings.section id="direktlink" title="Einfacher Link">
    <x-slot:description>
        <p>Ein gestalteter Link zur Buchungsseite; reines HTML, das in jeden Baukasten passt.</p>
    </x-slot:description>
    <div class="grid gap-4 sm:grid-cols-2">
        <x-field name="widget_link_label" id="w-link-label" label="Text des Links" :value="$defaultLabel" maxlength="40" data-widget-input="link-label" />
        <x-field name="widget_link_color" id="w-link-color" label="Farbe">
            <input id="w-link-color" type="color" value="{{ $brandColor }}" data-widget-input="link-color"
                   class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
        </x-field>
    </div>
    @include('admin.settings.partials.widget-code', ['kind' => 'link', 'message' => 'Code für den Link kopiert.'])
</x-settings.section>
@endsection
```

- [ ] **Step 9: Routen, Navigation, Protokoll, Einrichtungsassistent**

`routes/web.php`: Import `use App\Http\Controllers\Admin\Settings\BookingPageController;`. Nach den Routen von Task 5:
```php
        Route::get('/settings/booking-page', [BookingPageController::class, 'show'])->name('settings.booking-page.show');
        Route::put('/settings/booking-page', [BookingPageController::class, 'update'])
            ->middleware('permission:opening_hours.manage')->name('settings.booking-page');
```

`config/navigation.php`, Eintrag `booking-page` ersetzen:
```php
            ['key' => 'booking-page', 'label' => 'Buchungsseite & Widget', 'route' => 'admin.settings.booking-page.show', 'scope' => 'location', 'can' => ['tables.manage'],
                'keywords' => ['Buchungsseite' => 'buchungsseite', 'Konfetti' => 'buchungsseite', 'Öffentlicher Tischplan' => 'buchungsseite', 'Website-Widget' => 'popup', 'Popup' => 'popup', 'Einbinden' => 'eingebettet', 'Direktlink' => 'direktlink']],
```

`app/Models/AuditLog.php`, `ACTION_LABELS`:
```php
        'location.booking_page_updated' => 'Standort: Buchungsseite geändert',
```

`resources/views/admin/onboarding/index.blade.php`: In der Zeile mit „Website-Widget einrichten“ den Link
```blade
<a href="{{ route('admin.settings.index') }}#widget"
```
ersetzen durch
```blade
<a href="{{ route('admin.settings.booking-page.show') }}#popup"
```

- [ ] **Step 10: Tests laufen lassen**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='BookingPageSettingsTest|NavigationTest|AuditLabelTest|AdminRoutesSmokeTest|OnboardingTest|WidgetBrandColorTest'
npm test
```
Expected: alles grün.

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/Admin/Settings/BookingPageController.php resources/views/admin/settings/booking-page.blade.php resources/views/admin/settings/partials/widget-code.blade.php resources/js/admin/widget.js resources/js/admin/index.js tests/js/widget.test.js tests/Feature/BookingPageSettingsTest.php routes/web.php config/navigation.php app/Models/AuditLog.php resources/views/admin/onboarding/index.blade.php
git commit -m "feat(settings): Seite „Buchungsseite & Widget“

Adresse zum Kopieren, Tischplan und Konfetti mit eigenem Speicherweg, Code
für Knopf, eingebettetes Formular und Link mit maskierten Texten.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Seite „Tags“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/TagSettingsController.php`, `resources/views/admin/settings/tags.blade.php`, `resources/views/admin/settings/partials/tag-fields.blade.php`, `tests/Feature/TagSettingsPageTest.php`
- Modify: `app/Http/Controllers/Admin/TagController.php`, `app/Models/Tag.php`, `routes/web.php`, `config/navigation.php` (Eintrag `tags`)
- Test: `tests/Feature/TagCrudTest.php`, `tests/Feature/CrudEditingTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `x-drawer`, `x-inline-confirm :reload`, `data-reset-after-save`, `data-reload-after-save` (Task 1); `x-empty-state`, `x-page-header`.
- Produces: Route `admin.settings.tags.show` (GET `/admin/settings/tags`); `Tag::reservations(): MorphToMany`; `TagController` antwortet bei `store`, `update`, `destroy` zusätzlich mit `message`; `store` mit vorhandenem Namen → Status 200 und Hinweis, neu → 201; `update` auf vorhandenen Namen → 422 mit Grund; Seitenleisten `tag-neu`, `tag-{id}`.

- [ ] **Step 1: Failing test – `tests/Feature/TagSettingsPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use App\Models\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TagSettingsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $setup
     */
    private function reservationWith(array $setup, Tag $tag): void
    {
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $reservation->tags()->attach($tag->id);
    }

    public function test_owner_sees_tags_with_their_use_and_drawers(): void
    {
        $setup = $this->createTenantSetup();
        $vip = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Gast-Tag', 'color' => '#334455', 'scope' => 'guest']);
        $this->reservationWith($setup, $vip);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/tags')->assertOk();

        $response->assertSee('Einstellungen › Buchungen')
            ->assertSee('Gilt für alle Standorte')
            ->assertSee('An 1 Reservierung')
            ->assertSee('data-drawer-open="tag-'.$vip->id.'"', false)
            ->assertSee('id="tag-neu" data-drawer hidden', false)
            ->assertSee('Tag „VIP“ löschen? Er verschwindet von 1 Reservierung.')
            ->assertSee('data-reload-after-save', false)
            ->assertDontSee('Gast-Tag');
        $this->assertMatchesRegularExpression('/data-nav-key="tags"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_an_empty_list_offers_one_action(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('Noch keine Tags');
    }

    public function test_the_page_needs_settings_rights_and_the_tag_picker_stays_open_for_the_team(): void
    {
        $setup = $this->createTenantSetup();
        $host = $this->createMember($setup['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($host)->get('/admin/settings/tags')->assertForbidden();
        $this->actingAs($host)->getJson('/admin/tags')->assertOk();
    }

    public function test_renaming_to_an_existing_name_explains_the_conflict(): void
    {
        $setup = $this->createTenantSetup();
        Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $other = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Stammgast', 'color' => '#334455', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/tags/{$other->id}", ['name' => 'VIP', 'color' => '#334455'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Einen Tag „VIP“ gibt es schon. Bitte einen anderen Namen wählen.');

        $this->assertSame('Stammgast', $other->fresh()->name);
    }

    public function test_creating_an_existing_name_takes_the_tag_and_says_so(): void
    {
        $setup = $this->createTenantSetup();
        $vip = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'VIP', 'color' => '#aa3300', 'scope' => 'reservation']);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'VIP', 'color' => '#00aa00'])
            ->assertOk()
            ->assertJsonPath('id', $vip->id)
            ->assertJsonPath('message', 'Einen Tag „VIP“ gab es schon; er hat jetzt die gewählte Farbe.');

        $this->assertSame('#00aa00', $vip->fresh()->color);
    }

    public function test_answers_keep_the_fields_of_the_tag_picker_and_add_a_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $created = $this->actingAs($owner)->postJson('/admin/tags', ['name' => 'Geburtstag', 'color' => '#aa3300'])
            ->assertCreated()
            ->assertJsonPath('name', 'Geburtstag')
            ->assertJsonPath('color', '#aa3300')
            ->assertJsonPath('message', 'Tag „Geburtstag“ angelegt.');
        $id = $created->json('id');

        $this->actingAs($owner)->putJson("/admin/tags/{$id}", ['name' => 'Jubiläum', 'color' => '#aa3300'])
            ->assertOk()
            ->assertJsonPath('name', 'Jubiläum')
            ->assertJsonPath('message', 'Tag „Jubiläum“ gespeichert.');

        $this->actingAs($owner)->deleteJson("/admin/tags/{$id}")
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Tag „Jubiläum“ gelöscht.');
    }

    public function test_a_system_tag_is_shown_without_actions_and_explains_the_refusal(): void
    {
        $setup = $this->createTenantSetup();
        $system = Tag::create(['tenant_id' => $setup['tenant']->id, 'name' => 'Allergie', 'color' => '#aa3300', 'scope' => 'both', 'is_system' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/settings/tags')
            ->assertOk()
            ->assertSee('fest eingerichtet')
            ->assertDontSee('data-drawer-open="tag-'.$system->id.'"', false);

        $this->actingAs($owner)->putJson("/admin/tags/{$system->id}", ['name' => 'X', 'color' => '#222222'])
            ->assertForbidden()
            ->assertJsonPath('message', 'Dieser Tag ist fest eingerichtet und lässt sich nicht ändern.');
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=TagSettingsPageTest`
Expected: FAIL (404, fehlende `message`, 500 beim Umbenennen).

- [ ] **Step 3: `app/Models/Tag.php` – Beziehung ergänzen**

Import `use Illuminate\Database\Eloquent\Relations\MorphToMany;` ergänzen und in der Klasse:
```php
    /** Reservierungen mit diesem Tag (Tabelle taggables). */
    public function reservations(): MorphToMany
    {
        return $this->morphedByMany(Reservation::class, 'taggable');
    }
```

- [ ] **Step 4: `app/Http/Controllers/Admin/TagController.php` ersetzen**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Tag;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Tags für Reservierungen. Die Seite Einstellungen › Buchungen › Tags und die
 * Tag-Auswahl in den Reservierungsdetails nutzen dieselben Wege: Die
 * Antworten tragen id, name und color für die Auswahl und message für die Seite.
 */
class TagController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): JsonResponse
    {
        $tags = Tag::where('tenant_id', $this->context->tenantId())
            ->whereIn('scope', ['reservation', 'both'])
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return response()->json($tags);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        // firstOrCreate fängt zwei gleichzeitige Anfragen mit demselben Namen
        // selbst ab (createOrFirst mit Savepoint): Die zweite bekommt den Tag
        // der ersten.
        $tag = Tag::firstOrCreate(
            ['tenant_id' => $this->context->tenantId(), 'name' => $validated['name'], 'scope' => 'reservation'],
            ['color' => $validated['color']]
        );

        if ($tag->wasRecentlyCreated) {
            $this->audit->log('tag.created', $tag);
        } else {
            $tag->update(['color' => $validated['color']]);
            $this->audit->log('tag.updated', $tag);
        }

        return response()->json([
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'message' => $tag->wasRecentlyCreated
                ? __('Tag „:name“ angelegt.', ['name' => $tag->name])
                : __('Einen Tag „:name“ gab es schon; er hat jetzt die gewählte Farbe.', ['name' => $tag->name]),
        ], $tag->wasRecentlyCreated ? 201 : 200);
    }

    public function update(Request $request, Tag $tag): mixed
    {
        abort_if($tag->tenant_id !== $this->context->tenantId(), 404);
        abort_if($tag->is_system, 403, __('Dieser Tag ist fest eingerichtet und lässt sich nicht ändern.'));

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:40'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ]);

        $taken = __('Einen Tag „:name“ gibt es schon. Bitte einen anderen Namen wählen.', ['name' => $validated['name']]);
        $exists = Tag::where('tenant_id', $tag->tenant_id)
            ->where('scope', $tag->scope)
            ->where('name', $validated['name'])
            ->whereKeyNot($tag->id)
            ->exists();
        if ($exists) {
            return $this->failed($request, 'name', $taken);
        }

        try {
            $tag->update(['name' => $validated['name'], 'color' => $validated['color']]);
        } catch (UniqueConstraintViolationException) {
            // Ein anderer Tag hat den Namen im selben Moment bekommen.
            return $this->failed($request, 'name', $taken);
        }
        $this->audit->log('tag.updated', $tag);

        return response()->json([
            'id' => $tag->id,
            'name' => $tag->name,
            'color' => $tag->color,
            'message' => __('Tag „:name“ gespeichert.', ['name' => $tag->name]),
        ]);
    }

    public function destroy(Tag $tag): JsonResponse
    {
        abort_if($tag->tenant_id !== $this->context->tenantId(), 404);
        abort_if($tag->is_system, 403, __('Dieser Tag ist fest eingerichtet und lässt sich nicht löschen.'));
        $name = $tag->name;
        $this->audit->log('tag.deleted', $tag, ['name' => $tag->name, 'color' => $tag->color]);
        $tag->delete();

        return response()->json(['ok' => true, 'message' => __('Tag „:name“ gelöscht.', ['name' => $name])]);
    }

    public function syncReservation(Request $request, Reservation $reservation): JsonResponse
    {
        abort_if($reservation->tenant_id !== $this->context->tenantId(), 404);

        $validated = $request->validate([
            'tag_ids' => ['present', 'array'],
            'tag_ids.*' => ['integer'],
        ]);

        $allowed = Tag::where('tenant_id', $this->context->tenantId())
            ->whereIn('id', $validated['tag_ids'])
            ->pluck('id');

        $reservation->tags()->sync($allowed);
        $this->audit->log('reservation.tags_updated', $reservation, null, ['tag_ids' => $allowed->toArray()]);

        return response()->json(['ok' => true, 'tag_ids' => $allowed->values()]);
    }
}
```

- [ ] **Step 5: Seite anlegen**

`app/Http/Controllers/Admin/Settings/TagSettingsController.php`:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * Einstellungen › Buchungen › Tags: farbige Markierungen für Reservierungen.
 * Gilt für alle Standorte. Anlegen, Ändern und Löschen laufen über
 * TagController (admin.tags.*), den auch die Reservierungsdetails nutzen.
 */
class TagSettingsController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        return view('admin.settings.tags', [
            'tags' => Tag::where('tenant_id', $tenant->id)
                ->whereIn('scope', ['reservation', 'both'])
                ->withCount('reservations')
                ->orderBy('name')
                ->get(),
            'defaultColor' => config('swayy.ui.default_label_color'),
        ]);
    }
}
```

`resources/views/admin/settings/partials/tag-fields.blade.php`:
```blade
{{-- Felder eines Tags in der Seitenleiste. $prefix macht die IDs je Seitenleiste eindeutig (neu oder ID des Tags). --}}
@php($p = 'tag-'.$prefix)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$tag?->name" required maxlength="40" placeholder="etwa VIP" />
<x-field name="color" label="Farbe" :id="$p.'-color'" help="Am besten eine kräftige Farbe, die auf Weiß gut zu sehen ist.">
    <input id="{{ $p }}-color" type="color" name="color" value="{{ $tag?->color ?? $defaultColor }}" aria-describedby="{{ $p }}-color-help"
           class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
</x-field>
```

`resources/views/admin/settings/tags.blade.php`:
```blade
@extends('layouts.admin')
@section('title', 'Tags')

@section('content')
<x-page-header title="Tags" :crumb="['Einstellungen', 'Buchungen']" scope="tenant"
               description="Farbige Markierungen für Reservierungen, etwa VIP, Geburtstag oder Allergie. Sie erscheinen in der Liste, im Tischplan und auf dem Live-Board.">
    <x-slot:actions>
        <button type="button" data-drawer-open="tag-neu" class="btn btn-primary">Tag hinzufügen</button>
    </x-slot:actions>
</x-page-header>

@if($tags->isEmpty())
    <x-empty-state title="Noch keine Tags">
        Mit Tags erkennt dein Team besondere Reservierungen auf einen Blick.
        <x-slot:action>
            <button type="button" data-drawer-open="tag-neu" class="btn btn-primary">Tag hinzufügen</button>
        </x-slot:action>
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($tags as $tag)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <span class="h-4 w-4 shrink-0 rounded-full ring-1 ring-stone-300" style="background-color: {{ $tag->color }}" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    @if($tag->is_system)
                        <p class="font-semibold [overflow-wrap:anywhere]">{{ $tag->name }}</p>
                    @else
                        <button type="button" data-drawer-open="tag-{{ $tag->id }}"
                                class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $tag->name }}</button>
                    @endif
                    <p class="text-[0.8125rem] text-stone-600">
                        {{ trans_choice('An :count Reservierung|An :count Reservierungen', $tag->reservations_count, ['count' => $tag->reservations_count]) }}@if($tag->is_system) · fest eingerichtet @endif
                    </p>
                </div>
                @unless($tag->is_system)
                    <x-inline-confirm id="tag-loeschen-{{ $tag->id }}" :action="route('admin.tags.destroy', $tag)" method="DELETE"
                                      label="Löschen" confirm="Tag löschen" :subject="$tag->name" :reload="true"
                                      :question="$tag->reservations_count > 0
                                          ? trans_choice('Tag „:name“ löschen? Er verschwindet von :count Reservierung.|Tag „:name“ löschen? Er verschwindet von :count Reservierungen.', $tag->reservations_count, ['name' => $tag->name, 'count' => $tag->reservations_count])
                                          : __('Tag „:name“ löschen?', ['name' => $tag->name])" />
                @endunless
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    <x-drawer id="tag-neu" title="Neuer Tag" description="Name und Farbe, wie sie an Reservierungen erscheinen.">
        <form id="form-tag-neu" method="POST" action="{{ route('admin.tags.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            @include('admin.settings.partials.tag-fields', ['tag' => null, 'prefix' => 'neu'])
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-tag-neu" class="btn btn-primary">Tag anlegen</button>
        </x-slot:footer>
    </x-drawer>
    @foreach($tags->reject(fn ($tag) => $tag->is_system) as $tag)
        <x-drawer id="tag-{{ $tag->id }}" :title="$tag->name" description="Änderungen gelten an allen Reservierungen mit diesem Tag.">
            <form id="form-tag-{{ $tag->id }}" method="POST" action="{{ route('admin.tags.update', $tag) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.settings.partials.tag-fields', ['tag' => $tag, 'prefix' => (string) $tag->id])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-tag-{{ $tag->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
```

- [ ] **Step 6: Route und Navigation**

`routes/web.php`: Import `use App\Http\Controllers\Admin\Settings\TagSettingsController;`. In der Einstellungsgruppe (`permission:tables.manage`) nach den Routen von Task 7:
```php
        Route::get('/settings/tags', TagSettingsController::class)->name('settings.tags.show');
```

`config/navigation.php`, Eintrag `tags` ersetzen:
```php
            ['key' => 'tags', 'label' => 'Tags', 'route' => 'admin.settings.tags.show', 'scope' => 'tenant', 'can' => ['tables.manage'],
                'keywords' => ['Tags', 'Markierung', 'VIP', 'Allergie', 'Neuer Tag' => 'tag-neu']],
```

- [ ] **Step 7: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='TagSettingsPageTest|TagCrudTest|CrudEditingTest|SettingsFormErrorsTest|NavigationTest|AuditLabelTest|AdminRoutesSmokeTest'`
Expected: alles grün.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/Settings/TagSettingsController.php app/Http/Controllers/Admin/TagController.php app/Models/Tag.php resources/views/admin/settings/tags.blade.php resources/views/admin/settings/partials/tag-fields.blade.php routes/web.php config/navigation.php tests/Feature/TagSettingsPageTest.php
git commit -m "feat(settings): Seite „Tags“ mit Seitenleiste und Rückfrage in der Zeile

Liste mit Zahl der Reservierungen je Tag; Umbenennen auf einen vorhandenen
Namen antwortet mit 422 und Grund; alle Antworten tragen eine Meldung.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Seite „Räume & Tische“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/RoomsController.php`, `app/Support/PlanLimitMessage.php`, `resources/views/admin/settings/rooms.blade.php`, `resources/views/admin/settings/partials/room-fields.blade.php`, `resources/views/admin/settings/partials/table-fields.blade.php`, `resources/views/admin/settings/partials/combination-fields.blade.php`, `tests/Feature/RoomsPageTest.php`
- Modify: `app/Http/Controllers/Admin/SettingsController.php` (Raum-, Tisch- und Kombinationswege entfernen), `app/Http/Controllers/Admin/FloorPlanController.php`, `resources/views/admin/settings/index.blade.php` (versteckte 0-Felder im Tischformular), `config/swayy.php` (`tables.room_size_max_m`), `.env.example`, `routes/web.php`, `config/navigation.php` (Eintrag `rooms`), `tests/Feature/SettingsFormErrorsTest.php` (Text der Tarifgrenze)
- Test: `tests/Feature/SettingsCrudGapsTest.php`, `tests/Feature/SettingsTablesTest.php`, `tests/Feature/AuditFixesTest.php`, `tests/Feature/CrudEditingTest.php`, `tests/Feature/FloorPlanEditorTest.php`, `tests/Feature/OnboardingTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `config('swayy.tables.capacity_max')` (Task 3); `x-drawer`, `x-inline-confirm :reload`, `data-reset-after-save`, `data-reload-after-save` (Task 1); `x-checkbox`, `x-status-icon` (Task 2); `PlanLimitService::canAdd()`, `::currentUsage()`.
- Produces:
  - Route `admin.settings.rooms.index` (GET `/admin/settings/rooms`); `admin.settings.rooms.*`, `admin.settings.tables.*`, `admin.settings.combinations.*` zeigen auf `RoomsController` (Adressen und Namen unverändert).
  - `RoomsController::COMBINATION_CAPACITY_MAX = 255`.
  - `storeRoom` antwortet per JSON mit `message` und `room_id`.
  - `App\Support\PlanLimitMessage::tables(Tenant $tenant, User $user): string`.
  - `config('swayy.tables.room_size_max_m')` (int, Meter).
  - Seitenleisten `raum-neu`, `raum-{id}`, `tisch-neu-{raumId}`, `tisch-{id}`, `kombi-neu`, `kombi-{id}`; Sprungziel `#kombinationen`.

- [ ] **Step 1: Failing test – `tests/Feature/RoomsPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\Reservation;
use App\Models\RestaurantTable;
use App\Models\Room;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class RoomsPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_owner_sees_rooms_tables_and_drawers(): void
    {
        $setup = $this->createTenantSetup([['min' => 2, 'max' => 4]]);
        $setup['tables'][0]->update(['online_bookable' => false, 'accessible' => true]);
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 20])]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk();

        $room = $setup['room'];
        $table = $setup['tables'][0];
        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('1 von 20 Tischen deines Tarifs genutzt (alle Standorte)')
            ->assertSee('data-drawer-open="raum-'.$room->id.'"', false)
            ->assertSee('data-drawer-open="tisch-neu-'.$room->id.'"', false)
            ->assertSee('id="tisch-'.$table->id.'" data-drawer hidden', false)
            ->assertSee('<span class="sr-only">Nur intern</span>', false)
            ->assertSee('2–4 Personen · kombinierbar · barrierefrei')
            ->assertSee('id="kombinationen"', false)
            ->assertSee('name="high_chair_possible"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="rooms"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_salons_and_the_service_team_are_turned_away(): void
    {
        $salon = $this->createTenantSetup();
        $salon['tenant']->update(['type' => TenantType::Salon]);
        $owner = $this->createMember($salon['tenant'], 'tenant_owner');
        $restaurant = $this->createTenantSetup();
        $host = $this->createMember($restaurant['tenant'], 'host');
        $this->clearTenantContext();

        $this->actingAs($owner)->getJson('/admin/settings/rooms')
            ->assertForbidden()
            ->assertJsonPath('message', 'Räume und Tische gibt es bei Restaurants. Salons richten unter „Platz & Zeiten“ Leistungen und Mitarbeiter ein.');
        $this->actingAs($host)->get('/admin/settings/rooms')->assertForbidden();
    }

    public function test_a_new_room_answers_with_its_id(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $response = $this->actingAs($owner)->postJson('/admin/settings/rooms', ['name' => 'Wintergarten', 'is_outdoor' => '0'])
            ->assertOk()
            ->assertJsonPath('message', 'Raum angelegt.');

        $room = Room::withoutGlobalScopes()->where('name', 'Wintergarten')->firstOrFail();
        $this->assertSame($room->id, $response->json('room_id'));
    }

    public function test_a_room_with_tables_names_them_and_stays(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/rooms/{$setup['room']->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.room.0', '„'.$setup['room']->name.'“ enthält noch 3 Tische. Bitte zuerst die Tische löschen.');

        $this->assertNotNull($setup['room']->fresh());
    }

    public function test_new_tables_keep_unticked_switches_and_the_high_chair(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/tables', [
            'room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
            'online_bookable' => '1', 'joinable' => '0', 'accessible' => '0', 'high_chair_possible' => '1', 'outdoor' => '0',
        ])->assertOk()->assertExactJson(['message' => 'Tisch angelegt.']);

        $table = RestaurantTable::withoutGlobalScopes()->where('name', 'T9')->firstOrFail();
        $this->assertFalse($table->joinable);
        $this->assertTrue($table->high_chair_possible);
        $this->assertTrue($table->online_bookable);
    }

    public function test_editing_changes_only_the_switches_that_were_sent(): void
    {
        $setup = $this->createTenantSetup();
        $table = $setup['tables'][0];
        $table->update(['outdoor' => true, 'joinable' => true]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/settings/tables/{$table->id}", [
            'name' => 'Fenster', 'min_capacity' => 1, 'max_capacity' => 2, 'joinable' => '0', 'high_chair_possible' => '1',
        ])->assertOk();

        $fresh = $table->fresh();
        $this->assertSame('Fenster', $fresh->name);
        $this->assertFalse($fresh->joinable);
        $this->assertTrue($fresh->high_chair_possible);
        $this->assertTrue($fresh->outdoor);
    }

    public function test_table_size_follows_the_settings(): void
    {
        config(['swayy.tables.capacity_max' => 12]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/settings/tables/{$table->id}", ['name' => 'T1', 'min_capacity' => 2, 'max_capacity' => 14])
            ->assertStatus(422)
            ->assertJsonPath('errors.max_capacity.0', 'Höchstkapazität darf höchstens 12 sein.');
        $this->actingAs($owner)->putJson("/admin/floorplan/tables/{$table->id}", ['name' => 'T1', 'min_capacity' => 2, 'max_capacity' => 14])
            ->assertStatus(422);
        $this->actingAs($owner)->get('/admin/settings/rooms')->assertOk()->assertSee('max="12"', false);
    }

    public function test_a_table_with_an_upcoming_reservation_names_the_count(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $table = $setup['tables'][0];
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 2,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $reservation->tables()->attach($table->id);
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson("/admin/settings/tables/{$table->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.table.0', '„'.$table->name.'“ hat noch eine kommende Reservierung. Bitte sie zuerst an einen anderen Tisch legen oder stornieren.');
    }

    public function test_a_combination_needs_two_joinable_tables(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tables'][0]->update(['joinable' => true]);
        $setup['tables'][1]->update(['joinable' => false]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/settings/combinations', [
            'name' => 'Tafel', 'min_capacity' => 4, 'max_capacity' => 8,
            'table_ids' => [$setup['tables'][0]->id, $setup['tables'][1]->id],
        ])->assertStatus(422)
            ->assertJsonPath('errors.table_ids.0', 'Eine Kombination braucht mindestens zwei Tische mit „Kombinierbar“. Bitte zwei solche Tische wählen oder bei einem Tisch „Kombinierbar“ einschalten.');
    }

    public function test_room_size_answers_with_a_message(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->patchJson("/admin/floorplan/rooms/{$setup['room']->id}/size", ['plan_width_m' => 8, 'plan_height_m' => 12])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', 'Maße von „'.$setup['room']->name.'“ gespeichert.');
    }

    public function test_the_plan_limit_names_a_step_the_role_can_take(): void
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->plan->update(['limits' => array_merge((array) $setup['tenant']->plan->limits, ['max_tables' => 3])]);
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->postJson('/admin/settings/tables', [
            'room_id' => $setup['room']->id, 'name' => 'T9', 'min_capacity' => 2, 'max_capacity' => 4,
        ])->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Dein Tarif umfasst 3 Tische, und alle sind schon angelegt. Für weitere Tische bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.');
    }

    public function test_the_old_settings_page_sends_every_table_switch(): void
    {
        $source = (string) file_get_contents(resource_path('views/admin/settings/index.blade.php'));

        foreach (['online_bookable', 'joinable', 'accessible', 'high_chair_possible'] as $flag) {
            $this->assertStringContainsString('<input type="hidden" name="'.$flag.'" value="0">', $source, $flag);
        }
    }

    public function test_the_page_uses_no_inline_handlers(): void
    {
        foreach (['rooms', 'partials/room-fields', 'partials/table-fields', 'partials/combination-fields'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/settings/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=RoomsPageTest`
Expected: FAIL (404 auf der Seite, alte Meldungen, `joinable` bleibt an).

- [ ] **Step 3: `app/Support/PlanLimitMessage.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;

/**
 * Meldung, wenn der Tarif keinen weiteren Tisch zulässt: die Grenze und ein
 * nächster Schritt, den die Person selbst gehen kann.
 */
final class PlanLimitMessage
{
    public static function tables(Tenant $tenant, User $user): string
    {
        $limit = (int) $tenant->limit('max_tables');

        return trans_choice(
            'Dein Tarif umfasst einen Tisch, und der ist schon angelegt.|Dein Tarif umfasst :count Tische, und alle sind schon angelegt.',
            $limit,
            ['count' => $limit],
        ).' '.($user->canInTenant('billing.manage', $tenant)
            ? __('Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.')
            : __('Für weitere Tische bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.'));
    }
}
```

- [ ] **Step 4: Einstellung für Raummaße**

`config/swayy.php`, Abschnitt `'tables'`: Kommentar ergänzen um `| room_size_max_m: größte Breite und Tiefe eines Raums im Tischplan in Metern (10–2000).` und im Array:
```php
        'room_size_max_m' => EnvSetting::integer('SWAYY_ROOM_SIZE_MAX_M', default: 500, min: 10, max: 2000),
```
`.env.example` nach `# SWAYY_TABLE_CAPACITY_MAX=50`:
```dotenv
# Größte Breite und Tiefe eines Raums im Tischplan in Metern (10–2000)
# SWAYY_ROOM_SIZE_MAX_M=500
```

- [ ] **Step 5: Controller anlegen – `app/Http/Controllers/Admin/Settings/RoomsController.php`**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Models\RestaurantTable;
use App\Models\Room;
use App\Models\TableCombination;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\PlanLimitMessage;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Räume & Tische: Räume, Tische und
 * Tischkombinationen des gewählten Standorts; wo die Tische stehen, legt der
 * Tischplan fest. Die Speicherwege (admin.settings.rooms.*, tables.*,
 * combinations.*) nutzen auch Einrichtungsassistent, Tischplan und bis Teil 3
 * die alte Sammelseite; ihre Antworten behalten deshalb ihre Felder.
 */
class RoomsController extends Controller
{
    use RespondsToSettingsForms;

    /** Größte Platzzahl einer Kombination: so viel fasst die Spalte (unsignedTinyInteger). */
    public const COMBINATION_CAPACITY_MAX = 255;

    /** Schalter eines Tisches; beim Bearbeiten gilt nur, was das Formular mitschickt. */
    private const TABLE_FLAGS = ['online_bookable', 'joinable', 'accessible', 'high_chair_possible', 'outdoor'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index(Request $request): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $tenant = $this->context->tenant();
        abort_if($tenant->isSalon(), 403, __('Räume und Tische gibt es bei Restaurants. Salons richten unter „Platz & Zeiten“ Leistungen und Mitarbeiter ein.'));

        $rooms = $location->rooms()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->with(['tables' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')->orderBy('name')])
            ->get();
        // Jeder Tisch kennt seinen Raum, ohne ihn neu zu laden.
        $tables = $rooms->flatMap(fn (Room $room) => $room->tables->each(fn (RestaurantTable $table) => $table->setRelation('room', $room)));

        return view('admin.settings.rooms', [
            'rooms' => $rooms,
            'tables' => $tables,
            'joinable' => $tables->where('joinable', true)->values(),
            'combinations' => $location->tableCombinations()->with('tables:id,name')->orderBy('name')->get(),
            'canRooms' => $request->user()->canInTenant('rooms.manage', $tenant, $location),
            'canFloorplan' => $request->user()->canInTenant('floorplan.update', $tenant, $location),
            'tableLimit' => $tenant->limit('max_tables'),
            'tablesUsed' => $this->limits->currentUsage($tenant, 'max_tables'),
            'capacityMax' => (int) config('swayy.tables.capacity_max'),
            'comboMax' => self::COMBINATION_CAPACITY_MAX,
            'roomSizeMax' => (int) config('swayy.tables.room_size_max_m'),
        ]);
    }

    public function storeRoom(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_outdoor' => ['nullable', 'boolean'],
        ]);

        $room = $location->rooms()->create([
            'tenant_id' => $location->tenant_id,
            'name' => $validated['name'],
            'is_outdoor' => $request->boolean('is_outdoor'),
        ]);

        $this->audit->log('room.created', $room, null, $validated);

        // room_id: Der Einrichtungsassistent legt danach den ersten Tisch in diesem Raum an.
        if ($request->wantsJson()) {
            return response()->json(['message' => __('Raum angelegt.'), 'room_id' => $room->id]);
        }

        return back()->with('success', __('Raum angelegt.'));
    }

    public function updateRoom(Request $request, Room $room): mixed
    {
        abort_if($room->location_id !== $this->context->locationId(), 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'is_outdoor' => ['nullable', 'boolean'],
        ]);

        $old = ['name' => $room->name, 'is_outdoor' => $room->is_outdoor];
        $room->update([
            'name' => $validated['name'],
            'is_outdoor' => $request->boolean('is_outdoor'),
        ]);

        $this->audit->log('room.updated', $room, $old, $validated);

        return $this->saved($request, __('Raum gespeichert.'));
    }

    public function deleteRoom(Request $request, Room $room): mixed
    {
        abort_if($room->location_id !== $this->context->locationId(), 404);

        // Ein Raum löscht seine Tische samt Verknüpfung zu Reservierungen in
        // der Datenbank mit. Solange Tische darin stehen, bleibt er.
        $count = $room->tables()->count();
        if ($count > 0) {
            return $this->failed($request, 'room', trans_choice(
                '„:name“ enthält noch einen Tisch. Bitte zuerst den Tisch löschen.|„:name“ enthält noch :count Tische. Bitte zuerst die Tische löschen.',
                $count,
                ['name' => $room->name, 'count' => $count],
            ));
        }

        $this->audit->log('room.deleted', $room, ['name' => $room->name]);
        $room->delete();

        return $this->saved($request, __('Raum gelöscht.'));
    }

    public function storeTable(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        if (! $this->limits->canAdd($location->tenant, 'max_tables')) {
            return $this->failed($request, 'name', PlanLimitMessage::tables($location->tenant, $request->user()));
        }

        $validated = $request->validate($this->tableRules() + ['room_id' => ['required', 'integer']]);

        if (! $location->rooms()->where('id', $validated['room_id'])->exists()) {
            return $this->failed($request, 'room_id', __('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden und einen Raum aus der Liste wählen.'));
        }

        [$width, $height] = RestaurantTable::sizeForCapacity('rect', (int) $validated['max_capacity']);

        $table = $location->tables()->create([
            'tenant_id' => $location->tenant_id,
            'room_id' => (int) $validated['room_id'],
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => (int) $validated['max_capacity'],
            'outdoor' => $request->boolean('outdoor'),
            'accessible' => $request->boolean('accessible'),
            'high_chair_possible' => $request->boolean('high_chair_possible'),
            // Ohne Angabe (Einrichtungsassistent) ist ein neuer Tisch kombinierbar und online buchbar.
            'joinable' => $request->boolean('joinable', true),
            'online_bookable' => $request->boolean('online_bookable', true),
            'width' => $width, 'height' => $height,
            'pos_x' => 50, 'pos_y' => 50,
        ]);

        $this->audit->log('table.created', $table, null, $validated);

        return $this->saved($request, __('Tisch angelegt.'));
    }

    public function updateTable(Request $request, RestaurantTable $table): mixed
    {
        abort_if($table->location_id !== $this->context->locationId(), 404);

        $validated = $request->validate($this->tableRules());

        $values = [
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => (int) $validated['max_capacity'],
        ];
        foreach (self::TABLE_FLAGS as $flag) {
            if ($request->has($flag)) {
                $values[$flag] = $request->boolean($flag);
            }
        }

        $old = $table->only(array_keys($values));
        $table->update($values);

        $this->audit->log('table.updated', $table, $old, $validated);

        return $this->saved($request, __('Tisch gespeichert.'));
    }

    public function deleteTable(Request $request, RestaurantTable $table): mixed
    {
        abort_if($table->location_id !== $this->context->locationId(), 404);

        // Eine kommende Reservierung verlöre sonst still ihren Tisch.
        $upcoming = $table->reservations()
            ->where('start_at', '>=', now())
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->count();
        if ($upcoming > 0) {
            return $this->failed($request, 'table', trans_choice(
                '„:name“ hat noch eine kommende Reservierung. Bitte sie zuerst an einen anderen Tisch legen oder stornieren.|„:name“ hat noch :count kommende Reservierungen. Bitte sie zuerst an andere Tische legen oder stornieren.',
                $upcoming,
                ['name' => $table->name, 'count' => $upcoming],
            ));
        }

        $table->delete();
        $this->audit->log('table.deleted', $table);

        return $this->saved($request, __('Tisch gelöscht.'));
    }

    public function storeCombination(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);

        $validated = $request->validate($this->combinationRules(), $this->combinationMessages());
        $tableIds = $this->joinableIds($location, $validated['table_ids']);
        if ($tableIds->count() < 2) {
            return $this->failed($request, 'table_ids', $this->combinationNeedsTwo());
        }

        $combination = $location->tableCombinations()->create([
            'tenant_id' => $location->tenant_id,
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => (int) $validated['max_capacity'],
        ]);
        $combination->tables()->sync($tableIds);

        $this->audit->log('table_combination.created', $combination, null, $validated);

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Tischkombination angelegt.'), 'combination' => $this->combinationJson($combination)]);
        }

        return back()->with('success', __('Tischkombination angelegt.'));
    }

    public function updateCombination(Request $request, TableCombination $combination): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        abort_if($combination->location_id !== $location->id, 404);

        $validated = $request->validate($this->combinationRules(), $this->combinationMessages());
        $tableIds = $this->joinableIds($location, $validated['table_ids']);
        if ($tableIds->count() < 2) {
            return $this->failed($request, 'table_ids', $this->combinationNeedsTwo());
        }

        $combination->update([
            'name' => $validated['name'],
            'min_capacity' => (int) $validated['min_capacity'],
            'max_capacity' => (int) $validated['max_capacity'],
        ]);
        $combination->tables()->sync($tableIds);

        $this->audit->log('table_combination.updated', $combination, null, $validated);

        if ($request->wantsJson()) {
            return response()->json(['message' => __('Tischkombination aktualisiert.'), 'combination' => $this->combinationJson($combination)]);
        }

        return back()->with('success', __('Tischkombination aktualisiert.'));
    }

    public function deleteCombination(Request $request, TableCombination $combination): mixed
    {
        abort_if($combination->location_id !== $this->context->locationId(), 404);
        $this->audit->log('table_combination.deleted', $combination, ['name' => $combination->name]);
        $combination->delete();

        return $this->saved($request, __('Tischkombination gelöscht.'));
    }

    /**
     * @return array<string, list<string>>
     */
    private function tableRules(): array
    {
        $max = (int) config('swayy.tables.capacity_max');

        return [
            'name' => ['required', 'string', 'max:40'],
            'min_capacity' => ['required', 'integer', 'min:1', 'max:'.$max],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:'.$max, 'gte:min_capacity'],
            'outdoor' => ['nullable', 'boolean'],
            'accessible' => ['nullable', 'boolean'],
            'high_chair_possible' => ['nullable', 'boolean'],
            'joinable' => ['nullable', 'boolean'],
            'online_bookable' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function combinationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'table_ids' => ['required', 'array', 'min:2'],
            'table_ids.*' => ['integer'],
            'min_capacity' => ['required', 'integer', 'min:1', 'max:'.self::COMBINATION_CAPACITY_MAX],
            'max_capacity' => ['required', 'integer', 'min:1', 'max:'.self::COMBINATION_CAPACITY_MAX, 'gte:min_capacity'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function combinationMessages(): array
    {
        return [
            'table_ids.required' => __('Bitte mindestens zwei Tische für die Kombination wählen.'),
            'table_ids.min' => __('Bitte mindestens zwei Tische für die Kombination wählen.'),
        ];
    }

    private function combinationNeedsTwo(): string
    {
        return __('Eine Kombination braucht mindestens zwei Tische mit „Kombinierbar“. Bitte zwei solche Tische wählen oder bei einem Tisch „Kombinierbar“ einschalten.');
    }

    /**
     * Nur kombinierbare Tische dieses Standorts; fremde IDs fallen weg.
     *
     * @param  array<int, mixed>  $ids
     * @return Collection<int, int>
     */
    private function joinableIds(Location $location, array $ids): Collection
    {
        return $location->tables()->whereIn('id', $ids)->where('joinable', true)->pluck('id');
    }

    /**
     * Form, die der Tischplan erwartet.
     *
     * @return array<string, mixed>
     */
    private function combinationJson(TableCombination $combination): array
    {
        $combination->load('tables:id,name');

        return [
            'id' => $combination->id,
            'name' => $combination->name,
            'min_capacity' => $combination->min_capacity,
            'max_capacity' => $combination->max_capacity,
            'tables' => $combination->tables->map(fn (RestaurantTable $table) => ['id' => $table->id, 'name' => $table->name])->values(),
        ];
    }
}
```

- [ ] **Step 6: Ansichten anlegen**

`resources/views/admin/settings/partials/room-fields.blade.php`:
```blade
{{-- Felder eines Raums in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID des Raums). --}}
@php($p = 'raum-'.$prefix)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$room?->name" required maxlength="80" placeholder="etwa Terrasse" />
<x-checkbox name="is_outdoor" :id="$p.'-outdoor'" label="Draußen" :checked="(bool) $room?->is_outdoor"
            help="Für Terrasse, Biergarten und andere Plätze im Freien." />
```

`resources/views/admin/settings/partials/table-fields.blade.php`:
```blade
{{-- Felder eines Tisches in der Seitenleiste. $prefix macht die IDs eindeutig; $outdoorDefault gilt für neue Tische (Lage des Raums). --}}
@php($p = 'tisch-'.$prefix)
<x-field name="name" label="Name oder Nummer" :id="$p.'-name'" :value="$table?->name" required maxlength="40" placeholder="etwa T12" />
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="min_capacity" label="Ab Personen" type="number" :id="$p.'-min'" :value="$table?->min_capacity ?? 1" required min="1" :max="$capacityMax" />
    <x-field name="max_capacity" label="Bis Personen" type="number" :id="$p.'-max'" :value="$table?->max_capacity ?? 4" required min="1" :max="$capacityMax" />
</div>
<fieldset class="space-y-3">
    <legend class="text-sm font-semibold text-stone-800">Eigenschaften</legend>
    <x-checkbox name="online_bookable" :id="$p.'-online'" label="Online buchbar" :checked="$table?->online_bookable ?? true"
                help="Ohne Haken vergibt nur dein Team den Tisch." />
    <x-checkbox name="joinable" :id="$p.'-joinable'" label="Kombinierbar" :checked="$table?->joinable ?? true"
                help="Lässt sich mit anderen Tischen zu einer Kombination zusammenstellen." />
    <x-checkbox name="accessible" :id="$p.'-accessible'" label="Barrierefrei" :checked="(bool) $table?->accessible" />
    <x-checkbox name="high_chair_possible" :id="$p.'-high-chair'" label="Kinderstuhl möglich" :checked="(bool) $table?->high_chair_possible" />
    <x-checkbox name="outdoor" :id="$p.'-outdoor'" label="Draußen" :checked="$table ? (bool) $table->outdoor : $outdoorDefault" />
</fieldset>
```

`resources/views/admin/settings/partials/combination-fields.blade.php`:
```blade
{{-- Felder einer Tischkombination in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID der Kombination). --}}
@php($p = 'kombi-'.$prefix)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$combo?->name" required maxlength="80" placeholder="etwa Große Tafel" />
<fieldset class="space-y-2">
    <legend class="text-sm font-semibold text-stone-800">Tische<span class="text-red-700" aria-hidden="true"> *</span></legend>
    <p class="text-[0.8125rem] text-stone-600">Mindestens zwei Tische mit „Kombinierbar“.</p>
    @foreach($joinable as $table)
        <label class="flex items-center gap-3">
            <input type="checkbox" name="table_ids[]" value="{{ $table->id }}" @checked($combo?->tables->contains('id', $table->id))>
            <span class="[overflow-wrap:anywhere]">{{ $table->name }} <span class="text-stone-500">({{ $table->room?->name }}, {{ $table->min_capacity }}–{{ $table->max_capacity }})</span></span>
        </label>
    @endforeach
</fieldset>
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="min_capacity" label="Ab Personen" type="number" :id="$p.'-min'" :value="$combo?->min_capacity ?? 2" required min="1" :max="$comboMax" />
    <x-field name="max_capacity" label="Bis Personen" type="number" :id="$p.'-max'" :value="$combo?->max_capacity ?? 8" required min="1" :max="$comboMax" />
</div>
```

`resources/views/admin/settings/rooms.blade.php`:
```blade
@extends('layouts.admin')
@section('title', 'Räume & Tische')

@section('content')
<x-page-header title="Räume & Tische" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               description="Räume, Tische und Kombinationen dieses Standorts. Wo die Tische stehen, legst du im Tischplan fest.">
    @if($canRooms)
        <x-slot:actions>
            <button type="button" data-drawer-open="raum-neu" class="btn btn-primary">Raum hinzufügen</button>
        </x-slot:actions>
    @endif
</x-page-header>

<div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-stone-600">
    <p>
        @if($tableLimit === null)
            {{ trans_choice(':count Tisch an diesem Standort|:count Tische an diesem Standort', $tables->count(), ['count' => $tables->count()]) }}
        @else
            {{ $tablesUsed }} von {{ $tableLimit }} Tischen deines Tarifs genutzt (alle Standorte)
        @endif
        · Ein Raum lässt sich löschen, sobald keine Tische mehr darin stehen.
    </p>
    <a href="{{ route('admin.floorplan.index') }}" class="font-semibold underline underline-offset-2">Im Tischplan anordnen</a>
</div>

@if($rooms->isEmpty())
    <x-empty-state title="Noch keine Räume">
        Ein Raum fasst Tische zusammen, etwa Gastraum, Terrasse oder Wintergarten.
        @if($canRooms)
            <x-slot:action>
                <button type="button" data-drawer-open="raum-neu" class="btn btn-primary">Raum hinzufügen</button>
            </x-slot:action>
        @endif
    </x-empty-state>
@else
    <div class="space-y-6">
        @foreach($rooms as $room)
            <section aria-labelledby="raum-{{ $room->id }}-titel" class="rounded-xl border border-stone-200 bg-white">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-stone-200 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="raum-{{ $room->id }}-titel" class="font-semibold [overflow-wrap:anywhere]">
                            @if($canRooms)
                                <button type="button" data-drawer-open="raum-{{ $room->id }}" class="text-left underline-offset-2 hover:underline">{{ $room->name }}</button>
                            @else
                                {{ $room->name }}
                            @endif
                        </h2>
                        <p class="text-[0.8125rem] text-stone-600">
                            {{ $room->is_outdoor ? 'Draußen' : 'Drinnen' }} · {{ trans_choice(':count Tisch|:count Tische', $room->tables->count(), ['count' => $room->tables->count()]) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <button type="button" data-drawer-open="tisch-neu-{{ $room->id }}" class="btn btn-quiet">Tisch hinzufügen<span class="sr-only"> in „{{ $room->name }}“</span></button>
                        @if($canRooms && $room->tables->isEmpty())
                            <x-inline-confirm id="raum-loeschen-{{ $room->id }}" :action="route('admin.settings.rooms.delete', $room)" method="DELETE"
                                              label="Löschen" confirm="Raum löschen" :subject="$room->name" :reload="true"
                                              :question="'Raum „'.$room->name.'“ löschen?'" />
                        @endif
                    </div>
                </div>
                @if($room->tables->isEmpty())
                    <p class="px-4 py-3 text-sm text-stone-600">Noch keine Tische in diesem Raum.</p>
                @else
                    <ul class="divide-y divide-stone-200">
                        @foreach($room->tables as $table)
                            @php
                                $flags = array_filter([
                                    $table->joinable ? 'kombinierbar' : null,
                                    $table->accessible ? 'barrierefrei' : null,
                                    $table->high_chair_possible ? 'Kinderstuhl' : null,
                                    $table->outdoor ? 'draußen' : null,
                                ]);
                            @endphp
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                                <x-status-icon :state="$table->online_bookable ? 'ok' : 'off'" :label="$table->online_bookable ? 'Online buchbar' : 'Nur intern'" />
                                <div class="min-w-0 flex-1">
                                    <button type="button" data-drawer-open="tisch-{{ $table->id }}"
                                            class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $table->name }}</button>
                                    <p class="text-[0.8125rem] text-stone-600">{{ $table->min_capacity }}–{{ $table->max_capacity }} Personen{{ $flags ? ' · '.implode(' · ', $flags) : '' }}</p>
                                </div>
                                <x-inline-confirm id="tisch-loeschen-{{ $table->id }}" :action="route('admin.settings.tables.delete', $table)" method="DELETE"
                                                  label="Löschen" confirm="Tisch löschen" :subject="$table->name" :reload="true"
                                                  :question="'Tisch „'.$table->name.'“ löschen? Er verschwindet aus dem Tischplan; vergangene Reservierungen behalten ihn.'" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
@endif

<section id="kombinationen" aria-labelledby="kombinationen-titel" class="mt-8">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="kombinationen-titel" class="text-base font-semibold">Tischkombinationen</h2>
            <p class="text-sm text-stone-600">Zusammengestellte Tische für größere Gruppen. Swayy nimmt sie, wenn ein einzelner Tisch zu klein ist.</p>
        </div>
        @if($joinable->count() >= 2 && $combinations->isNotEmpty())
            <button type="button" data-drawer-open="kombi-neu" class="btn btn-secondary">Kombination hinzufügen</button>
        @endif
    </div>
    @if($combinations->isEmpty())
        <x-empty-state title="Noch keine Kombinationen">
            @if($joinable->count() >= 2)
                Für Gruppen, die an keinen einzelnen Tisch passen, stellst du hier Tische zusammen.
                <x-slot:action>
                    <button type="button" data-drawer-open="kombi-neu" class="btn btn-primary">Kombination hinzufügen</button>
                </x-slot:action>
            @else
                Kombinationen brauchen mindestens zwei Tische mit „Kombinierbar“.
            @endif
        </x-empty-state>
    @else
        <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
            @foreach($combinations as $combo)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <button type="button" data-drawer-open="kombi-{{ $combo->id }}"
                                class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $combo->name }}</button>
                        <p class="text-[0.8125rem] text-stone-600">{{ $combo->tables->pluck('name')->implode(' + ') }} · {{ $combo->min_capacity }}–{{ $combo->max_capacity }} Personen</p>
                    </div>
                    <x-inline-confirm id="kombi-loeschen-{{ $combo->id }}" :action="route('admin.settings.combinations.delete', $combo)" method="DELETE"
                                      label="Löschen" confirm="Kombination löschen" :subject="$combo->name" :reload="true"
                                      :question="'Kombination „'.$combo->name.'“ löschen? Die Tische bleiben.'" />
                </li>
            @endforeach
        </ul>
    @endif
</section>

@push('drawers')
    @if($canRooms)
        <x-drawer id="raum-neu" title="Neuer Raum" description="Danach legst du im Raum die Tische an.">
            <form id="form-raum-neu" method="POST" action="{{ route('admin.settings.rooms.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
                @csrf
                @include('admin.settings.partials.room-fields', ['room' => null, 'prefix' => 'neu'])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-raum-neu" class="btn btn-primary">Raum anlegen</button>
            </x-slot:footer>
        </x-drawer>
    @endif
    @foreach($rooms as $room)
        @if($canRooms)
            <x-drawer id="raum-{{ $room->id }}" :title="$room->name" description="Name und Lage des Raums.">
                <form id="form-raum-{{ $room->id }}" method="POST" action="{{ route('admin.settings.rooms.update', $room) }}" data-ajax data-reload-after-save class="space-y-5">
                    @csrf
                    @method('PUT')
                    @include('admin.settings.partials.room-fields', ['room' => $room, 'prefix' => (string) $room->id])
                </form>
                @if($canFloorplan)
                    <section aria-labelledby="raum-{{ $room->id }}-masse-titel" class="space-y-4 border-t border-stone-200 pt-5">
                        <h3 id="raum-{{ $room->id }}-masse-titel" class="text-sm font-semibold">Maße für den Tischplan</h3>
                        <p class="text-[0.8125rem] text-stone-600">Mit Breite und Tiefe zeichnet der Tischplan den Raum maßstabsgetreu.</p>
                        <form method="POST" action="{{ route('admin.floorplan.rooms.size', $room) }}" data-ajax class="space-y-3">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field name="plan_width_m" label="Breite" type="number" unit="m" :id="'raum-'.$room->id.'-breite'"
                                         :value="$room->plan_width_m" min="1" :max="$roomSizeMax" step="0.5" />
                                <x-field name="plan_height_m" label="Tiefe" type="number" unit="m" :id="'raum-'.$room->id.'-tiefe'"
                                         :value="$room->plan_height_m" min="1" :max="$roomSizeMax" step="0.5" />
                            </div>
                            <button type="submit" class="btn btn-secondary">Maße speichern</button>
                        </form>
                    </section>
                @endif
                <x-slot:footer>
                    <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                    <button type="submit" form="form-raum-{{ $room->id }}" class="btn btn-primary">Speichern</button>
                </x-slot:footer>
            </x-drawer>
        @endif
        <x-drawer id="tisch-neu-{{ $room->id }}" :title="'Neuer Tisch in „'.$room->name.'“'" description="Neue Tische stehen im Tischplan oben links; dort ziehst du sie an ihren Platz.">
            <form id="form-tisch-neu-{{ $room->id }}" method="POST" action="{{ route('admin.settings.tables.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
                @csrf
                <input type="hidden" name="room_id" value="{{ $room->id }}">
                @include('admin.settings.partials.table-fields', ['table' => null, 'prefix' => 'neu-'.$room->id, 'outdoorDefault' => (bool) $room->is_outdoor])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-tisch-neu-{{ $room->id }}" class="btn btn-primary">Tisch anlegen</button>
            </x-slot:footer>
        </x-drawer>
        @foreach($room->tables as $table)
            <x-drawer id="tisch-{{ $table->id }}" :title="$table->name" :description="'Raum „'.$room->name.'“'">
                <form id="form-tisch-{{ $table->id }}" method="POST" action="{{ route('admin.settings.tables.update', $table) }}" data-ajax data-reload-after-save class="space-y-5">
                    @csrf
                    @method('PUT')
                    @include('admin.settings.partials.table-fields', ['table' => $table, 'prefix' => (string) $table->id, 'outdoorDefault' => false])
                </form>
                <x-slot:footer>
                    <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                    <button type="submit" form="form-tisch-{{ $table->id }}" class="btn btn-primary">Speichern</button>
                </x-slot:footer>
            </x-drawer>
        @endforeach
    @endforeach
    @if($joinable->count() >= 2)
        <x-drawer id="kombi-neu" title="Neue Kombination" description="Tische, die für eine größere Gruppe zusammengestellt werden.">
            <form id="form-kombi-neu" method="POST" action="{{ route('admin.settings.combinations.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
                @csrf
                @include('admin.settings.partials.combination-fields', ['combo' => null, 'prefix' => 'neu'])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-kombi-neu" class="btn btn-primary">Kombination anlegen</button>
            </x-slot:footer>
        </x-drawer>
    @endif
    @foreach($combinations as $combo)
        <x-drawer id="kombi-{{ $combo->id }}" :title="$combo->name" description="Tische und Gruppengröße der Kombination.">
            <form id="form-kombi-{{ $combo->id }}" method="POST" action="{{ route('admin.settings.combinations.update', $combo) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.settings.partials.combination-fields', ['combo' => $combo, 'prefix' => (string) $combo->id])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-kombi-{{ $combo->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
```

- [ ] **Step 7: Tischplan, alte Sammelseite, Routen, Navigation**

`app/Http/Controllers/Admin/FloorPlanController.php`:
- Import `use App\Support\PlanLimitMessage;` ergänzen.
- In `updateRoomSize()` die Prüfregeln ersetzen durch
```php
        $maxM = (int) config('swayy.tables.room_size_max_m');
        $data = $request->validate([
            'plan_width' => ['nullable', 'integer', 'min:200', 'max:'.($maxM * 100)],
            'plan_height' => ['nullable', 'integer', 'min:200', 'max:'.($maxM * 100)],
            'plan_width_m' => ['nullable', 'numeric', 'min:1', 'max:'.$maxM],
            'plan_height_m' => ['nullable', 'numeric', 'min:1', 'max:'.$maxM],
        ]);
```
  und in der Antwort nach `'ok' => true,` ergänzen: `'message' => __('Maße von „:name“ gespeichert.', ['name' => $room->name]),`
- In `storeTable()`: die Zeile `return response()->json(['message' => __('Tisch-Limit Ihres Tarifs erreicht.')], 422);` ersetzen durch `return response()->json(['message' => PlanLimitMessage::tables($location->tenant, $request->user())], 422);`, in der Prüfung `'max:50'` (zweimal) durch `'max:'.(int) config('swayy.tables.capacity_max')` ersetzen und `abort_unless($location->rooms()->where('id', $validated['room_id'])->exists(), 422);` ersetzen durch `abort_unless($location->rooms()->where('id', $validated['room_id'])->exists(), 422, __('Dieser Raum gehört nicht zu diesem Standort. Bitte die Seite neu laden.'));`
- In `updateTable()`: `'max:50'` (zweimal) durch `'max:'.(int) config('swayy.tables.capacity_max')` ersetzen.

`resources/views/admin/settings/index.blade.php`, im Formular „Tische bearbeiten“ vor jedem der vier Kästchen ein verstecktes 0-Feld:
```blade
                    <input type="hidden" name="online_bookable" value="0">
                    <label class="flex items-center gap-1 pb-2 text-xs"><input type="checkbox" name="online_bookable" value="1" @checked($t->online_bookable)> Online</label>
                    <input type="hidden" name="joinable" value="0">
                    <label class="flex items-center gap-1 pb-2 text-xs"><input type="checkbox" name="joinable" value="1" @checked($t->joinable)> Komb.</label>
                    <input type="hidden" name="accessible" value="0">
                    <label class="flex items-center gap-1 pb-2 text-xs"><input type="checkbox" name="accessible" value="1" @checked($t->accessible)> Barrierefrei</label>
                    <input type="hidden" name="high_chair_possible" value="0">
                    <label class="flex items-center gap-1 pb-2 text-xs"><input type="checkbox" name="high_chair_possible" value="1" @checked($t->high_chair_possible)> Kinderstuhl</label>
```

`app/Http/Controllers/Admin/SettingsController.php`: Die Methoden `storeRoom`, `storeTable`, `deleteTable`, `storeCombination`, `updateCombination`, `deleteCombination`, `updateRoom`, `deleteRoom`, `updateTable` löschen; den Import `App\Models\TableCombination`, den Import `App\Services\PlanLimitService` und den Konstruktor-Parameter `private readonly PlanLimitService $limits` entfernen.

`routes/web.php`: Import `use App\Http\Controllers\Admin\Settings\RoomsController;`. In der Einstellungsgruppe die neun Routen `settings.rooms.store|update|delete`, `settings.tables.store|update|delete`, `settings.combinations.store|update|delete` auf `RoomsController` umstellen (Methodennamen bleiben, Middleware bleibt) und davor einfügen:
```php
        Route::get('/settings/rooms', [RoomsController::class, 'index'])->name('settings.rooms.index');
```

`config/navigation.php`, Eintrag `rooms` ersetzen:
```php
            ['key' => 'rooms', 'label' => 'Räume & Tische', 'route' => 'admin.settings.rooms.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'restaurant',
                'keywords' => ['Raum', 'Neuer Raum' => 'raum-neu', 'Tisch', 'Tischkombination' => 'kombinationen', 'Kombination' => 'kombinationen', 'Raummaße', 'Kinderstuhl', 'Barrierefrei']],
```

`tests/Feature/SettingsFormErrorsTest.php`, in `test_a_table_beyond_the_plan_limit_says_how_to_get_more()` die erwartete Meldung ersetzen durch
```php
            'Dein Tarif umfasst 3 Tische, und alle sind schon angelegt. Für weitere Tische den Tarif unter „Abo & Rechnungen“ wechseln.',
```

- [ ] **Step 8: Tests laufen lassen**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='RoomsPageTest|SettingsCrudGapsTest|SettingsTablesTest|AuditFixesTest|CrudEditingTest|FloorPlanEditorTest|SettingsFormErrorsTest|OnboardingTest|NavigationTest|SettingsMenuTest|AdminRoutesSmokeTest|TenantIsolationTest'
vendor/bin/phpstan analyse --memory-limit=1G
```
Expected: alles grün.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/Settings/RoomsController.php app/Support/PlanLimitMessage.php app/Http/Controllers/Admin/SettingsController.php app/Http/Controllers/Admin/FloorPlanController.php resources/views/admin/settings config/swayy.php .env.example routes/web.php config/navigation.php tests/Feature/RoomsPageTest.php tests/Feature/SettingsFormErrorsTest.php
git commit -m "feat(settings): Seite „Räume & Tische“ mit Seitenleisten für Raum, Tisch und Kombination

Raum-, Tisch- und Kombinationswege in RoomsController; Kinderstuhl wird
gespeichert, Bearbeiten ändert nur mitgeschickte Schalter, Löschen nennt
Tische bzw. kommende Reservierungen; storeRoom liefert room_id für den
Einrichtungsassistenten; Tarifgrenze mit nächstem Schritt.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Seite „Leistungen“ (Salon)

**Files:**
- Modify: `app/Http/Controllers/Admin/ServiceController.php`, `resources/views/admin/services/index.blade.php`, `config/navigation.php` (Eintrag `services`)
- Create: `resources/views/admin/services/partials/fields.blade.php`, `tests/Feature/ServicesPageTest.php`
- Test: `tests/Feature/OnboardingTest.php`, `tests/Feature/SalonAdminTest.php`, `tests/Feature/ServiceDepositRuleTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `config('swayy.salon.*')`, `config('swayy.ui.default_label_color')` (Task 3); `x-drawer`, `x-inline-confirm :reload`, `data-reset-after-save`, `data-reload-after-save` (Task 1); `x-checkbox`, `x-field :minutes`, `x-status-icon` (Task 2).
- Produces: `ServiceController` antwortet per `RespondsToSettingsForms`; `store`/`update` nehmen `price` (Euro) oder `price_minor` (Cent, Einrichtungsassistent); `destroy` lehnt bei kommenden Terminen ab (422, Feld `service`); Seitenleisten `leistung-neu`, `leistung-{id}`.

- [ ] **Step 1: Failing test – `tests/Feature/ServicesPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ServicesPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function salon(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['owner'] = $this->createMember($setup['tenant'], 'tenant_owner');
        $setup['staff'] = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Anna', 'is_active' => true,
        ]);

        return $setup;
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    private function service(array $setup, string $name = 'Schnitt'): Service
    {
        return Service::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => $name,
            'duration_minutes' => 60, 'price_minor' => 3500, 'currency' => 'EUR', 'is_active' => true,
        ]);
    }

    public function test_salon_owner_sees_services_with_drawers(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $service->staff()->attach($setup['staff']->id);
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->get('/admin/services')->assertOk();

        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('data-drawer-open="leistung-'.$service->id.'"', false)
            ->assertSee('id="leistung-neu" data-drawer hidden', false)
            ->assertSee('1 Std. · 35,00 € · Anna')
            ->assertSee('<span class="sr-only">Online buchbar</span>', false)
            ->assertSee('name="price"', false)
            ->assertSee('value="35.00"', false);
        $this->assertMatchesRegularExpression('/data-nav-key="services"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_restaurants_get_an_explanation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->getJson('/admin/services')
            ->assertForbidden()
            ->assertJsonPath('message', 'Leistungen gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.');
    }

    public function test_the_price_comes_in_euros_and_from_the_setup_assistant_in_cents(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Färben', 'duration_minutes' => 90, 'price' => '49.90', 'color' => '#aa3300', 'is_active' => '1',
            'staff_ids' => [$setup['staff']->id],
        ])->assertOk()->assertJsonPath('message', 'Leistung „Färben“ angelegt.');
        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Beratung', 'duration_minutes' => 30, 'price_minor' => '1500', 'is_active' => '1',
        ])->assertOk();

        $this->assertSame(4990, Service::withoutGlobalScopes()->where('name', 'Färben')->value('price_minor'));
        $this->assertSame(1500, Service::withoutGlobalScopes()->where('name', 'Beratung')->value('price_minor'));
    }

    public function test_staff_of_another_location_is_ignored(): void
    {
        $setup = $this->salon();
        $other = $this->createTenantSetup();
        $foreign = StaffMember::create([
            'tenant_id' => $other['tenant']->id, 'location_id' => $other['location']->id, 'name' => 'Fremd', 'is_active' => true,
        ]);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Schnitt', 'duration_minutes' => 30, 'price' => '20', 'is_active' => '1',
            'staff_ids' => [$setup['staff']->id, $foreign->id],
        ])->assertOk();

        $service = Service::withoutGlobalScopes()->where('name', 'Schnitt')->firstOrFail();
        $this->assertSame([$setup['staff']->id], $service->staff()->withoutGlobalScopes()->pluck('staff_members.id')->all());
    }

    public function test_a_service_with_upcoming_appointments_stays(): void
    {
        $setup = $this->salon();
        $service = $this->service($setup);
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(10, 0);
        Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 1,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHour()->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast', 'service_id' => $service->id,
        ]);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$service->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.service.0', '„Schnitt“ ist noch für einen kommenden Termin gebucht. Bitte den Termin zuerst umbuchen oder die Leistung pausieren: Haken bei „Online buchbar“ entfernen.');
        $this->assertNotNull($service->fresh());

        $free = $this->service($setup, 'Pflege');
        $this->actingAs($setup['owner'])->deleteJson("/admin/services/{$free->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Leistung „Pflege“ gelöscht.');
    }

    public function test_limits_come_from_the_settings(): void
    {
        config(['swayy.salon.service_duration_max' => 120, 'swayy.salon.service_price_max' => 100]);
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson('/admin/services', [
            'name' => 'Lang', 'duration_minutes' => 150, 'price' => '120', 'is_active' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.duration_minutes.0', 'Dauer darf höchstens 120 sein.')
            ->assertJsonPath('errors.price.0', 'Preis darf höchstens 100 sein.');
    }

    public function test_the_page_uses_no_inline_handlers(): void
    {
        foreach (['index', 'partials/fields'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/services/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=ServicesPageTest`
Expected: FAIL.

- [ ] **Step 3: `app/Http/Controllers/Admin/ServiceController.php` ersetzen**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffMember;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Leistungen (Salon): was Gäste buchen,
 * mit Dauer, Preis und den Personen, die es anbieten. Gilt für den gewählten
 * Standort. Der Einrichtungsassistent legt die erste Leistung über store() an.
 */
class ServiceController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        return view('admin.services.index', [
            'location' => $location,
            'services' => Service::where('location_id', $location->id)->with('staff')->orderBy('sort_order')->orderBy('name')->get(),
            'staff' => StaffMember::where('location_id', $location->id)->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'limits' => (array) config('swayy.salon'),
            'defaultColor' => config('swayy.ui.default_label_color'),
        ]);
    }

    public function store(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules(), [], $this->attributes());

        $service = Service::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            ...$this->values($request, $validated),
        ]);
        $service->staff()->sync($this->staffIds($location->id, $validated));

        $this->audit->log('service.created', $service, null, $validated);

        return $this->saved($request, __('Leistung „:name“ angelegt.', ['name' => $service->name]));
    }

    public function update(Request $request, Service $service): mixed
    {
        abort_if($service->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules(), [], $this->attributes());

        $old = $service->only(['name', 'duration_minutes', 'price_minor', 'is_active']);
        $service->update($this->values($request, $validated));
        $service->staff()->sync($this->staffIds($service->location_id, $validated));

        $this->audit->log('service.updated', $service, $old, $validated);

        return $this->saved($request, __('Leistung „:name“ gespeichert.', ['name' => $service->name]));
    }

    public function destroy(Request $request, Service $service): mixed
    {
        abort_if($service->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        // Kommende Termine verlören sonst still ihre Leistung (nullOnDelete).
        $upcoming = Reservation::where('location_id', $service->location_id)
            ->where('start_at', '>=', now())
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->where(fn ($query) => $query->where('service_id', $service->id)
                ->orWhereHas('services', fn ($services) => $services->where('services.id', $service->id)))
            ->count();
        if ($upcoming > 0) {
            return $this->failed($request, 'service', trans_choice(
                '„:name“ ist noch für einen kommenden Termin gebucht. Bitte den Termin zuerst umbuchen oder die Leistung pausieren: Haken bei „Online buchbar“ entfernen.|„:name“ ist noch für :count kommende Termine gebucht. Bitte die Termine zuerst umbuchen oder die Leistung pausieren: Haken bei „Online buchbar“ entfernen.',
                $upcoming,
                ['name' => $service->name, 'count' => $upcoming],
            ));
        }

        $name = $service->name;
        $this->audit->log('service.deleted', $service, ['name' => $name]);
        $service->delete();

        return $this->saved($request, __('Leistung „:name“ gelöscht.', ['name' => $name]));
    }

    private function ensureSalon(): void
    {
        abort_unless($this->context->tenant()?->isSalon(), 403, __('Leistungen gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.'));
    }

    /**
     * Preis in Euro (Seite) oder in Cent (Einrichtungsassistent).
     *
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        $limits = (array) config('swayy.salon');

        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'duration_minutes' => ['required', 'integer', 'min:'.$limits['service_duration_min'], 'max:'.$limits['service_duration_max']],
            'price' => ['nullable', 'required_without:price_minor', 'numeric', 'min:0', 'max:'.$limits['service_price_max']],
            'price_minor' => ['nullable', 'required_without:price', 'integer', 'min:0', 'max:'.($limits['service_price_max'] * 100)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
            'staff_ids' => ['nullable', 'array'],
            'staff_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['price' => __('Preis'), 'duration_minutes' => __('Dauer'), 'is_active' => __('Online buchbar')];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function values(Request $request, array $validated): array
    {
        $priceMinor = isset($validated['price'])
            ? (int) round(((float) $validated['price']) * 100)
            : (int) ($validated['price_minor'] ?? 0);

        return [
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'duration_minutes' => (int) $validated['duration_minutes'],
            'price_minor' => $priceMinor,
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ];
    }

    /**
     * Nur Personen dieses Standorts; fremde IDs fallen weg.
     *
     * @param  array<string, mixed>  $validated
     * @return Collection<int, int>
     */
    private function staffIds(int $locationId, array $validated): Collection
    {
        return StaffMember::where('location_id', $locationId)->whereIn('id', $validated['staff_ids'] ?? [])->pluck('id');
    }
}
```

- [ ] **Step 4: Ansichten**

`resources/views/admin/services/partials/fields.blade.php`:
```blade
{{-- Felder einer Leistung in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID der Leistung). --}}
@php($p = 'svc-'.$prefix)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$service?->name" required maxlength="120" placeholder="etwa Haarschnitt" />
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="duration_minutes" label="Dauer" type="number" unit="Min." :minutes="true" :id="$p.'-duration'" required
             :value="$service?->duration_minutes ?? 30" :min="$limits['service_duration_min']" :max="$limits['service_duration_max']" />
    <x-field name="price" label="Preis" type="number" unit="€" :id="$p.'-price'" required step="0.01" min="0" :max="$limits['service_price_max']"
             :value="$service ? number_format($service->price_minor / 100, 2, '.', '') : '0.00'"
             help="Bei 0 steht auf der Buchungsseite „auf Anfrage“." />
</div>
<x-field name="description" label="Beschreibung" :id="$p.'-description'" help="Ein Satz für die Buchungsseite.">
    <textarea id="{{ $p }}-description" name="description" rows="2" maxlength="1000" aria-describedby="{{ $p }}-description-help" class="w-full">{{ $service?->description }}</textarea>
</x-field>
<x-field name="color" label="Farbe im Terminplan" :id="$p.'-color'">
    <input id="{{ $p }}-color" type="color" name="color" value="{{ $service?->color ?? $defaultColor }}"
           class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
</x-field>
<fieldset class="space-y-2">
    <legend class="text-sm font-semibold text-stone-800">Wer bietet die Leistung an?</legend>
    @forelse($staff as $member)
        <label class="flex items-center gap-3">
            <input type="checkbox" name="staff_ids[]" value="{{ $member->id }}" @checked($service?->staff->contains($member))>
            <span class="[overflow-wrap:anywhere]">{{ $member->name }}</span>
        </label>
    @empty
        <p class="text-sm text-stone-600">Personen legst du unter <a href="{{ route('admin.staff.index') }}" class="font-semibold underline underline-offset-2">Mitarbeiter</a> an.</p>
    @endforelse
</fieldset>
<x-checkbox name="is_active" :id="$p.'-active'" label="Online buchbar" :checked="$service?->is_active ?? true"
            help="Pausierte Leistungen bleiben erhalten; bestehende Termine behalten sie." />
```

`resources/views/admin/services/index.blade.php` ersetzen:
```blade
@extends('layouts.admin')
@section('title', 'Leistungen')

@section('content')
<x-page-header title="Leistungen" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               description="Was Gäste buchen können, mit Dauer, Preis und den Personen, die es anbieten.">
    <x-slot:actions>
        <button type="button" data-drawer-open="leistung-neu" class="btn btn-primary">Leistung hinzufügen</button>
    </x-slot:actions>
</x-page-header>

@if($staff->isEmpty())
    <p role="note" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Buchbar wird eine Leistung, sobald eine Person sie anbietet. Lege die Personen unter
        <a href="{{ route('admin.staff.index') }}" class="font-semibold underline underline-offset-2">Mitarbeiter</a> an.
    </p>
@endif

@if($services->isEmpty())
    <x-empty-state title="Noch keine Leistungen">
        Leistungen sind das, was Gäste buchen: etwa Haarschnitt, Färben oder Beratung.
        <x-slot:action>
            <button type="button" data-drawer-open="leistung-neu" class="btn btn-primary">Leistung hinzufügen</button>
        </x-slot:action>
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($services as $service)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <x-status-icon :state="$service->is_active ? 'ok' : 'off'" :label="$service->is_active ? 'Online buchbar' : 'Pausiert'" />
                <div class="min-w-0 flex-1">
                    <button type="button" data-drawer-open="leistung-{{ $service->id }}"
                            class="flex items-center gap-2 text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">
                        @if($service->color)
                            <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $service->color }}" aria-hidden="true"></span>
                        @endif
                        {{ $service->name }}
                    </button>
                    <p class="text-[0.8125rem] text-stone-600">
                        {{ $service->durationFormatted() }} · {{ $service->priceFormatted() }} · {{ $service->staff->isNotEmpty() ? $service->staff->pluck('name')->join(', ') : 'Noch niemandem zugeordnet' }}
                    </p>
                </div>
                <x-inline-confirm id="leistung-loeschen-{{ $service->id }}" :action="route('admin.services.destroy', $service)" method="DELETE"
                                  label="Löschen" confirm="Leistung löschen" :subject="$service->name" :reload="true"
                                  :question="'Leistung „'.$service->name.'“ löschen?'" />
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    <x-drawer id="leistung-neu" title="Neue Leistung" description="Name, Dauer und Preis, wie Gäste sie auf der Buchungsseite sehen.">
        <form id="form-leistung-neu" method="POST" action="{{ route('admin.services.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            @include('admin.services.partials.fields', ['service' => null, 'prefix' => 'neu'])
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-leistung-neu" class="btn btn-primary">Leistung anlegen</button>
        </x-slot:footer>
    </x-drawer>
    @foreach($services as $service)
        <x-drawer id="leistung-{{ $service->id }}" :title="$service->name" description="Änderungen gelten für neue Termine.">
            <form id="form-leistung-{{ $service->id }}" method="POST" action="{{ route('admin.services.update', $service) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.services.partials.fields', ['service' => $service, 'prefix' => (string) $service->id])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-leistung-{{ $service->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
```

`config/navigation.php`, Eintrag `services` ersetzen:
```php
            ['key' => 'services', 'label' => 'Leistungen', 'route' => 'admin.services.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon', 'active' => ['admin.services.*'],
                'keywords' => ['Leistung', 'Neue Leistung' => 'leistung-neu', 'Dauer', 'Preis']],
```

- [ ] **Step 5: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='ServicesPageTest|SalonAdminTest|OnboardingTest|ServiceDepositRuleTest|SalonAvailabilityTest|StaffCalendarTest|NavigationTest|AdminRoutesSmokeTest'`
Expected: alles grün.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/ServiceController.php resources/views/admin/services config/navigation.php tests/Feature/ServicesPageTest.php
git commit -m "feat(settings): Seite „Leistungen“ mit Seitenleiste, Preis in Euro und Schutz für gebuchte Leistungen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 11: Seite „Mitarbeiter“ (Salon)

**Files:**
- Modify: `app/Http/Controllers/Admin/StaffMemberController.php`, `resources/views/admin/staff/index.blade.php`, `config/navigation.php` (Eintrag `staff`)
- Create: `resources/views/admin/staff/partials/fields.blade.php`, `resources/views/admin/staff/partials/hour-row.blade.php`, `tests/Feature/StaffPageTest.php`
- Test: `tests/Feature/SalonAdminTest.php`, `tests/Feature/OnboardingTest.php`, `tests/Feature/StaffCalendarTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `config('swayy.ui.default_staff_color')` (Task 3); `x-drawer`, `x-inline-confirm :reload`, Formular-Attribute aus Task 1; `data-rows`, `x-change-dot`, `x-checkbox` (Task 2).
- Produces: `StaffMemberController` antwortet per `RespondsToSettingsForms`; `store` per JSON mit `message` und `id`; `updateWorkingHours` meldet Fehler am Feld der Zeile (`hours.{zeile}.ends_at`); `destroy` lehnt bei kommenden Terminen ab (422, Feld `member`); Seitenleisten `person-neu`, `person-{id}` mit drei Formularen (Profil als Hauptformular, Arbeitszeiten, Abwesenheit).

- [ ] **Step 1: Failing test – `tests/Feature/StaffPageTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Enums\TenantType;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class StaffPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    /**
     * @return array<string, mixed>
     */
    private function salon(): array
    {
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['type' => TenantType::Salon]);
        $setup['owner'] = $this->createMember($setup['tenant'], 'tenant_owner');
        $setup['member'] = StaffMember::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Anna', 'is_active' => true,
        ]);

        return $setup;
    }

    public function test_salon_owner_sees_people_with_their_drawer_sections(): void
    {
        $setup = $this->salon();
        StaffWorkingHour::create([
            'tenant_id' => $setup['tenant']->id, 'staff_member_id' => $setup['member']->id,
            'weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00',
        ]);
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->get('/admin/staff')->assertOk();

        $id = $setup['member']->id;
        $response->assertSee('Einstellungen › Platz & Zeiten')
            ->assertSee('data-drawer-open="person-'.$id.'"', false)
            ->assertSee('id="person-neu" data-drawer hidden', false)
            ->assertSee('name="hours[0][starts_at]"', false)
            ->assertSee('value="09:00"', false)
            ->assertSee('name="hours[__INDEX__][weekday]"', false)
            ->assertSee('action="'.route('admin.staff.absences.store', $setup['member']).'"', false)
            ->assertSee('1 Zeitfenster je Woche');
        $this->assertMatchesRegularExpression('/data-nav-key="staff"[^>]*aria-current="page"/', (string) $response->getContent());
    }

    public function test_restaurants_get_an_explanation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->getJson('/admin/staff')
            ->assertForbidden()
            ->assertJsonPath('message', 'Mitarbeiter mit Arbeitszeiten gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.');
    }

    public function test_a_new_person_answers_with_id_and_ignores_foreign_services(): void
    {
        $setup = $this->salon();
        $own = Service::create(['tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'name' => 'Schnitt', 'duration_minutes' => 30, 'price_minor' => 0, 'currency' => 'EUR', 'is_active' => true]);
        $other = $this->createTenantSetup();
        $foreign = Service::create(['tenant_id' => $other['tenant']->id, 'location_id' => $other['location']->id, 'name' => 'Fremd', 'duration_minutes' => 30, 'price_minor' => 0, 'currency' => 'EUR', 'is_active' => true]);
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->postJson('/admin/staff', [
            'name' => 'Ben', 'color' => '#0f766e', 'is_active' => '1', 'service_ids' => [$own->id, $foreign->id],
        ])->assertOk()->assertJsonPath('message', '„Ben“ angelegt.');

        $ben = StaffMember::withoutGlobalScopes()->where('name', 'Ben')->firstOrFail();
        $this->assertSame($ben->id, $response->json('id'));
        $this->assertSame([$own->id], $ben->services()->withoutGlobalScopes()->pluck('services.id')->all());
    }

    public function test_working_hours_name_the_row_with_a_reversed_window(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $response = $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}/working-hours", [
            'hours' => [
                0 => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00'],
                'n1' => ['weekday' => 1, 'starts_at' => '18:00', 'ends_at' => '09:00'],
            ],
        ])->assertStatus(422);

        $this->assertSame(
            'Das Zeitfenster endet vor seinem Beginn. Bitte eine spätere Endzeit eintragen.',
            $response->json('errors')['hours.n1.ends_at'][0] ?? null,
        );

        $this->actingAs($setup['owner'])->putJson("/admin/staff/{$setup['member']->id}/working-hours", [
            'hours' => [0 => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '17:00']],
        ])->assertOk()->assertJsonPath('message', 'Arbeitszeiten von „Anna“ gespeichert.');
        $this->assertSame(1, StaffWorkingHour::withoutGlobalScopes()->where('staff_member_id', $setup['member']->id)->count());
    }

    public function test_absences_are_added_and_removed_with_messages(): void
    {
        $setup = $this->salon();
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->postJson("/admin/staff/{$setup['member']->id}/absences", [
            'starts_on' => '2026-11-02', 'ends_on' => '2026-11-06', 'reason' => 'Urlaub',
        ])->assertOk()->assertJsonPath('message', 'Abwesenheit für „Anna“ eingetragen.');

        $absence = StaffAbsence::withoutGlobalScopes()->where('staff_member_id', $setup['member']->id)->firstOrFail();
        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/absences/{$absence->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Abwesenheit gelöscht.');
    }

    public function test_a_person_with_upcoming_appointments_stays(): void
    {
        $setup = $this->salon();
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(10, 0);
        Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 1,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHour()->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast', 'staff_member_id' => $setup['member']->id,
        ]);
        $this->clearTenantContext();

        $this->actingAs($setup['owner'])->deleteJson("/admin/staff/{$setup['member']->id}")
            ->assertStatus(422)
            ->assertJsonPath('errors.member.0', '„Anna“ hat noch einen kommenden Termin. Bitte ihn zuerst an eine andere Person geben oder „Anna“ pausieren: Haken bei „Buchbar“ entfernen.');
        $this->assertNotNull($setup['member']->fresh());
    }

    public function test_the_page_uses_no_inline_handlers_or_scripts(): void
    {
        foreach (['index', 'partials/fields', 'partials/hour-row'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/staff/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
            $this->assertStringNotContainsString('<script', $source, $view);
        }
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=StaffPageTest`
Expected: FAIL.

- [ ] **Step 3: `app/Http/Controllers/Admin/StaffMemberController.php` ersetzen**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\ReservationStatus;
use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\Service;
use App\Models\StaffAbsence;
use App\Models\StaffMember;
use App\Models\StaffWorkingHour;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Einstellungen › Platz & Zeiten › Mitarbeiter (Salon): wer Termine annimmt,
 * mit Leistungen, Arbeitszeiten und Abwesenheiten. Gilt für den gewählten
 * Standort. Der Einrichtungsassistent legt die erste Person über store() an.
 */
class StaffMemberController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function index(): View
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        return view('admin.staff.index', [
            'location' => $location,
            'members' => StaffMember::where('location_id', $location->id)
                ->with(['services', 'workingHours', 'absences' => fn ($query) => $query->where('ends_at', '>=', now())->orderBy('starts_at')])
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get(),
            'services' => Service::where('location_id', $location->id)->where('is_active', true)->orderBy('name')->get(),
            'defaultColor' => config('swayy.ui.default_staff_color'),
        ]);
    }

    public function store(Request $request): mixed
    {
        $location = $this->context->location();
        abort_if($location === null, 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules(), [], $this->attributes());

        $member = StaffMember::create([
            'tenant_id' => $location->tenant_id,
            'location_id' => $location->id,
            'name' => $validated['name'],
            'bio' => $validated['bio'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);
        $member->services()->sync($this->serviceIds($location->id, $validated));

        $this->audit->log('staff_member.created', $member, null, $validated);

        $message = __('„:name“ angelegt.', ['name' => $member->name]);
        // id: Der Einrichtungsassistent merkt sich die angelegte Person.
        if ($request->wantsJson()) {
            return response()->json(['message' => $message, 'id' => $member->id]);
        }

        return back()->with('success', $message);
    }

    public function update(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $validated = $request->validate($this->rules(), [], $this->attributes());

        $old = $member->only(['name', 'is_active']);
        $member->update([
            'name' => $validated['name'],
            'bio' => $validated['bio'] ?? null,
            'color' => $validated['color'] ?? null,
            'is_active' => $request->boolean('is_active', true),
        ]);
        $member->services()->sync($this->serviceIds($member->location_id, $validated));

        $this->audit->log('staff_member.updated', $member, $old, $validated);

        return $this->saved($request, __('„:name“ gespeichert.', ['name' => $member->name]));
    }

    public function destroy(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        // Kommende Termine verlören sonst still ihre Person (nullOnDelete).
        $upcoming = Reservation::where('location_id', $member->location_id)
            ->where('staff_member_id', $member->id)
            ->where('start_at', '>=', now())
            ->whereIn('status', ReservationStatus::activeStatuses())
            ->count();
        if ($upcoming > 0) {
            return $this->failed($request, 'member', trans_choice(
                '„:name“ hat noch einen kommenden Termin. Bitte ihn zuerst an eine andere Person geben oder „:name“ pausieren: Haken bei „Buchbar“ entfernen.|„:name“ hat noch :count kommende Termine. Bitte sie zuerst an andere Personen geben oder „:name“ pausieren: Haken bei „Buchbar“ entfernen.',
                $upcoming,
                ['name' => $member->name, 'count' => $upcoming],
            ));
        }

        $name = $member->name;
        $this->audit->log('staff_member.deleted', $member, ['name' => $name]);
        $member->delete();

        return $this->saved($request, __('„:name“ gelöscht.', ['name' => $name]));
    }

    /**
     * Wochenzeiten einer Person ersetzen. Ohne Eintrag gelten die
     * Öffnungszeiten des Standorts.
     */
    public function updateWorkingHours(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $validated = $request->validate([
            'hours' => ['nullable', 'array'],
            'hours.*.weekday' => ['required', 'integer', 'min:0', 'max:6'],
            'hours.*.starts_at' => ['required', 'date_format:H:i'],
            'hours.*.ends_at' => ['required', 'date_format:H:i'],
        ], [
            'hours.*.starts_at.required' => __('Bitte bei jedem Zeitfenster eintragen, wann es beginnt.'),
            'hours.*.starts_at.date_format' => __('Bitte den Beginn als Uhrzeit eintragen, etwa 09:00.'),
            'hours.*.ends_at.required' => __('Bitte bei jedem Zeitfenster eintragen, wann es endet.'),
            'hours.*.ends_at.date_format' => __('Bitte das Ende als Uhrzeit eintragen, etwa 18:00.'),
        ]);

        foreach ($validated['hours'] ?? [] as $i => $hour) {
            if ($hour['ends_at'] <= $hour['starts_at']) {
                return $this->failed($request, "hours.{$i}.ends_at", __('Das Zeitfenster endet vor seinem Beginn. Bitte eine spätere Endzeit eintragen.'));
            }
        }

        DB::transaction(function () use ($member, $validated) {
            $member->workingHours()->delete();
            foreach ($validated['hours'] ?? [] as $hour) {
                StaffWorkingHour::create([
                    'tenant_id' => $member->tenant_id,
                    'staff_member_id' => $member->id,
                    'weekday' => (int) $hour['weekday'],
                    'starts_at' => $hour['starts_at'],
                    'ends_at' => $hour['ends_at'],
                ]);
            }
        });

        $this->audit->log('staff_member.working_hours_updated', $member, null, ['count' => count($validated['hours'] ?? [])]);

        return $this->saved($request, __('Arbeitszeiten von „:name“ gespeichert.', ['name' => $member->name]));
    }

    public function storeAbsence(Request $request, StaffMember $member): mixed
    {
        abort_if($member->location_id !== $this->context->locationId(), 404);
        $this->ensureSalon();

        $tz = $member->location->timezone;

        $validated = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'starts_time' => ['nullable', 'date_format:H:i'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'ends_time' => ['nullable', 'date_format:H:i'],
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        // Ohne Uhrzeit ganze Tage (00:00 bis Tagesende) in der Zeitzone des Standorts, gespeichert in UTC.
        $startsLocal = CarbonImmutable::parse($validated['starts_on'].' '.($validated['starts_time'] ?? '00:00'), $tz);
        $endsLocal = isset($validated['ends_time'])
            ? CarbonImmutable::parse($validated['ends_on'].' '.$validated['ends_time'], $tz)
            : CarbonImmutable::parse($validated['ends_on'], $tz)->endOfDay();

        if ($endsLocal->lte($startsLocal)) {
            return $this->failed($request, 'ends_time', __('Das Ende der Abwesenheit liegt vor dem Beginn. Bitte Datum oder Uhrzeit des Endes später wählen.'));
        }

        $absence = StaffAbsence::create([
            'tenant_id' => $member->tenant_id,
            'staff_member_id' => $member->id,
            'starts_at' => $startsLocal->utc(),
            'ends_at' => $endsLocal->utc(),
            'reason' => $validated['reason'] ?? null,
        ]);

        $this->audit->log('staff_member.absence_created', $absence, null, $validated);

        return $this->saved($request, __('Abwesenheit für „:name“ eingetragen.', ['name' => $member->name]));
    }

    public function deleteAbsence(Request $request, StaffAbsence $absence): mixed
    {
        abort_if($absence->staffMember->location_id !== $this->context->locationId(), 404);
        $this->audit->log('staff_member.absence_deleted', $absence, ['reason' => $absence->reason]);
        $absence->delete();

        return $this->saved($request, __('Abwesenheit gelöscht.'));
    }

    private function ensureSalon(): void
    {
        abort_unless($this->context->tenant()?->isSalon(), 403, __('Mitarbeiter mit Arbeitszeiten gibt es bei Salons. Den Betriebstyp stellst du unter Einstellungen › Allgemein um.'));
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'bio' => ['nullable', 'string', 'max:500'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['nullable', 'boolean'],
            'service_ids' => ['nullable', 'array'],
            'service_ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return ['is_active' => __('Buchbar')];
    }

    /**
     * Nur Leistungen dieses Standorts; fremde IDs fallen weg.
     *
     * @param  array<string, mixed>  $validated
     * @return Collection<int, int>
     */
    private function serviceIds(int $locationId, array $validated): Collection
    {
        return Service::where('location_id', $locationId)->whereIn('id', $validated['service_ids'] ?? [])->pluck('id');
    }
}
```

- [ ] **Step 4: Ansichten**

`resources/views/admin/staff/partials/fields.blade.php`:
```blade
{{-- Profil einer Person in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID der Person). --}}
@php($p = 'person-'.$prefix)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$member?->name" required maxlength="120" placeholder="etwa Alex" />
<x-field name="bio" label="Kurzvorstellung" :id="$p.'-bio'" help="Ein, zwei Sätze für die Auswahl auf der Buchungsseite.">
    <textarea id="{{ $p }}-bio" name="bio" rows="2" maxlength="500" aria-describedby="{{ $p }}-bio-help" class="w-full">{{ $member?->bio }}</textarea>
</x-field>
<x-field name="color" label="Farbe im Terminplan" :id="$p.'-color'">
    <input id="{{ $p }}-color" type="color" name="color" value="{{ $member?->color ?? $defaultColor }}"
           class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
</x-field>
<fieldset class="space-y-2">
    <legend class="text-sm font-semibold text-stone-800">Leistungen</legend>
    @forelse($services as $service)
        <label class="flex items-center gap-3">
            <input type="checkbox" name="service_ids[]" value="{{ $service->id }}" @checked($member?->services->contains($service))>
            <span class="[overflow-wrap:anywhere]">{{ $service->name }} <span class="text-stone-500">({{ $service->durationFormatted() }})</span></span>
        </label>
    @empty
        <p class="text-sm text-stone-600">Leistungen legst du unter <a href="{{ route('admin.services.index') }}" class="font-semibold underline underline-offset-2">Leistungen</a> an.</p>
    @endforelse
</fieldset>
<x-checkbox name="is_active" :id="$p.'-active'" label="Buchbar" :checked="$member?->is_active ?? true"
            help="Pausiert bleibt die Person mit ihren Terminen erhalten, Gäste wählen sie online nicht." />
```

`resources/views/admin/staff/partials/hour-row.blade.php`:
```blade
{{-- Ein Zeitfenster der Arbeitszeit (Zeilen: resources/js/admin/rows.js). $key = Schlüssel der Zeile, in der Vorlage __INDEX__. --}}
@php($p = 'wh-'.$member->id.'-'.$key)
<div data-row data-change-item="{{ $p }}" class="flex flex-wrap items-end gap-x-3 gap-y-2">
    <x-field :name="'hours['.$key.'][weekday]'" :id="$p.'-day'" label="Tag" class="w-36">
        <select id="{{ $p }}-day" name="hours[{{ $key }}][weekday]" class="w-full">
            @foreach($weekdays as $day => $dayName)
                <option value="{{ $day }}" @selected((int) ($hour['weekday'] ?? 0) === $day)>{{ $dayName }}</option>
            @endforeach
        </select>
    </x-field>
    <x-field :name="'hours['.$key.'][starts_at]'" :id="$p.'-start'" label="Von" type="time" :value="$hour['starts_at'] ?? ''" required class="w-32" />
    <x-field :name="'hours['.$key.'][ends_at]'" :id="$p.'-end'" label="Bis" type="time" :value="$hour['ends_at'] ?? ''" required class="w-32" />
    <div class="flex items-center gap-2 pb-1">
        <x-change-dot />
        <button type="button" data-rows-remove class="btn btn-quiet-danger">Entfernen<span class="sr-only"> dieses Zeitfenster</span></button>
    </div>
</div>
```

`resources/views/admin/staff/index.blade.php` ersetzen:
```blade
@extends('layouts.admin')
@section('title', 'Mitarbeiter')

@section('content')
@php
    $weekdays = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];
    $tz = $location->timezone;
@endphp
<x-page-header title="Mitarbeiter" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               description="Wer Termine annimmt: mit Leistungen, Arbeitszeiten und Abwesenheiten.">
    <x-slot:actions>
        <button type="button" data-drawer-open="person-neu" class="btn btn-primary">Person hinzufügen</button>
    </x-slot:actions>
</x-page-header>

@if($members->isEmpty())
    <x-empty-state title="Noch niemand angelegt">
        Gäste buchen Termine bei einer Person. Lege an, wer bei dir Termine annimmt.
        <x-slot:action>
            <button type="button" data-drawer-open="person-neu" class="btn btn-primary">Person hinzufügen</button>
        </x-slot:action>
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($members as $member)
            @php
                $next = $member->absences->first();
            @endphp
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <x-status-icon :state="$member->is_active ? 'ok' : 'off'" :label="$member->is_active ? 'Buchbar' : 'Pausiert'" />
                <div class="min-w-0 flex-1">
                    <button type="button" data-drawer-open="person-{{ $member->id }}"
                            class="flex items-center gap-2 text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">
                        @if($member->color)
                            <span class="h-3 w-3 shrink-0 rounded-full" style="background-color: {{ $member->color }}" aria-hidden="true"></span>
                        @endif
                        {{ $member->name }}
                    </button>
                    <p class="text-[0.8125rem] text-stone-600">
                        {{ $member->services->isNotEmpty() ? $member->services->pluck('name')->join(', ') : 'Noch keine Leistung' }}
                        · {{ $member->workingHours->isNotEmpty()
                            ? trans_choice(':count Zeitfenster je Woche|:count Zeitfenster je Woche', $member->workingHours->count(), ['count' => $member->workingHours->count()])
                            : 'Arbeitszeit wie Öffnungszeiten' }}
                        @if($next)
                            · abwesend ab {{ $next->starts_at->copy()->setTimezone($tz)->format('d.m.') }}
                        @endif
                    </p>
                </div>
                <x-inline-confirm id="person-loeschen-{{ $member->id }}" :action="route('admin.staff.destroy', $member)" method="DELETE"
                                  label="Löschen" confirm="Person löschen" :subject="$member->name" :reload="true"
                                  :question="'„'.$member->name.'“ löschen? Arbeitszeiten und Abwesenheiten gehen mit.'" />
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    <x-drawer id="person-neu" title="Neue Person" description="Arbeitszeiten und Abwesenheiten trägst du danach in ihrer Seitenleiste ein.">
        <form id="form-person-neu" method="POST" action="{{ route('admin.staff.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            @include('admin.staff.partials.fields', ['member' => null, 'prefix' => 'neu'])
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-person-neu" class="btn btn-primary">Person anlegen</button>
        </x-slot:footer>
    </x-drawer>
    @foreach($members as $member)
        @php
            $hours = $member->workingHours
                ->sortBy([['weekday', 'asc'], ['starts_at', 'asc']])
                ->values()
                ->map(fn ($hour) => [
                    'weekday' => (int) $hour->weekday,
                    'starts_at' => substr((string) $hour->starts_at, 0, 5),
                    'ends_at' => substr((string) $hour->ends_at, 0, 5),
                ]);
        @endphp
        <x-drawer id="person-{{ $member->id }}" :title="$member->name" description="Profil, Arbeitszeiten und Abwesenheiten.">
            <form id="form-person-{{ $member->id }}" method="POST" action="{{ route('admin.staff.update', $member) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.staff.partials.fields', ['member' => $member, 'prefix' => (string) $member->id])
            </form>

            <section aria-labelledby="arbeitszeit-{{ $member->id }}-titel" class="space-y-4 border-t border-stone-200 pt-5">
                <h3 id="arbeitszeit-{{ $member->id }}-titel" class="text-sm font-semibold">Arbeitszeiten</h3>
                <p class="text-[0.8125rem] text-stone-600">Ohne Eintrag gelten die Öffnungszeiten des Standorts. Mit Einträgen ist {{ $member->name }} nur in diesen Zeitfenstern buchbar.</p>
                <form method="POST" action="{{ route('admin.staff.working-hours', $member) }}" data-ajax data-reload-after-save class="space-y-3">
                    @csrf
                    @method('PUT')
                    <div data-rows class="space-y-3">
                        <div data-rows-list class="space-y-3">
                            @foreach($hours as $key => $hour)
                                @include('admin.staff.partials.hour-row', ['key' => $key, 'hour' => $hour])
                            @endforeach
                        </div>
                        <template data-rows-template>
                            @include('admin.staff.partials.hour-row', ['key' => '__INDEX__', 'hour' => ['weekday' => 0, 'starts_at' => '09:00', 'ends_at' => '18:00']])
                        </template>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" data-rows-add class="btn btn-secondary">Zeitfenster hinzufügen</button>
                            <button type="submit" class="btn btn-primary">Arbeitszeiten speichern</button>
                        </div>
                    </div>
                </form>
            </section>

            <section aria-labelledby="abwesenheit-{{ $member->id }}-titel" class="space-y-4 border-t border-stone-200 pt-5">
                <h3 id="abwesenheit-{{ $member->id }}-titel" class="text-sm font-semibold">Abwesenheiten</h3>
                @if($member->absences->isEmpty())
                    <p class="text-sm text-stone-600">Für die kommende Zeit ist nichts eingetragen.</p>
                @else
                    <ul class="divide-y divide-stone-200 rounded-lg border border-stone-200">
                        @foreach($member->absences as $absence)
                            @php
                                $span = $absence->starts_at->copy()->setTimezone($tz)->format('d.m.Y H:i').' – '.$absence->ends_at->copy()->setTimezone($tz)->format('d.m.Y H:i');
                            @endphp
                            <li class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                <span class="[overflow-wrap:anywhere]">{{ $span }}@if($absence->reason) · {{ $absence->reason }}@endif</span>
                                <x-inline-confirm id="abwesenheit-loeschen-{{ $absence->id }}" :action="route('admin.staff.absences.destroy', $absence)" method="DELETE"
                                                  label="Entfernen" confirm="Entfernen" :subject="$span" :reload="true"
                                                  :question="'Abwesenheit '.$span.' entfernen? '.$member->name.' ist dann wieder buchbar.'" />
                            </li>
                        @endforeach
                    </ul>
                @endif
                <form method="POST" action="{{ route('admin.staff.absences.store', $member) }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-3">
                    @csrf
                    <div class="grid gap-3 sm:grid-cols-2">
                        <x-field name="starts_on" :id="'abw-'.$member->id.'-von'" label="Von" type="date" required />
                        <x-field name="starts_time" :id="'abw-'.$member->id.'-von-zeit'" label="ab Uhrzeit" type="time" help="Leer: ab Tagesbeginn." />
                        <x-field name="ends_on" :id="'abw-'.$member->id.'-bis'" label="Bis" type="date" required />
                        <x-field name="ends_time" :id="'abw-'.$member->id.'-bis-zeit'" label="bis Uhrzeit" type="time" help="Leer: bis Tagesende." />
                    </div>
                    <x-field name="reason" :id="'abw-'.$member->id.'-grund'" label="Grund" maxlength="120" placeholder="etwa Urlaub" />
                    <button type="submit" class="btn btn-secondary">Abwesenheit eintragen</button>
                </form>
            </section>

            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-person-{{ $member->id }}" class="btn btn-primary">Profil speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
```

`config/navigation.php`, Eintrag `staff` ersetzen:
```php
            ['key' => 'staff', 'label' => 'Mitarbeiter', 'route' => 'admin.staff.index', 'scope' => 'location', 'can' => ['tables.manage'], 'only' => 'salon',
                'keywords' => ['Mitarbeiter', 'Neue Person' => 'person-neu', 'Arbeitszeiten', 'Abwesenheit', 'Urlaub']],
```

- [ ] **Step 5: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='StaffPageTest|SalonAdminTest|OnboardingTest|StaffCalendarTest|SalonAvailabilityTest|NavigationTest|AuditLabelTest|AdminRoutesSmokeTest'`
Expected: alles grün.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/StaffMemberController.php resources/views/admin/staff config/navigation.php tests/Feature/StaffPageTest.php
git commit -m "feat(settings): Seite „Mitarbeiter“ mit Seitenleiste für Profil, Arbeitszeiten und Abwesenheiten

Arbeitszeiten als Zeilen ohne Inline-Skript, Fehler am Zeitfenster, Schutz
für Personen mit kommenden Terminen.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 12: Reservierungsliste am Smartphone und Anhänge

**Files:**
- Modify: `resources/views/admin/reservations/index.blade.php`, `resources/views/admin/reservations/show.blade.php`
- Create: `tests/Feature/ReservationListLayoutTest.php`
- Test: `tests/Feature/ReservationBookColumnsTest.php`, `tests/Feature/ReservationBookRangeTest.php`, `tests/Feature/BulkTransitionTest.php`, `tests/Feature/ReservationAttachmentTest.php` (laufen lassen)

**Interfaces:**
- Consumes: `nav.js` `initAutosubmit()` (`data-autosubmit`), `x-inline-confirm :ajax="false"` (Task 1).
- Produces: Unter 768 px ist jede Reservierung zweizeilig (Name mit Kurzzeile „Tag Uhrzeit · Personen · Tisch · Quelle“, Status, Aktionen), ohne seitliches Scrollen; „Alle auswählen“ steht dort über der Liste (`.bulk-all`, zwei Stück).

- [ ] **Step 1: Failing test – `tests/Feature/ReservationListLayoutTest.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class ReservationListLayoutTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_each_row_carries_a_compact_line_for_small_screens(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $start = CarbonImmutable::now($setup['location']->timezone)->addDay()->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id, 'location_id' => $setup['location']->id, 'party_size' => 4,
            'reservation_date' => $start->toDateString(), 'start_at' => $start->utc(), 'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone, 'status' => ReservationStatus::Confirmed, 'source' => 'online',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $reservation->tables()->attach($setup['tables'][1]->id);
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/reservations?range=all')->assertOk()->getContent();
        $body = mb_substr($html, (int) mb_strpos($html, '<tbody'));

        $this->assertStringContainsString('<p class="text-[0.8125rem] text-stone-600 md:hidden">', $body);
        $this->assertMatchesRegularExpression('/19:00–21:00 · 4 Pers\. · T2 · /', $body);
        $this->assertStringContainsString('md:min-w-[42rem]', $html);
        $this->assertDoesNotMatchRegularExpression('/class="[^"]*(?<![:\w-])min-w-\[42rem\]/', $html);
        $this->assertSame(2, substr_count($html, 'class="bulk-all rounded"'));
    }

    public function test_filters_and_attachments_use_no_inline_handlers(): void
    {
        $index = (string) file_get_contents(resource_path('views/admin/reservations/index.blade.php'));
        $show = (string) file_get_contents(resource_path('views/admin/reservations/show.blade.php'));

        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $index);
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $show);
        $this->assertSame(3, substr_count($index, 'data-autosubmit'));
        $this->assertStringContainsString('id="anhang-loeschen-{{ $attachment->id }}"', $show);
        // Gerade Anführungszeichen beendeten das Attribut data-tip mitten im Text.
        $this->assertStringNotContainsString('„Bestätigen"', $index);
        $this->assertStringNotContainsString('„Heute"', $index);
        $this->assertStringNotContainsString('„Von/Bis"', $index);
    }
}
```

- [ ] **Step 2: Test laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter=ReservationListLayoutTest`
Expected: FAIL.

- [ ] **Step 3: `resources/views/admin/reservations/index.blade.php` ändern**

1. Kopfzeile: `<div class="flex gap-2">` (Zeile 6) ersetzen durch `<div class="flex flex-wrap gap-2">`.
2. In den drei Filtern (`status`, `source`, `room_id`) jeweils `onchange="this.form.submit()"` ersetzen durch `data-autosubmit`. Im Hinweis zum Zeitraum `„Von/Bis"` ersetzen durch `„Von/Bis“`: Das gerade Anführungszeichen beendete das Attribut `data-tip` mitten im Text (dasselbe gilt für die Spaltenhinweise, die Schritt 4 neu schreibt).
3. Die Sammelleiste `<div id="bulkBar" class="mb-4 hidden items-center gap-3 …">` bekommt zusätzlich die Klasse `flex-wrap`.
4. Den Block von `<div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">` bis zum schließenden `</div>` nach `</table>` ersetzen durch:
```blade
<label class="mb-2 flex min-h-11 items-center gap-3 px-1 text-sm font-semibold md:hidden">
    <input type="checkbox" class="bulk-all rounded" aria-controls="reservationList"> Alle auswählen
</label>
<div class="rounded-2xl bg-white shadow-sm ring-1 ring-stone-100 md:overflow-x-auto">
    <table id="reservationList" class="w-full text-sm max-md:block md:min-w-[42rem]">
        <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500 max-md:hidden">
            <tr>
                <th class="w-8 px-3 py-3"><input type="checkbox" class="bulk-all rounded" aria-label="Alle auswählen"><span class="tip" tabindex="0" data-tip="Hakt alle Reservierungen der Liste an. Oben erscheint dann eine Leiste, mit der du für alle zusammen denselben Schritt ausführen kannst.">?</span></th>
                <th class="px-4 py-3">Wann<span class="tip" tabindex="0" data-tip="Tag und Uhrzeit des Besuchs. Für die nächsten Tage steht „Heute“ oder „Morgen“ da, damit sie sofort ins Auge fallen.">?</span></th>
                <th class="px-4 py-3">Gast</th>
                <th class="px-4 py-3">Pers.<span class="tip" tabindex="0" data-tip="Personen – für wie viele Gäste der Tisch reserviert ist.">?</span></th>
                <th class="px-4 py-3">Tisch</th>
                <th class="px-4 py-3">Status<span class="tip" tabindex="0" data-tip="Der Bearbeitungsstand: Anfrage wartet auf deine Bestätigung · Bestätigt ist zugesagt · Da sitzt gerade am Tisch · Abgeschlossen ist gegangen · No-Show ist nicht erschienen.">?</span></th>
                <th class="px-4 py-3">Quelle<span class="tip" tabindex="0" data-tip="Woher die Buchung kam – über deine Buchungsseite, von euch selbst eingetragen, als Laufgast oder aus der Warteliste.">?</span></th>
                <th class="px-4 py-3">Gebucht<span class="tip" tabindex="0" data-tip="Wann die Reservierung bei euch eingegangen ist. Hilft bei Rückfragen und zeigt, wie kurzfristig gebucht wurde.">?</span></th>
                <th class="px-4 py-3">Aktionen<span class="tip tip-left" tabindex="0" data-tip="Bringt die Reservierung einen Schritt weiter. „Bestätigen“ sagt dem Gast per E-Mail zu, „Ablehnen“ sagt ab. „Angekommen“ markiert, dass die Gäste da sind. „No-Show“ hält fest, dass niemand kam; das zählt beim Gast mit und kann eine Anzahlung einbehalten. „Auschecken“ gibt den Tisch wieder frei.">?</span></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-50 max-md:block [&>tr:hover]:bg-stone-50/70">
            @forelse($reservations as $r)
                @php
                    $tableNames = $r->tables->pluck('name')->implode(', ');
                @endphp
                {{-- Unter 768 px: Raster aus Auswahl, Gast mit Kurzzeile, Status und darunter den Aktionen. --}}
                <tr class="hover:bg-stone-50 max-md:grid max-md:grid-cols-[auto_minmax(0,1fr)_auto] max-md:items-start max-md:gap-x-3 max-md:gap-y-2 max-md:px-3 max-md:py-3">
                    <td class="px-3 py-3 max-md:row-span-2 max-md:p-0 max-md:pt-0.5"><input type="checkbox" form="bulkForm" name="ids[]" value="{{ $r->id }}" class="bulk-check rounded" aria-label="{{ $r->guest_name_snapshot }} auswählen"></td>
                    <td class="whitespace-nowrap px-4 py-3 max-md:hidden">
                        <div class="text-xs font-medium text-stone-500">{{ $r->localDayLabel() }}</div>
                        <div class="font-semibold">{{ $r->localStart()->format('H:i') }}<span class="font-normal text-stone-400">–{{ $r->localEnd()->format('H:i') }}</span></div>
                    </td>
                    <td class="min-w-0 px-4 py-3 max-md:p-0">
                        <a href="{{ route('admin.reservations.show', $r) }}" class="font-semibold hover:underline [overflow-wrap:anywhere]">{{ $r->guest_name_snapshot }}</a>
                        @if($r->guest?->is_vip)<span title="VIP">⭐</span>@endif
                        @if($r->allergy_note)<span title="Allergien: {{ $r->allergy_note }}">⚠️</span>@endif
                        @if($r->no_show_risk >= 50)<span class="rounded bg-red-100 px-1.5 text-xs text-red-700">Risiko</span>@endif
                        <p class="text-[0.8125rem] text-stone-600 md:hidden">{{ $r->localDayLabel() }} {{ $r->localStart()->format('H:i') }}–{{ $r->localEnd()->format('H:i') }} · {{ $r->party_size }} Pers.{{ $tableNames !== '' ? ' · '.$tableNames : '' }} · {{ __('reservations.source.' . $r->source) }}</p>
                    </td>
                    <td class="px-4 py-3 max-md:hidden">{{ $r->party_size }}</td>
                    <td class="px-4 py-3 max-md:hidden">{{ $tableNames !== '' ? $tableNames : '–' }}</td>
                    <td class="px-4 py-3 max-md:p-0"><x-status-badge :status="$r->status" /></td>
                    <td class="px-4 py-3 text-stone-500 max-md:hidden">{{ __('reservations.source.' . $r->source) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-xs text-stone-500 max-md:hidden">
                        @if($gebucht = $r->localCreatedAt())
                            <span title="{{ $gebucht->translatedFormat('l, d.m.Y H:i') }} Uhr">
                                {{ $gebucht->format('d.m.y') }}
                                <span class="text-stone-400">{{ $gebucht->format('H:i') }}</span>
                            </span>
                        @else
                            –
                        @endif
                    </td>
                    <td class="px-4 py-3 max-md:col-span-2 max-md:col-start-2 max-md:p-0">
                        <div class="flex flex-wrap gap-1.5">
                            @if($r->status->value === 'requested')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="confirmed">
                                    <button class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">Bestätigen</button>
                                </form>
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="rejected">
                                    <button class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">Ablehnen</button>
                                </form>
                            @elseif($r->status->value === 'confirmed')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="seated">
                                    <button class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">Angekommen</button>
                                </form>
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="no_show">
                                    <button class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">No-Show</button>
                                </form>
                            @elseif($r->status->value === 'seated')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}"
                                      data-confirm="Der Tisch wird freigegeben und die Reservierung abgeschlossen."
                                      data-confirm-title="Gäste auschecken?" data-confirm-ok="✓ Auschecken">@csrf
                                    <input type="hidden" name="status" value="completed">
                                    <button class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700">✓ Auschecken</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="px-4 py-8 text-center text-stone-500">Keine Reservierungen gefunden.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
```
Die Aktionen sind dieselben wie bisher; neu ist der umbrechende Wrapper `flex flex-wrap gap-1.5`. Die Datei hat oben schon einen `@php … @endphp`-Block (Zeitraum-Vorgaben); im Raster steht deshalb ebenfalls die Blockform (`BladePhpBlockTest`).

5. Das Skript der Sammelauswahl am Ende ersetzen durch:
```blade
<script>
(function () {
    const bar = document.getElementById('bulkBar');
    const count = document.getElementById('bulkCount');
    const alls = [...document.querySelectorAll('.bulk-all')];
    const boxes = () => [...document.querySelectorAll('.bulk-check')];
    if (!bar || alls.length === 0) return;

    function sync() {
        const checked = boxes().filter(b => b.checked).length;
        count.textContent = checked;
        bar.classList.toggle('hidden', checked === 0);
        bar.classList.toggle('flex', checked > 0);
        alls.forEach(all => {
            all.checked = checked > 0 && checked === boxes().length;
            all.indeterminate = checked > 0 && checked < boxes().length;
        });
    }

    alls.forEach(all => all.addEventListener('change', () => { boxes().forEach(b => b.checked = all.checked); sync(); }));
    boxes().forEach(b => b.addEventListener('change', sync));
    document.getElementById('bulkClear').addEventListener('click', () => { boxes().forEach(b => b.checked = false); sync(); });
})();
</script>
```

- [ ] **Step 4: `resources/views/admin/reservations/show.blade.php` – Anhang löschen**

Das Formular
```blade
                            <form method="POST" action="{{ route('admin.reservations.attachments.destroy', [$reservation, $attachment]) }}"
                                  onsubmit="return confirm('Datei löschen?')">
                                @csrf @method('DELETE')
                                <button class="text-xs text-red-500 hover:text-red-700">Löschen</button>
                            </form>
```
ersetzen durch
```blade
                            <x-inline-confirm id="anhang-loeschen-{{ $attachment->id }}" :action="route('admin.reservations.attachments.destroy', [$reservation, $attachment])" method="DELETE"
                                              :ajax="false" label="Löschen" confirm="Datei löschen" :subject="$attachment->original_name"
                                              :question="'„'.$attachment->original_name.'“ löschen? Die Datei lässt sich danach nicht wiederherstellen.'" />
```

- [ ] **Step 5: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='ReservationListLayoutTest|ReservationBookColumnsTest|ReservationBookRangeTest|BulkTransitionTest|ReservationAttachmentTest|ReservationTimelineTest'`
Expected: alles grün.

- [ ] **Step 6: Commit**

```bash
git add resources/views/admin/reservations/index.blade.php resources/views/admin/reservations/show.blade.php tests/Feature/ReservationListLayoutTest.php
git commit -m "feat(reservations): Liste am Smartphone zweizeilig, Filter und Anhänge ohne Inline-Handler

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 13: Nacharbeiten an den Betriebsseiten aus 2a

**Files:**
- Modify: `app/Http/Controllers/Admin/UserManagementController.php`, `lang/de/roles.php`, `app/Http/Controllers/Admin/LocationController.php`, `app/Http/Controllers/Admin/LocationLogoController.php`, `app/Http/Controllers/Admin/Settings/GeneralController.php`, `resources/views/admin/locations/index.blade.php`, `resources/views/admin/locations/partials/fields.blade.php`, `resources/views/admin/billing/index.blade.php`, `config/swayy.php` (`locations.intro_max`), `.env.example`
- Create: `tests/Feature/BillingPageTest.php`
- Test: `tests/Feature/TeamPageTest.php`, `tests/Feature/LocationManagementTest.php`, `tests/Feature/LocationLogoTest.php`

**Interfaces:**
- Consumes: `x-status-icon :announce` (Task 2); Fehler an Gruppen ohne ID (Task 1, deckt „mindestens ein Standort“ ab); offene Rückfrage beim Laden fokussiert (Task 1, deckt „Betrieb löschen“ ab).
- Produces: `config('swayy.locations.intro_max')` (int, Zeichen).

- [ ] **Step 1: Failing tests**

`tests/Feature/TeamPageTest.php`, neue Tests:
```php
    public function test_an_overlong_email_is_rejected_with_the_limit(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $email = 'person@'.str_repeat('abcdefghij.', 18).'test';

        $this->actingAs($owner)->postJson('/admin/users/invite', ['email' => $email, 'role' => 'host', 'all_locations' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Die E-Mail-Adresse ist zu lang. Bitte höchstens 200 Zeichen verwenden.');
    }

    public function test_the_operations_manager_learns_who_changes_access(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($manager)->postJson('/admin/users/invite', ['email' => $staff->email, 'role' => 'host', 'all_locations' => '1'])
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Diese Person gehört schon zum Team. Rolle und Standorte ändert eine Person mit der Rolle „Inhaber“ oder „Administration“.');
    }

    public function test_role_summaries_name_what_stays_with_the_owner(): void
    {
        $this->assertStringContainsString('bleiben beim Inhaber', __('roles.tenant_admin.summary'));
        $this->assertStringContainsString('Rollen vergibt die Administration', __('roles.operations_manager.summary'));
    }
```

`tests/Feature/LocationManagementTest.php`, neue Tests:
```php
    public function test_the_intro_limit_comes_from_the_settings(): void
    {
        config(['swayy.locations.intro_max' => 120]);
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/locations')
            ->assertOk()
            ->assertSee('bis 120 Zeichen')
            ->assertSee('maxlength="120"', false);

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", [
            'name' => 'Mitte', 'timezone' => 'Europe/Berlin', 'public_intro' => str_repeat('a', 121),
        ])->assertStatus(422)->assertJsonValidationErrors('public_intro');
    }

    public function test_the_timezone_is_marked_as_required(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = (string) $this->actingAs($owner)->get('/admin/locations')->getContent();

        $this->assertMatchesRegularExpression('/Zeitzone<span class="text-red-700" aria-hidden="true"> \*<\/span>/', $html);
    }

    public function test_more_locations_than_the_plan_allows_reads_plainly(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1]])->id]);
        Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/locations')
            ->assertOk()
            ->assertSee('2 Standorte angelegt, dein Tarif umfasst einen.')
            ->assertDontSee('2 von 1 Standorten');
    }
```

`tests/Feature/LocationLogoTest.php`, neuer Test:
```php
    public function test_a_new_logo_replaces_the_old_file_after_it_is_stored(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();
        $url = route('admin.locations.logo.store', $setup['location']);

        $this->actingAs($owner)->postJson($url, ['logo' => UploadedFile::fake()->image('alt.png', 120, 60)])->assertOk();
        $old = (string) $setup['location']->fresh()->brand_logo_path;
        $this->actingAs($owner)->postJson($url, ['logo' => UploadedFile::fake()->image('neu.png', 120, 60)])->assertOk();
        $new = (string) $setup['location']->fresh()->brand_logo_path;

        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertExists($new);
        Storage::disk('public')->assertMissing($old);
    }
```

`tests/Feature/BillingPageTest.php` anlegen:
```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BillingProfile;
use App\Models\Plan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class BillingPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_an_active_direct_debit_is_named_once_and_can_be_cancelled(): void
    {
        config(['services.gocardless.access_token' => 'sandbox_token']);
        $setup = $this->createTenantSetup();
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['price_monthly_minor' => 3900, 'currency' => 'EUR'])->id]);
        BillingProfile::create(['tenant_id' => $setup['tenant']->id, 'billing_email' => 'kunde@beispiel.test']);
        BillingProfile::where('tenant_id', $setup['tenant']->id)->update([
            'gocardless_status' => 'active', 'gocardless_subscription_id' => 'SB123', 'gocardless_mandate_id' => 'MD123',
        ]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/billing')
            ->assertOk()
            ->assertSee('title="Aktiv"', false)
            ->assertDontSee('<span class="sr-only">Aktiv</span>', false)
            ->assertSee('Lastschrift kündigen')
            ->assertDontSee('Per Lastschrift einrichten');
    }
}
```

- [ ] **Step 2: Tests laufen lassen**

Run: `PAO_DISABLE=1 php artisan test --filter='TeamPageTest|LocationManagementTest|LocationLogoTest|BillingPageTest'`
Expected: FAIL in den neuen Tests.

- [ ] **Step 3: Team & Rollen**

`app/Http/Controllers/Admin/UserManagementController.php`, in `invite()`:
- Regel `'email' => ['required', 'email:rfc'],` ersetzen durch `'email' => ['required', 'email:rfc', 'max:200'],` und bei den Meldungen ergänzen: `'email.max' => __('Die E-Mail-Adresse ist zu lang. Bitte höchstens :max Zeichen verwenden.'),`
- Die Zeile
```php
                return $this->failed($request, 'email', __('Diese Person gehört schon zum Team. Rolle und Standorte änderst du direkt in der Liste.'));
```
ersetzen durch
```php
                // Rollen und Standorte ändern nur Personen mit users.roles.manage; die anderen erfahren, wer das kann.
                return $this->failed($request, 'email', $request->user()->canInTenant('users.roles.manage', $tenant)
                    ? __('Diese Person gehört schon zum Team. Rolle und Standorte änderst du direkt in der Liste.')
                    : __('Diese Person gehört schon zum Team. Rolle und Standorte ändert eine Person mit der Rolle „Inhaber“ oder „Administration“.'));
```

`lang/de/roles.php`, zwei Zusammenfassungen ersetzen:
```php
    'tenant_admin' => [
        'label' => 'Administration',
        'summary' => 'Alle Einstellungen, Team mit Rollen, Abo und Zahlungen. Datenexport, Import und „Betrieb löschen“ bleiben beim Inhaber.',
    ],
    'operations_manager' => [
        'label' => 'Betriebsleitung',
        'summary' => 'Service, Buchungen, Gäste, Zeiten, Räume, Events, Vorlagen, Marketing und Standorte; lädt Personen ein, Rollen vergibt die Administration.',
    ],
```

- [ ] **Step 4: Standorte**

`config/swayy.php`, Abschnitt `'locations'`: Kommentar ergänzen um `| intro_max: längste Begrüßung auf der Buchungsseite in Zeichen (100–5000).` und im Array:
```php
        'intro_max' => EnvSetting::integer('SWAYY_LOCATION_INTRO_MAX', default: 1000, min: 100, max: 5000),
```
`.env.example` nach `# SWAYY_DEFAULT_TIMEZONE=Europe/Berlin`:
```dotenv
# Längste Begrüßung auf der Buchungsseite in Zeichen (100–5000)
# SWAYY_LOCATION_INTRO_MAX=1000
```

`app/Http/Controllers/Admin/LocationController.php`: in `rules()` die Regel `'public_intro' => ['nullable', 'string', 'max:1000'],` ersetzen durch `'public_intro' => ['nullable', 'string', 'max:'.(int) config('swayy.locations.intro_max')],`; in `index()` dem View zusätzlich `'introMax' => (int) config('swayy.locations.intro_max'),` übergeben.

`app/Http/Controllers/Admin/Settings/GeneralController.php`: in `update()` dieselbe Ersetzung für `public_intro` (die alte Sammelseite schickt das Feld bis Teil 3).

`resources/views/admin/locations/partials/fields.blade.php`:
- Zeitzone: `<x-field name="timezone" label="Zeitzone" :id="$p.'-timezone'" help="…">` um `required` ergänzen: `<x-field name="timezone" label="Zeitzone" :id="$p.'-timezone'" required help="Gilt für Öffnungszeiten, Erinnerungen und alle Uhrzeiten dieses Standorts.">`
- Begrüßung ersetzen durch:
```blade
<x-field name="public_intro" label="Begrüßung auf der Buchungsseite" :id="$p.'-public_intro'"
         :help="'Ein, zwei Sätze über dem Buchungsformular, bis '.$introMax.' Zeichen.'">
    <textarea id="{{ $p }}-public_intro" name="public_intro" rows="3" maxlength="{{ $introMax }}"
              aria-describedby="{{ $p }}-public_intro-help" class="w-full">{{ $loc?->public_intro }}</textarea>
</x-field>
```

`resources/views/admin/locations/index.blade.php`, den Absatz mit der Zählung ersetzen:
```blade
<p class="mb-4 text-sm text-stone-600">
    @if($limit === null)
        {{ trans_choice(':count Standort|:count Standorte', $used, ['count' => $used]) }}
    @elseif($used > $limit)
        {{ trans_choice(':count Standort angelegt|:count Standorte angelegt', $used, ['count' => $used]) }}, {{ trans_choice('dein Tarif umfasst einen.|dein Tarif umfasst :count.', (int) $limit, ['count' => $limit]) }}
    @else
        {{ $used }} von {{ $limit }} Standorten deines Tarifs genutzt
    @endif
</p>
```

`app/Http/Controllers/Admin/LocationLogoController.php`, in `store()` den Block
```php
        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
        }

        $path = $request->file('logo')->store(config('swayy.uploads.logo_directory').'/'.$location->id, 'public');
        $location->update(['brand_logo_path' => $path]);
```
ersetzen durch
```php
        $previous = $location->brand_logo_path;
        $path = $request->file('logo')->store(config('swayy.uploads.logo_directory').'/'.$location->id, 'public');
        $location->update(['brand_logo_path' => $path]);
        // Die alte Datei geht erst, wenn die neue gespeichert ist; scheitert das Speichern, bleibt das alte Logo.
        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }
```

- [ ] **Step 5: Abo & Rechnungen**

`resources/views/admin/billing/index.blade.php`: `<x-status-icon state="ok" label="Aktiv" />` ersetzen durch `<x-status-icon state="ok" label="Aktiv" :announce="false" />`.

- [ ] **Step 6: Tests laufen lassen**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='TeamPageTest|LocationManagementTest|LocationLogoTest|BillingPageTest|DirectDebitTest|SettingsGeneralPageTest|SettingsFormErrorsTest|RoleAssignmentTest'
```
Expected: alles grün. (`RoleAssignmentTest` gibt es nur nach Task 0.)

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/Admin/UserManagementController.php lang/de/roles.php app/Http/Controllers/Admin/LocationController.php app/Http/Controllers/Admin/LocationLogoController.php app/Http/Controllers/Admin/Settings/GeneralController.php resources/views/admin/locations resources/views/admin/billing/index.blade.php config/swayy.php .env.example tests/Feature/TeamPageTest.php tests/Feature/LocationManagementTest.php tests/Feature/LocationLogoTest.php tests/Feature/BillingPageTest.php
git commit -m "fix(admin): Restpunkte aus 2a an Team, Standorten und Abo

E-Mail-Länge beim Einladen, Meldung je nach Recht, Rollenbeschreibungen;
Zeitzone als Pflichtfeld markiert, Länge der Begrüßung aus den
Einstellungen, altes Logo erst nach dem neuen gelöscht, klare Zählung über
der Tarifgrenze; „Aktiv“ nur einmal angesagt.

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 14: Prüfung Teil 2b, Protokoll, Testinstanz

Diese Aufgabe führt die steuernde Sitzung selbst aus (Browser, Server); sie ändert Code nur, wenn die Prüfung einen Fehler findet.

**Files:**
- Create: `docs/superpowers/plans/2026-10-10-new-ui-teil-2b-protokoll.md`
- Modify: bei Befunden die betroffenen Dateien; nach Freigabe `C:\Users\brigh\Claude Workingdir\Serverprotokolle\mx.mailware.cc.md` (Eintrag zum Auffrischen von dev-swayy)

**Interfaces:**
- Consumes: alles aus Task 0–13.
- Produces: grüne Gesamtprüfung, Prüfprotokoll, nach Freigabe dev-swayy auf dem Stand von `new-ui`.

- [ ] **Step 1: Gesamte Testsuite und Werkzeuge**

Run:
```bash
PAO_DISABLE=1 php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm test
npm run build
```
Expected: alles grün. Anzahl der Tests mit dem Stand aus Protokoll 2a vergleichen (1107 PHPUnit, 31 Vitest) und notieren.

- [ ] **Step 2: PostgreSQL-Lauf**

Run:
```bash
MSYS_NO_PATHCONV=1 docker run -d --name gastrobook-pgtest-2b -p 127.0.0.1:55433:5432 -e POSTGRES_USER=gastrobook -e POSTGRES_PASSWORD=gastrobook -e POSTGRES_DB=gastrobook_test --tmpfs /var/lib/postgresql/data postgres:17-alpine
PAO_DISABLE=1 DB_PORT=55433 vendor/bin/phpunit -c phpunit.pgsql.xml
docker rm -f gastrobook-pgtest-2b
```
Expected: Exit-Code 0. Nie gleichzeitig mit Step 1 im selben Worktree.

- [ ] **Step 3: Browserprüfung**

Dev-Server `gastrobook-new-ui` (Port 8010) mit Demodaten; zusätzlich ein Salon (Betriebstyp unter „Allgemein“ umstellen und danach zurück) und Mitglieder mit den Rollen Inhaber, Betriebsleitung, Standortleitung, Empfang. Breiten 390, 768 und 1280 px; jede Seite ohne seitliches Scrollen (`document.documentElement.scrollWidth <= innerWidth`).

| Seite | Prüfung |
|---|---|
| Kapazität & Dauer | Tischzeit hinzufügen, ändern, entfernen: Zähler „n Einstellungen geändert“, Punkt an der Zeile; „Verwerfen“ stellt die Zeilen wieder her; Überschneidung → Fehler am Feld „Ab Personen“ der Zeile; Zählweise „Nach Tischen“ blendet „Höchstens gleichzeitig“ aus; Minuten zeigen „entspricht …“; Salon sieht Takt, Puffer, Lückenoptimierung |
| Buchungsregeln | Stornofrist 120 → „entspricht 2 Std.“; „Größte Gruppe“ kleiner als „Kleinste Gruppe“ → Fehler mit Zahl; Bestätigung als Auswahlkarten per Tastatur |
| Buchungsformular | E-Mail und Telefon „Ausgeblendet“ → Hinweis erscheint; Zähler „1 Feld geändert“; Standortleitung sieht den Eintrag nicht |
| Buchungsseite & Widget | Adresse kopieren; Knopftext mit Anführungszeichen → Code maskiert, „Code kopieren“ liefert denselben Text; „Knopf schwebt“ ergänzt `data-float`; Konfetti speichert über die Leiste |
| Tags | Tag anlegen (Seitenleiste „Neu“ danach leer), umbenennen auf vorhandenen Namen → Grund am Feld, löschen mit Zahl der Reservierungen; Klick auf den Hintergrund mit Eingaben → Hinweis, Leiste bleibt |
| Räume & Tische | Raum anlegen, Tisch anlegen (steht im Tischplan oben links), Kinderstuhl speichern, Raum mit Tischen ohne „Löschen“, Tisch mit kommender Reservierung → Grund in der Zeile; Maße speichern hält die Seitenleiste offen; Kombination mit einem kombinierbaren Tisch → Grund an der Gruppe |
| Leistungen (Salon) | Preis 49,90 € speichern, Liste zeigt „49,90 €“; Leistung mit kommendem Termin → Grund in der Zeile |
| Mitarbeiter (Salon) | Zeitfenster hinzufügen, Ende vor Beginn → Fehler an der Zeile; Abwesenheit eintragen → Liste lädt neu, Seitenleiste ist wieder offen (Adresse `#person-…`); Abwesenheit entfernen |
| Reservierungen | 390 px: Zeilen zweizeilig, „Alle auswählen“ über der Liste, Sammelleiste bricht um; Filter wechseln ohne Inline-Handler; Anhang löschen fragt in der Zeile |
| Team, Standorte, Abo, Daten | „Mindestens ein Standort“ steht an der Gruppe; Zeitzone mit Stern; „Daten“: falscher Name → Frage offen und fokussiert |
| Einstellungsmenü und Suche | „Stornofrist“ führt zu Buchungsregeln#fristen, „Tischkombination“ zu Räume & Tische#kombinationen, „Neuer Tag“ öffnet die Seitenleiste |
| Alte Sammelseite | Reiter wechseln, Buchungsregeln speichern, Tisch „Kombinierbar“ aus- und einschalten wirkt |

Tastatur: Seitenleisten, Rückfragen, Zeilen und Speicherleiste nur mit Tab, Enter, Leertaste und Escape; sichtbarer Fokus überall; Fokus nie unter der Speicherleiste.

- [ ] **Step 4: Befunde beheben**

Jeder Befund ein eigener Commit mit Test, wo sinnvoll. Danach Step 1 erneut.

- [ ] **Step 5: Prüfprotokoll schreiben**

`docs/superpowers/plans/2026-10-10-new-ui-teil-2b-protokoll.md` mit: Stand (Commit), Ergebnisse von Step 1 und 2, Tabelle der Browserprüfung mit Befund je Zeile, behobene Befunde mit Commit, Entscheidungen, offene Punkte für 2c und Teil 3. Commit:
```bash
git add docs/superpowers/plans/2026-10-10-new-ui-teil-2b-protokoll.md
git commit -m "docs: Prüfprotokoll Teil 2b

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 6: dev-swayy auffrischen (nur nach Freigabe von Mathias, mit Serverprotokoll)**

Vorher `Serverprotokolle/mx.mailware.cc.md` frisch lesen und einen Eintrag „dev-swayy: Abbild auf `new-ui` <commit> (Teil 2b)“ oben unter dem Steckbrief gezielt einfügen; Uhrzeiten vom Server mit Zeitzone. Ablauf wie im Eintrag vom 09.10.2026, 23:28–23:31: `git archive <commit>` per Pipe nach `/root/gastrobook-<commit>.tar.gz`, entpacken nach `/root/build-gastrobook-<commit>`, `nice -n 10 docker build -t gastrobook-local:dev-swayy-<commit> .`, Sicherung `/opt/dev-swayy/.env.vor-<commit>-<datum>`, in `.env` nur `GASTROBOOK_TEST_IMAGE` umstellen, `docker compose -f docker-compose.yml -f compose.sidecar.yml up -d`. Prüfung: Logs von app, queue, scheduler ohne Fehler; keine offenen Migrationen; von innen (Host-Kopf) `/up`, `/`, `/login` 200, `/admin/settings/capacity` 302 auf die Anmeldung; von außen `https://dev-swayy.bcsrv.de/admin` → PIN-Seite des NetBird-Proxys. Eintrag mit Ergebnis, Prüfung und Rückweg (`GASTROBOOK_TEST_IMAGE` auf das vorige Abbild) abschließen. Datenstand bleibt.

---

## Offen für 2c und Teil 3

| Punkt | Wohin |
|---|---|
| Protokoll: Bereiche für auth/support/provisioning, Rohschlüssel bei fehlendem Kürzel, Werte zu `type` als Code, Freitextsuche | 2c |
| Grenzen für Erinnerung, Feedback und Erstattung in `config/swayy.php` (heute fest in `updateBookingRules`) | 2c (Benachrichtigungen, Anzahlungen) |
| `SettingsController::updateBookingRules`, Sammelseite samt Reitern „Räume & Tags“ und „Website-Widget“ | Teil 3 (Abbau) |
| Handbuch: `03-online-buchungen/01-buchungsseite-widget.md`, `03-online-buchungen/02-zeiten-und-regeln.md`, `04-salon/01-leistungen-mitarbeiter-terminplan.md`, `02-alltag/03-tischplan.md` auf die neuen Seiten umstellen | Teil 3, zusammen mit dem Handbuch-Punkt aus 2a |
| „Sperren…“ am Tisch in Räume & Tische und im Tischplan | Teil 3 (Ausnahmen) |
| Räume mit Tischen löschen samt Schutz für kommende Reservierungen (Spec-Beispiel „mit 6 Tischen löschen?“) | eigener Plan, falls gewünscht |

## Ausführung

Vorschlag nach dem Muster von 2a (Agenten nur nach Freigabe):

| Rolle | Anzahl | Aufgabe | Dateien | Modell |
|---|---|---|---|---|
| Umsetzer | 13 (je Task 1–13 einer) | Task nach Plan umsetzen, Tests laufen lassen, committen | die Dateien unter „Files“ des Tasks | Sonnet 5.5 |
| Prüfer | 13 (je Task einer) | Diff des Tasks gegen Plan, Spec und Global Constraints prüfen; Befunde per Nachricht an denselben Umsetzer | lesend: Diff des Tasks, Plan, Spec | Sonnet 5.5 |
| Gesamtprüfer | 1 | ganzer Zweig nach Task 13, Review Focus zuerst | lesend: `git diff` seit Planstand | Opus 5.5 |

Task 0 und Task 14 führt die steuernde Sitzung selbst aus. Korrekturen gehen per Nachricht an denselben Umsetzer bzw. Prüfer; jeder Neustart eines Agenten braucht eine neue Freigabe.
