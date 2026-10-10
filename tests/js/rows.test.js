import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initRows } from '../../resources/js/admin/rows.js';
import { initSaveBar } from '../../resources/js/admin/savebar.js';
import { jsonResponse, mount } from './helpers.js';

const rows = (max = '') => `
    <form id="cap" method="POST" action="/admin/settings/capacity" data-ajax>
        <div data-rows ${max ? `data-rows-max="${max}"` : ''}>
            <div data-rows-list>
                <div data-row data-change-item="rule-0">
                    <input id="rule-0-min" name="duration_rules[0][min_party]" value="1">
                    <button type="button" data-rows-remove>Entfernen</button>
                </div>
            </div>
            <template data-rows-template>
                <div data-row data-change-item="rule-__INDEX__">
                    <input id="rule-__INDEX__-min" name="duration_rules[__INDEX__][min_party]" value="">
                    <button type="button" data-rows-remove>Entfernen</button>
                </div>
            </template>
            <button type="button" data-rows-add>Tischzeit hinzufügen</button>
        </div>
    </form>`;

// Zwei Zeilen unter einer Speicherleiste; mit data-reset-after-save kehrt das Formular nach dem Speichern zurück.
const withSaveBar = (formAttributes = 'data-savebar') => `
    <div data-save-bar hidden data-count-one="1 Einstellung geändert" data-count-many=":count Einstellungen geändert">
        <span data-save-bar-text>Ungespeicherte Änderungen</span><span data-save-bar-count></span>
        <button type="button" data-save-bar-discard>Verwerfen</button>
        <button type="button" data-save-bar-save>Speichern</button>
    </div>
    <form id="cap" method="POST" action="/admin/settings/capacity" data-ajax ${formAttributes}>
        <div data-rows>
            <div data-rows-list>
                <div data-row data-change-item="rule-0">
                    <input id="rule-0-min" name="duration_rules[0][min_party]" value="1">
                    <button type="button" data-rows-remove>Entfernen</button>
                    <span data-change-dot hidden></span>
                </div>
                <div data-row data-change-item="rule-1">
                    <input id="rule-1-min" name="duration_rules[1][min_party]" value="2">
                    <button type="button" data-rows-remove>Entfernen</button>
                    <span data-change-dot hidden></span>
                </div>
            </div>
            <template data-rows-template>
                <div data-row data-change-item="rule-__INDEX__">
                    <input id="rule-__INDEX__-min" name="duration_rules[__INDEX__][min_party]" value="">
                    <button type="button" data-rows-remove>Entfernen</button>
                    <span data-change-dot hidden></span>
                </div>
            </template>
            <button type="button" data-rows-add>Tischzeit hinzufügen</button>
        </div>
    </form>`;

const allRows = () => document.querySelectorAll('[data-rows-list] [data-row]');
const values = () => [...allRows()].map((row) => row.querySelector('input').value);
const bar = () => document.querySelector('[data-save-bar]');

