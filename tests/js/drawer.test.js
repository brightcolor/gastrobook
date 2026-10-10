import { beforeAll, describe, expect, it, vi } from 'vitest';
import { page } from '../../resources/js/admin/dom.js';
import { initDrawers } from '../../resources/js/admin/drawer.js';
import { initAjaxForms, submitForm } from '../../resources/js/admin/forms.js';
import { initInlineConfirms } from '../../resources/js/admin/inline-confirm.js';
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
    initInlineConfirms();
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

    it('zeigt beim Öffnen keine Fehler einer Anfrage, die erst nach dem Schließen scheiterte', async () => {
        let resolve;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((r) => { resolve = r; })));
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();
        document.getElementById('f-neu-name').value = 'Filiale';
        const sending = submitForm(document.getElementById('form-neu'));
        await vi.waitFor(() => expect(resolve).toBeTypeOf('function'));

        escape();
        resolve(jsonResponse({
            message: 'Der Name ist vergeben.',
            errors: { name: ['Dieser Name ist schon vergeben.'] },
        }, 422));
        await sending;
        expect(document.querySelector('#form-neu [data-form-problems]')).not.toBeNull();

        document.getElementById('opener').click();

        expect(document.querySelector('#form-neu [data-form-problems]')).toBeNull();
        expect(document.getElementById('f-neu-name-error')).toBeNull();
        expect(document.getElementById('f-neu-name').hasAttribute('aria-invalid')).toBe(false);
        expect(document.getElementById('f-neu-name').value).toBe('');
        escape();
    });

    it('behält beim Öffnen über die Adresse die Fehler, die der Server mitgeliefert hat', () => {
        history.replaceState(null, '', '/admin/locations#standort-neu');
        mount(layout.replace('<form id="form-neu" method="POST" action="/admin/locations" data-ajax>',
            '<form id="form-neu" method="POST" action="/admin/locations" data-ajax><div data-form-problems role="alert">Bitte einen Namen eintragen.</div>'));

        initDrawers();

        expect(document.getElementById('standort-neu').hidden).toBe(false);
        expect(document.querySelector('#form-neu [data-form-problems]')?.textContent).toBe('Bitte einen Namen eintragen.');
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

    it('sendet nach dem Schließen keine Folgeanfrage mehr mit dem zurückgesetzten Stand', async () => {
        const resolvers = [];
        // Die erste Anfrage wartet; jede weitere würde sofort antworten.
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => new Promise((r) => { resolvers.push(r); }))
            .mockImplementation(async () => jsonResponse({ message: 'Standort angelegt.' }));
        vi.stubGlobal('fetch', fetchMock);
        mount(layout);
        initDrawers();
        document.getElementById('opener').click();
        const name = document.getElementById('f-neu-name');
        const formEl = document.getElementById('form-neu');

        name.value = 'Filiale Süd';
        formEl.requestSubmit();
        name.value = 'Filiale Süd II';
        const followUp = submitForm(formEl);
        resolvers[0](jsonResponse({ message: 'Standort angelegt.' }));

        await vi.waitFor(() => expect(document.getElementById('standort-neu').hidden).toBe(true));
        await expect(followUp).resolves.toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('übersteht eine kaputt kodierte Adresse', () => {
        history.replaceState(null, '', '/admin/locations#%');
        mount(layout);

        expect(() => initDrawers()).not.toThrow();
        expect(document.getElementById('standort-neu').hidden).toBe(true);
    });

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
});
