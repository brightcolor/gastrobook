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

// Echte Tastenereignisse sind abbrechbar; drawer.js erkennt eine schon
// bearbeitete Escape-Taste an defaultPrevented.
const escape = () => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

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
