import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { copyWindows, initHours, overnightHint, shortHint } from '../../resources/js/admin/hours.js';
import { initRows } from '../../resources/js/admin/rows.js';
import { initSaveBar } from '../../resources/js/admin/savebar.js';
import { jsonResponse, mount } from './helpers.js';

describe('Hinweise am Zeitfenster', () => {
    it('nennt den Folgetag bei Fenstern über Mitternacht', () => {
        expect(overnightHint('22:00', '02:00', 'Sonntag')).toBe('Endet am Folgetag (Sonntag) um 02:00.');
        expect(overnightHint('12:00', '22:00', 'Sonntag')).toBeNull();
    });

    it('warnt, wenn das Fenster kürzer ist als ein Besuch', () => {
        expect(shortHint('12:00', '13:00', 90)).toBe('Kürzer als ein Besuch (90 Minuten): Hier finden Gäste keine Uhrzeit.');
        expect(shortHint('22:00', '01:00', 120)).toBeNull();
        expect(shortHint('12:00', '13:30', 90)).toBeNull();
    });

    it('lässt unvollständige und gleiche Uhrzeiten ohne Hinweis', () => {
        expect(overnightHint('', '02:00', 'Sonntag')).toBeNull();
        expect(overnightHint('22:00', '22:00', 'Sonntag')).toBeNull();
        expect(shortHint('12:00', '', 90)).toBeNull();
        expect(shortHint('12:00', '12:00', 90)).toBeNull();
        expect(shortHint('12:00', '13:00', Number.NaN)).toBeNull();
    });

    it('spricht im Salon von Terminen und Kunden', () => {
        expect(shortHint('12:00', '13:00', 90, true)).toBe('Kürzer als ein Termin (90 Minuten): Hier finden Kunden keine Uhrzeit.');
    });
});

describe('Auf andere Tage übertragen', () => {
    it('ersetzt die Fenster der Zieltage durch die des Ausgangstags', () => {
        document.body.innerHTML = `
            <form>
              <fieldset data-day="0" data-open="1"><div data-rows><div data-rows-list>
                <div data-row><input name="hours[a][opens_at]" value="11:00"><input name="hours[a][closes_at]" value="14:00"><input name="hours[a][service_name]" value="Mittag"></div>
              </div><template data-rows-template><div data-row><input type="hidden" name="hours[__INDEX__][weekday]" value="0"><input name="hours[__INDEX__][opens_at]"><input name="hours[__INDEX__][closes_at]"><input name="hours[__INDEX__][service_name]"></div></template></div></fieldset>
              <fieldset data-day="1" data-open="0"><div data-rows><div data-rows-list></div><template data-rows-template><div data-row><input type="hidden" name="hours[__INDEX__][weekday]" value="1"><input name="hours[__INDEX__][opens_at]"><input name="hours[__INDEX__][closes_at]"><input name="hours[__INDEX__][service_name]"></div></template></div></fieldset>
            </form>`;
        const form = document.querySelector('form');

        copyWindows(form, 0, [1]);

        const target = form.querySelector('[data-day="1"]');
        expect(target.dataset.open).toBe('1');
        expect([...target.querySelectorAll('[name$="[opens_at]"]')].map((i) => i.value)).toEqual(['11:00']);
        expect(target.querySelector('[name$="[weekday]"]').value).toBe('1');
    });
});

// --- Seite „Öffnungszeiten“ im Browser -----------------------------------------

const DAYS = ['Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag', 'Sonntag'];

const windowRow = (key, weekday, opens = '', closes = '', name = '') => `
    <div data-row>
        <input type="hidden" name="hours[${key}][weekday]" value="${weekday}">
        <input type="time" name="hours[${key}][opens_at]" value="${opens}" required>
        <input type="time" name="hours[${key}][closes_at]" value="${closes}" required>
        <input type="text" name="hours[${key}][service_name]" value="${name}">
        <button type="button" data-rows-remove>Zeitfenster entfernen</button>
        <p data-hint aria-live="polite"></p>
    </div>`;

