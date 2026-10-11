import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initSaveBar } from '../../resources/js/admin/savebar.js';
import { jsonResponse, mount, setWide } from './helpers.js';

const bar = `
    <div data-save-bar hidden data-count-one="1 Einstellung geändert" data-count-many=":count Einstellungen geändert">
        <span data-save-bar-text>Ungespeicherte Änderungen</span><span data-save-bar-count></span>
        <button type="button" data-save-bar-discard>Verwerfen</button>
        <button type="button" data-save-bar-save>Speichern</button>
    </div>
    <p data-save-bar-status></p>`;

const page = `
    <div data-page-header><h1>Allgemein</h1><div data-page-header-controls><select id="scope"><option>Mitte</option></select></div></div>
    ${bar}
    <form id="g" method="POST" action="/admin/settings/general" data-ajax data-savebar>
        <input id="f-name" name="name" value="Alt">
    </form>`;

const counted = `
    <div data-page-header><h1>Kapazität &amp; Dauer</h1></div>
    ${bar}
    <form id="k" method="POST" action="/admin/settings/capacity" data-ajax data-savebar>
        <input id="f-buffer" name="buffer_minutes" value="0">
        <input id="f-duration" name="default_duration_minutes" value="120">
        <div data-change-item="rule-0">
            <input id="r0-min" name="duration_rules[0][min_party]" value="1">
            <input id="r0-max" name="duration_rules[0][max_party]" value="4">
            <span data-change-dot hidden></span>
        </div>
    </form>`;

