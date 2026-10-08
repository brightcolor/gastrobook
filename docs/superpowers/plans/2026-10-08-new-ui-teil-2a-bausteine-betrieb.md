# Neue Oberfläche, Teil 2a: Bausteine und Betriebsseiten – Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Die Seitenbausteine für Listen (Seitenleiste zum Anlegen und Bearbeiten, Rückfrage in der Zeile, Leerzustand, Zustandssymbol) entstehen mit JS-Tests, und die fünf Seiten der Gruppe „Betrieb“ (Standorte, Team & Rollen, Abo & Rechnungen, Protokoll, Daten) bekommen das neue Seitenmuster.

**Architecture:** Teil 1 hat Navigation, Layout, `x-page-header`, `x-settings.section`, `x-field`, `x-save-bar` und die Module `forms.js`, `savebar.js`, `toast.js`, `nav.js` gebaut. Teil 2a ergänzt Vitest mit jsdom, macht `forms.js` zu einem delegierten Handler (auch für Formulare in Seitenleisten), baut `x-drawer`, `x-inline-confirm`, `x-empty-state`, `x-status-icon` mit den Modulen `drawer.js`, `inline-confirm.js`, `copy.js` und stellt die Betriebsseiten darauf um. Controller antworten per `RespondsToSettingsForms` mit JSON (`message`, `reload`) oder wie bisher mit Weiterleitung.

**Tech Stack:** Laravel 13, PHP 8.3, Blade, Tailwind CSS 4 (Vite), Vanilla-JS als ES-Module, Vitest + jsdom, PHPUnit, Pint, PHPStan (Larastan Level 2).

**Spec:** `docs/superpowers/specs/2026-10-07-new-ui-admin-design.md`

## Fahrplan Teil 2

Teil 2 aus dem Gesamtfahrplan (Plan Teil 1) läuft in drei Plänen nacheinander; jeder endet lauffähig und geprüft.

| Plan | Inhalt |
|---|---|
| **2a (dieser)** | Vitest + jsdom; `forms.js` delegiert; Speicherleiste sperrt den Seitenkopf; Suchbegriffe mit Ziel; Navigation prüft Rechte einmal; Bausteine `x-drawer`, `x-inline-confirm`, `x-empty-state`, `x-status-icon`, Knopfstufen; Seiten Standorte (mit Profil und Logo), Team & Rollen, Abo & Rechnungen, Protokoll (mit Filter je Eintrag), Daten (aus „Mein Konto“) |
| 2b | Platz & Buchungen: Räume & Tische, Leistungen, Mitarbeiter, Kapazität & Dauer, Buchungsregeln, Buchungsformular, Buchungsseite & Widget, Tags; Zähler „n geändert“ und Punkt an geänderten Zeilen in der Speicherleiste; Reservierungsliste am Smartphone ohne seitliches Scrollen |
| 2c | Gästekommunikation, Zahlungen, Verbindungen: Benachrichtigungen (mit Anrede), Vorlagen, Absender & Antworten, SMS-Versand, Zahlungsanbieter, Anzahlungen, Newsletter, API-Schlüssel, Webhooks; eigene Fehlerseiten mit nächstem Schritt; „Mein Konto“: Passwort ändern (Spec; heute nur über „Passwort vergessen“) |

Teil 3 bleibt wie im Gesamtfahrplan (Öffnungszeiten, Vorschau, Ausnahmen, „Sperren…“, Abbau der Sammelseite, Zuordnungstest, Weiterleitungen).

## Global Constraints

- Farben, Schriften und Tokens von Swayy bleiben (Stein-Palette, Teal-Akzent `--brand`, Inter). Kein Gestaltungsschema von `bright-color-apps`.
- Rechte unverändert: `config/permissions.php` wird nicht geändert; jede Route behält ihre `permission:`-Middleware.
- Code und Bezeichner englisch, Oberflächentexte deutsch mit echten Umlauten, Anrede „du“. Texte im Stil „Sie“ auf den berührten Seiten werden zu „du“.
- Oberflächentexte ohne Negativabgrenzungen („X statt Y“, „kein X, sondern Y“, „X – ohne Y“); eine Verneinung nur, wenn sie selbst die Information ist.
- Fehlermeldungen nennen Ursache und nächsten Schritt. Statuscode oder Kennung dürfen als Zusatz dastehen; Pfade, Stacktraces und Zugangsdaten nie.
- Nichts fest im Code: Verhaltenswerte stehen mit Vorgabe und Grenzen in `config/swayy.php` (über `App\Support\EnvSetting`), dazu ein Eintrag in `.env.example`; die Navigation in `config/navigation.php`. Tests prüfen auch mit anderen Werten als der Vorgabe. Wer bestehenden Code anfasst, zieht fest eingetragene Werte im berührten Bereich in Einstellungen.
- Neuer und berührter Code nutzt keine `onclick`/`onchange`/`onsubmit`-Attribute.
- Formulare mit `data-savebar` enthalten keine Dateifelder; Uploads speichern über eigene Formulare.
- Seiten mit dem Cap-Widget behalten `'unsafe-eval'` in `script-src` der CSP. Teil 2a ändert keine CSP.
- Barrierefreiheit WCAG 2.2 AA: sichtbarer Fokus, Klickziele ≥ 24 × 24 px, Zustände nie über Farbe allein, Live-Region für Hinweise, Fokus nie unter der Speicherleiste (2.4.11).
- Tests PHP: `declare(strict_types=1)`, `use CreatesTenants, RefreshDatabase;`, vor Requests `$this->clearTenantContext()`. Keine echten Namen oder Adressen in Testdaten.
- Tests JS: Vitest mit jsdom unter `tests/js/*.test.js`, `npm test`.
- PHP: Imports alphabetisch, keine vollqualifizierten Klassennamen in Code oder Docblocks (Pint `fully_qualified_strict_types`).
- Arbeitskopie: Worktree `C:\Users\brigh\Claude Workingdir\gastrobook-new-ui`, Branch `new-ui` ohne Upstream. Nie auf `main` pushen (Watchtower rollt `main` sofort aus). Push von `new-ui` und Entwurfs-PR erst nach Mathias' Freigabe. Teil 1 und 2a werden nicht ausgerollt.
- Befehle laufen in Git Bash im Worktree. Tests mit `PAO_DISABLE=1 php artisan test …` (sonst gibt laravel/pao JSON aus).
- Blade: Auf `</x-slot:…>` folgt nie direkt Text (Blade macht daraus `@endslotText`); immer Zeilenumbruch oder Tag dazwischen.
- Commit-Nachrichten enden mit `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Vor dem späteren Merge nach `main` (Plan Teil 3): Eintrag `gastrobook-new-ui` aus `.claude/launch.json` entfernen; Changelog-Hinweis Farbwechsel `#0d9488` → `#0f766e`.

## Review Focus

1. **Fehler in der Seitenleiste:** Speichern im Drawer mit ungültiger Eingabe (422) zeigt Text am Feld und die Zusammenfassung im Drawer, Fokus auf dem ersten Feld; Escape schließt danach den Drawer und setzt das Formular zurück, der Fokus steht wieder auf dem öffnenden Knopf. Getestet in Task 4 (`drawer.test.js`) und Task 2 (`forms.test.js`).
2. **Eingeschränkte Mitglieder:** Eine Standortleitung mit genau einem freigegebenen Standort sieht dieselbe Navigation wie vorher, und die Navigation fragt `location_user` je Anfrage höchstens einmal ab. Getestet in Task 3.
3. **Letzter Inhaber:** Herabstufen, Entfernen oder Löschen des letzten Inhabers per Rückfrage in der Zeile liefert 422 mit verständlichem Grund, der in der Zeile erscheint; die Rollenauswahl springt auf den gespeicherten Wert zurück. Getestet in Task 6 (PHP) und Task 2 (`data-reset-on-error`).
4. **„Daten“ nur für Inhaber:** `tenant_admin` und Support sehen den Eintrag nicht und bekommen auf der Seite 403 mit Erklärung; der Inhaber sieht Export und Import, „Betrieb löschen“ nur als letzter Inhaber. Getestet in Task 9.
5. **Logo-Upload:** SVG, zu große Dateien (auch mit kleinerem Grenzwert aus den Einstellungen) und Standorte anderer Betriebe werden abgelehnt (422 mit Grund bzw. 404). Getestet in Task 5.

## Dateien

| Datei | Aufgabe |
|---|---|
| `vitest.config.js`, `tests/js/setup.js`, `tests/js/helpers.js` | JS-Testumgebung (Task 1) |
| `resources/js/admin/dom.js` | Helfer: Tastaturfokus, Neuladen, bedienbare Elemente (Task 2) |
| `resources/js/admin/forms.js` | delegierter Submit, `swayy:saved` blubbert, `data-reset-on-error`, Neuladen nach Verwerfen (Task 2) |
| `resources/js/admin/savebar.js` | `swayy:discarded` blubbert, Seitenkopf gesperrt bei sichtbarer Leiste (Task 2) |
| `resources/js/admin/nav.js` | Suchbegriffe mit Ziel, Beispiele aus den Einstellungen (Task 2) |
| `app/Support/Navigation/Navigation.php`, `config/navigation.php` | Rollenfilter, Rechteprüfung einmal, Suchbegriffe mit Ziel (Task 3) |
| `resources/views/components/{drawer,inline-confirm,empty-state,status-icon}.blade.php` | Bausteine (Task 4) |
| `resources/js/admin/{drawer,inline-confirm,copy}.js`, `index.js` | Verhalten der Bausteine (Task 4) |
| `resources/css/app.css` | Knopfstufen `.btn-*` (Task 4) |
| `LocationController`, `LocationLogoController`, `admin/locations/*` | Standorte (Task 5) |
| `UserManagementController`, `lang/de/roles.php`, `admin/users/*` | Team & Rollen (Task 6) |
| `admin/billing/index.blade.php` | Abo & Rechnungen (Task 7) |
| `AuditLogController`, `admin/audit/index.blade.php`, `admin/reservations/show.blade.php` | Protokoll (Task 8) |
| `Settings\DataController`, `admin/settings/data.blade.php`, `admin/account/index.blade.php` | Daten (Task 9) |

---

### Task 1: JS-Testumgebung mit Vitest und jsdom

**Files:**
- Modify: `package.json`, `package-lock.json`, `.github/workflows/ci.yml`
- Create: `vitest.config.js`, `tests/js/setup.js`, `tests/js/helpers.js`, `tests/js/toast.test.js`

**Interfaces:**
- Consumes: `resources/js/admin/toast.js` (`showToast(message, { error, undo })`) aus Teil 1.
- Produces: `npm test` (Vitest, jsdom); Helfer in `tests/js/helpers.js`: `jsonResponse(body, status = 200)`, `htmlResponse(status)`, `setWide(matches)`, `mount(html)`.

- [ ] **Step 1: Pakete installieren**

Run:
```bash
npm install --save-dev vitest jsdom
```
Expected: `package.json` nennt `vitest` und `jsdom` unter `devDependencies`, `package-lock.json` ist aktualisiert.

- [ ] **Step 2: Skript `test` in `package.json` ergänzen**

Der Block `scripts` lautet danach:
```json
    "scripts": {
        "build": "vite build",
        "dev": "vite",
        "test": "vitest run"
    },
```

- [ ] **Step 3: `vitest.config.js` anlegen**

Eigene Datei, damit Vitest das Laravel-Plugin aus `vite.config.js` nicht lädt.
```js
import { defineConfig } from 'vitest/config';

// JS-Tests der Verwaltung: jsdom als Browser, je Test frische Mocks und Globals.
export default defineConfig({
    test: {
        environment: 'jsdom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
        restoreMocks: true,
        unstubGlobals: true,
    },
});
```

- [ ] **Step 4: `tests/js/setup.js` anlegen**

```js
// Gemeinsame Vorbereitung der JS-Tests: Werte, die sonst das Layout über
// window.SwayyAdmin liefert, die Hinweis-Fläche und was jsdom fehlt.
import { afterEach, beforeEach, vi } from 'vitest';

// jsdom kennt CSS.escape nicht.
if (!globalThis.CSS?.escape) {
    globalThis.CSS = {
        ...(globalThis.CSS ?? {}),
        escape: (value) => String(value).replace(/[^a-zA-Z0-9_-]/g, (char) => `\\${char}`),
    };
}

beforeEach(() => {
    window.SwayyAdmin = {
        ui: { toastMs: 3200, reloadDelayMs: 0 },
        problemTexts: {
            default: 'Der Server hat mit Status :status geantwortet. Bitte später erneut versuchen.',
            500: 'Auf dem Server ist ein Fehler aufgetreten. Bitte später erneut versuchen.',
            unclear: 'Unklar, ob gespeichert wurde. Bitte die Seite neu laden und prüfen.',
            network: 'Keine Verbindung zum Server. Bitte die Verbindung prüfen und erneut speichern.',
        },
    };
    // jsdom kennt matchMedia nicht; setWide() aus helpers.js stellt die Breite ein.
    window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    document.body.innerHTML = '';
    document.body.removeAttribute('style');
});

afterEach(() => {
    vi.useRealTimers();
    history.replaceState(null, '', '/');
});
```

- [ ] **Step 5: `tests/js/helpers.js` anlegen**

```js
// Helfer für die JS-Tests der Verwaltung.

// Antwort mit JSON, wie sie die Controller über RespondsToSettingsForms senden.
export function jsonResponse(body, status = 200) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

// Antwort ohne JSON, etwa eine Fehlerseite des Proxys.
export function htmlResponse(status) {
    return new Response('<html></html>', { status, headers: { 'Content-Type': 'text/html' } });
}

// Bildschirmbreite ab 768 px (md) an oder aus.
export function setWide(matches) {
    window.matchMedia = () => ({ matches, addEventListener() {}, removeEventListener() {} });
}

// HTML in den Body setzen; die Hinweis-Fläche des Layouts kommt immer dazu.
export function mount(html) {
    document.body.innerHTML = `${html}<div id="swayyToast" data-overlay-layer></div>`;
}
```

- [ ] **Step 6: Ersten Test schreiben – `tests/js/toast.test.js`**

```js
import { describe, expect, it, vi } from 'vitest';
import { showToast } from '../../resources/js/admin/toast.js';
import { mount } from './helpers.js';

describe('showToast', () => {
    it('zeigt den Text und blendet nach der eingestellten Dauer aus', () => {
        vi.useFakeTimers();
        window.SwayyAdmin.ui.toastMs = 1500;
        mount('');
        const toast = document.getElementById('swayyToast');

        showToast('Gespeichert');
        expect(toast.textContent).toBe('Gespeichert');
        expect(toast.dataset.state).toBe('ok');
        expect(toast.classList.contains('opacity-0')).toBe(false);

        vi.advanceTimersByTime(1499);
        expect(toast.classList.contains('opacity-0')).toBe(false);
        vi.advanceTimersByTime(1);
        expect(toast.classList.contains('opacity-0')).toBe(true);
        expect(toast.classList.contains('pointer-events-none')).toBe(true);
    });

    it('macht den Rückgängig-Knopf beim Ausblenden unerreichbar', () => {
        vi.useFakeTimers();
        mount('');
        const undo = vi.fn();

        showToast('Raum gelöscht', { undo });
        const button = document.querySelector('#swayyToast button');
        expect(button.textContent).toBe('Rückgängig');

        vi.advanceTimersByTime(window.SwayyAdmin.ui.toastMs);
        expect(button.inert).toBe(true);
    });

    it('kennzeichnet Fehler', () => {
        mount('');
        showToast('Keine Verbindung', { error: true });
        expect(document.getElementById('swayyToast').dataset.state).toBe('error');
    });
});
```

- [ ] **Step 7: Tests laufen lassen**

Run: `npm test`
Expected: PASS, 3 Tests in `tests/js/toast.test.js`.

- [ ] **Step 8: CI um `npm test` ergänzen**

In `.github/workflows/ci.yml`, Job `frontend`, nach `- run: npm ci`:
```yaml
      - run: npm ci
      - run: npm test
      - run: npm run build
```

- [ ] **Step 9: Commit**

```bash
git add package.json package-lock.json vitest.config.js tests/js .github/workflows/ci.yml
git commit -m "test: Vitest mit jsdom für die Skripte der Verwaltung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: Formulare, Speicherleiste und Suche für Teil 2

**Files:**
- Create: `resources/js/admin/dom.js`, `tests/js/forms.test.js`, `tests/js/savebar.test.js`, `tests/js/nav-search.test.js`
- Modify: `resources/js/admin/forms.js`, `resources/js/admin/savebar.js`, `resources/js/admin/nav.js`, `resources/views/layouts/admin.blade.php`, `resources/views/components/page-header.blade.php`, `resources/views/admin/partials/nav/settings.blade.php`, `resources/views/admin/settings/menu.blade.php`, `config/navigation.php`
- Test: `tests/Feature/NavigationLayoutTest.php`

**Interfaces:**
- Consumes: `showToast` (toast.js), `snapshot`, `savedSnapshot`, `submitForm` (forms.js, Teil 1).
- Produces:
  - `dom.js`: `keyboardFocused(el): boolean`, `page.reload(): void`, `focusables(root): Element[]`.
  - `forms.js`: `initAjaxForms()` registriert einmal je Seite einen Submit-Handler in der Capture-Phase am Dokument für alle `form[data-ajax]`, auch später eingefügte. `swayy:saved` blubbert (`bubbles: true`, `detail` = JSON). Formulare mit `data-reset-on-error` kehren bei Fehlern zum gespeicherten Stand zurück. `swayy:discarded` am Dokument löst ein zurückgestelltes Neuladen aus.
  - `savebar.js`: `swayy:discarded` blubbert; solange die Leiste sichtbar ist und die Breite ≥ 768 px beträgt, trägt `[data-page-header]` das Attribut `inert`.
  - `nav.js`: Suchbegriffe in `data-keywords` haben die Form `Begriff` oder `Begriff#ziel`; ein Treffer mit Ziel setzt `href` des Eintrags auf `<Adresse ohne #>#ziel`. Beispiele im Kein-Treffer-Hinweis kommen aus `data-examples` am Suchfeld.
  - Layout: `#swayyToast` und `#confirmModal` tragen `data-overlay-layer`; der Bestätigungsdialog überspringt Ereignisse mit `defaultPrevented` und Formulare mit `data-ajax`.
  - `x-page-header` trägt `data-page-header`.
  - `config('navigation.search_examples')`: Liste der Beispielbegriffe.

- [ ] **Step 1: Failing tests – `tests/js/forms.test.js`**

```js
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { page } from '../../resources/js/admin/dom.js';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { htmlResponse, jsonResponse, mount } from './helpers.js';

const form = (attrs = '') => `
    <form id="f" method="POST" action="/admin/thing" data-ajax ${attrs}>
        <label for="f-name">Name</label>
        <input id="f-name" name="name" value="Alt">
        <button type="submit">Speichern</button>
    </form>`;

beforeAll(() => initAjaxForms());

beforeEach(() => {
    vi.spyOn(page, 'reload').mockImplementation(() => {});
});

describe('Formulare mit data-ajax', () => {
    it('senden auch Formulare, die nach dem Start eingefügt wurden', async () => {
        const fetchMock = vi.fn(async () => jsonResponse({ message: 'Gespeichert.' }));
        vi.stubGlobal('fetch', fetchMock);
        mount('<div id="later"></div>');
        document.getElementById('later').innerHTML = form();

        document.getElementById('f').requestSubmit();

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        // form.action liefert die volle Adresse.
        expect(fetchMock.mock.calls[0][0]).toBe(new URL('/admin/thing', window.location.href).href);
    });

    it('melden das Speichern als blubberndes swayy:saved', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Gespeichert.' })));
        mount(`<div id="wrap">${form()}</div>`);
        const seen = vi.fn();
        document.getElementById('wrap').addEventListener('swayy:saved', (e) => seen(e.detail));

        document.getElementById('f').requestSubmit();

        await vi.waitFor(() => expect(seen).toHaveBeenCalledWith({ message: 'Gespeichert.' }));
    });

    it('zeigen Prüfungsfehler am Feld und in der Zusammenfassung, Fokus aufs Feld', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
            message: 'Der Name fehlt.',
            errors: { name: ['Bitte einen Namen eintragen.'] },
        }, 422)));
        mount(form());

        document.getElementById('f').requestSubmit();

        await vi.waitFor(() => expect(document.getElementById('f-name-error')?.textContent).toBe('Bitte einen Namen eintragen.'));
        const field = document.getElementById('f-name');
        expect(field.getAttribute('aria-invalid')).toBe('true');
        expect(field.getAttribute('aria-describedby')).toContain('f-name-error');
        expect(document.querySelector('[data-form-problems] a').getAttribute('href')).toBe('#f-name');
        expect(document.activeElement).toBe(field);
    });

    it('setzen Formulare mit data-reset-on-error auf den gespeicherten Wert zurück', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
            message: 'Der Betrieb braucht mindestens einen Inhaber.',
            errors: { role: ['Der Betrieb braucht mindestens einen Inhaber.'] },
        }, 422)));
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

        await vi.waitFor(() => expect(document.querySelector('#r [data-form-problems]')).not.toBeNull());
        expect(select.value).toBe('tenant_owner');
    });

    it('übersetzen Antworten ohne JSON in einen Text mit Status', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => htmlResponse(502)));
        mount(form());

        document.getElementById('f').requestSubmit();

        await vi.waitFor(() => expect(document.querySelector('[data-form-problems]')?.textContent)
            .toBe('Auf dem Server ist ein Fehler aufgetreten. Bitte später erneut versuchen.'));
    });

    it('senden bei doppeltem Absenden nur einmal', async () => {
        let resolve;
        const fetchMock = vi.fn(() => new Promise((r) => { resolve = r; }));
        vi.stubGlobal('fetch', fetchMock);
        mount(form());
        const el = document.getElementById('f');

        el.requestSubmit();
        el.requestSubmit();
        resolve(jsonResponse({ message: 'Gespeichert.' }));

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('laden nach reload erst neu, wenn keine Änderungen mehr offen sind, und nach Verwerfen sofort', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Gespeichert.', reload: true })));
        mount(`${form()}<form id="other" data-dirty></form>`);

        document.getElementById('f').requestSubmit();
        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        await new Promise((r) => setTimeout(r, 5));
        expect(page.reload).not.toHaveBeenCalled();

        document.getElementById('other').removeAttribute('data-dirty');
        document.getElementById('other').dispatchEvent(new CustomEvent('swayy:discarded', { bubbles: true }));

        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
    });
});
```

- [ ] **Step 2: Run – expect FAIL**

Run: `npm test -- tests/js/forms.test.js`
Expected: FAIL, unter anderem „Failed to resolve import ../../resources/js/admin/dom.js“.

- [ ] **Step 3: `resources/js/admin/dom.js` anlegen**

```js
// Kleine Helfer für die Module der Verwaltung.

// Ob ein Element per Tastatur fokussiert ist. Wo der Selektor :focus-visible
// fehlt (ältere Browser, jsdom), gilt der Fokus als per Zeiger gesetzt.
export function keyboardFocused(el) {
    try {
        return el.matches(':focus-visible');
    } catch {
        return false;
    }
}

// Neuladen der Seite; als Objekt, damit Tests den Aufruf beobachten können.
export const page = {
    reload() {
        window.location.reload();
    },
};

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

// Bedienbare Elemente in einem Bereich, in Dokumentreihenfolge, ohne solche
// in verborgenen Teilen.
export function focusables(root) {
    return [...root.querySelectorAll(FOCUSABLE)].filter((el) => !el.closest('[hidden]'));
}
```

- [ ] **Step 4: `resources/js/admin/forms.js` ersetzen**

