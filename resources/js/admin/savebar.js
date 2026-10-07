// Speicherleiste (x-save-bar): erscheint, sobald ein Formular mit
// data-savebar geändert ist. „Speichern" schickt die geänderten Formulare
// nacheinander ab, „Verwerfen" setzt sie auf den gespeicherten Stand zurück.
// Wer die Seite mit Änderungen verlässt, bekommt die Rückfrage des Browsers.

import { submitForm } from './forms.js';
import { showToast } from './toast.js';

const snapshot = (form) => new URLSearchParams(new FormData(form)).toString();

export function initSaveBar() {
    const bar = document.querySelector('[data-save-bar]');
    const forms = [...document.querySelectorAll('form[data-savebar]')];
    if (!bar || forms.length === 0) return;

    const base = new Map(forms.map((form) => [form, snapshot(form)]));
    const dirtyForms = () => forms.filter((form) => snapshot(form) !== base.get(form));

    const status = document.querySelector('[data-save-bar-status]');
    let wasDirty = false;

    const render = () => {
        const dirty = dirtyForms();
        bar.hidden = dirty.length === 0;
        forms.forEach((form) => form.toggleAttribute('data-dirty', dirty.includes(form)));

        // Angesagt wird nur der Wechsel, damit der Screenreader nicht bei jedem Tastendruck spricht.
        if (status && (dirty.length > 0) !== wasDirty) {
            wasDirty = dirty.length > 0;
            status.textContent = wasDirty ? (bar.querySelector('[data-save-bar-text]')?.textContent ?? '') : '';
        }
    };

    forms.forEach((form) => {
        form.addEventListener('input', render);
        form.addEventListener('change', render);
        const rebase = () => {
            base.set(form, snapshot(form));
            render();
        };
        form.addEventListener('swayy:saved', rebase);
        form.addEventListener('swayy:rebase', rebase);
    });

    bar.querySelector('[data-save-bar-discard]')?.addEventListener('click', () => {
        dirtyForms().forEach((form) => {
            form.reset();
            form.dispatchEvent(new CustomEvent('swayy:discarded'));
        });
        render();
        showToast('Änderungen verworfen');
    });

    bar.querySelector('[data-save-bar-save]')?.addEventListener('click', async () => {
        for (const form of dirtyForms()) {
            const ok = await submitForm(form);
            if (!ok) break;
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
