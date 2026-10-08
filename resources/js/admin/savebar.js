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