Ganze Datei:
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
// data-reset-on-error: Das Formular kehrt bei einem Fehler zum gespeicherten
// Stand zurück, etwa eine Rollenauswahl, die sofort speichert.

import { page } from './dom.js';
import { showToast } from './toast.js';

let problemCount = 0;
let inFlight = 0;
let reloadRequested = false;
let installed = false;
const pending = new WeakMap();
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

// Fehlertext direkt am Feld, mit derselben ID wie bei x-field ({id}-error):
// unter dem Feld in seinem Block (Beschriftung, Feld, Hilfesatz).
function showFieldError(form, field, text) {
    const errorId = `${field.id}-error`;
    let note = document.getElementById(errorId);
    if (!note) {
        note = document.createElement('p');
        note.id = errorId;
        note.className = 'text-[0.8125rem] text-red-700';
        const block = form.querySelector(`label[for="${CSS.escape(field.id)}"]`)?.parentElement ?? field.parentElement;
        block.append(note);
    }
    note.dataset.ajaxError = '';
    note.textContent = text;
    describedBy(field, errorId);
}

// Eintrag der Zusammenfassung: mit Feld als Link, der den Fokus dorthin setzt.
function summaryEntry({ message, field }) {
    if (!field?.id) return document.createTextNode(message);
    const link = document.createElement('a');
    link.href = `#${field.id}`;
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
        const fields = [...form.querySelectorAll(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`)];
        const text = [].concat(list).join(' ');
        const field = fields[0] ?? null;
        fields.forEach((el) => {
            el.setAttribute('aria-invalid', 'true');
            el.setAttribute('data-ajax-invalid', '');
            // Felder ohne eigene ID (etwa Optionsfelder) verweisen auf die Zusammenfassung.
            if (!el.id) describedBy(el, box.id);
        });
        if (field?.id) showFieldError(form, field, text);
        first ??= field;
        entries.push({ message: text, field });
    });
    if (entries.length === 0) messages.forEach((message) => entries.push({ message, field: null }));

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

function fail(form, json, messages) {
    showToast(messages.join(' · '), { error: true });
    if (form.hasAttribute('data-reset-on-error')) form.reset();
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
    const submitter = form.querySelector('[type="submit"]');
    submitter?.setAttribute('aria-busy', 'true');
    const values = readValues(form);
    const sent = snapshot(form);
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
            fail(form, json, problemsOf(res, json));
            return false;
        }

        // Gespeichert meldet nur, wer es bestätigt. Eine Antwort ohne JSON
        // (etwa nach einer Weiterleitung) lässt offen, ob die Änderung ankam.
        if (!json || typeof json.message !== 'string') {
            fail(form, null, [texts().unclear || '']);
            return false;
        }

        commitDefaults(values);
        sentSnapshots.set(form, sent);
        form.dispatchEvent(new CustomEvent('swayy:saved', { detail: json, bubbles: true }));
        showToast(json.message);
        if (json.reload) reloadRequested = true;
        return true;
    } catch {
        fail(form, null, [texts().network || '']);
        return false;
    } finally {
        submitter?.removeAttribute('aria-busy');
        inFlight--;
        if (reloadRequested) reloadWhenIdle();
    }
}

// Ein zweiter Aufruf während der Anfrage (Doppelklick, Enter und Speicherleiste)
// bekommt dieselbe Anfrage zurück; abgeschickt wird nur einmal.
export function submitForm(form) {
    if (!pending.has(form)) {
        pending.set(form, send(form).finally(() => pending.delete(form)));
    }
    return pending.get(form);
}

export function initAjaxForms() {
    if (installed) return;
    installed = true;

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) return;
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

- [ ] **Step 5: Run – expect PASS**

Run: `npm test -- tests/js/forms.test.js`
Expected: PASS, 7 Tests.

- [ ] **Step 6: Failing tests – `tests/js/savebar.test.js`**

```js
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initSaveBar } from '../../resources/js/admin/savebar.js';
import { mount, setWide } from './helpers.js';

const page = `
    <div data-page-header><h1>Allgemein</h1><select id="scope"><option>Mitte</option></select></div>
    <div data-save-bar hidden>
        <span data-save-bar-text>Ungespeicherte Änderungen</span>
        <button type="button" data-save-bar-discard>Verwerfen</button>
        <button type="button" data-save-bar-save>Speichern</button>
    </div>
    <p data-save-bar-status></p>
    <form id="g" method="POST" action="/admin/settings/general" data-ajax data-savebar>
        <input id="f-name" name="name" value="Alt">
    </form>`;

function edit(value) {
    const input = document.getElementById('f-name');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeAll(() => initAjaxForms());

describe('Speicherleiste', () => {
    it('erscheint bei Änderungen und sperrt ab 768 px den Seitenkopf', () => {
        setWide(true);
        mount(page);
        initSaveBar();

        edit('Neu');

        expect(document.querySelector('[data-save-bar]').hidden).toBe(false);
        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(true);
        expect(document.getElementById('g').hasAttribute('data-dirty')).toBe(true);
    });

    it('lässt den Seitenkopf unter 768 px bedienbar', () => {
        setWide(false);
        mount(page);
        initSaveBar();

        edit('Neu');

        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(false);
    });

    it('gibt den Seitenkopf nach Verwerfen frei und meldet swayy:discarded am Dokument', () => {
        setWide(true);
        mount(page);
        initSaveBar();
        const discarded = vi.fn();
        document.addEventListener('swayy:discarded', discarded, { once: true });

        edit('Neu');
        document.querySelector('[data-save-bar-discard]').click();

        expect(document.getElementById('f-name').value).toBe('Alt');
        expect(document.querySelector('[data-save-bar]').hidden).toBe(true);
        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(false);
        expect(discarded).toHaveBeenCalledTimes(1);
    });
});
```

- [ ] **Step 7: Run – expect FAIL**

Run: `npm test -- tests/js/savebar.test.js`
Expected: FAIL in „sperrt ab 768 px den Seitenkopf“ (Attribut `inert` fehlt) und „meldet swayy:discarded am Dokument“.

- [ ] **Step 8: `resources/js/admin/savebar.js` ersetzen**

Ganze Datei:
```js
// Speicherleiste (x-save-bar): erscheint, sobald ein Formular mit
// data-savebar geändert ist. „Speichern“ schickt die geänderten Formulare
// nacheinander ab, „Verwerfen“ setzt sie auf den gespeicherten Stand zurück.
// Wer die Seite mit Änderungen verlässt, bekommt die Rückfrage des Browsers.
// Formulare mit data-savebar enthalten keine Dateifelder; Uploads speichern
// sofort über eigene Formulare.

import { keyboardFocused } from './dom.js';
import { savedSnapshot, snapshot, submitForm } from './forms.js';
import { showToast } from './toast.js';

// Erstes bedienbares Feld eines Formulars.
const firstField = (form) =>
    [...form.elements].find(
        (el) => el.matches('input:not([type="hidden"]), select, textarea') && !el.disabled && el.getClientRects().length > 0,
    );

export function initSaveBar() {
    const bar = document.querySelector('[data-save-bar]');
    const forms = [...document.querySelectorAll('form[data-savebar]')];
    if (!bar || forms.length === 0) return;

    const base = new Map(forms.map((form) => [form, snapshot(form)]));
    const dirtyForms = () => forms.filter((form) => snapshot(form) !== base.get(form));
    const status = document.querySelector('[data-save-bar-status]');
    let wasDirty = false;
    let saving = false;

    // Ab 768 px (md) liegt die Leiste über dem Seitenkopf. Solange sie steht,
    // sind dessen Bedienelemente (Standortwahl, Hauptaktion) gesperrt, damit
    // der Tastaturfokus nie unter der Leiste verschwindet (WCAG 2.4.11).
    const header = document.querySelector('[data-page-header]');
    const wide = window.matchMedia('(min-width: 768px)');
    const syncHeader = () => header?.toggleAttribute('inert', !bar.hidden && wide.matches);
    wide.addEventListener('change', syncHeader);

    const render = () => {
        const dirty = dirtyForms();
        bar.hidden = dirty.length === 0;
        forms.forEach((form) => form.toggleAttribute('data-dirty', dirty.includes(form)));
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
        form.addEventListener('swayy:saved', () => {
            base.set(form, savedSnapshot(form) ?? snapshot(form));
            moveFocusOut(form);
            render();
        });
        form.addEventListener('swayy:rebase', () => {
            base.set(form, snapshot(form));
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

- [ ] **Step 9: Run – expect PASS**

Run: `npm test -- tests/js/savebar.test.js tests/js/forms.test.js`
Expected: PASS, 10 Tests.

- [ ] **Step 10: Failing tests – `tests/js/nav-search.test.js`**

```js
import { describe, expect, it } from 'vitest';
import { initSettingsSearch } from '../../resources/js/admin/nav.js';
import { mount } from './helpers.js';

const menu = `
    <input id="s" type="search" data-settings-search aria-controls="s-list" data-examples="Öffnungszeiten|Anzahlung">
    <div id="s-list">
        <div data-settings-group>
            <a href="/admin/settings/data" data-settings-item data-label="Daten" data-keywords="Export#export|Sicherung#export|Betrieb löschen#betrieb-loeschen">
                Daten <small data-settings-hit hidden></small>
            </a>
            <a href="/admin/settings#zeiten" data-settings-item data-label="Öffnungszeiten" data-keywords="Ruhetag|Saison#saison">
                Öffnungszeiten <small data-settings-hit hidden></small>
            </a>
        </div>
        <p data-settings-noresult role="status"></p>
    </div>`;

function search(text) {
    const input = document.getElementById('s');
    input.value = text;
    input.dispatchEvent(new Event('input'));
}

describe('Suche in den Einstellungen', () => {
    it('führt bei einem Begriff mit Ziel direkt zur Karte', () => {
        mount(menu);
        initSettingsSearch();

        search('export');

        const data = document.querySelector('[data-label="Daten"]');
        expect(data.hidden).toBe(false);
        expect(data.getAttribute('href')).toBe('/admin/settings/data#export');
        expect(data.querySelector('[data-settings-hit]').textContent).toBe('Export');
        expect(document.querySelector('[data-label="Öffnungszeiten"]').hidden).toBe(true);
    });

    it('ersetzt ein vorhandenes Ziel und stellt die Adresse ohne Suche wieder her', () => {
        mount(menu);
        initSettingsSearch();
        const hours = document.querySelector('[data-label="Öffnungszeiten"]');

        search('saison');
        expect(hours.getAttribute('href')).toBe('/admin/settings#saison');

        search('');
        expect(hours.getAttribute('href')).toBe('/admin/settings#zeiten');
        expect(hours.querySelector('[data-settings-hit]').hidden).toBe(true);
    });

    it('nennt ohne Treffer die Beispiele aus den Einstellungen', () => {
        mount(menu);
        initSettingsSearch();

        search('xyz');

        expect(document.querySelector('[data-settings-noresult]').textContent)
            .toBe('Zu „xyz“ passt keine Einstellung. Versuch einen anderen Begriff, etwa „Öffnungszeiten“ oder „Anzahlung“.');
    });
});
```

- [ ] **Step 11: Run – expect FAIL**

Run: `npm test -- tests/js/nav-search.test.js`
Expected: FAIL, `href` bleibt `/admin/settings/data`, Beispiele stammen aus den Suchbegriffen.

- [ ] **Step 12: `resources/js/admin/nav.js` anpassen**

1. Am Dateianfang nach dem Kopfkommentar einfügen:
```js
import { keyboardFocused } from './dom.js';
```
2. In `initRailTips` die Zeile
```js
            if (el.matches(':focus-visible')) show(el);
```
ersetzen durch
```js
            if (keyboardFocused(el)) show(el);
```
3. Die Funktion `initSettingsSearch` vollständig ersetzen durch:
```js
// Suche im Einstellungsbereich. Suchbegriffe (data-keywords, getrennt durch |)
// tragen optional ein Ziel auf der Seite: „Export#export“. Ein Treffer mit Ziel
// führt direkt zur passenden Karte; ohne Suche gilt wieder die Adresse aus der
// Navigation. Die Beispiele im Hinweis ohne Treffer stehen am Suchfeld
// (data-examples, config/navigation.php: search_examples).
export function initSettingsSearch() {
    document.querySelectorAll('[data-settings-search]').forEach((input) => {
        const list = document.getElementById(input.getAttribute('aria-controls'));
        if (!list) return;
        const noResult = list.querySelector('[data-settings-noresult]');
        const entries = (link) => (link.dataset.keywords || '')
            .split('|')
            .filter(Boolean)
            .map((entry) => {
                const [word, anchor = ''] = entry.split('#');
                return { word, anchor };
            });
        list.querySelectorAll('[data-settings-item]').forEach((link) => {
            link.dataset.baseHref = link.getAttribute('href');
        });

        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('de');
            let any = false;

            list.querySelectorAll('[data-settings-group]').forEach((group) => {
                let visible = 0;
                group.querySelectorAll('[data-settings-item]').forEach((link) => {
                    const label = (link.dataset.label || '').toLocaleLowerCase('de');
                    let hit = null;
                    let match = query === '' || label.includes(query);
                    if (!match) {
                        hit = entries(link).find(({ word }) => word.toLocaleLowerCase('de').includes(query)) ?? null;
                        match = hit !== null;
                    }
                    link.hidden = !match;
                    const base = link.dataset.baseHref;
                    link.setAttribute('href', hit?.anchor ? `${base.split('#')[0]}#${hit.anchor}` : base);
                    const hint = link.querySelector('[data-settings-hit]');
                    if (hint) {
                        hint.textContent = hit?.word ?? '';
                        hint.hidden = hit === null;
                    }
                    if (match) visible += 1;
                });
                group.hidden = visible === 0;
                if (visible > 0) any = true;
            });

            // Die Live-Region bleibt im Dokument, nur ihr Text wechselt; so sagt
            // der Screenreader den Hinweis an.
            if (noResult) {
                const examples = (input.dataset.examples || '').split('|').filter(Boolean).slice(0, 2);
                const hint = examples.length === 2 ? ` Versuch einen anderen Begriff, etwa „${examples[0]}“ oder „${examples[1]}“.` : '';
                noResult.textContent = any ? '' : `Zu „${input.value.trim()}“ passt keine Einstellung.${hint}`;
            }
        });
    });
}
```

- [ ] **Step 13: Beispielbegriffe in die Einstellungen**

In `config/navigation.php`:

1. Im Kopfkommentar unter `heading_min_items` ergänzen:
```php
|   search_examples  zwei Begriffe, die der Hinweis „passt keine Einstellung“
|                    als Beispiel nennt
```
2. Nach `'heading_min_items' => …,` einfügen:
```php
    'search_examples' => ['Öffnungszeiten', 'Anzahlung'],
```

In `resources/views/admin/partials/nav/settings.blade.php` das Suchfeld um das Attribut ergänzen (nach `data-settings-search`):
```blade
    <input id="{{ $searchId }}" type="search" data-settings-search aria-controls="{{ $searchId }}-list"
           data-examples="{{ implode('|', (array) config('navigation.search_examples')) }}"
```
In `resources/views/admin/settings/menu.blade.php` ebenso am Feld `settingsSearchPage`:
```blade
           data-examples="{{ implode('|', (array) config('navigation.search_examples')) }}"