// Ein Tag wie auf der Seite: offen, wenn er Zeilen hat.
function dayMarkup(weekday, rows) {
    const open = rows !== '';
    return `
        <fieldset data-day="${weekday}" data-day-name="${DAYS[weekday]}" data-open="${open ? 1 : 0}" data-change-item="day-${weekday}" data-change-state="${open ? 1 : 0}">
            <legend>${DAYS[weekday]} <span data-change-dot hidden>Geändert</span></legend>
            <input type="checkbox" id="open-${weekday}" data-day-open ${open ? 'checked' : ''}>
            <p data-closed-note ${open ? 'hidden' : ''}>Geschlossen</p>
            <div data-rows data-rows-max="3">
                <div data-rows-list ${open ? '' : 'hidden'}>${rows}</div>
                <template data-rows-template>${windowRow('__INDEX__', weekday)}</template>
                <div data-day-actions ${open ? '' : 'hidden'}>
                    <button type="button" data-rows-add>Zeitfenster hinzufügen</button>
                    <button type="button" data-drawer-open="tage-uebertragen" data-copy-open="${weekday}">Übertragen</button>
                </div>
            </div>
        </fieldset>`;
}

// Montag hat ein Fenster, alle anderen Tage sind geschlossen.
function page({ attributes = '', monday = windowRow(0, 0, '11:00', '14:00', 'Mittag') } = {}) {
    mount(`
        <div data-save-bar hidden data-count-one="1 Tag geändert" data-count-many=":count Tage geändert">
            <span data-save-bar-text>Ungespeicherte Änderungen</span><span data-save-bar-count></span>
            <button type="button" data-save-bar-discard>Verwerfen</button>
            <button type="button" data-save-bar-save>Speichern</button>
        </div>
        <form id="formHours" method="POST" action="/admin/settings/opening-hours" data-ajax data-savebar
              data-preview-url="/admin/settings/opening-hours/preview" data-preview-delay="400" data-duration="120" data-windows-max="3" ${attributes}>
            <input type="hidden" name="_token" value="token-123">
            <input type="hidden" name="hours_sent" value="1">
            <div role="status"><p data-week-empty hidden>Alle Tage sind geschlossen.</p></div>
            ${DAYS.map((_, weekday) => dayMarkup(weekday, weekday === 0 ? monday : '')).join('')}
            <section data-preview data-text-note="Vorschau für :day; gilt für die Zeiten im Formular, auch ungespeichert."
                     data-text-last="Letzte Uhrzeit :time" data-text-none="Keine Uhrzeit"
                     data-text-unavailable="Die Vorschau ist gerade nicht verfügbar. Deine Eingaben bleiben erhalten.">
                <p data-preview-note></p>
                <ul data-preview-times hidden></ul>
                <template data-preview-time-template><li></li></template>
                <p data-preview-reason></p>
                <ul data-preview-groups hidden></ul>
                <template data-preview-group-template><li><span data-group-label></span>: <span data-group-last></span></li></template>
            </section>
        </form>
        <div id="tage-uebertragen" data-drawer>
            <button type="button" data-drawer-close id="copy-close">Schließen</button>
            <p data-copy-source data-text-source="Von: :day"></p>
            <button type="button" data-copy-preset="0,1,2,3,4">Mo–Fr</button>
            <button type="button" data-copy-preset="0,1,2,3,4,5,6">Alle Tage</button>
            ${DAYS.map((name, weekday) => `<div data-copy-choice="${weekday}"><input type="checkbox" id="copy-${weekday}" data-copy-day="${weekday}"></div>`).join('')}
            <p data-copy-error hidden>Bitte mindestens einen Tag wählen.</p>
            <button type="button" data-copy-apply>Übertragen</button>
        </div>`);
}

