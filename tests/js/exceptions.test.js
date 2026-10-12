import { describe, expect, it, vi } from 'vitest';
import { initExceptions, syncEffects } from '../../resources/js/admin/exceptions.js';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initHours } from '../../resources/js/admin/hours.js';
import { initReveals } from '../../resources/js/admin/reveal.js';
import { initRows } from '../../resources/js/admin/rows.js';
import { jsonResponse, mount } from './helpers.js';

function form(checked) {
    document.body.innerHTML = `<form>${['closed', 'hours', 'limit', 'lock'].map((e) => `<input type="checkbox" name="effects[]" value="${e}" ${checked.includes(e) ? 'checked' : ''}>`).join('')}</form>`;
    return document.querySelector('form');
}

describe('Auswahlkarten „Was ist anders?“', () => {
    it('„Geschlossen“ wählt die anderen ab', () => {
        const f = form(['hours', 'limit']);
        const closed = f.querySelector('[value="closed"]');
        closed.checked = true;
        syncEffects(f, closed);
        expect([...f.querySelectorAll('[name="effects[]"]:checked')].map((i) => i.value)).toEqual(['closed']);
    });

    it('eine andere Wirkung wählt „Geschlossen“ ab', () => {
        const f = form(['closed']);
        const lock = f.querySelector('[value="lock"]');
        lock.checked = true;
        syncEffects(f, lock);
        expect([...f.querySelectorAll('[name="effects[]"]:checked')].map((i) => i.value)).toEqual(['lock']);
    });

    it('lässt mehrere andere Wirkungen nebeneinander stehen', () => {
        const f = form(['hours']);
        const limit = f.querySelector('[value="limit"]');
        limit.checked = true;
        syncEffects(f, limit);
        expect([...f.querySelectorAll('[name="effects[]"]:checked')].map((i) => i.value)).toEqual(['hours', 'limit']);
    });

    it('meldet die Änderung am Formular, damit die Abschnitte nachziehen', () => {
        const f = form(['hours']);
        const seen = vi.fn();
        f.addEventListener('input', seen);
        const closed = f.querySelector('[value="closed"]');
        closed.checked = true;
        syncEffects(f, closed);
        expect(seen).toHaveBeenCalledTimes(1);
    });

    it('zeigt die Abschnitte aller gewählten Wirkungen', () => {
        mount(`
            <form>
                <input type="checkbox" name="effects[]" value="hours" checked>
                <input type="checkbox" name="effects[]" value="limit" checked>
                <div id="hours" data-show-if="effects[]:hours">Zeiten</div>
                <div id="limit" data-show-if="effects[]:limit">Grenze</div>
                <div id="lock" data-show-if="effects[]:lock">Sperre</div>
            </form>`);
        initReveals();
        expect(document.getElementById('hours').hidden).toBe(false);
        expect(document.getElementById('limit').hidden).toBe(false);
        expect(document.getElementById('lock').hidden).toBe(true);
    });
});

// --- Vorschau „So wirkt es“ ---------------------------------------------------

const TEXTS = {
    start: 'Wähle Tage und was anders ist; hier steht dann, was Gäste erleben.',
    incomplete: 'Noch unvollständig: :message',
    unavailable: 'Die Vorschau ist gerade nicht verfügbar. Speichern klappt trotzdem.',
};

// Eine Seitenleiste wie auf der Seite: Formular im Inhalt, Satz im Fuß.
function drawer({ hidden = true, from = '', effects = [], method = 'PUT', key = 'g-abc' } = {}) {
    mount(`
        <div id="ausnahme-x" data-drawer ${hidden ? 'hidden' : ''}>
            <form id="form-x" method="POST" action="/admin/settings/exceptions/${key}" data-preview-url="/admin/settings/exceptions/preview" data-preview-delay="400">
                <input type="hidden" name="_token" value="token-123">
                ${method === 'PUT' ? '<input type="hidden" name="_method" value="PUT"><input type="hidden" name="key" value="' + key + '">' : ''}
                <input type="date" name="from" value="${from}">
                <input type="date" name="until" value="">
                ${['closed', 'hours', 'limit', 'lock'].map((e) => `<input type="checkbox" name="effects[]" value="${e}" ${effects.includes(e) ? 'checked' : ''}>`).join('')}
                <input type="text" name="label" value="">
            </form>
            <div data-effect-sentence aria-live="polite"
                 data-text-start="${TEXTS.start}" data-text-incomplete="${TEXTS.incomplete}" data-text-unavailable="${TEXTS.unavailable}">${TEXTS.start}</div>
        </div>`);
    return document.getElementById('form-x');
}

const sentence = () => document.querySelector('[data-effect-sentence]').textContent;