```

- [ ] **Step 14: Layout und Seitenkopf**

In `resources/views/components/page-header.blade.php` die erste Zeile nach dem Kommentar
```blade
<div class="mb-6 border-b border-stone-200 pb-5">
```
ersetzen durch
```blade
<div data-page-header class="mb-6 border-b border-stone-200 pb-5">
```

In `resources/views/layouts/admin.blade.php`:

1. Fehlerblock: `Bitte prüfen Sie Ihre Eingaben:` ersetzen durch `Bitte prüfe diese Angaben:`.
2. Hinweis-Fläche: `<div id="swayyToast" role="status"` ersetzen durch `<div id="swayyToast" data-overlay-layer role="status"`.
3. Bestätigungsdialog: `<div id="confirmModal" role="dialog"` ersetzen durch `<div id="confirmModal" data-overlay-layer role="dialog"`.
4. Im Skript des Bestätigungsdialogs die erste Zeile des Submit-Handlers ergänzen; der Handler beginnt danach so:
```js
    document.addEventListener('submit', e => {
        // Formulare mit data-ajax übernimmt forms.js (Rückfrage dort: x-inline-confirm).
        if (e.defaultPrevented || e.target.hasAttribute('data-ajax')) return;
        const el = e.target.querySelector('[data-confirm]') || (e.submitter && e.submitter.matches('[data-confirm]') ? e.submitter : null);
```

- [ ] **Step 15: Test für Layout und Suchfeld – `tests/Feature/NavigationLayoutTest.php`**

Neue Testmethode am Ende der Klasse:
```php
    public function test_layout_marks_overlay_layers_and_hands_over_search_examples(): void
    {
        config(['navigation.search_examples' => ['Tisch', 'Logo']]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/settings/general')->assertOk()->getContent();

        $this->assertStringContainsString('id="swayyToast" data-overlay-layer', $html);
        $this->assertStringContainsString('id="confirmModal" data-overlay-layer', $html);
        $this->assertStringContainsString('data-examples="Tisch|Logo"', $html);
        $this->assertStringContainsString('data-page-header', $html);
        $this->assertStringContainsString("e.target.hasAttribute('data-ajax')", $html);
    }
```

- [ ] **Step 16: Alles prüfen**

Run:
```bash
npm test
PAO_DISABLE=1 php artisan test --filter='NavigationLayoutTest|SettingsMenuTest|SettingsGeneralPageTest'
grep -rn "Bitte prüfen Sie" tests resources/views/layouts
```
Expected: Vitest PASS (16 Tests), PHPUnit PASS, `grep` ohne Treffer.

- [ ] **Step 17: Commit**

```bash
git add resources/js/admin tests/js resources/views config/navigation.php tests/Feature/NavigationLayoutTest.php
git commit -m "feat: Formulare delegiert, Seitenkopf unter der Speicherleiste gesperrt, Suchbegriffe mit Ziel

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 3: Navigation – Rollenfilter, Suchbegriffe mit Ziel, Rechte einmal prüfen

**Files:**
- Modify: `app/Support/Navigation/Navigation.php`, `config/navigation.php`
- Test: `tests/Feature/NavigationTest.php`

**Interfaces:**
- Consumes: `User::canInTenant()`, `User::canAccessLocation()`, `User::roleIn()`, `User::isSaasAdmin()`.
- Produces:
  - Eintragsfeld `roles` (Liste von Rollen-Schlüsseln aus `config/permissions.php`): Der Eintrag erscheint nur Mitgliedern mit einer dieser Rollen im Betrieb; Plattform-Zugänge ohne Mitgliedschaft sehen ihn nicht.
  - Eintragsfeld `keywords` nimmt Begriffe und Begriff ⇒ Ziel: `['Adresse', 'Logo' => 'logo']`. `NavItem::$keywords` enthält dann `['Adresse', 'Logo#logo']`.
  - Eine `Navigation`-Instanz prüft jedes Recht je Benutzer und Betrieb einmal und den Standortzugriff einmal je Standort.
  - `firstSettingsUrl()` hört beim ersten sichtbaren Eintrag auf.

- [ ] **Step 1: Failing tests – `tests/Feature/NavigationTest.php`**

Imports ergänzen (alphabetisch einsortieren): `use App\Models\User;`, `use Illuminate\Support\Facades\DB;`. Neue Methoden am Ende der Klasse:
```php
    public function test_keywords_can_point_to_a_section(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $nav = new Navigation(['settings' => [
            ['label' => 'Betrieb', 'items' => [
                ['key' => 'a', 'label' => 'A', 'route' => 'admin.locations.index', 'keywords' => ['Adresse', 'Logo' => 'logo', 'Neuer Standort' => 'standort-neu']],
            ]],
        ]]);

        $item = $nav->settings($owner, $setup['tenant'], $setup['location'], null)[0]->items[0];

        $this->assertSame(['Adresse', 'Logo#logo', 'Neuer Standort#standort-neu'], $item->keywords);
    }

    public function test_roles_limit_an_entry_to_members_with_that_role(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $support = User::factory()->create(['saas_role' => 'support_admin']);
        $nav = new Navigation(['settings' => [
            ['label' => 'Betrieb', 'items' => [
                ['key' => 'a', 'label' => 'A', 'route' => 'admin.locations.index'],
                ['key' => 'b', 'label' => 'B', 'route' => 'admin.users.index', 'roles' => ['tenant_owner']],
            ]],
        ]]);

        $this->assertSame([['Betrieb', ['a', 'b']]], $this->shape($nav->settings($owner, $setup['tenant'], $setup['location'], null)));
        $this->assertSame([['Betrieb', ['a']]], $this->shape($nav->settings($admin, $setup['tenant'], $setup['location'], null)));
        $this->assertSame([['Betrieb', ['a']]], $this->shape($nav->settings($support, $setup['tenant'], $setup['location'], null)));
    }

    public function test_the_data_entry_is_reserved_for_owners(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $nav = Navigation::fromConfig();

        $keys = fn (User $user) => array_merge(...array_map(
            fn (NavGroup $group) => array_map(fn (NavItem $item) => $item->key, $group->items),
            $nav->settings($user, $setup['tenant'], $setup['location'], null),
        ));

        $this->assertContains('data', $keys($owner));
        $this->assertNotContains('data', $keys($admin));
    }

    public function test_location_access_is_checked_once_per_navigation(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager', false);
        DB::table('location_user')->insert([
            'location_id' => $setup['location']->id,
            'user_id' => $manager->id,
            'tenant_id' => $setup['tenant']->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $nav = Navigation::fromConfig();

        DB::enableQueryLog();
        $main = $nav->main($manager, $setup['tenant'], $setup['location'], null);
        $nav->settings($manager, $setup['tenant'], $setup['location'], null);
        $nav->mobileBar($manager, $setup['tenant'], $setup['location'], null);
        $url = $nav->firstSettingsUrl($manager, $setup['tenant'], $setup['location']);
        $queries = array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'location_user'));
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame(['board', 'floorplan', 'walkins', 'waitlist'], array_map(fn (NavItem $item) => $item->key, $main[1]->items));
        $this->assertSame(route('admin.settings.index').'#zeiten', $url);
    }

    public function test_a_member_without_access_to_the_location_sees_only_unrestricted_entries(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'location_manager', false);

        $groups = Navigation::fromConfig()->main($manager, $setup['tenant'], $setup['location'], null);

        $this->assertSame([[null, ['dashboard']]], $this->shape($groups));
    }
```

Hinweis zu `test_roles_limit_an_entry_to_members_with_that_role`: Die Testkonfiguration setzt `heading_min_items` nicht, deshalb zeigt auch eine Gruppe mit einem Eintrag ihre Überschrift.

- [ ] **Step 2: Run – expect FAIL**

Run: `PAO_DISABLE=1 php artisan test --filter=NavigationTest`
Expected: FAIL in `test_keywords_can_point_to_a_section` (Ziele fehlen), `test_roles_limit_an_entry_to_members_with_that_role`, `test_the_data_entry_is_reserved_for_owners` und `test_location_access_is_checked_once_per_navigation` (mehrere Abfragen).

- [ ] **Step 3: `app/Support/Navigation/Navigation.php` ersetzen**

Ganze Datei:
```php
<?php

declare(strict_types=1);

namespace App\Support\Navigation;

use App\Models\Location;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Baut die Navigation der Verwaltung aus config/navigation.php.
 *
 * Filtert nach Rechten, Rollen und Betriebstyp und wendet die Regel an, dass
 * eine Gruppenüberschrift erst ab heading_min_items sichtbaren Einträgen
 * erscheint. Jedes Recht wird je Instanz einmal geprüft, der Zugriff auf den
 * Standort einmal je Standort.
 */
final class Navigation
{
    /** @var array<string, bool> Recht im Betrieb, Schlüssel Benutzer|Betrieb|Recht */
    private array $allowed = [];

    /** @var array<string, bool> Zugriff auf den Standort, Schlüssel Benutzer|Betrieb|Standort */
    private array $locationAccess = [];

    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('navigation', []));
    }

    /**
     * @return list<NavGroup>
     */
    public function main(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        return $this->groups((array) ($this->config['main'] ?? []), $user, $tenant, $location, $currentRoute);
    }

    /**
     * @return list<NavGroup>
     */
    public function settings(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        return $this->groups((array) ($this->config['settings'] ?? []), $user, $tenant, $location, $currentRoute);
    }

    /**
     * @return list<NavItem>
     */
    public function mobileBar(User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        $items = [];
        foreach ((array) ($this->config['main'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (($item['mobile_bar'] ?? false) === true && $this->visible($item, $user, $tenant, $location)) {
                    $items[] = $this->item($item, $tenant, $currentRoute);
                }
            }
        }

        if (! isset($this->config['mobile_bar_max'])) {
            return $items;
        }

        return array_slice($items, 0, max(0, (int) $this->config['mobile_bar_max']));
    }

    /**
     * Adresse der ersten erlaubten Einstellungsseite; die übrigen Einträge
     * werden dafür nicht geprüft.
     */
    public function firstSettingsUrl(User $user, Tenant $tenant, ?Location $location): ?string
    {
        foreach ((array) ($this->config['settings'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ($this->visible($item, $user, $tenant, $location)) {
                    return $this->item($item, $tenant, null)->url;
                }
            }
        }

        return null;
    }

    public function isSettingsRoute(?string $currentRoute): bool
    {
        if ($currentRoute === null) {
            return false;
        }

        $settingsRoutes = array_values(array_map('strval', (array) ($this->config['settings_routes'] ?? [])));
        if (Str::is($settingsRoutes, $currentRoute)) {
            return true;
        }

        foreach ((array) ($this->config['settings'] ?? []) as $group) {
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (Str::is($this->activePatterns($item), $currentRoute)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $groups
     * @return list<NavGroup>
     */
    private function groups(array $groups, User $user, Tenant $tenant, ?Location $location, ?string $currentRoute): array
    {
        // Ohne Eintrag heading_min_items zeigt jede Gruppe ihre Überschrift.
        $headingMinItems = (int) ($this->config['heading_min_items'] ?? 0);
        $result = [];
        foreach ($groups as $group) {
            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                if ($this->visible($item, $user, $tenant, $location)) {
                    $items[] = $this->item($item, $tenant, $currentRoute);
                }
            }

            if ($items === []) {
                continue;
            }

            $label = count($items) >= $headingMinItems ? ($group['label'] ?? null) : null;
            $result[] = new NavGroup($label, $items);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function visible(array $item, User $user, Tenant $tenant, ?Location $location): bool
    {
        $only = $item['only'] ?? null;
        if ($only === 'salon' && ! $tenant->isSalon()) {
            return false;
        }
        if ($only === 'restaurant' && $tenant->isSalon()) {
            return false;
        }

        $roles = array_map('strval', (array) ($item['roles'] ?? []));
        if ($roles !== [] && ! in_array($user->roleIn($tenant), $roles, true)) {
            return false;
        }

        foreach ((array) ($item['can'] ?? []) as $permission) {
            if (! $this->allows($user, (string) $permission, $tenant, $location)) {
                return false;
            }
        }

        $any = (array) ($item['can_any'] ?? []);
        if ($any === []) {
            return true;
        }

        foreach ($any as $permission) {
            if ($this->allows($user, (string) $permission, $tenant, $location)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gleiches Ergebnis wie User::canInTenant($permission, $tenant, $location),
     * mit Zwischenspeicher: Das Recht wird je Benutzer und Betrieb einmal
     * geprüft, der Standortzugriff (eine Abfrage bei Mitgliedern mit
     * freigegebenen Standorten) einmal je Standort. Plattform-Zugänge haben
     * Zugriff auf jeden Standort.
     */
    private function allows(User $user, string $permission, Tenant $tenant, ?Location $location): bool
    {
        $key = $user->id.'|'.$tenant->id.'|'.$permission;
        $this->allowed[$key] ??= $user->canInTenant($permission, $tenant);

        if (! $this->allowed[$key] || $location === null || $user->isSaasAdmin()) {
            return $this->allowed[$key];
        }

        $locationKey = $user->id.'|'.$tenant->id.'|'.$location->id;

        return $this->locationAccess[$locationKey] ??= $user->canAccessLocation($tenant, $location);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function item(array $item, Tenant $tenant, ?string $currentRoute): NavItem
    {
        $label = $tenant->isSalon() && isset($item['label_salon']) ? $item['label_salon'] : $item['label'];
        $url = route((string) $item['route']).(isset($item['fragment']) ? '#'.$item['fragment'] : '');

        return new NavItem(
            key: (string) $item['key'],
            label: (string) $label,
            url: $url,
            icon: (string) ($item['icon'] ?? ''),
            active: $currentRoute !== null && Str::is($this->activePatterns($item), $currentRoute),
            scope: isset($item['scope']) ? (string) $item['scope'] : null,
            keywords: $this->keywords((array) ($item['keywords'] ?? [])),
        );
    }

    /**
     * Suchbegriffe als Liste; Begriff ⇒ Ziel wird zu „Begriff#ziel“.
     *
     * @param  array<int|string, mixed>  $keywords
     * @return list<string>
     */
    private function keywords(array $keywords): array
    {
        $result = [];
        foreach ($keywords as $word => $target) {
            $result[] = is_string($word) ? $word.'#'.$target : (string) $target;
        }

        return $result;
    }

    /**
     * Routenmuster, bei denen ein Eintrag als aktuell gilt. Vorgabe ist die
     * eigene Route; eine leere Liste heißt: nie (Verweise auf Sprungmarken).
     *
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    private function activePatterns(array $item): array
    {
        return array_values(array_map('strval', (array) ($item['active'] ?? [$item['route']])));
    }
}
```

- [ ] **Step 4: `config/navigation.php` – Felder und Eintrag „Daten“**

1. Im Kopfkommentar bei den Feldern je Eintrag nach `can_any` ergänzen:
```php
|   roles       Rollen (config/permissions.php), denen der Eintrag vorbehalten
|               ist; Plattform-Zugänge ohne Mitgliedschaft sehen ihn nicht
```
und die Zeile zu `keywords` ersetzen durch:
```php
|   keywords    nur Einstellungen: Begriffe für die Suche; 'Begriff' => 'ziel'
|               führt direkt zur Stelle #ziel auf der Seite
```
2. Im Eintrag `data` den Teil `'can' => ['tenant.settings.manage']` ersetzen durch `'roles' => ['tenant_owner']`. Die Kontoseite zeigt Export, Import und „Betrieb löschen“ nur Inhabern; der Eintrag folgt derselben Prüfung. Route und Suchbegriffe ändert Task 9.

- [ ] **Step 5: Run – expect PASS**

Run: `PAO_DISABLE=1 php artisan test --filter='NavigationTest|NavigationLayoutTest|SettingsMenuTest'`
Expected: PASS.

- [ ] **Step 6: Statische Prüfung**

Run:
```bash
vendor/bin/pint --test app/Support/Navigation config/navigation.php tests/Feature/NavigationTest.php
vendor/bin/phpstan analyse --memory-limit=1G app/Support/Navigation
```
Expected: beide ohne Befund.

- [ ] **Step 7: Commit**

```bash
git add app/Support/Navigation/Navigation.php config/navigation.php tests/Feature/NavigationTest.php
git commit -m "feat: Navigation mit Rollenfilter, Suchbegriffen mit Ziel und einmaliger Rechteprüfung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 4: Bausteine – Seitenleiste, Rückfrage in der Zeile, Leerzustand, Zustandssymbol, Knopfstufen

**Files:**
- Create: `resources/views/components/drawer.blade.php`, `resources/views/components/inline-confirm.blade.php`, `resources/views/components/empty-state.blade.php`, `resources/views/components/status-icon.blade.php`, `resources/js/admin/drawer.js`, `resources/js/admin/inline-confirm.js`, `resources/js/admin/copy.js`, `tests/js/drawer.test.js`, `tests/js/inline-confirm.test.js`, `tests/js/copy.test.js`, `tests/Feature/AdminComponentsTest.php`
- Modify: `resources/js/admin/index.js`, `resources/css/app.css`

**Interfaces:**
- Consumes: `focusables()` (dom.js, Task 2), `showToast()` (toast.js), `swayy:saved` blubbernd (forms.js, Task 2), `@stack('drawers')` im Layout (Teil 1), `data-overlay-layer` an Hinweis und Bestätigungsdialog (Task 2).
- Produces:
  - `<x-drawer id title [description]>` mit Standard-Slot (Inhalt) und Slot `footer`. Markup: Hülle `[data-drawer]` mit `id`, Fläche `[role=dialog][aria-modal=true]`, Inhalt `[data-drawer-body]`, Schließen `[data-drawer-close]`. Seiten legen Drawer per `@push('drawers')` ab.
  - Öffnen: Element mit `data-drawer-open="{id}"` oder Adresse `#{id}`. Schließen: Escape, `[data-drawer-close]`, erfolgreiches Speichern eines Formulars darin (`swayy:saved`). Beim Schließen werden die Formulare zurückgesetzt, der Fokus kehrt zum öffnenden Element zurück, sonst zur Überschrift `[data-page-header] h1`.
  - `<x-inline-confirm id action label question confirm [method=POST] [tone=danger] [ajax=true] [open=false]>`; optionaler Standard-Slot für weitere Felder im Formular der Frage (etwa eine Eingabe zur Bestätigung). Markup: `[data-inline-confirm]` mit Knopf `[data-inline-confirm-open]` und Formular `[data-inline-confirm-panel]` (`id`), darin `[data-inline-confirm-cancel]`.
  - `<x-empty-state title>` mit Standard-Slot (ein Satz, wofür der Bereich da ist) und Slot `action`.
  - `<x-status-icon state label>`; `state` ist `ok`, `off`, `warn` oder `error`; das Wort steht als `title` und `sr-only`.
  - Knopf-Klassen in `app.css`: `.btn` mit `.btn-primary`, `.btn-secondary`, `.btn-danger`; `.btn-quiet` und `.btn-quiet-danger` für Zeilenaktionen.
  - `data-copy="Text"` (+ `data-copy-message`) kopiert in die Zwischenablage.
  - `index.js` startet zusätzlich `initInlineConfirms()`, `initDrawers()`, `initCopyButtons()`.

- [ ] **Step 1: Failing tests – `tests/js/drawer.test.js`**

```js
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initDrawers } from '../../resources/js/admin/drawer.js';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { jsonResponse, mount } from './helpers.js';

const layout = `
    <div id="app">
        <div data-page-header><h1>Standorte</h1></div>
        <button type="button" id="opener" data-drawer-open="standort-neu">Standort hinzufügen</button>
    </div>
    <div id="standort-neu" data-drawer hidden>
        <div data-drawer-close></div>
        <div role="dialog" aria-modal="true" aria-labelledby="standort-neu-title">
            <h2 id="standort-neu-title">Neuer Standort</h2>
            <button type="button" data-drawer-close id="close">Schließen</button>
            <div data-drawer-body>
                <form id="form-neu" method="POST" action="/admin/locations" data-ajax>
                    <label for="f-neu-name">Name</label>
                    <input id="f-neu-name" name="name" value="">
                </form>
            </div>
            <button type="submit" form="form-neu">Anlegen</button>
        </div>
    </div>`;

const escape = () => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

beforeAll(() => {
    initAjaxForms();
});

describe('Seitenleiste', () => {
    it('öffnet per Knopf, sperrt den Hintergrund und setzt den Fokus ins erste Feld', () => {
        mount(layout);
        initDrawers();

        document.getElementById('opener').click();

        expect(document.getElementById('standort-neu').hidden).toBe(false);
        expect(document.getElementById('app').hasAttribute('inert')).toBe(true);
        expect(document.getElementById('swayyToast').hasAttribute('inert')).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('f-neu-name'));

        escape();
    });

    it('schließt mit Escape, setzt das Formular zurück und gibt den Fokus zurück', () => {
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();
        document.getElementById('f-neu-name').value = 'Halb getippt';

        escape();

        expect(document.getElementById('standort-neu').hidden).toBe(true);
        expect(document.getElementById('app').hasAttribute('inert')).toBe(false);
        expect(document.getElementById('f-neu-name').value).toBe('');
        expect(document.activeElement).toBe(document.getElementById('opener'));
    });

    it('zeigt Prüfungsfehler im Drawer und bleibt offen', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
            message: 'Der Name fehlt.',
            errors: { name: ['Bitte einen Namen für den Standort eintragen.'] },
        }, 422)));
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();

        document.getElementById('form-neu').requestSubmit();

        await vi.waitFor(() => expect(document.getElementById('f-neu-name-error')?.textContent)
            .toBe('Bitte einen Namen für den Standort eintragen.'));
        expect(document.getElementById('standort-neu').hidden).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('f-neu-name'));

        escape();
        expect(document.getElementById('f-neu-name-error')).toBeNull();
    });

    it('schließt nach erfolgreichem Speichern', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Standort angelegt.' })));
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();
        document.getElementById('f-neu-name').value = 'Filiale Süd';

        document.getElementById('form-neu').requestSubmit();

        await vi.waitFor(() => expect(document.getElementById('standort-neu').hidden).toBe(true));
        expect(document.activeElement).toBe(document.getElementById('opener'));
    });

    it('öffnet über die Adresse und entfernt das Ziel beim Schließen', () => {
        history.replaceState(null, '', '/admin/locations#standort-neu');
        mount(layout);
        initDrawers();

        expect(document.getElementById('standort-neu').hidden).toBe(false);

        document.getElementById('close').click();

        expect(window.location.hash).toBe('');
        expect(document.activeElement).toBe(document.querySelector('[data-page-header] h1'));
    });
});
```

Jeder Test schließt seinen Drawer wieder: Der Zustand „offen“ lebt im Modul und gilt für die ganze Testdatei.

- [ ] **Step 2: Failing tests – `tests/js/inline-confirm.test.js`**

```js
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initDrawers } from '../../resources/js/admin/drawer.js';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initInlineConfirms } from '../../resources/js/admin/inline-confirm.js';
import { jsonResponse, mount } from './helpers.js';

const row = `
    <div data-inline-confirm>
        <button type="button" id="ask" data-inline-confirm-open>Entfernen</button>
        <form id="c1" data-inline-confirm-panel hidden method="POST" action="/admin/users/7" data-ajax>
            <p>„Kim“ aus dem Betrieb entfernen?</p>
            <button type="submit" id="yes">Entfernen</button>
            <button type="button" data-inline-confirm-cancel id="no">Abbrechen</button>
        </form>
    </div>`;

const escape = () => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));

beforeAll(() => {
    initAjaxForms();
    initInlineConfirms();
    initDrawers();
});

describe('Rückfrage in der Zeile', () => {
    it('ersetzt den Knopf durch die Frage und fokussiert die Bestätigung', () => {
        mount(row);

        document.getElementById('ask').click();

        expect(document.getElementById('ask').hidden).toBe(true);
        expect(document.getElementById('c1').hidden).toBe(false);
        expect(document.activeElement).toBe(document.getElementById('yes'));
    });

    it('schließt mit Abbrechen und Escape, Fokus zurück auf den Knopf', () => {
        mount(row);
        document.getElementById('ask').click();
        document.getElementById('no').click();
        expect(document.getElementById('c1').hidden).toBe(true);
        expect(document.activeElement).toBe(document.getElementById('ask'));

        document.getElementById('ask').click();
        escape();
        expect(document.getElementById('c1').hidden).toBe(true);
        expect(document.activeElement).toBe(document.getElementById('ask'));
    });

    it('zeigt eine Ablehnung des Servers in der Zeile', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
            message: 'Der Betrieb braucht mindestens einen Inhaber.',
            errors: { role: ['Der Betrieb braucht mindestens einen Inhaber.'] },
        }, 422)));
        mount(row);
        document.getElementById('ask').click();

        document.getElementById('c1').requestSubmit();

        await vi.waitFor(() => expect(document.querySelector('#c1 [data-form-problems]')?.textContent)
            .toBe('Der Betrieb braucht mindestens einen Inhaber.'));
    });

    it('schließt mit Escape zuerst die Frage und lässt eine umgebende Seitenleiste offen', () => {
        mount(`
            <div id="app"><button type="button" id="opener" data-drawer-open="d">Öffnen</button></div>
            <div id="d" data-drawer hidden><div role="dialog"><div data-drawer-body>${row}</div></div></div>`);
        document.getElementById('opener').click();
        document.getElementById('ask').click();

        escape();

        expect(document.getElementById('c1').hidden).toBe(true);
        expect(document.getElementById('d').hidden).toBe(false);

        escape();
        expect(document.getElementById('d').hidden).toBe(true);
    });
});
```

- [ ] **Step 3: Failing tests – `tests/js/copy.test.js`**

```js
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initCopyButtons } from '../../resources/js/admin/copy.js';
import { mount } from './helpers.js';

beforeAll(() => initCopyButtons());

describe('Kopierknopf', () => {
    it('legt den Text in die Zwischenablage und meldet das', async () => {
        const writeText = vi.fn(async () => {});
        vi.stubGlobal('navigator', { clipboard: { writeText } });
        mount('<button type="button" data-copy="https://swayy.test/invitation/abc" data-copy-message="Link kopiert.">Kopieren</button>');

        document.querySelector('[data-copy]').click();

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Link kopiert.'));
        expect(writeText).toHaveBeenCalledWith('https://swayy.test/invitation/abc');
    });

    it('nennt den Weg von Hand, wenn der Browser das Kopieren verweigert', async () => {
        vi.stubGlobal('navigator', { clipboard: { writeText: vi.fn(async () => { throw new Error('denied'); }) } });
        mount('<button type="button" data-copy="abc">Kopieren</button>');

        document.querySelector('[data-copy]').click();

        await vi.waitFor(() => expect(document.getElementById('swayyToast').dataset.state).toBe('error'));
        expect(document.getElementById('swayyToast').textContent).toContain('Strg+C');
    });
});
```

- [ ] **Step 4: Run – expect FAIL**

Run: `npm test -- tests/js/drawer.test.js tests/js/inline-confirm.test.js tests/js/copy.test.js`
Expected: FAIL, „Failed to resolve import“ für `drawer.js`, `inline-confirm.js`, `copy.js`.

- [ ] **Step 5: `resources/js/admin/drawer.js` anlegen**

```js
// Seitenleiste zum Anlegen und Bearbeiten (x-drawer): von rechts, auf dem
// Smartphone bildschirmfüllend. Ein Element mit data-drawer-open="{id}"
// öffnet sie, ebenso die Adresse mit #{id} (Suche in den Einstellungen).
// Escape, [data-drawer-close] und erfolgreiches Speichern (swayy:saved)
// schließen sie; die Formulare darin kehren zum gespeicherten Stand zurück,
// der Fokus zum öffnenden Element. Solange sie offen ist, ist alles andere
// gesperrt (inert), ausgenommen Ebenen mit data-overlay-layer (Hinweis,
// Bestätigungsdialog).

import { focusables } from './dom.js';

let installed = false;
let current = null;

function open(drawer, opener = null) {
    if (current?.drawer === drawer) return;
    if (current) close();

    const covered = [...document.body.children].filter(
        (el) => el !== drawer && !el.matches('script, [data-overlay-layer]') && !el.hasAttribute('inert'),
    );
    covered.forEach((el) => el.setAttribute('inert', ''));
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
    current = { drawer, opener, covered };

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
    drawer.querySelectorAll('form').forEach((form) => form.reset());
    covered.forEach((el) => el.removeAttribute('inert'));
    document.body.style.overflow = '';
    if (window.location.hash === `#${drawer.id}`) {
        history.replaceState(null, '', window.location.pathname + window.location.search);
    }

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
    const id = decodeURIComponent(window.location.hash.slice(1));
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
        if (current && current.drawer.contains(e.target) && e.target.closest('[data-drawer-close]')) close();
    });

    // Eine offene Rückfrage in der Zeile schließt Escape zuerst
    // (inline-confirm.js setzt dann defaultPrevented).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && current && !e.defaultPrevented) close();
    });

    document.addEventListener('swayy:saved', (e) => {
        if (current && e.target instanceof Node && current.drawer.contains(e.target)) close();
    });

    window.addEventListener('hashchange', openFromHash);
}
```

- [ ] **Step 6: `resources/js/admin/inline-confirm.js` anlegen**

```js
// Rückfrage in der Zeile (x-inline-confirm): Der Knopf weicht der Frage mit
// Bestätigen und „Abbrechen“. Escape und „Abbrechen“ schließen sie und setzen
// ihr Formular zurück, der Fokus kehrt zum Knopf zurück. Gesendet wird über
// das Formular der Frage, mit data-ajax per fetch (forms.js); eine Ablehnung
// des Servers erscheint dann in der Zeile.

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

function close(root) {
    const { opener, panel } = parts(root);
    panel.hidden = true;
    panel.reset();
    opener.hidden = false;
    opener.focus();
}

export function initInlineConfirms() {
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
        if (cancel) close(cancel.closest('[data-inline-confirm]'));
    });

    // Capture-Phase: Escape schließt zuerst die Frage; eine umgebende
    // Seitenleiste bleibt offen (drawer.js prüft defaultPrevented).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !(e.target instanceof Element)) return;
        const root = e.target.closest('[data-inline-confirm]');
        if (!root || parts(root).panel.hidden) return;
        e.preventDefault();
        close(root);
    }, true);
}
```

- [ ] **Step 7: `resources/js/admin/copy.js` anlegen**

```js
// Knopf mit data-copy="…" legt den Text in die Zwischenablage und meldet das
// als Hinweis (data-copy-message). Verweigert der Browser das Kopieren
// (Rechte, unsichere Verbindung), nennt der Hinweis den Weg von Hand.

import { showToast } from './toast.js';

let installed = false;

export function initCopyButtons() {
    if (installed) return;
    installed = true;

    document.addEventListener('click', async (e) => {
        if (!(e.target instanceof Element)) return;
        const button = e.target.closest('[data-copy]');
        if (!button) return;
        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            showToast(button.dataset.copyMessage || 'Kopiert.');
        } catch {
            showToast('Kopieren hat nicht geklappt. Bitte den Text markieren und mit Strg+C (Mac: ⌘+C) kopieren.', { error: true });
        }
    });
}
```

- [ ] **Step 8: `resources/js/admin/index.js` ersetzen**

```js
import { initCopyButtons } from './copy.js';
import { initDrawers } from './drawer.js';
import { initAjaxForms } from './forms.js';
import { initInlineConfirms } from './inline-confirm.js';
import { initAutosubmit, initMoreSheet, initRail, initSettingsSearch } from './nav.js';
import { initSaveBar } from './savebar.js';