const previewAnswer = (override = {}) => ({
    starts: ['11:00', '11:30'],
    last: '12:00',
    reason: 'Letzte Uhrzeit 12:00: Ein Besuch dauert 120 Minuten, um 14:00 wird geschlossen.',
    groups: [{ label: '6–20 Personen', last: null }, { label: '3–5 Personen', last: '11:30' }],
    hints: [],
    ...override,
});

const day = (weekday) => document.querySelector(`[data-day="${weekday}"]`);
const rowsIn = (weekday) => day(weekday).querySelectorAll('[data-rows-list] [data-row]');
const hintsIn = (weekday) => [...day(weekday).querySelectorAll('[data-hint]')].map((hint) => hint.textContent);
const formData = () => [...new FormData(document.getElementById('formHours')).keys()];

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeAll(() => {
    initAjaxForms();
});

describe('Tage öffnen und schließen', () => {
    it('öffnet einen geschlossenen Tag mit einer leeren Zeile, in die die Zeiten gehören', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();

        document.getElementById('open-1').click();

        expect(day(1).dataset.open).toBe('1');
        expect(day(1).dataset.changeState).toBe('1');
        expect(rowsIn(1)).toHaveLength(1);
        expect(rowsIn(1)[0].querySelector('[name$="[weekday]"]').value).toBe('1');
        expect(day(1).querySelector('[data-rows-list]').hidden).toBe(false);
        expect(day(1).querySelector('[data-day-actions]').hidden).toBe(false);
        expect(day(1).querySelector('[data-closed-note]').hidden).toBe(true);
        expect(document.activeElement).toBe(rowsIn(1)[0].querySelector('[name$="[opens_at]"]'));
    });

    it('schließt einen Tag: Felder gesperrt und verborgen, nichts davon wird gesendet', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        expect(formData()).toContain('hours[0][opens_at]');

        document.getElementById('open-0').click();

        expect(day(0).dataset.open).toBe('0');
        expect(day(0).dataset.changeState).toBe('0');
        expect(day(0).querySelector('[data-rows-list]').hidden).toBe(true);
        expect(day(0).querySelector('[data-day-actions]').hidden).toBe(true);
        expect(day(0).querySelector('[data-closed-note]').hidden).toBe(false);
        expect([...day(0).querySelectorAll('[data-rows-list] input')].every((input) => input.disabled)).toBe(true);
        expect(formData().filter((name) => name.startsWith('hours['))).toEqual([]);
        // Das Kennzeichen der Woche bleibt, damit eine leere Woche gespeichert werden kann.
        expect(formData()).toContain('hours_sent');
    });

    it('öffnet den Tag wieder mit den alten Zeiten', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();

        document.getElementById('open-0').click();
        document.getElementById('open-0').click();

        expect(rowsIn(0)).toHaveLength(1);
        expect(formData()).toContain('hours[0][opens_at]');
        expect(rowsIn(0)[0].querySelector('[name$="[opens_at]"]').disabled).toBe(false);
    });

    it('schaltet den Tag aus, wenn die letzte Zeile entfernt wird', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();

        rowsIn(0)[0].querySelector('[data-rows-remove]').click();
        await Promise.resolve();

        expect(rowsIn(0)).toHaveLength(0);
        expect(day(0).dataset.open).toBe('0');
        expect(document.getElementById('open-0').checked).toBe(false);
        expect(document.querySelector('[data-week-empty]').hidden).toBe(false);
        // Der Knopf, der den Fokus bekäme, ist mit dem Tag verschwunden: Der Fokus geht an den Schalter.
        expect(document.activeElement).toBe(document.getElementById('open-0'));
    });

    it('hält die Warnung bei einer leeren Woche aktuell', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        const warning = document.querySelector('[data-week-empty]');
        expect(warning.hidden).toBe(true);

        document.getElementById('open-0').click();
        expect(warning.hidden).toBe(false);

        document.getElementById('open-3').click();
        expect(warning.hidden).toBe(true);
    });

    it('stellt Schalter, Felder und Hinweise nach „Verwerfen“ wieder her', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        initSaveBar();
        document.getElementById('open-0').click();
        document.getElementById('open-2').click();

        document.querySelector('[data-save-bar-discard]').click();
        await Promise.resolve();

        expect(day(0).dataset.open).toBe('1');
        expect(document.getElementById('open-0').checked).toBe(true);
        expect(rowsIn(0)).toHaveLength(1);
        expect(rowsIn(0)[0].querySelector('[name$="[opens_at]"]').disabled).toBe(false);
        expect(day(2).dataset.open).toBe('0');
        expect(rowsIn(2)).toHaveLength(0);
        expect(document.querySelector('[data-week-empty]').hidden).toBe(true);
    });

    it('zählt einen geschlossenen Tag in der Speicherleiste als Änderung', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        initSaveBar();
        const bar = document.querySelector('[data-save-bar]');
        expect(bar.hidden).toBe(true);

        document.getElementById('open-0').click();

        expect(bar.hidden).toBe(false);
        expect(bar.querySelector('[data-save-bar-count]').textContent).toBe(' · 1 Tag geändert');
        expect(day(0).hasAttribute('data-changed')).toBe(true);
        expect(day(0).querySelector('[data-change-dot]').hidden).toBe(false);

        document.getElementById('open-0').click();
        expect(bar.hidden).toBe(true);
    });
});