function type(id, value) {
    const input = document.getElementById(id);
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

const counter = () => document.querySelector('[data-save-bar-count]').textContent;

beforeAll(() => initAjaxForms());

describe('Speicherleiste', () => {
    it('erscheint bei Änderungen und sperrt ab 768 px Standortwahl und Aktionen', () => {
        setWide(true);
        mount(page);
        initSaveBar();

        type('f-name', 'Neu');

        expect(document.querySelector('[data-save-bar]').hidden).toBe(false);
        expect(document.querySelector('[data-page-header-controls]').hasAttribute('inert')).toBe(true);
        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(false);
        expect(document.getElementById('g').hasAttribute('data-dirty')).toBe(true);
    });

    it('lässt den Seitenkopf unter 768 px bedienbar', () => {
        setWide(false);
        mount(page);
        initSaveBar();

        type('f-name', 'Neu');

        expect(document.querySelector('[data-page-header-controls]').hasAttribute('inert')).toBe(false);
    });

    it('gibt den Seitenkopf nach Verwerfen frei und meldet swayy:discarded am Dokument', () => {
        setWide(true);
        mount(page);
        initSaveBar();
        const discarded = vi.fn();
        document.addEventListener('swayy:discarded', discarded, { once: true });

        type('f-name', 'Neu');
        document.querySelector('[data-save-bar-discard]').click();

        expect(document.getElementById('f-name').value).toBe('Alt');
        expect(document.querySelector('[data-save-bar]').hidden).toBe(true);
        expect(document.querySelector('[data-page-header-controls]').hasAttribute('inert')).toBe(false);
        expect(discarded).toHaveBeenCalledTimes(1);
    });

    it('zählt geänderte Felder und Zeilen und zeigt den Punkt an der Zeile', () => {
        mount(counted);
        initSaveBar();

        type('f-buffer', '15');
        expect(counter()).toBe(' · 1 Einstellung geändert');

        type('r0-min', '2');
        type('r0-max', '6');
        expect(counter()).toBe(' · 2 Einstellungen geändert');
        const row = document.querySelector('[data-change-item]');
        expect(row.hasAttribute('data-changed')).toBe(true);
        expect(row.querySelector('[data-change-dot]').hidden).toBe(false);
    });

    it('zählt hinzugefügte und entfernte Zeilen mit', () => {
        mount(counted);
        initSaveBar();
        const form = document.getElementById('k');

        document.querySelector('[data-change-item="rule-0"]').remove();
        form.dispatchEvent(new Event('input', { bubbles: true }));
        expect(counter()).toBe(' · 1 Einstellung geändert');

        form.insertAdjacentHTML('beforeend', '<div data-change-item="rule-n1"><input name="duration_rules[n1][min_party]" value="7"></div>');
        form.dispatchEvent(new Event('input', { bubbles: true }));
        expect(counter()).toBe(' · 2 Einstellungen geändert');
    });

    it('zählt den Zustand einer Zeile außerhalb ihrer Felder (data-change-state)', () => {
        mount(`${bar}
            <form id="w" method="POST" action="/admin/settings/opening-hours" data-ajax data-savebar>
                <fieldset data-change-item="day-0" data-change-state="1">
                    <input id="d0-opens" name="hours[0][opens_at]" value="11:00">
                    <span data-change-dot hidden></span>
                </fieldset>
            </form>`);
        initSaveBar();
        const form = document.getElementById('w');
        const day = form.querySelector('[data-change-item]');

        // Ein geschlossener Tag sperrt seine Felder (die Leiste erscheint) und meldet seinen Zustand für die Zählung.
        day.dataset.changeState = '0';
        document.getElementById('d0-opens').disabled = true;
        form.dispatchEvent(new Event('input', { bubbles: true }));
        expect(counter()).toBe(' · 1 Einstellung geändert');
        expect(day.hasAttribute('data-changed')).toBe(true);
        expect(day.querySelector('[data-change-dot]').hidden).toBe(false);

        day.dataset.changeState = '1';
        document.getElementById('d0-opens').disabled = false;
        form.dispatchEvent(new Event('input', { bubbles: true }));
        expect(counter()).toBe('');
        expect(day.hasAttribute('data-changed')).toBe(false);
    });

    it('setzt Zähler und Punkt beim Verwerfen zurück', () => {
        mount(counted);
        initSaveBar();
        type('r0-min', '2');

        document.querySelector('[data-save-bar-discard]').click();

        expect(counter()).toBe('');
        expect(document.querySelector('[data-change-item]').hasAttribute('data-changed')).toBe(false);
        expect(document.querySelector('[data-change-dot]').hidden).toBe(true);
    });

    it('zählt nach dem Speichern nur, was während der Anfrage dazukam', async () => {
        let resolve;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((r) => { resolve = r; })));
        mount(counted);
        initSaveBar();

        type('f-buffer', '15');
        document.querySelector('[data-save-bar-save]').click();
        await vi.waitFor(() => expect(resolve).toBeTypeOf('function'));
        type('f-duration', '90');
        resolve(jsonResponse({ message: 'Gespeichert.' }));

        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        await vi.waitFor(() => expect(counter()).toBe(' · 1 Einstellung geändert'));
        expect(document.querySelector('[data-save-bar]').hidden).toBe(false);
    });

    describe('bei einem ungültigen Wert', () => {
        const hiddenField = `
            ${bar}
            <form id="k" method="POST" action="/admin/settings/capacity" data-ajax data-savebar>
                <input id="f-buffer" name="buffer_minutes" value="0">
                <select id="f-mode" name="mode"><option value="table" selected>Nach Tischen</option><option value="person">Nach Personen</option></select>
                <div id="covers" data-show-if="mode:person" hidden>
                    <label for="f-covers">Höchstens gleichzeitig <span aria-hidden="true">*</span></label>
                    <input id="f-covers" name="max_covers" type="number" max="100" value="150">
                </div>
            </form>`;

        // jsdom kennt kein Layout: Verborgene Bereiche liefern keine Rechtecke.
        const layout = () => vi.spyOn(HTMLElement.prototype, 'getClientRects')
            .mockImplementation(function rects() { return this.closest('[hidden]') ? [] : [{}]; });

        it('erklärt, wenn das ungültige Feld verborgen ist, und sendet nichts', () => {
            layout();
            const send = vi.fn();
            vi.stubGlobal('fetch', send);
            mount(hiddenField);
            initSaveBar();
            type('f-buffer', '15');

            document.querySelector('[data-save-bar-save]').click();

            const toast = document.getElementById('swayyToast');
            expect(toast.textContent).toBe('„Höchstens gleichzeitig“ enthält einen ungültigen Wert und ist durch eine andere Auswahl ausgeblendet. '
                + 'Bitte die Auswahl ändern, bis das Feld erscheint, und den Wert korrigieren, oder auf „Verwerfen“ klicken.');
            expect(toast.dataset.state).toBe('error');
            expect(send).not.toHaveBeenCalled();
        });

        it('nennt ein Feld ohne Beschriftung nach seinem aria-label', () => {
            layout();
            mount(hiddenField.replace('<label for="f-covers">Höchstens gleichzeitig <span aria-hidden="true">*</span></label>', '')
                .replace('id="f-covers"', 'id="f-covers" aria-label="Höchstzahl"'));
            initSaveBar();
            type('f-buffer', '15');

            document.querySelector('[data-save-bar-save]').click();

            expect(document.getElementById('swayyToast').textContent).toContain('„Höchstzahl“ enthält');
        });

        it('überlässt ein sichtbares ungültiges Feld der Sprechblase des Browsers', () => {
            layout();
            mount(hiddenField.replace('id="covers" data-show-if="mode:person" hidden', 'id="covers"'));
            initSaveBar();
            type('f-buffer', '15');

            document.querySelector('[data-save-bar-save]').click();

            expect(document.getElementById('swayyToast').textContent).toBe('');
        });
    });
});