export function initAdmin() {
    initRail();
    initMoreSheet();
    initAutosubmit();
    initSettingsSearch();
    initAjaxForms();
    initSaveBar();
    initInlineConfirms();
    initDrawers();
    initCopyButtons();
}
```

- [ ] **Step 9: Run – expect PASS**

Run: `npm test`
Expected: PASS, 27 Tests.

- [ ] **Step 10: Failing test – `tests/Feature/AdminComponentsTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class AdminComponentsTest extends TestCase
{
    public function test_drawer_renders_a_labelled_modal_panel(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-drawer id="standort-neu" title="Neuer Standort" description="Name und Adresse genügen für den Anfang.">
                <p>Inhalt</p>
                <x-slot:footer>
                    <button type="submit">Anlegen</button>
                </x-slot:footer>
            </x-drawer>
            BLADE);

        $this->assertStringContainsString('id="standort-neu" data-drawer hidden', $html);
        // Blade lässt um @if-Direktiven im Tag doppelte Leerzeichen stehen.
        $this->assertMatchesRegularExpression('/role="dialog" aria-modal="true" aria-labelledby="standort-neu-title"\s+aria-describedby="standort-neu-description"/', $html);
        $this->assertStringContainsString('<h2 id="standort-neu-title"', $html);
        $this->assertStringContainsString('data-drawer-body', $html);
        $this->assertStringContainsString('<p>Inhalt</p>', $html);
        $this->assertStringContainsString('<button type="submit">Anlegen</button>', $html);
        $this->assertSame(2, substr_count($html, 'data-drawer-close'));
    }

    public function test_inline_confirm_renders_button_and_hidden_question(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-inline-confirm id="remove-7" action="/admin/users/7" method="DELETE" label="Entfernen"
                question="„Kim“ aus dem Betrieb entfernen?" confirm="Entfernen" />
            BLADE);

        $this->assertStringContainsString('data-inline-confirm-open', $html);
        $this->assertMatchesRegularExpression('/id="remove-7" data-inline-confirm-panel\s+hidden\s+method="POST"/', $html);
        $this->assertStringContainsString('action="/admin/users/7"', $html);
        $this->assertStringContainsString('name="_method" value="DELETE"', $html);
        $this->assertStringContainsString('data-ajax', $html);
        $this->assertStringContainsString('„Kim“ aus dem Betrieb entfernen?', $html);
        $this->assertStringContainsString('btn-danger', $html);
        $this->assertStringContainsString('data-inline-confirm-cancel', $html);
    }

    public function test_inline_confirm_can_start_open_and_post_natively(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-inline-confirm id="tenant-delete" action="/admin/account/tenant" method="DELETE" label="Betrieb löschen …"
                question="Betrieb endgültig löschen?" confirm="Betrieb jetzt löschen" :ajax="false" :open="true">
                <input name="confirm">
            </x-inline-confirm>
            BLADE);

        $this->assertStringNotContainsString('data-ajax', $html);
        $this->assertMatchesRegularExpression('/id="tenant-delete" data-inline-confirm-panel\s+method="POST"/', $html);
        $this->assertMatchesRegularExpression('/data-inline-confirm-open\s+hidden/', $html);
        $this->assertStringContainsString('<input name="confirm">', $html);
    }

    public function test_status_icon_names_the_state_in_words(): void
    {
        $html = Blade::render('<x-status-icon state="off" label="Inaktiv" />');

        $this->assertStringContainsString('title="Inaktiv"', $html);
        $this->assertStringContainsString('<span class="sr-only">Inaktiv</span>', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }

    public function test_status_icon_falls_back_to_off_for_unknown_states(): void
    {
        $off = Blade::render('<x-status-icon state="off" label="X" />');
        $unknown = Blade::render('<x-status-icon state="unbekannt" label="X" />');

        $this->assertSame($off, $unknown);
    }

    public function test_empty_state_offers_one_action(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-empty-state title="Noch keine Einladungen">
                Eingeladene Personen erscheinen hier, bis sie den Link öffnen.
                <x-slot:action>
                    <button type="button">Person einladen</button>
                </x-slot:action>
            </x-empty-state>
            BLADE);

        $this->assertStringContainsString('Noch keine Einladungen', $html);
        $this->assertStringContainsString('Eingeladene Personen erscheinen hier', $html);
        $this->assertStringContainsString('<button type="button">Person einladen</button>', $html);
    }
}
```

- [ ] **Step 11: Run – expect FAIL**

Run: `PAO_DISABLE=1 php artisan test --filter=AdminComponentsTest`
Expected: FAIL, „Unable to locate a class or view for component [drawer]“.

- [ ] **Step 12: `resources/views/components/drawer.blade.php` anlegen**

```blade
@props(['id', 'title', 'description' => null])
{{-- Seitenleiste von rechts zum Anlegen und Bearbeiten (resources/js/admin/drawer.js), auf dem Smartphone bildschirmfüllend.
     Seiten legen sie mit @push('drawers') ans Ende des Dokuments. Geöffnet von data-drawer-open="{id}" oder der Adresse #{id}.
     Knöpfe im Slot "footer" verweisen mit form="…" auf das Formular im Inhalt. --}}
<div id="{{ $id }}" data-drawer hidden class="fixed inset-0 z-50">
    <div data-drawer-close class="absolute inset-0 bg-stone-900/40" aria-hidden="true"></div>
    <div role="dialog" aria-modal="true" aria-labelledby="{{ $id }}-title" @if($description) aria-describedby="{{ $id }}-description" @endif
         class="absolute inset-y-0 right-0 flex w-full flex-col bg-white shadow-2xl md:max-w-md">
        <div class="flex items-start justify-between gap-3 border-b border-stone-200 px-5 py-4">
            <div class="min-w-0">
                <h2 id="{{ $id }}-title" class="text-lg font-semibold">{{ $title }}</h2>
                @if($description)
                    <p id="{{ $id }}-description" class="mt-1 text-sm text-stone-600">{{ $description }}</p>
                @endif
            </div>
            <button type="button" data-drawer-close aria-label="Schließen"
                    class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md text-2xl leading-none text-stone-600 hover:bg-stone-100 hover:text-stone-900">×</button>
        </div>
        <div data-drawer-body class="flex-1 space-y-6 overflow-y-auto px-5 py-5">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex flex-wrap justify-end gap-2 border-t border-stone-200 px-5 py-4">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
```

- [ ] **Step 13: `resources/views/components/inline-confirm.blade.php` anlegen**

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
])
{{-- Rückfrage in der Zeile (resources/js/admin/inline-confirm.js): Der Knopf weicht der Frage mit Objekt und Folge.
     Der Standard-Slot nimmt weitere Felder auf (etwa eine Eingabe zur Bestätigung). :ajax="false" sendet klassisch,
     :open="true" zeigt die Frage gleich (nach einer Ablehnung, die mit Weiterleitung zurückkam). --}}
@php
    $verb = strtoupper($method);
    $opener = $tone === 'danger' ? 'btn-quiet-danger' : 'btn-quiet';
    $confirmClass = $tone === 'danger' ? 'btn btn-danger' : 'btn btn-primary';
@endphp
<div data-inline-confirm {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <button type="button" data-inline-confirm-open @if($open) hidden @endif class="btn {{ $opener }}">{{ $label }}</button>
    <form id="{{ $id }}" data-inline-confirm-panel @unless($open) hidden @endunless method="POST" action="{{ $action }}" @if($ajax) data-ajax @endif
          class="space-y-3 rounded-lg border border-stone-300 bg-stone-50 p-3 text-left">
        @csrf
        @if($verb !== 'POST')
            @method($verb)
        @endif
        <p class="text-sm font-semibold text-stone-900">{{ $question }}</p>
        {{ $slot }}
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="{{ $confirmClass }}">{{ $confirm }}</button>
            <button type="button" data-inline-confirm-cancel class="btn btn-secondary">Abbrechen</button>
        </div>
    </form>
</div>
```

- [ ] **Step 14: `resources/views/components/empty-state.blade.php` anlegen**

```blade
@props(['title'])
{{-- Leerzustand: wofür der Bereich da ist, und genau eine Aktion (Slot "action"). --}}
<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center']) }}>
    <p class="font-semibold text-stone-900">{{ $title }}</p>
    <p class="mx-auto mt-1 max-w-md text-sm text-stone-600">{{ $slot }}</p>
    @isset($action)
        <div class="mt-4 flex justify-center">
            {{ $action }}
        </div>
    @endisset
</div>
```

- [ ] **Step 15: `resources/views/components/status-icon.blade.php` anlegen**

```blade
@props(['state', 'label'])
{{-- Zustand als Symbol mit eigener Form und Farbe; das Wort steht im Tooltip und für Screenreader.
     ok = gefüllter Kreis mit Haken, off = leerer Kreis, warn = Dreieck, error = Quadrat mit Kreuz. --}}
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
    <span class="sr-only">{{ $label }}</span>
</span>
```

- [ ] **Step 16: Knopfstufen in `resources/css/app.css`**

Im Block `@layer components { … }` vor der schließenden Klammer ergänzen:
```css
    /* Knopfstufen der Verwaltung: Haupt, Neben, Gefahr; leise Knöpfe für Zeilenaktionen. */
    .btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.375rem;
        min-height: 2.5rem;
        padding: 0 1rem;
        border: 1px solid transparent;
        border-radius: 0.375rem;
        font-size: 0.875rem;
        font-weight: 600;
        line-height: 1.25;
        text-align: center;
    }
    .btn-primary { background-color: var(--color-stone-900); color: var(--color-white); }
    .btn-primary:hover { background-color: var(--color-stone-800); }
    .btn-secondary { background-color: var(--color-white); border-color: var(--color-stone-300); color: var(--color-stone-800); }
    .btn-secondary:hover { background-color: var(--color-stone-50); }
    .btn-danger { background-color: var(--color-red-700); color: var(--color-white); }
    .btn-danger:hover { background-color: var(--color-red-800); }
    .btn-quiet, .btn-quiet-danger { min-height: 2.25rem; padding: 0 0.625rem; font-weight: 500; }
    .btn-quiet { color: var(--color-stone-700); }
    .btn-quiet:hover { background-color: var(--color-stone-100); color: var(--color-stone-900); }
    .btn-quiet-danger { color: var(--color-red-700); }
    .btn-quiet-danger:hover { background-color: var(--color-red-50); color: var(--color-red-800); }
```

- [ ] **Step 17: Run – expect PASS**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter=AdminComponentsTest
npm test
npm run build
```
Expected: PHPUnit PASS (6 Tests), Vitest PASS (27 Tests), Build ohne Fehler.

- [ ] **Step 18: Commit**

```bash
git add resources/views/components resources/js/admin resources/css/app.css tests/js tests/Feature/AdminComponentsTest.php
git commit -m "feat: Bausteine Seitenleiste, Rückfrage in der Zeile, Leerzustand, Zustandssymbol und Knopfstufen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 5: Standorte mit Profil und Logo

**Files:**
- Create: `app/Http/Controllers/Admin/LocationLogoController.php`, `resources/views/admin/locations/partials/fields.blade.php`, `resources/views/admin/locations/partials/logo.blade.php`, `tests/Feature/LocationLogoTest.php`
- Modify: `app/Http/Controllers/Admin/LocationController.php`, `app/Http/Controllers/Admin/SettingsController.php` (nur `uploadLogo`), `app/Support/EnvSetting.php`, `resources/views/admin/locations/index.blade.php`, `routes/web.php`, `config/swayy.php`, `config/navigation.php`, `.env.example`
- Test: `tests/Feature/LocationManagementTest.php`, `tests/Unit/EnvSettingTest.php`

**Interfaces:**
- Consumes: `RespondsToSettingsForms::saved()/failed()` (Teil 1), `x-page-header`, `x-field` (Teil 1), `x-drawer`, `x-inline-confirm`, `x-status-icon`, `x-empty-state`, `.btn-*` (Task 4), Suchbegriffe mit Ziel (Task 3).
- Produces:
  - Routen `admin.locations.logo.store` (POST `/admin/locations/{location}/logo`) und `admin.locations.logo.destroy` (DELETE), beide hinter `permission:locations.manage` und `permission:tenant.settings.manage`.
  - Einstellungen `swayy.uploads.logo_max_kb` (`SWAYY_LOGO_MAX_KB`, Vorgabe 3072, 64–10240), `swayy.uploads.logo_types` (`SWAYY_LOGO_TYPES`, Vorgabe `jpg,jpeg,png,webp`, erlaubt zusätzlich `gif`), `swayy.uploads.logo_directory` (`SWAYY_LOGO_DIRECTORY`, Vorgabe `logos`), `swayy.locations.default_timezone` (`SWAYY_DEFAULT_TIMEZONE`, Vorgabe `Europe/Berlin`, nur gültige Zeitzonen).
  - Drawer-IDs: `standort-neu`, `standort-{id}`; Formular-IDs `form-standort-neu`, `form-standort-{id}`; Feld-IDs `loc-{neu|id}-{feld}`.
  - `LocationController` antwortet auf JSON mit `message` und `reload: true`; ohne JSON wie bisher (Anlegen leitet auf `admin.locations.index`).

- [ ] **Step 1: Failing tests – `tests/Feature/LocationManagementTest.php`**

Neue Methoden am Ende der Klasse:
```php
    public function test_json_store_answers_with_message_and_reload(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 5]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/locations', [
            'name' => 'Filiale Nord',
            'timezone' => 'Europe/Vienna',
            'public_intro' => 'Willkommen im Norden.',
        ])->assertOk()->assertExactJson([
            'message' => 'Standort „Filiale Nord“ angelegt. Als Nächstes Öffnungszeiten und Tische einrichten.',
            'reload' => true,
        ]);

        $loc = Location::withoutGlobalScopes()->where('tenant_id', $setup['tenant']->id)->where('name', 'Filiale Nord')->firstOrFail();
        $this->assertSame('Europe/Vienna', $loc->timezone);
        $this->assertSame('Willkommen im Norden.', $loc->public_intro);
    }

    public function test_json_store_names_the_plan_limit_and_the_next_step(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $setup['tenant']->update(['plan_id' => Plan::factory()->create(['limits' => ['max_locations' => 1]])->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/locations', ['name' => 'Zweite', 'timezone' => 'Europe/Berlin'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Dein Tarif umfasst einen Standort, und der ist schon angelegt. Für einen weiteren Standort den Tarif unter „Abo & Rechnungen“ wechseln.');
    }

    public function test_json_validation_explains_what_to_enter(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", ['name' => '', 'timezone' => 'Mond/Basis'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', 'Bitte einen Namen für den Standort eintragen.')
            ->assertJsonPath('errors.timezone.0', 'Bitte eine Zeitzone aus der Liste wählen.');
    }

    public function test_json_update_saves_profile_and_keeps_the_slug(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $slug = $setup['location']->slug;
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson("/admin/locations/{$setup['location']->id}", [
            'name' => 'Am Markt',
            'timezone' => 'Europe/Berlin',
            'address_line1' => 'Marktplatz 1',
            'postal_code' => '12345',
            'city' => 'Musterstadt',
            'public_intro' => 'Direkt am Markt.',
        ])->assertOk()->assertJson(['message' => 'Standort „Am Markt“ gespeichert.', 'reload' => true]);

        $fresh = $setup['location']->fresh();
        $this->assertSame('Am Markt', $fresh->name);
        $this->assertSame('Direkt am Markt.', $fresh->public_intro);
        $this->assertSame('Musterstadt', $fresh->city);
        $this->assertSame($slug, $fresh->slug);
    }

    public function test_json_toggle_explains_why_the_last_active_location_stays_active(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson("/admin/locations/{$setup['location']->id}/toggle-active")
            ->assertStatus(422)
            ->assertJsonPath('errors.active.0', fn (string $message) => str_contains($message, 'einzige aktive Standort')
                && str_contains($message, 'Lege zuerst einen weiteren Standort an'));
        $this->assertTrue($setup['location']->fresh()->is_active);
    }

    public function test_index_uses_drawers_status_icons_and_no_inline_handlers(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/locations')->assertOk()->getContent();

        $this->assertStringContainsString('data-drawer-open="standort-'.$setup['location']->id.'"', $html);
        $this->assertStringContainsString('id="standort-'.$setup['location']->id.'" data-drawer hidden', $html);
        $this->assertStringContainsString('<span class="sr-only">Aktiv</span>', $html);
        $this->assertStringContainsString('name="public_intro"', $html);
        $this->assertStringContainsString('Logo hochladen', $html);

        foreach (['index', 'partials/fields', 'partials/logo'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/locations/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }

    public function test_operations_manager_edits_locations_without_logo_controls(): void
    {
        $setup = $this->createTenantSetup([['min' => 1, 'max' => 4]]);
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/locations')
            ->assertOk()
            ->assertSee('name="public_intro"', false)
            ->assertDontSee('Logo hochladen');
    }
```

- [ ] **Step 2: Failing tests – `tests/Feature/LocationLogoTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class LocationLogoTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_defaults_are_defined_in_one_place(): void
    {
        $this->assertSame(3072, config('swayy.uploads.logo_max_kb'));
        $this->assertSame(['jpg', 'jpeg', 'png', 'webp'], config('swayy.uploads.logo_types'));
        $this->assertSame('logos', config('swayy.uploads.logo_directory'));
        $this->assertSame('Europe/Berlin', config('swayy.locations.default_timezone'));
    }

    public function test_owner_uploads_a_logo_for_a_location(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertOk()->assertJson(['reload' => true]);

        $path = $setup['location']->fresh()->brand_logo_path;
        $this->assertStringStartsWith('logos/'.$setup['location']->id.'/', (string) $path);
        Storage::disk('public')->assertExists($path);
    }

    public function test_the_storage_directory_comes_from_the_settings(): void
    {
        Storage::fake('public');
        config(['swayy.uploads.logo_directory' => 'marken']);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertOk();

        $this->assertStringStartsWith('marken/'.$setup['location']->id.'/', (string) $setup['location']->fresh()->brand_logo_path);
    }

    public function test_svg_is_rejected_with_the_allowed_formats(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
        ])->assertStatus(422)
            ->assertJsonPath('errors.logo.0', 'Die Datei ist kein Bild in einem erlaubten Format. Bitte das Logo als JPG, JPEG, PNG oder WEBP hochladen.');

        $this->assertNull($setup['location']->fresh()->brand_logo_path);
    }

    public function test_the_size_limit_comes_from_the_settings(): void
    {
        Storage::fake('public');
        config(['swayy.uploads.logo_max_kb' => 100]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60)->size(150),
        ])->assertStatus(422)
            ->assertJsonPath('errors.logo.0', 'Das Logo ist größer als 100 KB. Bitte eine kleinere Datei wählen.');
    }

    public function test_a_location_of_another_tenant_is_not_found(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $other = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson(route('admin.locations.logo.store', $other['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertNotFound();
    }

    public function test_operations_manager_may_not_change_logos(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->clearTenantContext();

        $this->actingAs($manager)->postJson(route('admin.locations.logo.store', $setup['location']), [
            'logo' => UploadedFile::fake()->image('logo.png', 120, 60),
        ])->assertForbidden();
    }

    public function test_removing_the_logo_deletes_the_file(): void
    {
        Storage::fake('public');
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        Storage::disk('public')->put('logos/'.$setup['location']->id.'/alt.png', 'x');
        $setup['location']->update(['brand_logo_path' => 'logos/'.$setup['location']->id.'/alt.png']);
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson(route('admin.locations.logo.destroy', $setup['location']))
            ->assertOk()->assertJson(['reload' => true]);

        $this->assertNull($setup['location']->fresh()->brand_logo_path);
        Storage::disk('public')->assertMissing('logos/'.$setup['location']->id.'/alt.png');
    }
}
```

- [ ] **Step 3: Run – expect FAIL**

Run: `PAO_DISABLE=1 php artisan test --filter='LocationManagementTest|LocationLogoTest'`
Expected: FAIL (Route `admin.locations.logo.store` fehlt, `public_intro` wird nicht gespeichert, Meldungen anders).

- [ ] **Step 4: `EnvSetting::timezone()` mit Test**

In `tests/Unit/EnvSettingTest.php` vor `private function set(` einfügen:
```php
    public function test_a_known_timezone_is_used(): void
    {
        $this->set('Europe/Vienna');

        $this->assertSame('Europe/Vienna', $this->capture(fn () => EnvSetting::timezone(self::VARIABLE, default: 'Europe/Berlin')));
        $this->assertSame('', $this->warnings);
    }

    public function test_an_unknown_timezone_falls_back_to_the_default_with_a_warning(): void
    {
        $this->set('Mond/Basis');

        $this->assertSame('Europe/Berlin', $this->capture(fn () => EnvSetting::timezone(self::VARIABLE, default: 'Europe/Berlin')));
        $this->assertStringContainsString(self::VARIABLE.'=„Mond/Basis“ ist keine bekannte Zeitzone. Es gilt die Vorgabe „Europe/Berlin“.', $this->warnings);
    }
```
Run: `PAO_DISABLE=1 php artisan test --filter=EnvSettingTest` → FAIL („Call to undefined method … timezone“).

In `app/Support/EnvSetting.php` `use DateTimeZone;` zu den Imports und nach der Methode `time()` einfügen:
```php
    /**
     * Zeitzone aus der Liste von PHP, etwa Europe/Berlin.
     */
    public static function timezone(string $variable, string $default): string
    {
        $raw = self::raw($variable);
        if ($raw === null) {
            return $default;
        }

        if (! in_array($raw, DateTimeZone::listIdentifiers(), true)) {
            self::warn("{$variable}=„{$raw}“ ist keine bekannte Zeitzone. Es gilt die Vorgabe „{$default}“. Bitte eine Zeitzone wie Europe/Berlin in der .env eintragen.");

            return $default;
        }

        return $raw;
    }
```
Run: `PAO_DISABLE=1 php artisan test --filter=EnvSettingTest` → PASS.

- [ ] **Step 4b: Einstellungen in `config/swayy.php`**

Vor dem Abschnitt `ui` einfügen:
```php
    /*
    |--------------------------------------------------------------------------
    | Standorte
    |--------------------------------------------------------------------------
    |
    | default_timezone: Zeitzone, die das Formular „Neuer Standort“ vorschlägt
    | und die gilt, solange kein Standort gewählt ist (etwa im Protokoll).
    |
    */
    'locations' => [
        'default_timezone' => EnvSetting::timezone('SWAYY_DEFAULT_TIMEZONE', default: 'Europe/Berlin'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hochgeladene Dateien
    |--------------------------------------------------------------------------
    |
    | logo_max_kb: größte Datei für ein Standort-Logo in KB (64–10240).
    | logo_types: erlaubte Dateiendungen für Logos; SVG ist nie erlaubt, weil
    | es Skripte tragen kann. Eine leere Liste („-“) gilt als Vorgabe, sonst
    | ließe sich kein Logo hochladen. logo_directory: Ordner auf dem Speicher
    | „public“, darunter je Standort ein Unterordner.
    |
    */
    'uploads' => [
        'logo_max_kb' => EnvSetting::integer('SWAYY_LOGO_MAX_KB', default: 3072, min: 64, max: 10240),
        'logo_types' => EnvSetting::choiceList('SWAYY_LOGO_TYPES', default: ['jpg', 'jpeg', 'png', 'webp'], allowed: ['jpg', 'jpeg', 'png', 'webp', 'gif'])
            ?: ['jpg', 'jpeg', 'png', 'webp'],
        'logo_directory' => EnvSetting::pattern('SWAYY_LOGO_DIRECTORY', default: 'logos', regex: '/^[a-z0-9][a-z0-9_-]*$/', hint: 'ist kein Ordnername aus Kleinbuchstaben, Ziffern, - und _'),
    ],

```
3. In `.env.example` nach dem Block `# --- Oberfläche der Verwaltung ---` (nach `# SWAYY_NAV_HEADING_MIN_ITEMS=2`) einfügen:
```
# Zeitzone, die „Neuer Standort“ vorschlägt (gültige Zeitzone wie Europe/Berlin)
# SWAYY_DEFAULT_TIMEZONE=Europe/Berlin
# Größte Datei für ein Standort-Logo in KB (64–10240)
# SWAYY_LOGO_MAX_KB=3072
# Erlaubte Dateiendungen für Logos, durch Komma getrennt (jpg, jpeg, png, webp, gif)
# SWAYY_LOGO_TYPES=jpg,jpeg,png,webp
# Ordner für Logos auf dem Speicher „public“ (Kleinbuchstaben, Ziffern, - und _)
# SWAYY_LOGO_DIRECTORY=logos
```