describe('Hinweise am Zeitfenster auf der Seite', () => {
    it('zeigt beim Tippen den Folgetag und die zu kurze Zeit', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        const [opens, closes] = rowsIn(0)[0].querySelectorAll('input[type="time"]');

        type(opens, '22:00');
        type(closes, '02:00');
        expect(hintsIn(0)).toEqual(['Endet am Folgetag (Dienstag) um 02:00.']);

        type(opens, '12:00');
        type(closes, '13:00');
        expect(hintsIn(0)).toEqual(['Kürzer als ein Besuch (120 Minuten): Hier finden Gäste keine Uhrzeit.']);

        type(opens, '22:00');
        type(closes, '23:00');
        expect(hintsIn(0)).toEqual(['Kürzer als ein Besuch (120 Minuten): Hier finden Gäste keine Uhrzeit.']);

        type(opens, '18:00');
        type(closes, '22:00');
        expect(hintsIn(0)).toEqual(['']);
    });

    it('nennt den Montag als Folgetag des Sonntags', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        document.getElementById('open-6').click();
        const [opens, closes] = rowsIn(6)[0].querySelectorAll('input[type="time"]');

        type(opens, '22:00');
        type(closes, '03:00');

        expect(hintsIn(6)).toEqual(['Endet am Folgetag (Montag) um 03:00.']);
    });

    it('spricht im Salon von Terminen und Kunden', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page({ attributes: 'data-salon' });
        initRows();
        initHours();
        const [opens, closes] = rowsIn(0)[0].querySelectorAll('input[type="time"]');

        type(opens, '12:00');
        type(closes, '13:00');

        expect(hintsIn(0)).toEqual(['Kürzer als ein Termin (120 Minuten): Hier finden Kunden keine Uhrzeit.']);
    });

    it('setzt Hinweise für Zeilen, die schon beim Laden zu kurz sind', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page({ monday: windowRow(0, 0, '12:00', '13:00') });
        initRows();
        initHours();

        expect(hintsIn(0)).toEqual(['Kürzer als ein Besuch (120 Minuten): Hier finden Gäste keine Uhrzeit.']);
    });
});

