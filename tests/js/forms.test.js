import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { page } from '../../resources/js/admin/dom.js';
import { initAjaxForms, submitForm } from '../../resources/js/admin/forms.js';
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

    it('verwerfen die Folgeanfrage, wenn das Formular nach dem Speichern zurückgesetzt wurde', async () => {
        const resolvers = [];
        // Die erste Anfrage wartet; jede weitere würde sofort antworten.
        const fetchMock = vi.fn()
            .mockImplementationOnce(() => new Promise((r) => { resolvers.push(r); }))
            .mockImplementation(async () => jsonResponse({ message: 'Angelegt.' }));
        vi.stubGlobal('fetch', fetchMock);
        mount(form('data-reset-after-save'));
        const el = document.getElementById('f');
        const input = document.getElementById('f-name');

        input.value = 'Terrasse';
        el.requestSubmit();
        input.value = 'Terrasse Süd';
        const followUp = submitForm(el);
        resolvers[0](jsonResponse({ message: 'Angelegt.' }));

        // Die Folgeanfrage meldet das Ergebnis der ersten.
        await expect(followUp).resolves.toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(input.value).toBe('Alt');
    });

    it('schicken die Folgeanfrage, wenn das Zurücksetzen vor dem zweiten Absenden lag', async () => {
        const resolvers = [];
        const fetchMock = vi.fn(() => new Promise((r) => { resolvers.push(r); }));
        vi.stubGlobal('fetch', fetchMock);
        mount(form());
        const el = document.getElementById('f');
        const input = document.getElementById('f-name');

        input.value = 'Erster Stand';
        el.requestSubmit();
        el.reset();
        input.value = 'Zweiter Stand';
        const followUp = submitForm(el);
        resolvers[0](jsonResponse({ message: 'Gespeichert.' }));

        await vi.waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));
        expect(fetchMock.mock.calls[1][1].body.toString()).toContain('name=Zweiter+Stand');
        resolvers[1](jsonResponse({ message: 'Gespeichert.' }));
        await expect(followUp).resolves.toBe(true);
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

    it('zeigen Fehler zu Kästchen mit ID in einer Gruppe mit data-error-group bei der Gruppe', async () => {
        const text = 'Bitte wählen, was an diesen Tagen anders ist.';
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: text, errors: { effects: [text] } }, 422)));
        mount(`
            <form id="i" method="POST" action="/admin/settings/exceptions" data-ajax>
                <fieldset id="wirkungen" data-error-group>
                    <legend>Was ist anders?</legend>
                    <label><input type="checkbox" id="w-closed" name="effects[]" value="closed"> Geschlossen</label>
                    <label><input type="checkbox" id="w-hours" name="effects[]" value="hours"> Andere Öffnungszeiten</label>
                </fieldset>
            </form>`);

        document.getElementById('i').requestSubmit();

        const group = document.getElementById('wirkungen');
        await vi.waitFor(() => expect(group.querySelector('[data-ajax-error]')?.textContent).toBe(text));
        // Der Text steht am Ende der Gruppe und nicht in der Karte des ersten Kästchens.
        expect(group.lastElementChild.id).toBe('wirkungen-error');
        expect(document.querySelector('label [data-ajax-error]')).toBeNull();
        expect(group.getAttribute('aria-describedby')).toBe('wirkungen-error');
        expect(document.querySelector('#i [data-form-problems] a').getAttribute('href')).toBe('#wirkungen');
        expect(document.activeElement).toBe(document.getElementById('w-closed'));
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
});
