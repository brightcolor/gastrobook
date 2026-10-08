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