describe('Vorschau', () => {
    it('rechnet beim Laden für den ersten offenen Tag und zeigt Uhrzeiten, Satz und Gruppen', async () => {
        const fetchMock = vi.fn(async () => jsonResponse(previewAnswer()));
        vi.stubGlobal('fetch', fetchMock);
        page();
        initRows();
        initHours();

        await vi.waitFor(() => expect(document.querySelector('[data-preview-reason]').textContent).toContain('Letzte Uhrzeit 12:00'));

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/admin/settings/opening-hours/preview');
        expect(init.method).toBe('POST');
        expect(init.headers['X-CSRF-TOKEN']).toBe('token-123');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(JSON.parse(init.body)).toEqual({ weekday: 0, windows: [{ opens_at: '11:00', closes_at: '14:00' }] });
        expect(document.querySelector('[data-preview-note]').textContent)
            .toBe('Vorschau für Montag; gilt für die Zeiten im Formular, auch ungespeichert.');
        const times = document.querySelector('[data-preview-times]');
        expect(times.hidden).toBe(false);
        expect([...times.children].map((item) => item.textContent)).toEqual(['11:00', '11:30']);
        const groups = document.querySelector('[data-preview-groups]');
        expect(groups.hidden).toBe(false);
        expect([...groups.children].map((item) => item.textContent)).toEqual(['6–20 Personen: Keine Uhrzeit', '3–5 Personen: Letzte Uhrzeit 11:30']);
    });

    it('wartet nach der letzten Eingabe und fragt dann einmal', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse(previewAnswer()));
        vi.stubGlobal('fetch', fetchMock);
        page();
        initRows();
        initHours();
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        const closes = rowsIn(0)[0].querySelector('[name$="[closes_at]"]');

        type(closes, '15:00');
        await vi.advanceTimersByTimeAsync(300);
        type(closes, '16:00');
        await vi.advanceTimersByTimeAsync(300);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(100);

        expect(fetchMock).toHaveBeenCalledTimes(2);
        expect(JSON.parse(fetchMock.mock.calls[1][1].body).windows).toEqual([{ opens_at: '11:00', closes_at: '16:00' }]);
    });

    it('fragt nicht mehr, wenn das Formular von der Seite genommen wurde', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse(previewAnswer()));
        vi.stubGlobal('fetch', fetchMock);
        page();
        initRows();
        initHours();
        await vi.advanceTimersByTimeAsync(0);
        type(rowsIn(0)[0].querySelector('[name$="[closes_at]"]'), '15:00');

        document.body.replaceChildren();
        await vi.advanceTimersByTimeAsync(400);

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });

    it('nimmt die Wartezeit aus dem Formular', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse(previewAnswer()));
        vi.stubGlobal('fetch', fetchMock);
        page();
        document.getElementById('formHours').setAttribute('data-preview-delay', '900');
        initRows();
        initHours();
        await vi.advanceTimersByTimeAsync(0);

        type(rowsIn(0)[0].querySelector('[name$="[closes_at]"]'), '15:00');
        await vi.advanceTimersByTimeAsync(800);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        await vi.advanceTimersByTimeAsync(100);

        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('sendet nur vollständige Fenster des zuletzt bearbeiteten Tages', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse(previewAnswer()));
        vi.stubGlobal('fetch', fetchMock);
        page();
        initRows();
        initHours();
        await vi.advanceTimersByTimeAsync(0);

        document.getElementById('open-2').click();
        const [opens] = rowsIn(2)[0].querySelectorAll('input[type="time"]');
        type(opens, '09:00');
        await vi.advanceTimersByTimeAsync(400);
        expect(JSON.parse(fetchMock.mock.calls.at(-1)[1].body)).toEqual({ weekday: 2, windows: [] });

        type(rowsIn(2)[0].querySelector('[name$="[closes_at]"]'), '12:00');
        await vi.advanceTimersByTimeAsync(400);
        expect(JSON.parse(fetchMock.mock.calls.at(-1)[1].body)).toEqual({ weekday: 2, windows: [{ opens_at: '09:00', closes_at: '12:00' }] });
    });

    it('wechselt mit dem Fokus zu einem anderen Tag', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse(previewAnswer()));
        vi.stubGlobal('fetch', fetchMock);
        page();
        initRows();
        initHours();
        document.getElementById('open-4').click();
        await vi.advanceTimersByTimeAsync(400);
        const before = fetchMock.mock.calls.length;

        rowsIn(0)[0].querySelector('[name$="[opens_at]"]').dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(400);

        expect(fetchMock).toHaveBeenCalledTimes(before + 1);
        expect(JSON.parse(fetchMock.mock.calls.at(-1)[1].body).weekday).toBe(0);
        // Derselbe Tag fragt bei erneutem Fokus nicht noch einmal.
        rowsIn(0)[0].querySelector('[name$="[closes_at]"]').dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(400);
        expect(fetchMock).toHaveBeenCalledTimes(before + 1);
    });

    it('bricht eine laufende Anfrage ab, wenn eine neue folgt, und zeigt nur die neueste Antwort', async () => {
        vi.useFakeTimers();
        const resolvers = [];
        const signals = [];
        vi.stubGlobal('fetch', vi.fn((url, init) => new Promise((resolve) => {
            signals.push(init.signal);
            resolvers.push(resolve);
        })));
        page();
        initRows();
        initHours();
        type(rowsIn(0)[0].querySelector('[name$="[closes_at]"]'), '15:00');
        await vi.advanceTimersByTimeAsync(400);
        expect(signals).toHaveLength(2);
        expect(signals[0].aborted).toBe(true);
        expect(signals[1].aborted).toBe(false);

        resolvers[1](jsonResponse(previewAnswer({ reason: 'Neu.' })));
        await vi.advanceTimersByTimeAsync(0);
        resolvers[0](jsonResponse(previewAnswer({ reason: 'Alt.' })));
        await vi.advanceTimersByTimeAsync(0);

        expect(document.querySelector('[data-preview-reason]').textContent).toBe('Neu.');
    });

    it('zeigt bei einer Ablehnung des Servers dessen Grund', async () => {
        const reason = 'Dieses Zeitfenster überschneidet sich mit 11:00–15:00 am selben Tag. Bitte die Zeiten anpassen oder die Fenster zusammenlegen.';
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
            message: 'Dieses Zeitfenster überschneidet sich.',
            errors: { 'windows.1.opens_at': [reason] },
        }, 422)));
        page();
        initRows();
        initHours();

        await vi.waitFor(() => expect(document.querySelector('[data-preview-reason]').textContent).toBe(reason));
        expect(document.querySelector('[data-preview-times]').hidden).toBe(true);
        expect(document.querySelector('[data-preview-groups]').hidden).toBe(true);
    });

    it('sagt bei Serverfehler und ohne Verbindung, dass die Vorschau fehlt und die Eingaben bleiben', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => new Response('<html></html>', { status: 500, headers: { 'Content-Type': 'text/html' } })));
        page();
        initRows();
        initHours();
        const unavailable = 'Die Vorschau ist gerade nicht verfügbar. Deine Eingaben bleiben erhalten.';

        await vi.waitFor(() => expect(document.querySelector('[data-preview-reason]').textContent).toBe(unavailable));

        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        }));
        document.querySelector('[data-preview-reason]').textContent = '';
        type(rowsIn(0)[0].querySelector('[name$="[closes_at]"]'), '15:00');
        await vi.waitFor(() => expect(document.querySelector('[data-preview-reason]').textContent).toBe(unavailable));
        expect(rowsIn(0)[0].querySelector('[name$="[opens_at]"]').value).toBe('11:00');
    });
});

