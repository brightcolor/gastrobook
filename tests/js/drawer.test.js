import { beforeAll, describe, expect, it, vi } from 'vitest';
import { page } from '../../resources/js/admin/dom.js';
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

    it('bleibt offen, wenn der Bestätigungsdialog darüber Escape verbraucht', () => {
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();
        document.getElementById('f-neu-name').value = 'Noch nicht gespeichert';

        // Der Dialog der Seite lauscht schon vor drawer.js und ruft preventDefault auf;
        // die Capture-Phase bildet diese Reihenfolge nach.
        const consume = (e) => { if (e.key === 'Escape') e.preventDefault(); };
        document.addEventListener('keydown', consume, true);
        try {
            document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

            expect(document.getElementById('standort-neu').hidden).toBe(false);
            expect(document.getElementById('f-neu-name').value).toBe('Noch nicht gespeichert');
        } finally {
            document.removeEventListener('keydown', consume, true);
        }

        document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));
        expect(document.getElementById('standort-neu').hidden).toBe(true);
    });

    it('bleibt beim Speichern eines Nebenformulars offen; ein Neuladen wartet auf ungespeicherte Eingaben', async () => {
        vi.spyOn(page, 'reload').mockImplementation(() => {});
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Logo gespeichert.', reload: true })));
        mount(`
            <div id="app"><div data-page-header><h1>Standorte</h1></div>
                <button type="button" id="opener" data-drawer-open="standort-5">Sonne</button></div>
            <div id="standort-5" data-drawer hidden><div role="dialog"><div data-drawer-body>
                <form id="form-5" method="POST" action="/admin/locations/5" data-ajax>
                    <label for="loc-5-name">Name</label><input id="loc-5-name" name="name" value="Sonne">
                </form>
                <form id="logo-5" method="POST" action="/admin/locations/5/logo" data-ajax>
                    <button type="submit">Logo hochladen</button>
                </form>
            </div><button type="submit" form="form-5">Speichern</button></div></div>`);
        initDrawers();
        document.getElementById('opener').click();
        const name = document.getElementById('loc-5-name');
        name.value = 'Sonne am Markt';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        expect(document.getElementById('form-5').hasAttribute('data-dirty')).toBe(true);

        document.getElementById('logo-5').requestSubmit();

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Logo gespeichert.'));
        await new Promise((resolve) => setTimeout(resolve, 5));
        expect(document.getElementById('standort-5').hidden).toBe(false);
        expect(name.value).toBe('Sonne am Markt');
        expect(page.reload).not.toHaveBeenCalled();

        escape();

        expect(document.getElementById('standort-5').hidden).toBe(true);
        expect(document.getElementById('form-5').hasAttribute('data-dirty')).toBe(false);
        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
    });

    it('markiert den Fußzeilen-Knopf als beschäftigt, solange das Hauptformular sendet', async () => {
        let resolve;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((r) => { resolve = r; })));
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();
        const button = document.querySelector('[form="form-neu"]');

        document.getElementById('form-neu').requestSubmit();

        expect(button.getAttribute('aria-busy')).toBe('true');
        resolve(jsonResponse({ message: 'Standort angelegt.' }));
        await vi.waitFor(() => expect(document.getElementById('standort-neu').hidden).toBe(true));
        expect(button.hasAttribute('aria-busy')).toBe(false);
    });

    it('übersteht eine kaputt kodierte Adresse', () => {
        history.replaceState(null, '', '/admin/locations#%');
        mount(layout);

        expect(() => initDrawers()).not.toThrow();
        expect(document.getElementById('standort-neu').hidden).toBe(true);
    });
});