- [ ] **Step 5: Routen in `routes/web.php`**

1. Import ergänzen (alphabetisch nach `LocationController`): `use App\Http\Controllers\Admin\LocationLogoController;`
2. In der Gruppe `permission:locations.manage` nach `locations.toggle-active` einfügen:
```php
        // Logo je Standort: Teil des Auftritts, deshalb zusätzlich das Recht für die Betriebseinstellungen.
        Route::post('/locations/{location}/logo', [LocationLogoController::class, 'store'])
            ->middleware('permission:tenant.settings.manage')->name('locations.logo.store');
        Route::delete('/locations/{location}/logo', [LocationLogoController::class, 'destroy'])
            ->middleware('permission:tenant.settings.manage')->name('locations.logo.destroy');
```

- [ ] **Step 6: `app/Http/Controllers/Admin/LocationLogoController.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Logo je Standort (Einstellungen › Betrieb › Standorte, Seitenleiste des
 * Standorts). Formate, Größe und Ordner stehen in config/swayy.php
 * (uploads.*); SVG ist nie dabei, weil es Skripte tragen kann.
 */
class LocationLogoController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    public function store(Request $request, Location $location): mixed
    {
        // Die Route-Bindung filtert schon nach Betrieb; das hier fängt einen fehlenden Kontext ab.
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $maxKb = (int) config('swayy.uploads.logo_max_kb');
        $types = array_map('strval', (array) config('swayy.uploads.logo_types'));
        $wrongType = __('Die Datei ist kein Bild in einem erlaubten Format. Bitte das Logo als :types hochladen.', [
            'types' => self::typeList($types),
        ]);

        $request->validate([
            'logo' => ['required', 'image', 'mimes:'.implode(',', $types), 'max:'.$maxKb],
        ], [
            'logo.required' => __('Bitte eine Bilddatei für das Logo auswählen.'),
            'logo.uploaded' => __('Das Logo kam nicht vollständig an. Bitte eine kleinere Datei wählen oder es erneut versuchen.'),
            'logo.image' => $wrongType,
            'logo.mimes' => $wrongType,
            'logo.max' => __('Das Logo ist größer als :size. Bitte eine kleinere Datei wählen.', ['size' => Number::fileSize($maxKb * 1024)]),
        ]);

        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
        }

        $path = $request->file('logo')->store(config('swayy.uploads.logo_directory').'/'.$location->id, 'public');
        $location->update(['brand_logo_path' => $path]);
        $this->audit->log('location.logo.updated', $location);

        return $this->saved($request, __('Logo für „:name“ gespeichert.', ['name' => $location->name]), reload: true);
    }

    public function destroy(Request $request, Location $location): mixed
    {
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        if ($location->brand_logo_path) {
            Storage::disk('public')->delete($location->brand_logo_path);
            $location->update(['brand_logo_path' => null]);
        }
        $this->audit->log('location.logo.deleted', $location);

        return $this->saved($request, __('Logo von „:name“ entfernt.', ['name' => $location->name]), reload: true);
    }

    /**
     * Endungen für Menschen: „JPG, PNG oder WEBP“.
     *
     * @param  list<string>  $types
     */
    public static function typeList(array $types): string
    {
        $upper = array_map('strtoupper', $types);
        $last = array_pop($upper);

        return $upper === [] ? (string) $last : implode(', ', $upper).' oder '.$last;
    }
}
```

- [ ] **Step 7: Alter Upload der Sammelseite liest dieselben Einstellungen**

In `app/Http/Controllers/Admin/SettingsController.php`, Methode `uploadLogo`, die Validierung und das Speichern ersetzen:
```php
        // No SVG: an SVG served from our own origin can carry executable
        // JavaScript (stored XSS). Raster formats only (config/swayy.php, uploads.*).
        $request->validate([
            'logo' => ['required', 'image', 'mimes:'.implode(',', (array) config('swayy.uploads.logo_types')), 'max:'.(int) config('swayy.uploads.logo_max_kb')],
        ]);
```
und
```php
        $path = $request->file('logo')->store(config('swayy.uploads.logo_directory').'/'.$location->id, 'public');
```

- [ ] **Step 8: `app/Http/Controllers/Admin/LocationController.php` ersetzen**

Ganze Datei:
```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Location;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Einstellungen › Betrieb › Standorte: Liste, Anlegen und Bearbeiten in der
 * Seitenleiste, Aktivieren und Deaktivieren. Einzige Stelle für Adresse,
 * Kontakt, Zeitzone und Begrüßungstext eines Standorts; das Logo speichert
 * LocationLogoController.
 */
class LocationController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $locations = $tenant->locations()
            ->withCount(['tables', 'rooms'])
            ->orderBy('name')
            ->get();
        $types = array_map('strval', (array) config('swayy.uploads.logo_types'));

        return view('admin.locations.index', [
            'tenant' => $tenant,
            'locations' => $locations,
            'limit' => $tenant->limit('max_locations'),
            'used' => $locations->count(),
            'canAdd' => $this->limits->canAdd($tenant, 'max_locations'),
            'canEditLogo' => $request->user()->canInTenant('tenant.settings.manage', $tenant),
            'canSeeBilling' => $request->user()->canInTenant('billing.manage', $tenant),
            'timezones' => DateTimeZone::listIdentifiers(),
            'defaultTimezone' => config('swayy.locations.default_timezone'),
            'logoAccept' => implode(',', array_map(fn (string $type) => '.'.$type, $types)),
            'logoHelp' => __('Als :types, bis :size.', [
                'types' => LocationLogoController::typeList($types),
                'size' => Number::fileSize((int) config('swayy.uploads.logo_max_kb') * 1024),
            ]),
        ]);
    }

    public function store(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        if (! $this->limits->canAdd($tenant, 'max_locations')) {
            $limit = (int) $tenant->limit('max_locations');

            return $this->failed($request, 'name', trans_choice(
                'Dein Tarif umfasst einen Standort, und der ist schon angelegt.|Dein Tarif umfasst :count Standorte, und alle sind schon angelegt.',
                $limit,
                ['count' => $limit],
            ).' '.__('Für einen weiteren Standort den Tarif unter „Abo & Rechnungen“ wechseln.'));
        }

        $validated = $request->validate($this->rules(), $this->messages(), $this->attributes());

        $location = Location::create([
            'tenant_id' => $tenant->id,
            'slug' => $this->uniqueSlug($tenant->id, $validated['name']),
            'is_active' => true,
            'online_booking_enabled' => true,
            ...$this->values($validated),
        ]);
        $location->settings()->create(['tenant_id' => $tenant->id]);

        $this->audit->log('location.created', $location, null, ['name' => $location->name]);

        $message = $tenant->isSalon()
            ? __('Standort „:name“ angelegt. Als Nächstes Öffnungszeiten und Leistungen einrichten.', ['name' => $location->name])
            : __('Standort „:name“ angelegt. Als Nächstes Öffnungszeiten und Tische einrichten.', ['name' => $location->name]);

        if ($request->wantsJson()) {
            return $this->saved($request, $message, reload: true);
        }

        return redirect()->route('admin.locations.index')->with('success', $message);
    }

    public function update(Request $request, Location $location)
    {
        // Route-model binding is tenant-scoped via the global scope; this is
        // belt-and-suspenders against a missing context.
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $values = $this->values($request->validate($this->rules(), $this->messages(), $this->attributes()));
        $old = $location->only(array_keys($values));

        // The slug stays fixed on rename so existing public booking links
        // (/book/{tenant}/{location}) keep working.
        $location->update($values);

        $this->audit->log('location.updated', $location, $old, $values);

        return $this->saved($request, __('Standort „:name“ gespeichert.', ['name' => $location->name]), reload: true);
    }

    public function toggleActive(Request $request, Location $location)
    {
        abort_if($location->tenant_id !== $this->context->tenantId(), 404);

        $tenant = $this->context->tenant();

        // Never deactivate the last active location – the admin area resolves a
        // location from the active set and the public booking would break.
        if ($location->is_active
            && $tenant->locations()->where('is_active', true)->count() <= 1) {
            return $this->failed($request, 'active', __(
                '„:name“ ist der einzige aktive Standort und bleibt aktiv, damit Gäste online buchen können. Lege zuerst einen weiteren Standort an oder aktiviere einen.',
                ['name' => $location->name],
            ));
        }

        $location->update(['is_active' => ! $location->is_active]);

        $this->audit->log('location.'.($location->is_active ? 'activated' : 'deactivated'), $location);

        return $this->saved($request, $location->is_active
            ? __('Standort „:name“ ist wieder aktiv.', ['name' => $location->name])
            : __('Standort „:name“ ist deaktiviert.', ['name' => $location->name]), reload: true);
    }

    /**
     * @return array<string, list<string>>
     */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'timezone'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email:rfc', 'max:200'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'city' => ['nullable', 'string', 'max:100'],
            'public_intro' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function messages(): array
    {
        return [
            'name.required' => __('Bitte einen Namen für den Standort eintragen.'),
            'timezone.required' => __('Bitte eine Zeitzone aus der Liste wählen.'),
            'timezone.timezone' => __('Bitte eine Zeitzone aus der Liste wählen.'),
            'email.email' => __('Die E-Mail-Adresse ist unvollständig. Bitte in der Form name@beispiel.de eintragen.'),
            '*.max' => __(':attribute ist zu lang. Bitte auf höchstens :max Zeichen kürzen.'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function attributes(): array
    {
        return [
            'name' => __('Name'),
            'phone' => __('Telefon'),
            'email' => __('E-Mail'),
            'address_line1' => __('Straße und Hausnummer'),
            'postal_code' => __('PLZ'),
            'city' => __('Ort'),
            'public_intro' => __('Begrüßung'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function values(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'timezone' => $validated['timezone'],
            'phone' => $validated['phone'] ?? null,
            'email' => $validated['email'] ?? null,
            'address_line1' => $validated['address_line1'] ?? null,
            'postal_code' => $validated['postal_code'] ?? null,
            'city' => $validated['city'] ?? null,
            'public_intro' => $validated['public_intro'] ?? null,
        ];
    }

    private function uniqueSlug(int $tenantId, string $name): string
    {
        $base = Str::slug($name) ?: 'standort';
        $slug = $base;
        $i = 1;
        while (Location::withTrashed()
            ->withoutGlobalScope('tenant')
            ->where('tenant_id', $tenantId)
            ->where('slug', $slug)
            ->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }
}
```

`'*.max'` gilt für die Regel `max` jedes Feldes (Laravel löst `*` in eigenen Meldungen auf).

- [ ] **Step 9: `resources/views/admin/locations/partials/fields.blade.php` anlegen**

```blade
{{-- Felder eines Standorts in der Seitenleiste. $prefix macht die IDs je Seitenleiste eindeutig (neu oder ID des Standorts). --}}
@php($p = 'loc-'.$prefix)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$loc?->name" required maxlength="120"
         help="Erscheint auf der Buchungsseite und in Mails an Gäste." />
<x-field name="address_line1" label="Straße und Hausnummer" :id="$p.'-address_line1'" :value="$loc?->address_line1" maxlength="200" />
<div class="grid gap-4 sm:grid-cols-3">
    <x-field name="postal_code" label="PLZ" :id="$p.'-postal_code'" :value="$loc?->postal_code" maxlength="20" />
    <x-field name="city" label="Ort" :id="$p.'-city'" :value="$loc?->city" maxlength="100" class="sm:col-span-2" />
</div>
<x-field name="phone" label="Telefon" type="tel" :id="$p.'-phone'" :value="$loc?->phone" maxlength="40" />
<x-field name="email" label="E-Mail" type="email" :id="$p.'-email'" :value="$loc?->email" maxlength="200"
         help="Für Rückfragen von Gästen; steht in Bestätigungen." />
<x-field name="timezone" label="Zeitzone" :id="$p.'-timezone'" help="Gilt für Öffnungszeiten, Erinnerungen und alle Uhrzeiten dieses Standorts.">
    <select id="{{ $p }}-timezone" name="timezone" required aria-describedby="{{ $p }}-timezone-help" class="w-full">
        @foreach($timezones as $tz)
            <option value="{{ $tz }}" @selected(($loc?->timezone ?? $defaultTimezone) === $tz)>{{ $tz }}</option>
        @endforeach
    </select>
</x-field>
<x-field name="public_intro" label="Begrüßung auf der Buchungsseite" :id="$p.'-public_intro'"
         help="Ein, zwei Sätze über dem Buchungsformular, bis 1000 Zeichen.">
    <textarea id="{{ $p }}-public_intro" name="public_intro" rows="3" maxlength="1000"
              aria-describedby="{{ $p }}-public_intro-help" class="w-full">{{ $loc?->public_intro }}</textarea>
</x-field>
```

- [ ] **Step 10: `resources/views/admin/locations/partials/logo.blade.php` anlegen**

```blade
{{-- Logo eines Standorts: eigenes Formular, speichert sofort (LocationLogoController). --}}
<section aria-labelledby="logo-{{ $loc->id }}-title" class="space-y-4 border-t border-stone-200 pt-5">
    <h3 id="logo-{{ $loc->id }}-title" class="text-sm font-semibold">Logo</h3>
    @if($loc->brand_logo_path)
        <img src="{{ route('brand.location.logo', [$tenant->slug, $loc->slug]) }}?v={{ $loc->updated_at?->timestamp }}"
             alt="Aktuelles Logo von {{ $loc->name }}" class="h-16 w-auto max-w-full rounded border border-stone-200 bg-white p-1">
    @else
        <p class="text-sm text-stone-600">Ohne Logo zeigt die Buchungsseite den Namen des Standorts.</p>
    @endif
    <form method="POST" action="{{ route('admin.locations.logo.store', $loc) }}" enctype="multipart/form-data" data-ajax class="space-y-3">
        @csrf
        <x-field name="logo" label="Neues Logo" type="file" :id="'logo-'.$loc->id.'-file'" :accept="$logoAccept" :help="$logoHelp" required />
        <button type="submit" class="btn btn-secondary">Logo hochladen</button>
    </form>
    @if($loc->brand_logo_path)
        <x-inline-confirm id="logo-entfernen-{{ $loc->id }}" :action="route('admin.locations.logo.destroy', $loc)" method="DELETE"
                          label="Logo entfernen" confirm="Logo entfernen"
                          :question="'Logo von „'.$loc->name.'“ entfernen? Die Buchungsseite zeigt dann den Namen.'" />
    @endif
</section>
```

- [ ] **Step 11: `resources/views/admin/locations/index.blade.php` ersetzen**

```blade
@extends('layouts.admin')
@section('title', 'Standorte')

@section('content')
@php($description = $tenant->isSalon()
    ? 'Adresse, Kontakt und Begrüßung jedes Standorts. Zeiten, Leistungen und Mitarbeiter richtest du je Standort unter „Platz & Zeiten“ ein.'
    : 'Adresse, Kontakt und Begrüßung jedes Standorts. Zeiten, Räume und Tische richtest du je Standort unter „Platz & Zeiten“ ein.')
<x-page-header title="Standorte" :crumb="['Einstellungen', 'Betrieb']" scope="tenant" :description="$description">
    @if($canAdd)
        <x-slot:actions>
            <button type="button" data-drawer-open="standort-neu" class="btn btn-primary">Standort hinzufügen</button>
        </x-slot:actions>
    @endif
</x-page-header>

<p class="mb-4 text-sm text-stone-600">
    @if($limit === null)
        {{ trans_choice(':count Standort|:count Standorte', $used, ['count' => $used]) }}
    @else
        {{ $used }} von {{ $limit }} Standorten deines Tarifs genutzt
    @endif
</p>

@unless($canAdd)
    <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ trans_choice('Dein Tarif umfasst einen Standort, und der ist schon angelegt.|Dein Tarif umfasst :count Standorte, und alle sind schon angelegt.', (int) $limit, ['count' => $limit]) }}
        @if($canSeeBilling)
            <a href="{{ route('admin.billing.show') }}" class="font-semibold underline underline-offset-2">Tarif unter „Abo & Rechnungen“ wechseln</a>
        @else
            Für einen weiteren Standort bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.
        @endif
    </p>
@endunless

@if($locations->isEmpty())
    <x-empty-state title="Noch kein Standort">
        Ein Standort ist der Ort, an dem Gäste buchen: mit Adresse, Zeiten und Plätzen.
        @if($canAdd)
            <x-slot:action>
                <button type="button" data-drawer-open="standort-neu" class="btn btn-primary">Standort hinzufügen</button>
            </x-slot:action>
        @endif
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($locations as $loc)
            @php($address = implode(', ', array_filter([$loc->address_line1, trim($loc->postal_code.' '.$loc->city)])))
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <x-status-icon :state="$loc->is_active ? 'ok' : 'off'" :label="$loc->is_active ? 'Aktiv' : 'Inaktiv'" />
                <div class="min-w-0 flex-1">
                    <button type="button" data-drawer-open="standort-{{ $loc->id }}"
                            class="text-left font-semibold underline-offset-2 hover:underline">{{ $loc->name }}</button>
                    <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">
                        {{ $address !== '' ? $address : 'Adresse fehlt noch' }}
                        @unless($tenant->isSalon())
                            · {{ trans_choice(':count Raum|:count Räume', $loc->rooms_count, ['count' => $loc->rooms_count]) }}
                            · {{ trans_choice(':count Tisch|:count Tische', $loc->tables_count, ['count' => $loc->tables_count]) }}
                        @endunless
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    @if($loc->is_active)
                        <x-inline-confirm id="deaktivieren-{{ $loc->id }}" :action="route('admin.locations.toggle-active', $loc)"
                                          label="Deaktivieren" confirm="Deaktivieren"
                                          :question="'„'.$loc->name.'“ deaktivieren? Gäste können dort dann nicht mehr online buchen; bestehende Buchungen bleiben.'" />
                    @else
                        <form method="POST" action="{{ route('admin.locations.toggle-active', $loc) }}" data-ajax>
                            @csrf
                            <button type="submit" class="btn btn-quiet">Aktivieren</button>
                        </form>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    @if($canAdd)
        <x-drawer id="standort-neu" title="Neuer Standort" description="Name und Zeitzone genügen für den Anfang; den Rest ergänzt du jederzeit.">
            <form id="form-standort-neu" method="POST" action="{{ route('admin.locations.store') }}" data-ajax class="space-y-5">
                @csrf
                @include('admin.locations.partials.fields', ['loc' => null, 'prefix' => 'neu'])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-standort-neu" class="btn btn-primary">Standort anlegen</button>
            </x-slot:footer>
        </x-drawer>
    @endif
    @foreach($locations as $loc)
        <x-drawer id="standort-{{ $loc->id }}" :title="$loc->name" description="Gilt für Buchungsseite, Bestätigungen und Erinnerungen dieses Standorts.">
            <form id="form-standort-{{ $loc->id }}" method="POST" action="{{ route('admin.locations.update', $loc) }}" data-ajax class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.locations.partials.fields', ['loc' => $loc, 'prefix' => (string) $loc->id])
            </form>
            @if($canEditLogo)
                @include('admin.locations.partials.logo', ['loc' => $loc])
            @endif
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-standort-{{ $loc->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
```

- [ ] **Step 12: Suchbegriffe des Eintrags „Standorte“**

In `config/navigation.php`, Eintrag `locations`, `keywords` ersetzen durch:
```php
                'keywords' => ['Standort', 'Neuer Standort' => 'standort-neu', 'Adresse', 'Zeitzone', 'Telefon', 'Logo', 'Begrüßung']],
```

- [ ] **Step 13: Run – expect PASS**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='LocationManagementTest|LocationLogoTest|BrandingLogoTest|NavigationTest|SettingsMenuTest|EnvSettingTest'
vendor/bin/pint --test app/Http/Controllers/Admin/LocationController.php app/Http/Controllers/Admin/LocationLogoController.php app/Http/Controllers/Admin/SettingsController.php config routes tests/Feature/LocationManagementTest.php tests/Feature/LocationLogoTest.php
vendor/bin/phpstan analyse --memory-limit=1G app/Http/Controllers/Admin/LocationController.php app/Http/Controllers/Admin/LocationLogoController.php
```
Expected: alle PASS, Pint und PHPStan ohne Befund.

- [ ] **Step 14: Commit**

```bash
git add app/Http/Controllers/Admin app/Support/EnvSetting.php config routes/web.php resources/views/admin/locations tests/Feature/LocationManagementTest.php tests/Feature/LocationLogoTest.php tests/Unit/EnvSettingTest.php .env.example
git commit -m "feat: Standorte mit Seitenleiste, Begrüßungstext und Logo je Standort

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 6: Team & Rollen

**Files:**
- Create: `lang/de/roles.php`, `resources/views/admin/users/partials/locations.blade.php`, `tests/Feature/TeamPageTest.php`
- Modify: `app/Http/Controllers/Admin/UserManagementController.php`, `resources/views/admin/users/index.blade.php`, `config/navigation.php`

**Interfaces:**
- Consumes: `RespondsToSettingsForms`, `x-page-header`, `x-field`, `x-drawer`, `x-inline-confirm`, `x-empty-state`, `.btn-*`, `data-copy` (Task 4), `data-reset-on-error` (Task 2), `data-autosubmit` (nav.js, Teil 1), `swayy.locations.default_timezone` (Task 5).
- Produces:
  - `lang/de/roles.php`: je Rolle aus `config/permissions.php` `label` und `summary` (`__('roles.staff.label')`).
  - Drawer-IDs `einladen` (Person einladen) und `zugang-{membership id}` (Standorte einer Person); Abschnitte `#einladungen`, `#rollen`.
  - Controller antwortet auf JSON mit `message` (+ `reload: true` außer bei der Rollenänderung) oder 422 mit `errors`.

