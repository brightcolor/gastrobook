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
// mit. Den Zustand einer Zeile außerhalb ihrer Felder, etwa einen geschlossenen
// Tag, meldet data-change-state. Geänderte Zeilen tragen data-changed und
// zeigen ihren Punkt ([data-change-dot], x-change-dot). Die Wörter stehen an der Leiste:
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
        // Zustand der Zeile außerhalb ihrer Felder (etwa ein geschlossener Tag): data-change-state.
        if (item.dataset.changeState !== undefined) values.push(`state=${item.dataset.changeState}`);
        parts.set(`item:${item.dataset.changeItem}`, values.join('&'));
    });
    for (const el of form.elements) {
        if (!el.name || el.type === 'submit' || el.type === 'button' || el.closest('[data-change-item]')) continue;
        const key = `field:${el.name}`;
        parts.set(key, `${parts.get(key) ?? ''}|${valueOf(el)}`);
    }
    return parts;
}

// Name eines Feldes für den Hinweis: Text der Beschriftung (ohne Pflichtstern),
// sonst aria-label.
function fieldName(field) {
    const label = field.labels?.[0];
    if (label) {
        const copy = label.cloneNode(true);
        copy.querySelectorAll('[aria-hidden="true"]').forEach((el) => el.remove());
        const text = copy.textContent.replace(/\s+/g, ' ').trim();
        if (text) return text;
    }
    return field.getAttribute('aria-label')?.trim() ?? '';
}

// Die Sprechblase des Browsers erscheint nur an einem sichtbaren Feld. Liegt
// das erste ungültige Feld verborgen (etwa weil eine andere Auswahl es
// ausblendet), nennt ein Hinweis das Feld und den Weg heraus.
function explainHiddenInvalid(form) {
    const field = [...form.elements].find((el) => el.willValidate && !el.validity.valid);
    if (!field || field.getClientRects().length > 0) return;
    const name = fieldName(field);
    const subject = name ? `„${name}“` : 'Ein verborgenes Feld';
    showToast(`${subject} enthält einen ungültigen Wert und ist durch eine andere Auswahl ausgeblendet. `
        + 'Bitte die Auswahl ändern, bis das Feld erscheint, und den Wert korrigieren, oder auf „Verwerfen“ klicken.', { error: true });
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
            // data-reset-after-save und data-savebar schließen sich aus: savedSnapshot hielte den gesendeten Stand eines zurückgesetzten Formulars.
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
                if (!form.reportValidity()) {
                    explainHiddenInvalid(form);
                    break;
                }
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