function type(id, value) {
    const input = document.getElementById(id);
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

// Eine Anfrage, die erst antwortet, wenn der Test es sagt.
async function pendingSave() {
    const call = {};
    vi.stubGlobal('fetch', vi.fn(() => new Promise((resolve) => { call.resolve = resolve; })));
    document.querySelector('[data-save-bar-save]').click();
    await vi.waitFor(() => expect(call.resolve).toBeTypeOf('function'));
    return () => call.resolve(jsonResponse({ message: 'Gespeichert.' }));
}

const settled = () => vi.waitFor(() => expect(bar().hasAttribute('aria-busy')).toBe(false));

beforeAll(() => initAjaxForms());

describe('Zeilen', () => {
    it('hängt eine Zeile mit eigener Kennung an, fokussiert sie und meldet die Änderung', () => {
        mount(rows());
        initRows();
        const changed = vi.fn();
        document.getElementById('cap').addEventListener('input', changed);

        document.querySelector('[data-rows-add]').click();

        const added = allRows()[1];
        expect(added.dataset.changeItem).toMatch(/^rule-n\d+$/);
        expect(added.querySelector('input').name).toMatch(/^duration_rules\[n\d+\]\[min_party\]$/);
        expect(added.querySelector('input').id).toMatch(/^rule-n\d+-min$/);
        expect(document.activeElement).toBe(added.querySelector('input'));
        expect(changed).toHaveBeenCalledTimes(1);
    });

    it('entfernt eine Zeile und setzt den Fokus auf „Hinzufügen“', () => {
        mount(rows());
        initRows();
        const changed = vi.fn();
        document.getElementById('cap').addEventListener('input', changed);

        document.querySelector('[data-rows-remove]').click();

        expect(allRows()).toHaveLength(0);
        expect(document.activeElement).toBe(document.querySelector('[data-rows-add]'));
        expect(changed).toHaveBeenCalledTimes(1);
    });

    it('sperrt „Hinzufügen“ bei der Höchstzahl und gibt es nach dem Entfernen frei', () => {
        mount(rows('2'));
        initRows();
        const add = document.querySelector('[data-rows-add]');

        add.click();
        expect(add.disabled).toBe(true);

        allRows()[1].querySelector('[data-rows-remove]').click();
        expect(add.disabled).toBe(false);
    });

    it('stellt beim Zurücksetzen den gespeicherten Stand wieder her', () => {
        mount(rows());
        initRows();
        document.querySelector('[data-rows-remove]').click();
        document.querySelector('[data-rows-add]').click();

        document.getElementById('cap').reset();

        expect(allRows()).toHaveLength(1);
        expect(allRows()[0].querySelector('input').value).toBe('1');
    });

    it('übernimmt nach dem Speichern den neuen Stand', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Gespeichert.' })));
        mount(rows());
        initRows();
        document.querySelector('[data-rows-add]').click();
        document.activeElement.value = '5';

        document.getElementById('cap').requestSubmit();
        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        document.getElementById('cap').reset();

        expect(allRows()).toHaveLength(2);
        expect(allRows()[1].querySelector('input').value).toBe('5');
    });

    it('stellt beim Zurücksetzen die Zeilen ohne Fehlermarkierungen früherer Antworten wieder her', async () => {
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({
            message: 'Die Eingaben sind nicht gültig.',
            errors: { 'duration_rules.0.min_party': ['Bitte eine Zahl von 1 bis 20 eintragen.'] },
        }, 422)));
        mount(rows());
        initRows();

        document.getElementById('cap').requestSubmit();
        await vi.waitFor(() => expect(document.querySelector('[data-rows-list] [data-ajax-error]')).not.toBeNull());
        document.querySelector('[data-rows-add]').click();
        document.getElementById('cap').reset();

        const list = document.querySelector('[data-rows-list]');
        expect(allRows()).toHaveLength(1);
        expect(list.querySelector('[data-ajax-error]')).toBeNull();
        expect(list.querySelector('[data-ajax-invalid]')).toBeNull();
        expect(list.querySelector('[aria-invalid="true"]')).toBeNull();
        expect(list.querySelector('[aria-describedby]')).toBeNull();
    });

    it('lässt eine Zeile, die während des Speicherns dazukam, beim Verwerfen verschwinden', async () => {
        mount(withSaveBar());
        initRows();
        initSaveBar();
        type('rule-0-min', '3');
        const answer = await pendingSave();

        document.querySelector('[data-rows-add]').click();
        answer();
        await settled();
        expect(bar().hidden).toBe(false);

        document.querySelector('[data-save-bar-discard]').click();

        expect(values()).toEqual(['3', '2']);
        expect(bar().hidden).toBe(true);
    });

    it('bringt eine Zeile, die während des Speicherns entfernt wurde, mit den gesendeten Werten zurück', async () => {
        mount(withSaveBar());
        initRows();
        initSaveBar();
        type('rule-0-min', '3');
        type('rule-1-min', '7');
        const answer = await pendingSave();

        allRows()[1].querySelector('[data-rows-remove]').click();
        answer();
        await settled();
        expect(allRows()).toHaveLength(1);
        expect(bar().hidden).toBe(false);

        document.querySelector('[data-save-bar-discard]').click();

        expect(values()).toEqual(['3', '7']);
        expect(allRows()[1].hasAttribute('data-changed')).toBe(false);
        expect(allRows()[1].querySelector('[data-change-dot]').hidden).toBe(true);
        expect(bar().hidden).toBe(true);
    });

    it('behält bei Formularen, die nach dem Speichern zurückkehren, den Ausgangsstand', async () => {
        mount(withSaveBar('data-reset-after-save'));
        initRows();
        document.querySelector('[data-rows-add]').click();
        document.activeElement.value = '9';
        vi.stubGlobal('fetch', vi.fn(async () => jsonResponse({ message: 'Gespeichert.' })));

        document.getElementById('cap').requestSubmit();
        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        expect(values()).toEqual(['1', '2']);
        document.getElementById('cap').reset();

        expect(values()).toEqual(['1', '2']);
    });

    it('übernimmt die gesendeten Zeilen auch, wenn das Formular mitten in der Anfrage zurückgesetzt wird', async () => {
        mount(withSaveBar());
        initRows();
        initSaveBar();
        const form = document.getElementById('cap');
        type('rule-0-min', '3');
        let resolve;
        vi.stubGlobal('fetch', vi.fn(() => new Promise((r) => { resolve = r; })));

        form.requestSubmit();
        await vi.waitFor(() => expect(resolve).toBeTypeOf('function'));
        form.reset();
        resolve(jsonResponse({ message: 'Gespeichert.' }));
        await vi.waitFor(() => expect(document.getElementById('swayyToast').textContent).toBe('Gespeichert.'));
        expect(bar().hidden).toBe(false);

        document.querySelector('[data-save-bar-discard]').click();

        expect(values()).toEqual(['3', '2']);
        expect(bar().hidden).toBe(true);
    });

    it('nimmt ohne vorherige Sendemeldung die aktuellen Zeilen als gespeicherten Stand', () => {
        mount(withSaveBar());
        initRows();
        document.querySelector('[data-rows-add]').click();

        document.getElementById('cap').dispatchEvent(new CustomEvent('swayy:saved', { bubbles: true }));
        document.getElementById('cap').reset();

        expect(values()).toEqual(['1', '2', '']);
    });
});
