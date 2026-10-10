import { beforeAll, describe, expect, it, vi } from 'vitest';
import { page } from '../../resources/js/admin/dom.js';
import { initDrawers } from '../../resources/js/admin/drawer.js';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initRows } from '../../resources/js/admin/rows.js';
import { jsonResponse, mount } from './helpers.js';

// Seitenleiste einer Person (Mitarbeiter): Profil als Hauptformular, Arbeitszeiten als Zeilen, Abwesenheit.
// $sections = false lässt data-drawer-section weg: Der Name kommt dann aus der Überschrift des Abschnitts.
const personWith = (sections) => `
    <div id="app"><button type="button" id="opener" data-drawer-open="person-5">Anna</button></div>
    <div id="person-5" data-drawer hidden>
        <div data-drawer-close data-drawer-backdrop id="backdrop"></div>
        <div role="dialog" aria-modal="true">
            <button type="button" id="close" data-drawer-close>Abbrechen</button>
            <div data-drawer-body>
                <form id="form-person-5" method="POST" action="/admin/staff/5" data-ajax data-reload-after-save ${sections ? 'data-drawer-section="Profil"' : ''}>
                    <input id="p-name" name="name" value="Anna">
                </form>
                <section>
                    <h3>Arbeitszeiten</h3>
                    <form id="hours-5" method="POST" action="/admin/staff/5/working-hours" data-ajax data-reload-after-save ${sections ? 'data-drawer-section="Arbeitszeiten"' : ''}>
                        <div data-rows>
                            <div data-rows-list>
                                <div data-row>
                                    <input id="wh-5-0-start" name="hours[0][starts_at]" value="09:00">
                                    <button type="button" data-rows-remove>Entfernen</button>
                                </div>
                            </div>
                            <template data-rows-template>
                                <div data-row>
                                    <input id="wh-5-__INDEX__-start" name="hours[__INDEX__][starts_at]" value="09:00">
                                    <button type="button" data-rows-remove>Entfernen</button>
                                </div>
                            </template>
                            <button type="button" id="add" data-rows-add>Zeitfenster hinzufügen</button>
                        </div>
                        <button type="submit" id="save-hours">Arbeitszeiten speichern</button>
                    </form>
                </section>
                <section>
                    <h3>Abwesenheiten</h3>
                    <form id="absence-5" method="POST" action="/admin/staff/5/absences" data-ajax data-reset-after-save data-reload-after-save ${sections ? 'data-drawer-section="Abwesenheit"' : ''}>
                        <input id="abw-von" name="starts_on" value="">
                        <button type="submit">Abwesenheit eintragen</button>
                    </form>
                </section>
            </div>
            <button type="submit" form="form-person-5">Profil speichern</button>
        </div>
    </div>`;
const person = personWith(true);

const toast = () => document.getElementById('swayyToast').textContent;
const notSaved = 'Die Eingaben sind noch nicht gespeichert. Speichern oder mit „Abbrechen“ verwerfen.';
const type = (id, value) => {
    const field = document.getElementById(id);
    field.value = value;
    field.dispatchEvent(new Event('input', { bubbles: true }));
};
const hidden = () => document.getElementById('person-5').hidden;
const dirty = (id) => document.getElementById(id).hasAttribute('data-dirty');
const open = () => {
    initDrawers();
    initRows();
    document.getElementById('opener').click();
};
const later = () => new Promise((resolve) => setTimeout(resolve, 5));

beforeAll(() => {
    initAjaxForms();
});

