import fs from 'node:fs';
import path from 'node:path';
import { describe, expect, it, vi } from 'vitest';
import { mount } from './helpers.js';

// Das Fenster eines Tisches im Tischplan lebt im Skript der Ansicht (resources/views/admin/floorplan/index.blade.php).
// Der Test liest showPopup aus dieser Datei, ersetzt die Blade-Ausdrücke durch feste Adressen und führt die Funktion
// in jsdom aus. Findet er die Funktion nicht mehr, scheitert er laut.
const source = fs.readFileSync(path.resolve(process.cwd(), 'resources/views/admin/floorplan/index.blade.php'), 'utf8');

function showPopupFunction() {
    const start = source.indexOf('    function showPopup(t) {');
    const end = source.indexOf('    function startReassign(', start);
    expect(start).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(start);
    const body = source.slice(start, end).replace(/\{\{[^}]*\}\}/g, '/blade');
    return new Function('popup', 'dateInput', 'timeInput', 'esc', 'setParty', 'checkout', 'startReassign', `${body}\nreturn showPopup;`);
}

const LOCK_URL = '/admin/settings/exceptions';

function popupFor(table, { lockUrl = LOCK_URL } = {}) {
    mount(`<div id="tablePopup" class="hidden"${lockUrl ? ` data-lock-url="${lockUrl}"` : ''}></div>`);
    const popup = document.getElementById('tablePopup');
    const esc = (value) => String(value).replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
    const show = showPopupFunction()(popup, { value: '2026-10-12' }, { value: '12:00' }, esc, vi.fn(), vi.fn(), vi.fn());
    show(table);
    return popup;
}

const base = { id: 7, name: 'T7', status: 'free', seats: 4, occupied: 0, current: null, upcoming: null };
const current = { id: 5, name: 'Test', party: 2, until: '14:00', status: 'confirmed', tags: [] };
const upcoming = { id: 6, name: 'Test', party: 2, at: '12:30', tags: [] };
const lockLink = (popup) => [...popup.querySelectorAll('.pp-body a')].find((a) => /sperren/i.test(a.textContent));

describe('Fenster eines Tisches im Tischplan', () => {
    it.each([
        ['frei', base],
        ['mit laufender Reservierung', { ...base, status: 'awaiting', current }],
        ['mit nächster Reservierung', { ...base, status: 'soon', upcoming }],
    ])('bietet bei einem Tisch %s „Tisch sperren…“ an', (_, table) => {
        const link = lockLink(popupFor(table));
        expect(link.textContent).toBe('Tisch sperren…');
        expect(link.getAttribute('href')).toBe(`${LOCK_URL}?tisch=7#ausnahme-neu`);
        expect(link.className).toBe('pp-btn pp-soft');
    });

    it.each([
        ['gesperrt', { ...base, status: 'blocked' }],
        ['gesperrt mit laufender Reservierung', { ...base, status: 'blocked', current }],
        ['gesperrt mit nächster Reservierung', { ...base, status: 'blocked', upcoming }],
    ])('führt bei einem Tisch %s zur Liste der Ausnahmen', (_, table) => {
        const link = lockLink(popupFor(table));
        expect(link.textContent).toBe('Sperren ansehen…');
        expect(link.getAttribute('href')).toBe(LOCK_URL);
    });

    it('hängt den Link ans Ende des Inhalts', () => {
        const popup = popupFor({ ...base, status: 'awaiting', current });
        expect(popup.querySelector('.pp-body').lastElementChild).toBe(lockLink(popup));
    });

    it('zeigt nur mit data-lock-url einen Link', () => {
        const popup = popupFor({ ...base, current }, { lockUrl: '' });
        expect(lockLink(popup)).toBeUndefined();
        expect(popup.querySelector('.pp-body a').textContent).toBe('Reservierung öffnen');
    });

    it('schließt über den Knopf und trägt keinen Inline-Handler', () => {
        const popup = popupFor(base);
        popup.classList.remove('hidden');
        expect(popup.innerHTML).not.toContain('onclick');

        popup.querySelector('.pp-x').click();

        expect(popup.classList.contains('hidden')).toBe(true);
    });
});
