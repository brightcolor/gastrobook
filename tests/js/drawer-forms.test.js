import { beforeAll, describe, expect, it, vi } from 'vitest';
import { page } from '../../resources/js/admin/dom.js';
import { initDrawers } from '../../resources/js/admin/drawer.js';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initRows } from '../../resources/js/admin/rows.js';
import { jsonResponse, mount } from './helpers.js';

// Seitenleiste einer Person (Mitarbeiter): Profil als Hauptformular, Arbeitszeiten als Zeilen, Abwesenheit.
const person = `
    <div id="app"><button type="button" id="opener" data-drawer-open="person-5">Anna</button></div>
    <div id="person-5" data-drawer hidden>
        <div data-drawer-close data-drawer-backdrop id="backdrop"></div>
        <div role="dialog" aria-modal="true">
            <div data-drawer-body>
                <form id="form-person-5" method="POST" action="/admin/staff/5" data-ajax data-reload-after-save>
                    <input id="p-name" name="name" value="Anna">
                </form>
                <form id="hours-5" method="POST" action="/admin/staff/5/working-hours" data-ajax data-reload-after-save>
                    <div data-rows>
                        <div data-rows-list>
                            <div data-row data-change-item="wh-5-0">
                                <input id="wh-5-0-start" name="hours[0][starts_at]" value="09:00">
                                <button type="button" data-rows-remove>Entfernen</button>
                            </div>
                        </div>
                        <template data-rows-template>
                            <div data-row data-change-item="wh-5-__INDEX__">
                                <input id="wh-5-__INDEX__-start" name="hours[__INDEX__][starts_at]" value="09:00">
                                <button type="button" data-rows-remove>Entfernen</button>
                            </div>
                        </template>
                        <button type="button" id="add" data-rows-add>Zeitfenster hinzufügen</button>
                    </div>
                    <button type="submit" id="save-hours">Arbeitszeiten speichern</button>
                </form>
                <form id="absence-5" method="POST" action="/admin/staff/5/absences" data-ajax data-reset-after-save data-reload-after-save>
                    <input id="abw-von" name="starts_on" value="">
                    <button type="submit">Abwesenheit eintragen</button>
                </form>
            </div>
            <button type="submit" form="form-person-5">Profil speichern</button>
        </div>
    </div>`;

const toast = () => document.getElementById('swayyToast').textContent;
const notSaved = 'Die Eingaben sind noch nicht gespeichert. Speichern oder mit „Abbrechen“ verwerfen.';
const type = (id, value) => {
    const field = document.getElementById(id);
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
};
const hidden = () => document.getElementById('person-5').hidden;

beforeAll(() => {
    initAjaxForms();
});

describe('Seitenleiste mit mehreren Formularen', () => {
    it('schützt über den Hintergrund jedes der drei Formulare', () => {
        mount(person);
        initDrawers();
        initRows();
        document.getElementById('opener').click();

        // Profil
        type('p-name', 'Anna B.');
        document.getElementById('backdrop').click();
        expect(hidden()).toBe(false);
        expect(toast()).toBe(notSaved);
        type('p-name', 'Anna');
        expect(document.getElementById('form-person-5').hasAttribute('data-dirty')).toBe(false);

        // Eine neue Zeile der Arbeitszeiten zählt als Änderung des Formulars.
        document.getElementById('swayyToast').textContent = '';
        document.getElementById('add').click();
        expect(document.getElementById('hours-5').hasAttribute('data-dirty')).toBe(true);
        document.getElementById('backdrop').click();
        expect(hidden()).toBe(false);
        expect(toast()).toBe(notSaved);

        // Zeile wieder entfernen: Das Formular steht wie beim Öffnen.
        document.querySelector('#hours-5 [data-row]:last-child [data-rows-remove]').click();
        expect(document.getElementById('hours-5').hasAttribute('data-dirty')).toBe(false);

        // Abwesenheit
        document.getElementById('swayyToast').textContent = '';
        type('abw-von', '2026-11-02');
        document.getElementById('backdrop').click();
        expect(hidden()).toBe(false);
        expect(toast()).toBe(notSaved);

        type('abw-von', '');
        document.getElementById('backdrop').click();
        expect(hidden()).toBe(true);
    });

    it('speichert die Arbeitszeiten, ohne die Seitenleiste zu schließen, und lässt danach den Hintergrund zu', async () => {
        vi.spyOn(page, 'reload').mockImplementation(() => {});
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Arbeitszeiten gespeichert.' })));
        mount(person);
        initDrawers();
        initRows();
        document.getElementById('opener').click();
        document.getElementById('add').click();
        const added = document.querySelector('#hours-5 [data-row]:last-child input');
        type(added.id, '10:00');
        expect(document.getElementById('hours-5').hasAttribute('data-dirty')).toBe(true);

        document.getElementById('hours-5').requestSubmit();

        await vi.waitFor(() => expect(toast()).toBe('Arbeitszeiten gespeichert.'));
        expect(hidden()).toBe(false);
        expect(document.getElementById('hours-5').hasAttribute('data-dirty')).toBe(false);
        // Die Liste der Seite zeigt die Zahl der Zeitfenster; sie lädt nach dem Speichern neu, die Seitenleiste öffnet sich über die Adresse wieder.
        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
        // Die neue Zeile behält ihren Schlüssel (n1, n2 …); der Server ersetzt beim nächsten Speichern die ganze Liste.
        expect(added.isConnected).toBe(true);
        expect(added.name).toMatch(/^hours\[n\d+\]\[starts_at\]$/);

        document.getElementById('backdrop').click();
        expect(hidden()).toBe(true);
    });
});
