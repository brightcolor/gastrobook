import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initRows } from '../../resources/js/admin/rows.js';
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

const allRows = () => document.querySelectorAll('[data-rows-list] [data-row]');

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
});