function type(input, value) {
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

describe('Vorschau', () => {
    it('rechnet nach der Wartezeit und zeigt den Satz als Text', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Am 24.12.2026 kann <b>niemand</b> online buchen.' }));
        vi.stubGlobal('fetch', fetchMock);
        const f = drawer({ hidden: false, effects: ['closed'] });
        initExceptions();
        fetchMock.mockClear();

        type(f.elements.from, '2026-12-24');
        await vi.advanceTimersByTimeAsync(399);
        expect(fetchMock).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, options] = fetchMock.mock.calls[0];
        expect(url).toBe('/admin/settings/exceptions/preview');
        expect(options.method).toBe('POST');
        expect(options.headers.Accept).toBe('application/json');
        expect(options.headers['X-CSRF-TOKEN']).toBe('token-123');
        expect(options.credentials).toBe('same-origin');
        expect(sentence()).toBe('Am 24.12.2026 kann <b>niemand</b> online buchen.');
        expect(document.querySelector('[data-effect-sentence] b')).toBeNull();
    });

    it('sendet die Felder und den Schlüssel als POST an die Vorschau-Route', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Satz.' }));
        vi.stubGlobal('fetch', fetchMock);
        const f = drawer({ hidden: false, effects: ['hours'], from: '2026-12-24' });
        initExceptions();
        await vi.advanceTimersByTimeAsync(0);

        const body = fetchMock.mock.calls.at(-1)[1].body;
        expect(body).toBeInstanceOf(FormData);
        expect(body.get('from')).toBe('2026-12-24');
        expect(body.getAll('effects[]')).toEqual(['hours']);
        expect(body.get('key')).toBe('g-abc');
        expect(body.get('_token')).toBe('token-123');
        // Mit _method=PUT käme die Anfrage bei der Vorschau-Route als PUT an.
        expect(body.has('_method')).toBe(false);
        expect(f.elements._method.value).toBe('PUT');
    });

    it('zeigt bei 422 die erste Meldung des Servers', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
            message: 'Fehler',
            errors: { lock_rooms: ['Bitte mindestens einen Raum oder Tisch wählen.'], from: ['x'] },
        }, 422)));
        const f = drawer({ hidden: false, effects: ['lock'], from: '2026-12-24' });
        initExceptions();

        type(f.elements.label, 'Feier');
        await vi.advanceTimersByTimeAsync(400);

        expect(sentence()).toBe('Noch unvollständig: Bitte mindestens einen Raum oder Tisch wählen.');
    });

    it('nennt bei einem Netzwerkfehler, dass Speichern trotzdem klappt', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        }));
        const f = drawer({ hidden: false, effects: ['closed'], from: '2026-12-24' });
        initExceptions();

        type(f.elements.label, 'Feier');
        await vi.advanceTimersByTimeAsync(400);

        expect(sentence()).toBe(TEXTS.unavailable);
    });

    it('nennt auch bei einer Antwort ohne JSON, dass die Vorschau fehlt', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn(async () => new Response('<html></html>', { status: 502, headers: { 'Content-Type': 'text/html' } })));
        const f = drawer({ hidden: false, effects: ['closed'], from: '2026-12-24' });
        initExceptions();

        type(f.elements.label, 'Feier');
        await vi.advanceTimersByTimeAsync(400);

        expect(sentence()).toBe(TEXTS.unavailable);
    });

    it('bricht die laufende Anfrage ab, wenn eine neue beginnt, und zeigt nur die neueste Antwort', async () => {
        vi.useFakeTimers();
        const signals = [];
        const resolvers = [];
        vi.stubGlobal('fetch', vi.fn((url, options) => {
            signals.push(options.signal);
            return new Promise((resolve, reject) => {
                options.signal.addEventListener('abort', () => reject(new DOMException('abgebrochen', 'AbortError')));
                resolvers.push(() => resolve(jsonResponse({ sentence: `Satz ${signals.length}.` })));
            });
        }));
        const f = drawer({ hidden: false, effects: ['closed'], from: '2026-12-24' });
        initExceptions();
        await vi.advanceTimersByTimeAsync(0);
        expect(signals).toHaveLength(1);

        type(f.elements.label, 'A');
        await vi.advanceTimersByTimeAsync(400);

        expect(signals).toHaveLength(2);
        expect(signals[0].aborted).toBe(true);
        expect(signals[1].aborted).toBe(false);
        resolvers[1]();
        await vi.advanceTimersByTimeAsync(0);
        expect(sentence()).toBe('Satz 2.');
        // Der Abbruch der ersten Anfrage ist keine Störung.
        expect(sentence()).not.toBe(TEXTS.unavailable);
    });

    it('rechnet beim Öffnen der Seitenleiste einmal', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Satz beim Öffnen.' }));
        vi.stubGlobal('fetch', fetchMock);
        drawer({ hidden: true, effects: ['closed'], from: '2026-12-24' });
        initExceptions();
        await vi.advanceTimersByTimeAsync(0);
        expect(fetchMock).not.toHaveBeenCalled();

        document.getElementById('ausnahme-x').hidden = false;
        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(sentence()).toBe('Satz beim Öffnen.');
    });

    it('rechnet beim Start, wenn die Seitenleiste schon offen ist', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Offen.' }));
        vi.stubGlobal('fetch', fetchMock);
        drawer({ hidden: false, effects: ['closed'], from: '2026-12-24' });

        initExceptions();
        await vi.advanceTimersByTimeAsync(0);

        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(sentence()).toBe('Offen.');
    });

    it('bleibt bei einem leeren Formular beim Anfangstext', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Sollte nicht erscheinen.' }));
        vi.stubGlobal('fetch', fetchMock);
        const f = drawer({ hidden: false, method: 'POST' });
        initExceptions();
        await vi.advanceTimersByTimeAsync(0);

        type(f.elements.label, '');
        await vi.advanceTimersByTimeAsync(400);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(sentence()).toBe(TEXTS.start);
    });

    it('kehrt zum Anfangstext zurück, wenn alles wieder leer ist', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ sentence: 'Ein Satz.' })));
        const f = drawer({ hidden: false, method: 'POST' });
        initExceptions();

        type(f.elements.from, '2026-12-24');
        f.querySelector('[value="closed"]').checked = true;
        f.querySelector('[value="closed"]').dispatchEvent(new Event('change', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(400);
        expect(sentence()).toBe('Ein Satz.');

        type(f.elements.from, '');
        f.querySelector('[value="closed"]').checked = false;
        f.querySelector('[value="closed"]').dispatchEvent(new Event('change', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(400);

        expect(sentence()).toBe(TEXTS.start);
    });

    it('gleicht die Auswahlkarten beim Anhaken ab und rechnet danach', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Satz.' }));
        vi.stubGlobal('fetch', fetchMock);
        const f = drawer({ hidden: false, effects: ['hours', 'limit'], from: '2026-12-24' });
        initExceptions();
        await vi.advanceTimersByTimeAsync(0);
        fetchMock.mockClear();

        const closed = f.querySelector('[value="closed"]');
        closed.checked = true;
        closed.dispatchEvent(new Event('change', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(400);

        expect([...f.querySelectorAll('[name="effects[]"]:checked')].map((i) => i.value)).toEqual(['closed']);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][1].body.getAll('effects[]')).toEqual(['closed']);
    });

    it('wartet auf den ersten Tag, auch wenn schon eine Wirkung gewählt ist', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Sollte nicht erscheinen.' }));
        vi.stubGlobal('fetch', fetchMock);
        const f = drawer({ hidden: false, method: 'POST', effects: ['lock'] });
        initExceptions();

        type(f.elements.label, 'Feier');
        await vi.advanceTimersByTimeAsync(400);

        expect(fetchMock).not.toHaveBeenCalled();
        expect(sentence()).toBe(TEXTS.start);
    });

    it('zeigt die Meldung des Servers, wenn der Eintrag inzwischen gelöscht ist', async () => {
        vi.useFakeTimers();
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Diese Ausnahme gibt es nicht mehr. Bitte die Seite neu laden.' }, 404)));
        const f = drawer({ hidden: false, effects: ['closed'], from: '2026-12-24' });
        initExceptions();

        type(f.elements.label, 'Feier');
        await vi.advanceTimersByTimeAsync(400);

        expect(sentence()).toBe('Diese Ausnahme gibt es nicht mehr. Bitte die Seite neu laden.');
    });

    it('fragt nicht, solange die Seitenleiste geschlossen ist', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Satz.' }));
        vi.stubGlobal('fetch', fetchMock);
        const f = drawer({ hidden: true, effects: ['closed'], from: '2026-12-24' });
        initExceptions();

        // Das Zurücksetzen beim Schließen meldet ein input-Ereignis (hours.js); die Vorschau bleibt still.
        f.dispatchEvent(new Event('input', { bubbles: true }));
        await vi.advanceTimersByTimeAsync(1000);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('teilt sich die Seite mit der Vorschau der Öffnungszeiten: Nur die Ausnahmen fragen den Server', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Satz.' }));
        vi.stubGlobal('fetch', fetchMock);
        const f = drawer({ hidden: false, effects: ['closed'], from: '2026-12-24' });
        initAjaxForms();
        initRows();
        initHours();
        initExceptions();
        await vi.advanceTimersByTimeAsync(0);
        fetchMock.mockClear();

        type(f.elements.label, 'Feier');
        f.dispatchEvent(new Event('reset'));
        await vi.advanceTimersByTimeAsync(400);

        // Nur die Anfrage der Ausnahmen: FormData an die Vorschau-Adresse, keine JSON-Anfrage mit Wochentag.
        const urls = fetchMock.mock.calls.map(([url]) => url);
        expect(new Set(urls)).toEqual(new Set(['/admin/settings/exceptions/preview']));
        expect(fetchMock.mock.calls.every(([, options]) => options.body instanceof FormData)).toBe(true);
    });

    it('ignoriert Eingaben außerhalb der Formulare mit Vorschau', async () => {
        vi.useFakeTimers();
        const fetchMock = vi.fn(async () => jsonResponse({ sentence: 'Satz.' }));
        vi.stubGlobal('fetch', fetchMock);
        drawer({ hidden: true });
        document.body.insertAdjacentHTML('beforeend', '<input id="other" type="text">');
        initExceptions();

        type(document.getElementById('other'), 'x');
        await vi.advanceTimersByTimeAsync(1000);

        expect(fetchMock).not.toHaveBeenCalled();
    });
});