- [ ] **Step 1: Failing tests – `tests/Feature/TeamPageTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Invitation;
use App\Models\Location;
use App\Models\TenantUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class TeamPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_every_role_has_a_label_and_a_summary(): void
    {
        foreach (array_keys((array) config('permissions.roles')) as $role) {
            $this->assertNotSame("roles.{$role}.label", __("roles.{$role}.label"), $role);
            $this->assertNotSame("roles.{$role}.summary", __("roles.{$role}.summary"), $role);
        }
    }

    public function test_page_shows_role_names_drawers_and_copyable_invitation_links(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->createMember($setup['tenant'], 'staff');
        $this->actAsTenant($setup['tenant'], $setup['location']);
        $invitation = Invitation::create([
            'tenant_id' => $setup['tenant']->id,
            'email' => 'neu@example.test',
            'role' => 'host',
            'all_locations' => true,
            'invited_by' => $owner->id,
        ]);
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/users')->assertOk()->getContent();

        $this->assertStringContainsString('data-drawer-open="einladen"', $html);
        $this->assertStringContainsString('id="einladen" data-drawer hidden', $html);
        $this->assertStringContainsString('>Service</option>', $html);
        $this->assertStringContainsString('Empfang · gültig bis', $html);
        $this->assertStringContainsString('data-copy="'.route('invitation.accept', $invitation->token).'"', $html);
        $this->assertStringContainsString('data-reset-on-error', $html);
        $this->assertStringNotContainsString('>staff<', $html);

        foreach (['index', 'partials/locations'] as $view) {
            $source = (string) file_get_contents(resource_path("views/admin/users/{$view}.blade.php"));
            $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source, $view);
        }
    }

    public function test_location_access_appears_only_with_several_locations(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/users')->assertOk()->assertDontSee('data-drawer-open="zugang-', false);

        Location::factory()->create(['tenant_id' => $setup['tenant']->id, 'name' => 'Filiale Süd']);

        $this->actingAs($owner)->get('/admin/users')->assertOk()->assertSee('data-drawer-open="zugang-', false);
    }

    public function test_json_invite_creates_an_invitation_and_reloads(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => 'service@example.test',
            'role' => 'staff',
            'all_locations' => '1',
        ])->assertOk()->assertExactJson([
            'message' => 'Einladung für service@example.test angelegt. Den Link findest du unter „Offene Einladungen“ zum Kopieren.',
            'reload' => true,
        ]);

        $this->assertSame(1, Invitation::withoutGlobalScopes()->where('email', 'service@example.test')->count());
    }

    public function test_invite_without_any_location_is_rejected(): void
    {
        $setup = $this->createTenantSetup();
        Location::factory()->create(['tenant_id' => $setup['tenant']->id]);
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => 'service@example.test',
            'role' => 'staff',
            'all_locations' => '0',
        ])->assertStatus(422)
            ->assertJsonPath('errors.location_ids.0', 'Bitte mindestens einen Standort auswählen oder „Alle Standorte“ anhaken.');

        $this->assertSame(0, Invitation::withoutGlobalScopes()->count());
    }

    public function test_inviting_a_member_again_explains_where_to_change_access(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($owner)->postJson('/admin/users/invite', [
            'email' => $staff->email,
            'role' => 'host',
            'all_locations' => '1',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Diese Person gehört schon zum Team. Rolle und Standorte änderst du direkt in der Liste.');

        $this->assertSame('staff', TenantUser::where('user_id', $staff->id)->value('role'));
    }

    public function test_role_change_answers_without_reload(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $membership = TenantUser::where('user_id', $staff->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($owner)->putJson(route('admin.users.role', $membership), ['role' => 'host'])
            ->assertOk()
            ->assertExactJson(['message' => "Rolle von {$staff->name} ist jetzt „Empfang“."]);
    }

    public function test_demoting_the_last_owner_is_rejected_with_a_reason(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $membership = TenantUser::where('user_id', $owner->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($admin)->putJson(route('admin.users.role', $membership), ['role' => 'staff'])
            ->assertStatus(422)
            ->assertJsonPath('errors.role.0', 'Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du diese Rolle ändern.');

        $this->assertSame('tenant_owner', $membership->fresh()->role);
    }

    public function test_removing_the_last_owner_is_rejected_in_the_row(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $membership = TenantUser::where('user_id', $owner->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($admin)->deleteJson(route('admin.users.remove', $membership))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du diese Person entfernen.');

        $this->actingAs($admin)->deleteJson(route('admin.users.delete', $membership))
            ->assertStatus(422)
            ->assertJsonPath('message', 'Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du dieses Konto löschen.');

        $this->assertNotNull($membership->fresh());
    }

    public function test_removing_a_member_reloads_the_list(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $staff = $this->createMember($setup['tenant'], 'staff');
        $membership = TenantUser::where('user_id', $staff->id)->firstOrFail();
        $this->clearTenantContext();

        $this->actingAs($owner)->deleteJson(route('admin.users.remove', $membership))
            ->assertOk()
            ->assertExactJson(['message' => "{$staff->name} ist aus dem Team entfernt.", 'reload' => true]);
    }

    public function test_operations_manager_sees_roles_as_text(): void
    {
        $setup = $this->createTenantSetup();
        $manager = $this->createMember($setup['tenant'], 'operations_manager');
        $this->createMember($setup['tenant'], 'staff');
        $this->clearTenantContext();

        $this->actingAs($manager)->get('/admin/users')
            ->assertOk()
            ->assertDontSee('data-autosubmit', false)
            ->assertDontSee('data-inline-confirm-open', false)
            ->assertSee('Betriebsleitung');
    }
}
```

- [ ] **Step 2: Run – expect FAIL**

Run: `PAO_DISABLE=1 php artisan test --filter=TeamPageTest`
Expected: FAIL (`roles.*` fehlt, Meldungen und Antworten anders, Drawer fehlen).

- [ ] **Step 3: `lang/de/roles.php` anlegen**

```php
<?php

// Rollen im Betrieb: Bezeichnung und ein Satz, was die Rolle darf. Die Rechte
// selbst stehen in config/permissions.php; neue Rollen dort brauchen hier
// einen Eintrag (Test TeamPageTest).
return [
    'tenant_owner' => [
        'label' => 'Inhaber',
        'summary' => 'Alles, dazu Datenexport, Import und „Betrieb löschen“.',
    ],
    'tenant_admin' => [
        'label' => 'Administration',
        'summary' => 'Alle Einstellungen, Team, Abo und Zahlungen.',
    ],
    'operations_manager' => [
        'label' => 'Betriebsleitung',
        'summary' => 'Service, Buchungen, Gäste, Zeiten, Räume, Events, Vorlagen, Marketing und Standorte; lädt Personen ein.',
    ],
    'location_manager' => [
        'label' => 'Standortleitung',
        'summary' => 'Service, Buchungen, Gäste, Zeiten, Räume, Events und Berichte.',
    ],
    'host' => [
        'label' => 'Empfang',
        'summary' => 'Reservierungen, Walk-ins, Warteliste, Tische zuweisen und Gäste pflegen.',
    ],
    'staff' => [
        'label' => 'Service',
        'summary' => 'Reservierungen ansehen und anlegen, Gäste platzieren, Walk-ins und Warteliste.',
    ],
    'marketing_manager' => [
        'label' => 'Marketing',
        'summary' => 'Gästeliste mit Export, Vorlagen, Kampagnen, Events und Berichte.',
    ],
    'readonly' => [
        'label' => 'Nur lesen',
        'summary' => 'Reservierungen, Gäste und Berichte ansehen.',
    ],
];
```

- [ ] **Step 4: `app/Http/Controllers/Admin/UserManagementController.php` ersetzen**

Ganze Datei:
```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Settings\Concerns\RespondsToSettingsForms;
use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PlanLimitService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Einstellungen › Betrieb › Team & Rollen: Mitglieder, Rollen,
 * Standortfreigaben und offene Einladungen.
 */
class UserManagementController extends Controller
{
    use RespondsToSettingsForms;

    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
        private readonly PlanLimitService $limits,
    ) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();
        $locations = $tenant->locations()->orderBy('name')->get();

        return view('admin.users.index', [
            'tenant' => $tenant,
            // Die zugeteilten Standorte gleich mitladen: Die Ansicht fragte sie
            // je Mitglied UND je Standort einzeln ab - bei elf Mitgliedern und
            // zehn Standorten waren das hundert zusaetzliche Abfragen fuer eine
            // einzige Seite.
            'memberships' => $tenant->memberships()->with('user.allowedLocations:id,name')->get(),
            'invitations' => Invitation::whereNull('accepted_at')->where('expires_at', '>', now())->orderBy('expires_at')->get(),
            'roleOptions' => $this->labels($this->assignableRoles($request, $tenant)),
            'allRoles' => array_keys((array) config('permissions.roles')),
            'locations' => $locations,
            'hasSeveralLocations' => $locations->count() > 1,
            'canManageRoles' => $request->user()->canInTenant('users.roles.manage', $tenant),
            'tz' => $this->context->location()?->timezone ?? config('swayy.locations.default_timezone'),
        ]);
    }

    public function invite(Request $request)
    {
        $tenant = $this->context->tenant();

        if (! $this->limits->canAdd($tenant, 'max_users')) {
            $limit = (int) $tenant->limit('max_users');

            return $this->failed($request, 'email', trans_choice(
                'Dein Tarif umfasst einen Zugang, und der ist vergeben.|Dein Tarif umfasst :count Zugänge, und alle sind vergeben.',
                $limit,
                ['count' => $limit],
            ).' '.__('Für weitere Personen den Tarif unter „Abo & Rechnungen“ wechseln oder einen Zugang entfernen.'));
        }

        $validated = $request->validate([
            'email' => ['required', 'email:rfc'],
            'role' => ['required', 'in:'.implode(',', $this->assignableRoles($request, $tenant))],
            'all_locations' => ['nullable', 'boolean'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
        ], [
            'email.required' => __('Bitte die E-Mail-Adresse der Person eintragen.'),
            'email.email' => __('Die E-Mail-Adresse ist unvollständig. Bitte in der Form name@beispiel.de eintragen.'),
            'role.required' => __('Bitte eine Rolle wählen.'),
            'role.in' => __('Diese Rolle kannst du nicht vergeben. Bitte eine Rolle aus der Liste wählen.'),
        ]);

        $allLocations = $request->boolean('all_locations', true);
        if (! $allLocations && empty($validated['location_ids'])) {
            return $this->failed($request, 'location_ids', __('Bitte mindestens einen Standort auswählen oder „Alle Standorte“ anhaken.'));
        }

        // Existing user → direct membership, otherwise invitation token
        $user = User::where('email', strtolower($validated['email']))->first();
        if ($user !== null) {
            // Niemand aendert ueber diese Route seine eigene Freigabe. Sonst
            // reicht users.invite, um sich selbst in einen bisher gesperrten
            // Standort einzutragen - jede andere Aenderung an einer
            // Mitgliedschaft liegt hinter users.roles.manage.
            abort_if($user->id === $request->user()->id, 403);

            $membership = TenantUser::firstOrCreate(
                ['tenant_id' => $tenant->id, 'user_id' => $user->id],
                ['role' => $validated['role'], 'all_locations' => $allLocations]
            );

            // Nur fuer eine NEUE Mitgliedschaft. Eine bestehende zu erweitern
            // ist eine Aenderung an fremden Rechten und gehoert nicht zum
            // Einladen - firstOrCreate laesst die Rolle aus demselben Grund
            // unangetastet.
            if ($membership->wasRecentlyCreated && ! $allLocations) {
                $locationIds = collect($validated['location_ids'] ?? [])
                    ->filter(fn ($id) => $tenant->locations()->where('id', $id)->exists());
                foreach ($locationIds as $locationId) {
                    DB::table('location_user')->insertOrIgnore([
                        'location_id' => $locationId,
                        'user_id' => $user->id,
                        'tenant_id' => $tenant->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }

            if (! $membership->wasRecentlyCreated) {
                return $this->failed($request, 'email', __('Diese Person gehört schon zum Team. Rolle und Standorte änderst du direkt in der Liste.'));
            }

            $this->audit->log('user.added', $user, null, ['role' => $validated['role']]);

            return $this->saved($request, __(':name gehört jetzt zum Team.', ['name' => $user->name]), reload: true);
        }

        $invitation = Invitation::create([
            'tenant_id' => $tenant->id,
            'email' => strtolower($validated['email']),
            'role' => $validated['role'],
            'all_locations' => $allLocations,
            'location_ids' => $validated['location_ids'] ?? null,
            'invited_by' => $request->user()->id,
        ]);

        $this->audit->log('user.invited', $invitation, null, ['email' => $invitation->email, 'role' => $invitation->role]);

        return $this->saved($request, __('Einladung für :email angelegt. Den Link findest du unter „Offene Einladungen“ zum Kopieren.', [
            'email' => $invitation->email,
        ]), reload: true);
    }

    /**
     * Welche Rollen darf dieser Benutzer vergeben?
     *
     * Die Inhaberrolle schliesst Abrechnung und "Betrieb loeschen" ein. Ohne
     * diese Grenze koennte jemand mit users.invite (z. B. die Betriebsleitung,
     * die users.roles.manage ausdruecklich NICHT hat) sich ueber eine zweite
     * Mailadresse selbst zum Inhaber machen.
     *
     * @return array<int, string>
     */
    private function assignableRoles(Request $request, Tenant $tenant): array
    {
        $alle = array_keys(config('permissions.roles'));
        $user = $request->user();

        if ($user?->isSaasAdmin() || $user?->membershipFor($tenant)?->role === 'tenant_owner') {
            return $alle;
        }

        return array_values(array_diff($alle, ['tenant_owner']));
    }

    /**
     * @param  array<int, string>  $roles
     * @return array<string, string> Rolle => Bezeichnung
     */
    private function labels(array $roles): array
    {
        return collect($roles)->mapWithKeys(fn (string $role) => [$role => __("roles.{$role}.label")])->all();
    }

    public function updateRole(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);

        $validated = $request->validate([
            'role' => ['required', 'in:'.implode(',', $this->assignableRoles($request, $tenant))],
        ], [
            'role.in' => __('Diese Rolle kannst du nicht vergeben. Bitte eine Rolle aus der Liste wählen.'),
        ]);

        // The last owner cannot be demoted
        if ($membership->role === 'tenant_owner' && $validated['role'] !== 'tenant_owner') {
            $owners = $tenant->memberships()->where('role', 'tenant_owner')->count();
            if ($owners <= 1) {
                return $this->failed($request, 'role', __('Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du diese Rolle ändern.'));
            }
        }

        $old = $membership->role;
        $membership->update(['role' => $validated['role']]);

        $this->audit->log('user.role_changed', $membership, ['role' => $old], ['role' => $validated['role']]);

        return $this->saved($request, __('Rolle von :name ist jetzt „:role“.', [
            'name' => $membership->user?->name,
            'role' => __("roles.{$validated['role']}.label"),
        ]));
    }

    /**
     * Standortfreigaben einer bestehenden Mitgliedschaft aendern.
     *
     * Frueher lief das ueber "Benutzer einladen" mit - und weil dort nur
     * `users.invite` verlangt wird, konnte sich damit jeder selbst einen
     * gesperrten Standort freischalten. Das Erweitern ist eine Aenderung an
     * fremden Rechten und gehoert darum hierher, hinter dasselbe Recht wie die
     * Rolle. Ohne diesen Weg gaebe es gar keinen mehr: Ein Mitglied, das
     * zusaetzlich an einem zweiten Standort arbeitet, muesste sonst entfernt
     * und neu eingeladen werden.
     */
    public function updateLocations(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        // Auch hier nicht die eigene Freigabe.
        abort_if($membership->user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'all_locations' => ['nullable', 'boolean'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
        ]);

        $alle = $request->boolean('all_locations');
        $locationIds = $alle
            ? collect()
            : collect($validated['location_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $tenant->locations()->where('id', $id)->exists())
                ->values();

        // Eine Mitgliedschaft ohne "alle Standorte" und ohne einen einzigen
        // freigegebenen ist der Zustand, in dem gar kein Standort aufgeloest
        // werden kann - genau daran fiel frueher die Standortschranke auf.
        if (! $alle && $locationIds->isEmpty()) {
            return $this->failed($request, 'location_ids', __('Bitte mindestens einen Standort auswählen oder „Alle Standorte“ anhaken.'));
        }

        $vorher = [
            'all_locations' => (bool) $membership->all_locations,
            'location_ids' => DB::table('location_user')
                ->where('user_id', $membership->user_id)
                ->where('tenant_id', $tenant->id)
                ->pluck('location_id')->all(),
        ];

        DB::transaction(function () use ($membership, $tenant, $alle, $locationIds) {
            $membership->update(['all_locations' => $alle]);

            DB::table('location_user')
                ->where('user_id', $membership->user_id)
                ->where('tenant_id', $tenant->id)
                ->delete();

            foreach ($locationIds as $locationId) {
                DB::table('location_user')->insert([
                    'location_id' => $locationId,
                    'user_id' => $membership->user_id,
                    'tenant_id' => $tenant->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->audit->log('user.locations_changed', $membership, $vorher, [
            'all_locations' => $alle,
            'location_ids' => $locationIds->all(),
        ]);

        return $this->saved($request, __('Standorte von :name gespeichert.', ['name' => $membership->user?->name]), reload: true);
    }

    public function remove(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        abort_if($membership->user_id === $request->user()->id, 422, __('Du kannst dich nicht selbst entfernen. Bitte eine andere Person mit Rechten für das Team fragen.'));

        if ($membership->role === 'tenant_owner'
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1) {
            return $this->failed($request, 'role', __('Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du diese Person entfernen.'));
        }

        $name = $membership->user?->name;
        $this->audit->log('user.removed', $membership, ['user_id' => $membership->user_id, 'role' => $membership->role]);
        $membership->delete();

        return $this->saved($request, __(':name ist aus dem Team entfernt.', ['name' => $name]), reload: true);
    }

    public function deleteUser(Request $request, TenantUser $membership)
    {
        $tenant = $this->context->tenant();
        abort_if($membership->tenant_id !== $tenant->id, 404);
        abort_if($membership->user_id === $request->user()->id, 422, __('Dein eigenes Konto löschst du unter „Mein Konto“.'));

        if ($membership->role === 'tenant_owner'
            && $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1) {
            return $this->failed($request, 'delete', __('Der Betrieb braucht mindestens einen Inhaber. Mach zuerst eine andere Person zum Inhaber, dann kannst du dieses Konto löschen.'));
        }

        $user = User::findOrFail($membership->user_id);
        $otherTenants = $user->tenants()->where('tenants.id', '!=', $tenant->id)->exists();

        if ($otherTenants) {
            $this->audit->log('user.removed', $membership, ['user_id' => $user->id]);
            $membership->delete();

            return $this->saved($request, __(':name ist aus dem Team entfernt. Das Konto bleibt, weil die Person zu einem weiteren Betrieb gehört.', ['name' => $user->name]), reload: true);
        }

        $this->audit->log('user.deleted', $user, ['email' => $user->email, 'name' => $user->name]);
        $user->delete();

        return $this->saved($request, __('Das Konto von :name ist gelöscht.', ['name' => $user->name]), reload: true);
    }
}
```

`failed()` liefert bei JSON `message` und `errors`; die Tests zu Entfernen und Löschen prüfen `message`, weil die Rückfrage in der Zeile kein Feld `role` oder `delete` hat.

- [ ] **Step 5: `resources/views/admin/users/partials/locations.blade.php` anlegen**

```blade
{{-- Standortfreigabe: „Alle Standorte“ oder einzelne Standorte. Das versteckte Feld sendet 0, wenn der Haken fehlt. --}}
<fieldset class="space-y-3">
    <legend class="text-sm font-semibold text-stone-800">Standorte</legend>
    <input type="hidden" name="all_locations" value="0">
    <label class="flex items-start gap-3">
        <input type="checkbox" name="all_locations" value="1" @checked($allLocations) class="mt-1">
        <span>
            <span class="block font-semibold">Alle Standorte</span>
            <span class="block text-[0.8125rem] text-stone-600">Gilt auch für Standorte, die später dazukommen.</span>
        </span>
    </label>
    <div class="space-y-2 pl-7">
        <p class="text-[0.8125rem] text-stone-600">Ohne Haken bei „Alle Standorte“ gelten nur diese:</p>
        @foreach($locations as $loc)
            <label class="flex items-center gap-3">
                <input type="checkbox" name="location_ids[]" value="{{ $loc->id }}" @checked(in_array($loc->id, $selected, true))>
                <span class="[overflow-wrap:anywhere]">{{ $loc->name }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
```

- [ ] **Step 6: `resources/views/admin/users/index.blade.php` ersetzen**

```blade
@extends('layouts.admin')
@section('title', 'Team & Rollen')

@section('content')
<x-page-header title="Team & Rollen" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Wer sich anmelden darf und was die Person tun kann. Jede Person bekommt einen eigenen Zugang; so steht im Protokoll, wer etwas geändert hat.">
    <x-slot:actions>
        <button type="button" data-drawer-open="einladen" class="btn btn-primary">Person einladen</button>
    </x-slot:actions>
</x-page-header>

<section aria-labelledby="team-title" class="space-y-3">
    <h2 id="team-title" class="text-base font-semibold">Team</h2>
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($memberships as $m)
            @php
                $isSelf = $m->user_id === auth()->id();
                $canEdit = $canManageRoles && ! $isSelf;
                $access = $m->all_locations
                    ? 'Alle Standorte'
                    : ($m->user?->allowedLocations->pluck('name')->implode(', ') ?: 'Kein Standort freigegeben');
            @endphp
            <li class="grid gap-3 px-4 py-3 md:grid-cols-[minmax(0,1fr)_13rem_auto] md:items-center">
                <div class="min-w-0">
                    <p class="font-semibold [overflow-wrap:anywhere]">
                        {{ $m->user?->name }}
                        @if($isSelf)
                            <span class="text-[0.8125rem] font-normal text-stone-600">(du)</span>
                        @endif
                    </p>
                    <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ $m->user?->email }}</p>
                    @if($hasSeveralLocations)
                        <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ $access }}</p>
                    @endif
                </div>
                <div>
                    @if($canEdit && array_key_exists($m->role, $roleOptions))
                        <form method="POST" action="{{ route('admin.users.role', $m) }}" data-ajax data-reset-on-error class="space-y-2">
                            @csrf
                            @method('PUT')
                            <label for="role-{{ $m->id }}" class="sr-only">Rolle von {{ $m->user?->name }}</label>
                            <select id="role-{{ $m->id }}" name="role" data-autosubmit class="w-full">
                                @foreach($roleOptions as $role => $label)
                                    <option value="{{ $role }}" @selected($m->role === $role)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </form>
                    @else
                        <p class="text-sm">{{ __('roles.'.$m->role.'.label') }}</p>
                    @endif
                </div>
                <div class="flex flex-wrap items-start gap-1 md:justify-end">
                    @if($canEdit && $hasSeveralLocations)
                        <button type="button" data-drawer-open="zugang-{{ $m->id }}" class="btn btn-quiet">Standorte</button>
                    @endif
                    @if($canEdit)
                        <x-inline-confirm id="entfernen-{{ $m->id }}" :action="route('admin.users.remove', $m)" method="DELETE"
                                          label="Entfernen" confirm="Entfernen"
                                          :question="'„'.$m->user?->name.'“ aus dem Betrieb entfernen? Die Person kann sich hier dann nicht mehr anmelden; ihr Konto bleibt bestehen.'" />
                        <x-inline-confirm id="loeschen-{{ $m->id }}" :action="route('admin.users.delete', $m)" method="DELETE"
                                          label="Konto löschen" confirm="Konto löschen"
                                          :question="'Konto von „'.$m->user?->name.'“ löschen? Gehört die Person zu keinem weiteren Betrieb, ist ihr Zugang danach endgültig weg.'" />
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
</section>

<section id="einladungen" aria-labelledby="einladungen-title" class="mt-8 scroll-mt-6 space-y-3">
    <h2 id="einladungen-title" class="text-base font-semibold">Offene Einladungen</h2>
    @if($invitations->isEmpty())
        <x-empty-state title="Keine offenen Einladungen">
            Wen du einlädst, erscheint hier mit dem Link zum Weitergeben, bis die Person ihn öffnet.
            <x-slot:action>
                <button type="button" data-drawer-open="einladen" class="btn btn-secondary">Person einladen</button>
            </x-slot:action>
        </x-empty-state>
    @else
        <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
            @foreach($invitations as $inv)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold [overflow-wrap:anywhere]">{{ $inv->email }}</p>
                        <p class="text-[0.8125rem] text-stone-600">{{ __('roles.'.$inv->role.'.label') }} · gültig bis {{ $inv->expires_at->copy()->setTimezone($tz)->format('d.m.Y') }}</p>
                    </div>
                    <button type="button" class="btn btn-secondary"
                            data-copy="{{ route('invitation.accept', $inv->token) }}"
                            data-copy-message="Link für {{ $inv->email }} kopiert.">Link kopieren</button>
                </li>
            @endforeach
        </ul>
    @endif
</section>

<section id="rollen" aria-labelledby="rollen-title" class="mt-8 scroll-mt-6 space-y-3">
    <h2 id="rollen-title" class="text-base font-semibold">Was die Rollen dürfen</h2>
    <dl class="grid gap-x-6 gap-y-3 rounded-xl border border-stone-200 bg-white px-5 py-4 text-sm sm:grid-cols-[12rem_minmax(0,1fr)]">
        @foreach($allRoles as $role)
            <dt class="font-semibold">{{ __('roles.'.$role.'.label') }}</dt>
            <dd class="text-stone-600">{{ __('roles.'.$role.'.summary') }}</dd>
        @endforeach
    </dl>
</section>

@push('drawers')
    <x-drawer id="einladen" title="Person einladen"
              description="Hat die Person schon ein Konto bei Swayy, gehört sie sofort zum Team. Sonst entsteht ein Link, den du ihr schickst.">
        <form id="form-einladen" method="POST" action="{{ route('admin.users.invite') }}" data-ajax class="space-y-5">
            @csrf
            <x-field name="email" label="E-Mail-Adresse" type="email" id="invite-email" required maxlength="200" />
            <fieldset class="space-y-2">
                <legend class="mb-1 text-sm font-semibold text-stone-800">Rolle<span class="text-red-700" aria-hidden="true"> *</span></legend>
                @foreach($roleOptions as $role => $label)
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-3 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand)">
                        <input type="radio" name="role" value="{{ $role }}" required class="mt-1">
                        <span class="min-w-0">
                            <span class="block font-semibold">{{ $label }}</span>
                            <span class="block text-[0.8125rem] text-stone-600">{{ __('roles.'.$role.'.summary') }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
            @if($hasSeveralLocations)
                @include('admin.users.partials.locations', ['allLocations' => true, 'selected' => []])
            @else
                <input type="hidden" name="all_locations" value="1">
            @endif
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-einladen" class="btn btn-primary">Einladen</button>
        </x-slot:footer>
    </x-drawer>

    @if($canManageRoles && $hasSeveralLocations)
        @foreach($memberships as $m)
            @continue($m->user_id === auth()->id())
            <x-drawer id="zugang-{{ $m->id }}" :title="'Standorte für '.$m->user?->name">
                <form id="form-zugang-{{ $m->id }}" method="POST" action="{{ route('admin.users.locations', $m) }}" data-ajax class="space-y-5">
                    @csrf
                    @method('PUT')
                    @include('admin.users.partials.locations', [
                        'allLocations' => (bool) $m->all_locations,
                        'selected' => $m->user?->allowedLocations->pluck('id')->all() ?? [],
                    ])
                </form>
                <x-slot:footer>
                    <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                    <button type="submit" form="form-zugang-{{ $m->id }}" class="btn btn-primary">Speichern</button>
                </x-slot:footer>
            </x-drawer>
        @endforeach
    @endif
@endpush
@endsection
```