describe('Seitenleiste mit mehreren Formularen', () => {
    it('schützt über den Hintergrund jedes der drei Formulare', () => {
        mount(person);
        open();

        // Profil
        type('p-name', 'Anna B.');
        document.getElementById('backdrop').click();
        expect(hidden()).toBe(false);
        expect(toast()).toBe(notSaved);
        type('p-name', 'Anna');
        expect(dirty('form-person-5')).toBe(false);

        // Eine neue Zeile der Arbeitszeiten zählt als Änderung des Formulars.
        document.getElementById('swayyToast').textContent = '';
        document.getElementById('add').click();
        expect(dirty('hours-5')).toBe(true);
        document.getElementById('backdrop').click();
        expect(hidden()).toBe(false);
        expect(toast()).toBe(notSaved);

        // Zeile wieder entfernen: Das Formular steht wie beim Öffnen.
        document.querySelector('#hours-5 [data-row]:last-child [data-rows-remove]').click();
        expect(dirty('hours-5')).toBe(false);

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
        open();
        document.getElementById('add').click();
        const added = document.querySelector('#hours-5 [data-row]:last-child input');
        type(added.id, '10:00');
        expect(dirty('hours-5')).toBe(true);

        document.getElementById('hours-5').requestSubmit();

        await vi.waitFor(() => expect(toast()).toBe('Arbeitszeiten gespeichert.'));
        expect(hidden()).toBe(false);
        expect(dirty('hours-5')).toBe(false);
        // Die Liste der Seite zeigt die Zahl der Zeitfenster; sie lädt nach dem Speichern neu, die Seitenleiste öffnet sich über die Adresse wieder.
        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
        // Die neue Zeile behält ihren Schlüssel (n1, n2 …); der Server ersetzt beim nächsten Speichern die ganze Liste.
        expect(added.isConnected).toBe(true);
        expect(added.name).toMatch(/^hours\[n\d+\]\[starts_at\]$/);

        document.getElementById('backdrop').click();
        expect(hidden()).toBe(true);
    });

    it('speichert das Profil und bleibt offen, solange die Arbeitszeiten ungespeichert sind', async () => {
        vi.spyOn(page, 'reload').mockImplementation(() => {});
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: '„Anna B.“ gespeichert.' })));
        mount(person);
        open();
        type('p-name', 'Anna B.');
        document.getElementById('add').click();
        const added = document.querySelector('#hours-5 [data-row]:last-child input');
        type(added.id, '10:00');

        document.getElementById('form-person-5').requestSubmit();

        // Der Hinweis nennt den Abschnitt, der noch offen ist, in einer Meldung mit der Antwort des Servers.
        await vi.waitFor(() => expect(toast()).toBe('„Anna B.“ gespeichert. Noch nicht gespeichert: Arbeitszeiten. Speichern oder mit „Abbrechen“ verwerfen.'));
        expect(hidden()).toBe(false);
        // Die Eingaben der Arbeitszeiten bleiben stehen, das gespeicherte Profil gilt als gespeichert.
        expect(added.isConnected).toBe(true);
        expect(added.value).toBe('10:00');
        expect(dirty('hours-5')).toBe(true);
        expect(dirty('form-person-5')).toBe(false);
        expect(document.getElementById('p-name').value).toBe('Anna B.');
        // Das Neuladen nach dem Speichern wartet, bis die Arbeitszeiten gespeichert oder verworfen sind.
        await later();
        expect(page.reload).not.toHaveBeenCalled();

        // Ein Klick auf den Hintergrund schützt die Arbeitszeiten weiter.
        document.getElementById('backdrop').click();
        expect(hidden()).toBe(false);

        // Verwerfen schließt die Seitenleiste, setzt die Arbeitszeiten zurück und lässt das Neuladen zu. Das gespeicherte Profil bleibt.
        document.getElementById('close').click();
        expect(hidden()).toBe(true);
        expect(document.getElementById('p-name').value).toBe('Anna B.');
        expect(document.querySelectorAll('#hours-5 [data-row]')).toHaveLength(1);
        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
    });

    it('nennt alle Abschnitte, die nach dem Speichern des Profils noch offen sind, und lädt nach dem Speichern der Reihe nach neu', async () => {
        vi.spyOn(page, 'reload').mockImplementation(() => {});
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Gespeichert.' })));
        mount(person);
        open();
        type('p-name', 'Anna B.');
        document.getElementById('add').click();
        type(document.querySelector('#hours-5 [data-row]:last-child input').id, '10:00');
        type('abw-von', '2026-11-02');

        document.getElementById('form-person-5').requestSubmit();

        await vi.waitFor(() => expect(toast()).toBe('Gespeichert. Noch nicht gespeichert: Arbeitszeiten und Abwesenheit. Speichern oder mit „Abbrechen“ verwerfen.'));
        expect(hidden()).toBe(false);
        expect(document.getElementById('abw-von').value).toBe('2026-11-02');

        // Sind die Arbeitszeiten gespeichert, bleibt die Abwesenheit offen und hält das Neuladen auf.
        document.getElementById('hours-5').requestSubmit();
        await vi.waitFor(() => expect(toast()).toBe('Gespeichert.'));
        await later();
        expect(page.reload).not.toHaveBeenCalled();
        expect(document.getElementById('abw-von').value).toBe('2026-11-02');

        document.getElementById('absence-5').requestSubmit();
        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
    });

    it('schließt nach dem Speichern des Profils wie bisher, wenn die übrigen Formulare unverändert sind', async () => {
        vi.spyOn(page, 'reload').mockImplementation(() => {});
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: '„Anna B.“ gespeichert.' })));
        mount(person);
        open();
        type('p-name', 'Anna B.');

        document.getElementById('form-person-5').requestSubmit();

        await vi.waitFor(() => expect(hidden()).toBe(true));
        expect(toast()).toBe('„Anna B.“ gespeichert.');
        await vi.waitFor(() => expect(page.reload).toHaveBeenCalledTimes(1));
    });

    it('nimmt den Namen des Abschnitts aus der Überschrift, wenn das Formular keinen trägt', async () => {
        vi.spyOn(page, 'reload').mockImplementation(() => {});
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Profil gespeichert.' })));
        mount(personWith(false));
        open();
        type('p-name', 'Anna B.');
        type('abw-von', '2026-11-02');

        document.getElementById('form-person-5').requestSubmit();

        await vi.waitFor(() => expect(toast()).toBe('Profil gespeichert. Noch nicht gespeichert: Abwesenheiten. Speichern oder mit „Abbrechen“ verwerfen.'));
        expect(hidden()).toBe(false);
    });
});
