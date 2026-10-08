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