- [ ] **Step 7: Suchbegriffe des Eintrags „Team & Rollen“**

In `config/navigation.php`, Eintrag `team`, `keywords` ersetzen durch:
```php
                'keywords' => ['Benutzer', 'Person einladen' => 'einladen', 'Einladungslink' => 'einladungen', 'Rollen' => 'rollen', 'Zugang', 'Servicekraft']],
```

- [ ] **Step 8: Run – expect PASS**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='TeamPageTest|UserLocationAccessTest|AccessBoundariesTest|AuditFixes20260807Test|PermissionsTest|NavigationTest|SettingsMenuTest'
vendor/bin/pint --test app/Http/Controllers/Admin/UserManagementController.php lang/de/roles.php config/navigation.php tests/Feature/TeamPageTest.php
vendor/bin/phpstan analyse --memory-limit=1G app/Http/Controllers/Admin/UserManagementController.php
```
Expected: alle PASS, Pint und PHPStan ohne Befund. Schlägt ein bestehender Test an einer geänderten Meldung oder an `assertSessionHas('success')` beim erneuten Einladen fehl, gilt die neue Meldung bzw. `assertSessionHasErrors('email')`; den Test entsprechend anpassen und das im Bericht nennen.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/Admin/UserManagementController.php lang/de/roles.php resources/views/admin/users config/navigation.php tests/Feature/TeamPageTest.php
git commit -m "feat: Team & Rollen mit Rollennamen, Einladung und Standorten in der Seitenleiste, Rückfrage in der Zeile

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 7: Abo & Rechnungen

**Files:**
- Modify: `resources/views/admin/billing/index.blade.php`
- Test: `tests/Feature/DirectDebitTest.php`

**Interfaces:**
- Consumes: `x-page-header`, `x-settings.section` (Teil 1), `x-inline-confirm`, `x-status-icon`, `.btn-*` (Task 4). Controller `DirectDebitController` bleibt unverändert (`plan`, `profile`, `configured`; Kündigen per klassischem POST mit Weiterleitung).
- Produces: Abschnitte `#tarif` und `#lastschrift`.

- [ ] **Step 1: Failing test – `tests/Feature/DirectDebitTest.php`**

Neue Methode am Ende der Klasse (Imports `Plan` bzw. vorhandene Helfer der Klasse nutzen; die Klasse verwendet `CreatesTenants`):
```php
    public function test_billing_page_uses_the_settings_layout_without_inline_handlers(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/billing')
            ->assertOk()
            ->assertSee('Abo & Rechnungen')
            ->assertSee('id="tarif"', false)
            ->assertSee('id="lastschrift"', false);

        $source = (string) file_get_contents(resource_path('views/admin/billing/index.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source);
    }
```

- [ ] **Step 2: Run – expect FAIL**

Run: `PAO_DISABLE=1 php artisan test --filter=test_billing_page_uses_the_settings_layout`
Expected: FAIL (`id="tarif"` fehlt).

- [ ] **Step 3: `resources/views/admin/billing/index.blade.php` ersetzen**

```blade
@extends('layouts.admin')
@section('title', 'Abo & Rechnungen')

@section('content')
<x-page-header title="Abo & Rechnungen" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Dein Tarif und wie die monatliche Gebühr bezahlt wird." />

<x-settings.section id="tarif" title="Tarif">
    <x-slot:description>
        <p>Der Tarif legt fest, wie viele Standorte, Zugänge, Tische und Events möglich sind. Ist eine Grenze erreicht, sagt Swayy das beim Anlegen.</p>
    </x-slot:description>
    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[8rem_minmax(0,1fr)]">
        <dt class="font-semibold text-stone-600">Tarif</dt>
        <dd>{{ $plan?->name ?? 'Noch kein Tarif zugeordnet' }}</dd>
        @if($plan && (int) $plan->price_monthly_minor > 0)
            <dt class="font-semibold text-stone-600">Preis</dt>
            <dd>{{ number_format($plan->price_monthly_minor / 100, 2, ',', '.') }} {{ strtoupper($plan->currency ?? 'EUR') }} im Monat</dd>
        @endif
    </dl>
</x-settings.section>

<x-settings.section id="lastschrift" title="SEPA-Lastschrift">
    <x-slot:description>
        <p>Mit einem Mandat zieht Swayy die monatliche Gebühr von deinem Konto ein. Kündigen kannst du es jederzeit hier.</p>
    </x-slot:description>
    @if(! $configured)
        <p class="text-sm text-stone-600">Lastschrift ist gerade nicht verfügbar. Bitte später erneut versuchen oder den Support fragen.</p>
    @elseif($profile && $profile->hasActiveDirectDebit())
        <p class="flex flex-wrap items-center gap-2 text-sm">
            <x-status-icon state="ok" label="Aktiv" />
            <span class="font-semibold">Aktiv</span>
            <span class="text-stone-600">· Abbuchung monatlich per SEPA-Lastschrift</span>
        </p>
        <x-inline-confirm id="lastschrift-kuendigen" :action="route('admin.billing.directdebit.cancel')" :ajax="false"
                          :open="$errors->has('billing')"
                          label="Lastschrift kündigen" confirm="Lastschrift kündigen"
                          question="Lastschrift kündigen? Danach zieht Swayy keine Gebühren mehr von deinem Konto ein." />
    @elseif($plan && (int) $plan->price_monthly_minor > 0)
        <p class="text-sm text-stone-600">Richte ein SEPA-Lastschriftmandat ein, sicher über GoCardless. Zur Bestätigung leitet Swayy dich dorthin weiter.</p>
        <a href="{{ route('admin.billing.directdebit.setup') }}" class="btn btn-primary">Per Lastschrift einrichten</a>
    @else
        <p class="text-sm text-stone-600">Dein Tarif kostet nichts; eine Lastschrift brauchst du nicht.</p>
    @endif
</x-settings.section>
@endsection
```

Die Meldungen „Lastschrift gekündigt.“ und Fehler unter `billing` zeigt das Layout oben an (Erfolg und Fehlerliste); die frühere Doppelanzeige in der Karte entfällt.

- [ ] **Step 4: Run – expect PASS**

Run: `PAO_DISABLE=1 php artisan test --filter='DirectDebitTest|ProvisioningApiTest'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add resources/views/admin/billing/index.blade.php tests/Feature/DirectDebitTest.php
git commit -m "feat: Abo & Rechnungen im Seitenmuster der Einstellungen

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 8: Protokoll mit Bereich und Filter je Eintrag

**Files:**
- Modify: `app/Http/Controllers/Admin/AuditLogController.php`, `resources/views/admin/audit/index.blade.php`, `app/Http/Controllers/Admin/ReservationBookController.php` (nur `show`), `resources/views/admin/reservations/show.blade.php`, `config/swayy.php`, `.env.example`
- Test: `tests/Feature/AuditLogFilterTest.php` (neu), `tests/Feature/AuditLogDiffTest.php` (muss grün bleiben)

**Interfaces:**
- Consumes: `AuditLog::SUBJECTS` (Kürzel ⇒ Bezeichnung), `swayy.locations.default_timezone` (Task 5), `x-active-filters` (Bestand), `x-empty-state`, `.btn-*` (Task 4).
- Produces:
  - Abfrageparameter `area` (Kürzel aus `AuditLog::SUBJECTS`), `entity` + `entity_id` (Kürzel aus `config('swayy.audit.entities')`), weiterhin `action`, `user_id`, `from`, `until`.
  - Einstellungen `swayy.audit.per_page` (`SWAYY_AUDIT_PER_PAGE`, Vorgabe 100, 10–500) und `swayy.audit.entities` (Kürzel ⇒ Modellklasse).
  - Reservierungsdetail: Link „Alle Änderungen im Protokoll“ für Rollen mit `audit.view`.

- [ ] **Step 1: Failing tests – `tests/Feature/AuditLogFilterTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ReservationStatus;
use App\Models\AuditLog;
use App\Models\Reservation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class AuditLogFilterTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    private function log(int $tenantId, string $action, ?string $entityType = null, ?int $entityId = null): void
    {
        AuditLog::create([
            'tenant_id' => $tenantId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'created_at' => now(),
        ]);
    }

    public function test_the_entity_filter_shows_only_one_reservation(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.status_changed', Reservation::class, 41);
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 42);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?entity=reservation&entity_id=42')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('reservation.cancelled'))
            ->assertDontSee(AuditLog::labelFor('reservation.status_changed'))
            ->assertSee('Reservierung Nr. 42');
    }

    public function test_an_unknown_entity_is_ignored(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'reservation.cancelled', Reservation::class, 42);
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?entity=App%5CModels%5CUser&entity_id=42')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('reservation.cancelled'));
    }

    public function test_the_area_filter_uses_the_action_prefix(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->log($setup['tenant']->id, 'table.deleted');
        $this->log($setup['tenant']->id, 'table_block.created');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit?area=table')
            ->assertOk()
            ->assertSee(AuditLog::labelFor('table.deleted'))
            ->assertDontSee(AuditLog::labelFor('table_block.created'))
            ->assertSee('<option value="table" selected', false);
    }

    public function test_page_size_comes_from_the_settings(): void
    {
        config(['swayy.audit.per_page' => 10]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        foreach (range(1, 12) as $i) {
            $this->log($setup['tenant']->id, 'guest.updated', null, $i);
        }
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get('/admin/audit')->assertOk()->getContent();

        $this->assertSame(10, substr_count($html, 'Gast geändert'));
    }

    public function test_defaults_are_defined_in_one_place(): void
    {
        $this->assertSame(100, config('swayy.audit.per_page'));
        $this->assertSame(Reservation::class, config('swayy.audit.entities.reservation'));
    }

    public function test_without_entries_the_page_says_what_will_appear(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get('/admin/audit')
            ->assertOk()
            ->assertSee('Noch keine Einträge');

        $this->actingAs($owner)->get('/admin/audit?area=guest')
            ->assertOk()
            ->assertSee('Keine passenden Einträge')
            ->assertSee('Alle Einträge zeigen');
    }

    public function test_reservation_detail_links_to_its_history_for_roles_with_audit_access(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $host = $this->createMember($setup['tenant'], 'host');
        $start = CarbonImmutable::now($setup['location']->timezone)->addDays(2)->setTime(19, 0);
        $reservation = Reservation::create([
            'tenant_id' => $setup['tenant']->id,
            'location_id' => $setup['location']->id,
            'party_size' => 2,
            'reservation_date' => $start->toDateString(),
            'start_at' => $start->utc(),
            'end_at' => $start->addHours(2)->utc(),
            'timezone' => $setup['location']->timezone,
            'status' => ReservationStatus::Confirmed,
            'source' => 'staff',
            'guest_name_snapshot' => 'Testgast',
        ]);
        $this->clearTenantContext();
        $link = e(route('admin.audit.index', ['entity' => 'reservation', 'entity_id' => $reservation->id]));

        $this->actingAs($owner)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertSee($link, false)
            ->assertSee('Alle Änderungen im Protokoll');

        $this->actingAs($host)->get('/admin/reservations/'.$reservation->id)
            ->assertOk()
            ->assertDontSee('Alle Änderungen im Protokoll');
    }
}
```

- [ ] **Step 2: Run – expect FAIL**

Run: `PAO_DISABLE=1 php artisan test --filter=AuditLogFilterTest`
Expected: FAIL (Filter `entity`/`area` wirken nicht, Einstellungen fehlen, Link fehlt).

- [ ] **Step 3: Einstellungen in `config/swayy.php`**

1. Imports ergänzen (Pint sortiert): `use App\Models\Guest;`, `use App\Models\Location;`, `use App\Models\Reservation;`.
2. Vor dem Abschnitt `locations` (Task 5) einfügen:
```php
    /*
    |--------------------------------------------------------------------------
    | Protokoll
    |--------------------------------------------------------------------------
    |
    | per_page: Einträge je Seite im Protokoll (10–500).
    | entities: Kürzel in der Adresse (?entity=…&entity_id=…) und das Modell,
    | dessen Änderungen der Filter „nur dieser Eintrag“ zeigt. Die
    | Reservierungsdetails verlinken mit dem Kürzel „reservation“.
    |
    */
    'audit' => [
        'per_page' => EnvSetting::integer('SWAYY_AUDIT_PER_PAGE', default: 100, min: 10, max: 500),
        'entities' => [
            'reservation' => Reservation::class,
            'guest' => Guest::class,
            'location' => Location::class,
        ],
    ],

```
3. `.env.example`, nach den Einträgen aus Task 5:
```
# Einträge je Seite im Protokoll (10–500)
# SWAYY_AUDIT_PER_PAGE=100
```

- [ ] **Step 4: `app/Http/Controllers/Admin/AuditLogController.php` ersetzen**

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Einstellungen › Betrieb › Protokoll: wer wann was geändert hat. Filter nach
 * Bereich (Anfang des Aktionsnamens, AuditLog::SUBJECTS), Zeitraum und einem
 * einzelnen Eintrag (entity + entity_id, Kürzel aus config/swayy.php,
 * audit.entities).
 */
class AuditLogController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);

        $area = (string) $request->query('area', '');
        $area = array_key_exists($area, AuditLog::SUBJECTS) ? $area : '';
        $entity = (string) $request->query('entity', '');
        $entityClass = ((array) config('swayy.audit.entities'))[$entity] ?? null;
        $entityId = (int) $request->query('entity_id', 0);
        $byEntity = $entityClass !== null && $entityId > 0;

        $logs = AuditLog::query()
            ->where('tenant_id', $tenant->id) // explicit: AuditLog has no global scope
            ->when($area !== '', fn ($q) => $q->where('action', 'like', $area.'.%'))
            ->when($request->input('action'), fn ($q, $a) => $q->where('action', 'like', $a.'%'))
            ->when($byEntity, fn ($q) => $q->where('entity_type', $entityClass)->where('entity_id', $entityId))
            ->when($request->input('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', $d))
            ->when($request->input('until'), fn ($q, $d) => $q->where('created_at', '<=', $d.' 23:59:59'))
            ->with('user')
            ->orderByDesc('created_at')
            ->paginate((int) config('swayy.audit.per_page'))
            ->withQueryString();

        // Timestamps are stored in UTC; display them in the location's local
        // timezone so the times match the wall clock.
        $tz = $this->context->location()?->timezone ?? config('swayy.locations.default_timezone');

        return view('admin.audit.index', [
            'logs' => $logs,
            'tz' => $tz,
            'areas' => collect(AuditLog::SUBJECTS)->sortBy(fn (string $label) => Str::ascii($label))->all(),
            'area' => $area,
            'entityLabel' => $byEntity ? (AuditLog::SUBJECTS[$entity] ?? $entity).' Nr. '.$entityId : null,
            'filtered' => $area !== '' || $byEntity || $request->filled('action') || $request->filled('from') || $request->filled('until'),
        ]);
    }
}
```

- [ ] **Step 5: `resources/views/admin/audit/index.blade.php` ersetzen**

```blade
@extends('layouts.admin')
@section('title', 'Protokoll')

@section('content')
<x-page-header title="Protokoll" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Wer wann was geändert hat, mit altem und neuem Wert. Einträge lassen sich nachträglich nicht ändern." />

<x-active-filters :reset="route('admin.audit.index')" :filters="[
    'Bereich' => $area !== '' ? $areas[$area] : null,
    'Eintrag' => $entityLabel,
    'Suche' => request('action'),
    'Von' => request('from'),
    'Bis' => request('until'),
]" />

<form method="GET" class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-stone-200 bg-white p-4 text-sm">
    @if($entityLabel)
        <input type="hidden" name="entity" value="{{ request('entity') }}">
        <input type="hidden" name="entity_id" value="{{ request('entity_id') }}">
    @endif
    <div class="space-y-1.5">
        <label for="audit-area" class="block font-semibold text-stone-800">Bereich</label>
        <select id="audit-area" name="area" class="min-w-48">
            <option value="">Alle Bereiche</option>
            @foreach($areas as $key => $label)
                <option value="{{ $key }}" @selected($area === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="space-y-1.5">
        <label for="audit-from" class="block font-semibold text-stone-800">Von</label>
        <input id="audit-from" type="date" name="from" value="{{ request('from') }}">
    </div>
    <div class="space-y-1.5">
        <label for="audit-until" class="block font-semibold text-stone-800">Bis</label>
        <input id="audit-until" type="date" name="until" value="{{ request('until') }}">
    </div>
    <button type="submit" class="btn btn-primary">Filtern</button>
</form>

<div class="space-y-2.5">
    @forelse($logs as $log)
        @php($changes = $log->fieldChanges())
        <article class="rounded-xl border border-stone-200 bg-white p-4">
            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                <div class="flex flex-wrap items-baseline gap-x-2">
                    <h2 class="font-semibold text-stone-900">{{ $log->actionLabel() }}</h2>
                    @if($log->entity_id)
                        <span class="text-sm text-stone-600">Nr. {{ $log->entity_id }}</span>
                    @endif
                </div>
                <p class="text-[0.8125rem] text-stone-600">
                    {{ $log->user?->name ?? 'System' }}
                    @if($log->impersonator_id)
                        <span class="rounded bg-amber-100 px-1.5 py-0.5 font-semibold text-amber-800">Support</span>
                    @endif
                    · {{ $log->created_at->copy()->setTimezone($tz)->format('d.m.Y · H:i') }} Uhr
                </p>
            </div>

            @if(! empty($changes))
                <div class="mt-3 overflow-x-auto rounded-lg border border-stone-200">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="bg-stone-50 text-left text-[0.75rem] font-semibold text-stone-600">
                                <th scope="col" class="px-3 py-1.5">Feld</th>
                                <th scope="col" class="px-3 py-1.5">Vorher</th>
                                <th scope="col" class="w-6 px-1 py-1.5"><span class="sr-only">wird zu</span></th>
                                <th scope="col" class="px-3 py-1.5">Nachher</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach($changes as $c)
                                <tr>
                                    <td class="px-3 py-1.5 font-medium text-stone-700">{{ $c['label'] }}</td>
                                    <td class="px-3 py-1.5">
                                        @if($c['from'] !== null)
                                            <span class="rounded bg-stone-100 px-1.5 py-0.5 text-stone-700">{{ $c['from'] }}</span>
                                        @else
                                            <span class="text-[0.75rem] italic text-stone-600">leer</span>
                                        @endif
                                    </td>
                                    <td class="px-1 py-1.5 text-center text-stone-500" aria-hidden="true">→</td>
                                    <td class="px-3 py-1.5">
                                        @if($c['to'] !== null)
                                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-800">{{ $c['to'] }}</span>
                                        @else
                                            <span class="text-[0.75rem] font-semibold text-red-700">gelöscht</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </article>
    @empty
        @if($filtered)
            <x-empty-state title="Keine passenden Einträge">
                Zu diesen Filtern gibt es im Protokoll nichts.
                <x-slot:action>
                    <a href="{{ route('admin.audit.index') }}" class="btn btn-secondary">Alle Einträge zeigen</a>
                </x-slot:action>
            </x-empty-state>
        @else
            <x-empty-state title="Noch keine Einträge">
                Sobald jemand im Team etwas anlegt, ändert oder löscht, steht es hier.
            </x-empty-state>
        @endif
    @endforelse
</div>

<div class="mt-4">{{ $logs->links() }}</div>
@endsection
```

- [ ] **Step 6: Link im Reservierungsdetail**

In `app/Http/Controllers/Admin/ReservationBookController.php`, Methode `show`, im Array an `view(...)` nach `'canEditReservation' => …,` ergänzen:
```php
            'canSeeAudit' => request()->user()->canInTenant('audit.view', $this->context->tenant()),
```
In `resources/views/admin/reservations/show.blade.php` im Abschnitt `{{-- History --}}` die Zeile
```blade
            <h2 class="mb-3 font-bold">Verlauf<span class="tip" tabindex="0" data-tip="Lückenlose Historie dieser Reservierung: wer wann welchen Schritt gemacht hat. Hilft, wenn im Nachhinein unklar ist, wer etwas geändert oder storniert hat.">?</span></h2>
```
ersetzen durch
```blade
            <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="font-bold">Verlauf<span class="tip" tabindex="0" data-tip="Lückenlose Historie dieser Reservierung: wer wann welchen Schritt gemacht hat. Hilft, wenn im Nachhinein unklar ist, wer etwas geändert oder storniert hat.">?</span></h2>
                @if($canSeeAudit)
                    <a href="{{ route('admin.audit.index', ['entity' => 'reservation', 'entity_id' => $reservation->id]) }}"
                       class="text-sm font-semibold underline underline-offset-2">Alle Änderungen im Protokoll</a>
                @endif
            </div>
```