describe('Seitenleiste „Auf andere Tage übertragen“', () => {
    it('merkt sich den Ausgangstag, sperrt ihn in der Auswahl und wählt per Schnellwahl', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();

        document.querySelector('[data-copy-open="0"]').click();
        expect(document.getElementById('copy-0').disabled).toBe(true);
        expect(document.querySelector('[data-copy-choice="0"]').hidden).toBe(true);
        expect(document.querySelector('[data-copy-source]').textContent).toBe('Von: Montag');

        document.querySelector('[data-copy-preset="0,1,2,3,4"]').click();
        expect([...document.querySelectorAll('[data-copy-day]')].map((box) => box.checked)).toEqual([false, true, true, true, true, false, false]);

        document.querySelector('[data-copy-preset="0,1,2,3,4,5,6"]').click();
        expect([...document.querySelectorAll('[data-copy-day]')].map((box) => box.checked)).toEqual([false, true, true, true, true, true, true]);
    });

    it('überträgt die Fenster auf die gewählten Tage, meldet die Änderung und schließt die Seitenleiste', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        initSaveBar();
        const closed = vi.fn();
        document.getElementById('copy-close').addEventListener('click', closed);
        document.querySelector('[data-copy-open="0"]').click();
        document.getElementById('copy-2').checked = true;
        document.getElementById('copy-5').checked = true;

        document.querySelector('[data-copy-apply]').click();

        [2, 5].forEach((weekday) => {
            expect(day(weekday).dataset.open).toBe('1');
            expect(rowsIn(weekday)).toHaveLength(1);
            expect(rowsIn(weekday)[0].querySelector('[name$="[opens_at]"]').value).toBe('11:00');
            expect(rowsIn(weekday)[0].querySelector('[name$="[closes_at]"]').value).toBe('14:00');
            expect(rowsIn(weekday)[0].querySelector('[name$="[service_name]"]').value).toBe('Mittag');
            expect(rowsIn(weekday)[0].querySelector('[name$="[weekday]"]').value).toBe(String(weekday));
        });
        expect(day(1).dataset.open).toBe('0');
        const keys = [...document.querySelectorAll('[name$="[opens_at]"]')].map((input) => input.name);
        expect(new Set(keys).size).toBe(keys.length);
        expect(document.querySelector('[data-save-bar]').hidden).toBe(false);
        expect(document.querySelector('[data-save-bar-count]').textContent).toBe(' · 2 Tage geändert');
        expect(closed).toHaveBeenCalledTimes(1);
    });

    it('verlangt mindestens einen Tag und lässt die Seitenleiste offen', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        const closed = vi.fn();
        document.getElementById('copy-close').addEventListener('click', closed);
        document.querySelector('[data-copy-open="0"]').click();

        document.querySelector('[data-copy-apply]').click();

        expect(document.querySelector('[data-copy-error]').hidden).toBe(false);
        expect(closed).not.toHaveBeenCalled();
        expect(day(1).dataset.open).toBe('0');

        document.getElementById('copy-1').checked = true;
        document.querySelector('[data-copy-apply]').click();
        expect(document.querySelector('[data-copy-error]').hidden).toBe(true);
        expect(day(1).dataset.open).toBe('1');
    });

    it('beginnt bei jedem Öffnen ohne Haken und mit dem neuen Ausgangstag', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();
        document.querySelector('[data-copy-open="0"]').click();
        document.getElementById('copy-3').checked = true;

        document.querySelector('[data-copy-open="1"]').click();

        expect(document.getElementById('copy-3').checked).toBe(false);
        expect(document.getElementById('copy-1').disabled).toBe(true);
        expect(document.getElementById('copy-0').disabled).toBe(false);
        expect(document.querySelector('[data-copy-source]').textContent).toBe('Von: Dienstag');
    });

    it('überträgt höchstens so viele Fenster, wie ein Tag haben darf', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page({ monday: windowRow(0, 0, '08:00', '10:00') + windowRow(1, 0, '11:00', '14:00') + windowRow(2, 0, '17:00', '22:00') });
        initRows();
        initHours();
        const form = document.getElementById('formHours');

        form.dataset.windowsMax = '2';
        copyWindows(form, 0, [3]);

        expect([...rowsIn(3)].map((row) => row.querySelector('[name$="[opens_at]"]').value)).toEqual(['08:00', '11:00']);
    });

    it('überträgt einen geschlossenen Tag als geschlossen', () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse(previewAnswer())));
        page();
        initRows();
        initHours();

        copyWindows(document.getElementById('formHours'), 1, [0]);

        expect(day(0).dataset.open).toBe('0');
        expect(rowsIn(0)).toHaveLength(0);
    });
});