- [ ] **Step 7: Run – expect PASS**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='AuditLogFilterTest|AuditLogDiffTest|PermissionsTest|AccessBoundariesTest'
vendor/bin/pint --test app/Http/Controllers/Admin/AuditLogController.php app/Http/Controllers/Admin/ReservationBookController.php config/swayy.php tests/Feature/AuditLogFilterTest.php
vendor/bin/phpstan analyse --memory-limit=1G app/Http/Controllers/Admin/AuditLogController.php
```
Expected: PASS, ohne Befund.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Admin/AuditLogController.php app/Http/Controllers/Admin/ReservationBookController.php resources/views/admin/audit resources/views/admin/reservations/show.blade.php config/swayy.php .env.example tests/Feature/AuditLogFilterTest.php
git commit -m "feat: Protokoll mit Bereich, Filter je Eintrag und Link aus der Reservierung

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 9: Seite „Daten“ und schlankes „Mein Konto“

**Files:**
- Create: `app/Http/Controllers/Admin/Settings/DataController.php`, `resources/views/admin/settings/data.blade.php`, `tests/Feature/DataPageTest.php`
- Modify: `app/Http/Controllers/Admin/AccountController.php` (`import`, `destroyTenant`), `resources/views/admin/account/index.blade.php`, `routes/web.php`, `config/navigation.php`, `config/swayy.php`, `.env.example`
- Test: `tests/Feature/SettingsMenuTest.php`

**Interfaces:**
- Consumes: `User::roleIn()`, Routen `admin.account.export`, `admin.account.import`, `admin.account.tenant.destroy`, `admin.account.destroy` (unverändert), `x-page-header`, `x-settings.section`, `x-field` (Teil 1), `x-inline-confirm` (Task 4), Rollenfilter `roles` (Task 3), `swayy.uploads` (Task 5).
- Produces:
  - Route `admin.settings.data.show` (GET `/admin/settings/data`), nur für Mitglieder mit der Rolle `tenant_owner`; sonst 403 mit Erklärung.
  - Abschnitte `#export`, `#import`, `#betrieb-loeschen`.
  - Einstellung `swayy.uploads.import_max_kb` (`SWAYY_IMPORT_MAX_KB`, Vorgabe 20480, 1024–20480).
  - „Mein Konto“ enthält Profil und „Konto löschen“.

- [ ] **Step 1: Failing tests – `tests/Feature/DataPageTest.php`**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\CreatesTenants;
use Tests\TestCase;

class DataPageTest extends TestCase
{
    use CreatesTenants, RefreshDatabase;

    public function test_the_last_owner_sees_export_import_and_tenant_deletion(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get(route('admin.settings.data.show'))->assertOk()->getContent();

        $this->assertStringContainsString('id="export"', $html);
        $this->assertStringContainsString('href="'.route('admin.account.export').'"', $html);
        $this->assertStringContainsString('id="import"', $html);
        $this->assertStringContainsString('action="'.route('admin.account.import').'"', $html);
        $this->assertStringContainsString('id="betrieb-loeschen"', $html);
        $this->assertStringContainsString('action="'.route('admin.account.tenant.destroy').'"', $html);

        $source = (string) file_get_contents(resource_path('views/admin/settings/data.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source);
    }

    public function test_with_several_owners_deletion_explains_the_way(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)->get(route('admin.settings.data.show'))
            ->assertOk()
            ->assertDontSee('action="'.route('admin.account.tenant.destroy').'"', false)
            ->assertSee('Löschen kann den Betrieb nur, wer als einziger Inhaber übrig ist.');
    }

    public function test_admins_get_an_explanation_instead_of_the_page(): void
    {
        $setup = $this->createTenantSetup();
        $admin = $this->createMember($setup['tenant'], 'tenant_admin');
        $this->clearTenantContext();

        $this->actingAs($admin)->get(route('admin.settings.data.show'))
            ->assertForbidden()
            ->assertSee('„Daten“ steht nur Inhabern des Betriebs offen.');
    }

    public function test_the_import_size_limit_comes_from_the_settings(): void
    {
        config(['swayy.uploads.import_max_kb' => 1024]);
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $this->actingAs($owner)
            ->from(route('admin.settings.data.show'))
            ->post(route('admin.account.import'), [
                'file' => UploadedFile::fake()->createWithContent('export.json', str_repeat(' ', 1100 * 1024).'{}'),
                'confirm' => '1',
            ])
            ->assertRedirect(route('admin.settings.data.show'))
            ->assertSessionHasErrors(['file' => 'Die Datei ist größer als 1 MB, mehr nimmt der Import nicht an. Bitte den Support von Swayy fragen.']);
    }

    public function test_the_import_limit_defaults_to_twenty_megabytes(): void
    {
        $this->assertSame(20480, config('swayy.uploads.import_max_kb'));
    }

    public function test_my_account_keeps_only_personal_things(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        $html = $this->actingAs($owner)->get(route('admin.account.show'))->assertOk()->getContent();

        $this->assertStringNotContainsString(route('admin.account.export'), $html);
        $this->assertStringContainsString('id="profil"', $html);
        $this->assertStringContainsString('id="konto-loeschen"', $html);
        // Der einzige Inhaber löscht zuerst den Betrieb: Hinweis mit Weg zur Seite „Daten“.
        $this->assertStringContainsString('href="'.route('admin.settings.data.show').'#betrieb-loeschen"', $html);

        $source = (string) file_get_contents(resource_path('views/admin/account/index.blade.php'));
        $this->assertDoesNotMatchRegularExpression('/\son(click|change|submit|input)\s*=/i', $source);
    }
}
```

- [ ] **Step 2: Failing test – `tests/Feature/SettingsMenuTest.php`**

Die Methode `test_the_data_entry_lands_on_the_export_section` ersetzen durch:
```php
    public function test_the_data_entry_opens_the_data_page(): void
    {
        $setup = $this->createTenantSetup();
        $owner = $this->createMember($setup['tenant'], 'tenant_owner');
        $this->clearTenantContext();

        // Eintrag 'data' in config/navigation.php: admin.settings.data.show
        $this->actingAs($owner)->get('/admin/settings/menu')
            ->assertOk()
            ->assertSee('href="'.route('admin.settings.data.show').'"', false)
            ->assertSee('Export#export', false);

        $this->actingAs($owner)->get(route('admin.settings.data.show'))
            ->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('id="export"', false);
    }
```

- [ ] **Step 3: Run – expect FAIL**

Run: `PAO_DISABLE=1 php artisan test --filter='DataPageTest|SettingsMenuTest'`
Expected: FAIL, Route `admin.settings.data.show` fehlt.

- [ ] **Step 4: Einstellung für den Import**

In `config/swayy.php`, Abschnitt `uploads` (Task 5), im Kommentar ergänzen:
```php
    | import_max_kb: größte Datei für „Daten einspielen“ in KB (1024–20480);
    | mehr lassen PHP und nginx nicht durch (docker/php.ini).
```
und im Array:
```php
        'import_max_kb' => EnvSetting::integer('SWAYY_IMPORT_MAX_KB', default: 20480, min: 1024, max: 20480),
```
In `.env.example` nach `SWAYY_LOGO_DIRECTORY`:
```
# Größte Datei für „Daten einspielen“ in KB (1024–20480, mehr lassen PHP und nginx nicht durch)
# SWAYY_IMPORT_MAX_KB=20480
```

- [ ] **Step 5: `app/Http/Controllers/Admin/AccountController.php` anpassen**

1. Import ergänzen: `use Illuminate\Support\Number;`
2. In `import()` die Validierung ersetzen:
```php
        $maxKb = (int) config('swayy.uploads.import_max_kb');
        $request->validate([
            // Die Grenze steht in config/swayy.php (uploads.import_max_kb) und
            // liegt höchstens bei 20 MB, weil PHP und beide nginx genau so viel
            // durchlassen (docker/php.ini).
            'file' => ['required', 'file', 'mimetypes:application/json,text/plain', 'max:'.$maxKb],
            'confirm' => ['required', 'accepted'],
        ], [
            'file.required' => __('Bitte die Export-Datei auswählen.'),
            'file.mimetypes' => __('Die Datei ist keine Export-Datei von Swayy. Bitte die JSON-Datei aus „Daten herunterladen“ wählen.'),
            'file.max' => __('Die Datei ist größer als :size, mehr nimmt der Import nicht an. Bitte den Support von Swayy fragen.', [
                'size' => Number::fileSize($maxKb * 1024),
            ]),
            'confirm.required' => __('Bitte bestätige, dass die Daten zum aktuellen Betrieb hinzugefügt werden.'),
            'confirm.accepted' => __('Bitte bestätige, dass die Daten zum aktuellen Betrieb hinzugefügt werden.'),
        ]);
```
3. In `destroyTenant()` die Meldung bei falschem Namen ersetzen:
```php
            return back()->withErrors(['confirm_tenant' => __('Der eingetippte Name weicht von „:name“ ab. Bitte genau so eintippen, mit Groß- und Kleinschreibung.', ['name' => $tenant->name])]);
```

- [ ] **Step 6: `app/Http/Controllers/Admin/Settings/DataController.php` anlegen**

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Number;
use Illuminate\View\View;

/**
 * Einstellungen › Betrieb › Daten: Export, Import und „Betrieb löschen“.
 * Nur für Inhaber, wie die Aktionen im AccountController; der Eintrag in der
 * Navigation folgt derselben Prüfung (config/navigation.php, roles).
 */
class DataController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function show(Request $request): View
    {
        $tenant = $this->context->tenant();
        abort_if($tenant === null, 404);
        abort_unless(
            $request->user()->roleIn($tenant) === 'tenant_owner',
            403,
            __('„Daten“ steht nur Inhabern des Betriebs offen. Bitte eine Person mit der Rolle „Inhaber“ um den Export bitten.'),
        );

        return view('admin.settings.data', [
            'tenant' => $tenant,
            'isLastOwner' => $tenant->memberships()->where('role', 'tenant_owner')->count() <= 1,
            'importLimit' => Number::fileSize((int) config('swayy.uploads.import_max_kb') * 1024),
        ]);
    }
}
```

- [ ] **Step 7: Route in `routes/web.php`**

1. Import ergänzen (alphabetisch vor `GeneralController`): `use App\Http\Controllers\Admin\Settings\DataController;`
2. Nach der Zeile mit `settings.menu` einfügen:
```php
    // Daten (Export, Import, Betrieb löschen): nur Inhaber; prüft der Controller selbst.
    Route::get('/settings/data', [DataController::class, 'show'])->name('settings.data.show');
```

- [ ] **Step 8: `resources/views/admin/settings/data.blade.php` anlegen**

```blade
@extends('layouts.admin')
@section('title', 'Daten')

@section('content')
<x-page-header title="Daten" :crumb="['Einstellungen', 'Betrieb']" scope="tenant"
               description="Den ganzen Betrieb als Datei sichern, eine Sicherung einspielen oder den Betrieb löschen." />

<x-settings.section id="export" title="Alle Daten herunterladen">
    <x-slot:description>
        <p>Die Datei enthält Standorte, Räume und Tische, Zeiten, Reservierungen, Gäste, Events, Mitarbeiter, Leistungen und Einstellungen. Gut als Sicherung oder für einen Umzug.</p>
        <p>Zugangsdaten und Schlüssel für Zahlungsanbieter bleiben aus Sicherheitsgründen draußen.</p>
    </x-slot:description>
    <p class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        Die Datei enthält personenbezogene Daten deiner Gäste. Bewahre sie sorgsam auf.
    </p>
    <a href="{{ route('admin.account.export') }}" class="btn btn-primary">Daten von „{{ $tenant->name }}“ herunterladen</a>
</x-settings.section>

<x-settings.section id="import" title="Daten einspielen">
    <x-slot:description>
        <p>Liest eine zuvor heruntergeladene Datei ein, etwa beim Umzug von einem anderen Swayy-System. Die Daten kommen zu den bestehenden dazu.</p>
        <p>Bestätigungslinks entstehen neu; bereits versendete Links zum Ändern oder Stornieren gelten danach nicht mehr. Offene Erstattungen gelten als erledigt, sie gehören ins alte System. Stripe und PayPal verbindest du danach neu.</p>
    </x-slot:description>
    <form method="POST" action="{{ route('admin.account.import') }}" enctype="multipart/form-data" class="space-y-5">
        @csrf
        <x-field name="file" label="Export-Datei" type="file" id="import-file" accept=".json,application/json" required
                 :help="'JSON-Datei aus „Daten herunterladen“, bis '.$importLimit.'.'" />
        <div class="space-y-1.5">
            <label class="flex items-start gap-3 text-sm">
                <input type="checkbox" name="confirm" value="1" required class="mt-1"
                       @error('confirm') aria-invalid="true" aria-describedby="import-confirm-error" @enderror>
                <span>Ja, die Daten aus der Datei zu „{{ $tenant->name }}“ hinzufügen.</span>
            </label>
            @error('confirm')
                <p id="import-confirm-error" class="text-[0.8125rem] text-red-700">{{ $message }}</p>
            @enderror
        </div>
        <button type="submit" class="btn btn-primary">Datei einspielen</button>
    </form>
</x-settings.section>

<x-settings.section id="betrieb-loeschen" title="Betrieb löschen">
    <x-slot:description>
        <p>Löscht den Betrieb mit allen Standorten, Reservierungen, Gästedaten, Mitarbeitern und Einstellungen endgültig. Lade vorher die Daten herunter.</p>
    </x-slot:description>
    @if($isLastOwner)
        <x-inline-confirm id="betrieb-loeschen-frage" :action="route('admin.account.tenant.destroy')" method="DELETE" :ajax="false"
                          :open="$errors->has('confirm_tenant')"
                          label="Betrieb löschen …" confirm="Betrieb endgültig löschen"
                          :question="'„'.$tenant->name.'“ endgültig löschen? Danach meldet Swayy dich ab.'">
            <div class="space-y-1.5">
                <label for="confirm-tenant-name" class="block text-sm font-semibold text-stone-800">Zur Bestätigung den Namen des Betriebs eintippen</label>
                <input id="confirm-tenant-name" name="confirm" required autocomplete="off" class="w-full"
                       aria-describedby="confirm-tenant-name-help @error('confirm_tenant') confirm-tenant-name-error @enderror"
                       @error('confirm_tenant') aria-invalid="true" @enderror>
                <p id="confirm-tenant-name-help" class="text-[0.8125rem] text-stone-600">Genau so: {{ $tenant->name }}</p>
                @error('confirm_tenant')
                    <p id="confirm-tenant-name-error" class="text-[0.8125rem] text-red-700">{{ $message }}</p>
                @enderror
            </div>
        </x-inline-confirm>
    @else
        <p class="text-sm text-stone-700">
            Löschen kann den Betrieb nur, wer als einziger Inhaber übrig ist.
            Gib den anderen Inhabern unter <a href="{{ route('admin.users.index') }}" class="font-semibold underline underline-offset-2">Team & Rollen</a> zuerst eine andere Rolle.
        </p>
    @endif
</x-settings.section>
@endsection
```

- [ ] **Step 9: `resources/views/admin/account/index.blade.php` ersetzen**

```blade
@extends('layouts.admin')
@section('title', 'Mein Konto')

@section('content')
<x-page-header title="Mein Konto" description="Deine persönlichen Angaben und dein Zugang zu Swayy." />

<x-settings.section id="profil" title="Profil">
    <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-[9rem_minmax(0,1fr)]">
        <dt class="font-semibold text-stone-600">Name</dt>
        <dd class="[overflow-wrap:anywhere]">{{ $user->name }}</dd>
        <dt class="font-semibold text-stone-600">E-Mail</dt>
        <dd class="[overflow-wrap:anywhere]">{{ $user->email }}</dd>
        <dt class="font-semibold text-stone-600">Konto seit</dt>
        <dd>{{ $user->created_at->format('d.m.Y') }}</dd>
    </dl>
</x-settings.section>

<x-settings.section id="konto-loeschen" title="Konto löschen">
    <x-slot:description>
        <p>Entfernt deinen Zugang und deine Anmeldedaten. Buchungen und andere Daten des Betriebs bleiben erhalten.</p>
    </x-slot:description>
    @if($isLastOwner && $tenant !== null)
        <p class="text-sm text-stone-700">
            Du bist einziger Inhaber von „{{ $tenant->name }}“. Lösche zuerst den Betrieb unter
            <a href="{{ route('admin.settings.data.show') }}#betrieb-loeschen" class="font-semibold underline underline-offset-2">Einstellungen › Daten</a>
            oder mach unter <a href="{{ route('admin.users.index') }}" class="font-semibold underline underline-offset-2">Team & Rollen</a> eine andere Person zum Inhaber.
        </p>
    @else
        <x-inline-confirm id="konto-loeschen-frage" :action="route('admin.account.destroy')" method="DELETE" :ajax="false"
                          :open="$errors->has('confirm')"
                          label="Konto löschen …" confirm="Konto endgültig löschen"
                          question="Dein Konto endgültig löschen? Danach kannst du dich nicht mehr anmelden.">
            <x-field name="confirm" label="Zur Bestätigung LÖSCHEN eintippen" id="confirm-account" required autocomplete="off" />
        </x-inline-confirm>
    @endif
</x-settings.section>
@endsection
```

- [ ] **Step 10: Eintrag „Daten“ in `config/navigation.php`**

Den Eintrag `data` vollständig ersetzen durch:
```php
            ['key' => 'data', 'label' => 'Daten', 'route' => 'admin.settings.data.show', 'scope' => 'tenant', 'roles' => ['tenant_owner'],
                'keywords' => ['Export' => 'export', 'Sicherung' => 'export', 'Import' => 'import', 'Umzug' => 'import', 'Betrieb löschen' => 'betrieb-loeschen']],
```

- [ ] **Step 11: Run – expect PASS**

Run:
```bash
PAO_DISABLE=1 php artisan test --filter='DataPageTest|SettingsMenuTest|NavigationTest|NavigationLayoutTest|AccountExportTest|AccountImportTest|AccountMoveIntegrityTest|AccessBoundariesTest'
vendor/bin/pint --test app/Http/Controllers/Admin/AccountController.php app/Http/Controllers/Admin/Settings/DataController.php config routes tests/Feature/DataPageTest.php tests/Feature/SettingsMenuTest.php
vendor/bin/phpstan analyse --memory-limit=1G app/Http/Controllers/Admin/AccountController.php app/Http/Controllers/Admin/Settings/DataController.php
```
Expected: PASS, ohne Befund. Prüft ein bestehender Test die alte Meldung „Die Datei ist zu groß (max. 20 MB).“ oder „Der Name stimmt nicht überein.“, gilt die neue Meldung; Test anpassen und im Bericht nennen.

- [ ] **Step 12: Commit**

```bash
git add app/Http/Controllers/Admin/AccountController.php app/Http/Controllers/Admin/Settings/DataController.php resources/views/admin/settings/data.blade.php resources/views/admin/account/index.blade.php routes/web.php config .env.example tests/Feature/DataPageTest.php tests/Feature/SettingsMenuTest.php
git commit -m "feat: Seite Daten in den Einstellungen, Mein Konto nur noch persönlich

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 10: Prüfung Teil 2a und Testinstanz

Diese Aufgabe führt der Controller selbst aus (Browser, Server); sie ändert Code nur, wenn die Prüfung einen Fehler findet.

**Files:**
- Create: `docs/superpowers/plans/2026-10-08-new-ui-teil-2a-protokoll.md`
- Modify: bei Befunden die betroffenen Dateien; `C:\Users\brigh\Claude Workingdir\Serverprotokolle\mx.mailware.cc.md` (Eintrag zum Auffrischen von dev-swayy)

**Interfaces:**
- Consumes: alles aus Task 1–9.
- Produces: grüne Gesamtprüfung, Prüfprotokoll, dev-swayy auf dem Stand von `new-ui`.

- [ ] **Step 1: Gesamte Testsuite und Werkzeuge**

Run:
```bash
PAO_DISABLE=1 php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
npm test
npm run build
```
Expected: alles grün. Bekannter Befund außerhalb des Auftrags: Pint meldet `tests/Feature/ProvisioningApiTest.php` (Datei der Kundenpanel-Sitzung); er wird im Protokoll vermerkt und nicht in diesem Plan behoben.

- [ ] **Step 2: Browserprüfung**

Dev-Server `gastrobook-new-ui` (Port 8010) mit Testdaten: ein Betrieb mit zwei Standorten, Inhaber, Administration, Betriebsleitung, Service. Breiten 390, 768 und 1280 px; jede Seite ohne seitliches Scrollen (`document.documentElement.scrollWidth <= innerWidth`).

| Seite | Prüfung |
|---|---|
| Standorte | Drawer „Standort hinzufügen“ öffnet, Fokus im Namen, Escape schließt und gibt den Fokus zurück; leerer Name → Fehler am Feld im Drawer; Speichern legt an, Liste lädt neu; Drawer eines Standorts mit Begrüßung und Logo (PNG hochladen, SVG abgelehnt mit Grund); „Deaktivieren“ fragt in der Zeile; letzter aktiver Standort → Grund in der Zeile |
| Team & Rollen | Rollen mit Namen; Rollenwechsel per Auswahl speichert mit Hinweis; letzten Inhaber herabstufen → Grund, Auswahl springt zurück; „Person einladen“ mit Rollenbeschreibungen und Standorten; Link kopieren; Entfernen/Konto löschen fragen in der Zeile |
| Abo & Rechnungen | Tarif, Lastschrift-Zustand mit Symbol |
| Protokoll | Bereich filtern, Filter löschen, Leerzustand; aus einer Reservierung „Alle Änderungen im Protokoll“ |
| Daten | Inhaber: Export, Import, Betrieb löschen (Frage in der Zeile, falscher Name → Grund und Frage offen); Administration: Eintrag fehlt, Adresse → 403 mit Erklärung |
| Mein Konto | Profil, Konto löschen bzw. Hinweis mit Weg zu „Daten“ |
| Allgemein | Speicherleiste erscheint bei Änderung; bei ≥ 768 px ist der Seitenkopf gesperrt (Tab erreicht die Standortwahl nicht), nach Verwerfen wieder frei |
| Suche | „export“ führt zu Daten#export, „Person einladen“ öffnet den Drawer auf Team & Rollen, „xyz“ nennt die Beispiele |
| `/admin/settings` (alte Sammelseite) | Reiter wechseln, Standortwahl mit zwei Standorten wechselt den Standort, Formulare speichern wie vorher |

Tastatur: alle Drawer und Rückfragen nur mit Tab, Enter, Escape bedienbar; sichtbarer Fokus überall.

- [ ] **Step 3: Befunde beheben**

Jeder Befund ein eigener Commit mit Test, wo sinnvoll. Danach Step 1 erneut.

- [ ] **Step 4: Prüfprotokoll schreiben**

`docs/superpowers/plans/2026-10-08-new-ui-teil-2a-protokoll.md` mit: Stand (Commit), Ergebnisse von Step 1, Tabelle der Browserprüfung mit Befund je Zeile, behobene Befunde mit Commit, offene Punkte für 2b/2c. Commit:
```bash
git add docs/superpowers/plans/2026-10-08-new-ui-teil-2a-protokoll.md
git commit -m "docs: Prüfprotokoll Teil 2a

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

- [ ] **Step 5: dev-swayy auffrischen (mit Serverprotokoll)**

Vorher `Serverprotokolle/mx.mailware.cc.md` frisch lesen und einen Eintrag „dev-swayy: Abbild auf `new-ui` <commit>“ oben unter dem Steckbrief einfügen (gezielte Einfügung, Uhrzeit vom Server mit Zeitzone). Ablauf wie im Eintrag vom 08.10.2026 unter „Offen“: `git archive <commit>` nach sidecar, Abbild `gastrobook-local:dev-swayy-<commit>` mit `nice -n 10 docker build` bauen, in `/opt/dev-swayy/.env` `GASTROBOOK_TEST_IMAGE` umstellen (Sicherung der `.env` vorher), `docker compose up -d`. Prüfung: Logs von app, queue, scheduler ohne Fehler; von innen `/up` 200, `/admin/settings/data` 302 auf die Anmeldung; von außen 302 auf die NetBird-Anmeldung. Eintrag mit Ergebnis, Prüfung und Rückweg (altes Abbild `gastrobook-local:dev-swayy-42f6c29` bleibt bis zur Freigabe) abschließen. Datenstand bleibt.
