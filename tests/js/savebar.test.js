import { beforeAll, describe, expect, it, vi } from 'vitest';
import { initAjaxForms } from '../../resources/js/admin/forms.js';
import { initSaveBar } from '../../resources/js/admin/savebar.js';
import { mount, setWide } from './helpers.js';

const page = `
    <div data-page-header><h1>Allgemein</h1><select id="scope"><option>Mitte</option></select></div>
    <div data-save-bar hidden>
        <span data-save-bar-text>Ungespeicherte Änderungen</span>
        <button type="button" data-save-bar-discard>Verwerfen</button>
        <button type="button" data-save-bar-save>Speichern</button>
    </div>
    <p data-save-bar-status></p>
    <form id="g" method="POST" action="/admin/settings/general" data-ajax data-savebar>
        <input id="f-name" name="name" value="Alt">
    </form>`;

function edit(value) {
    const input = document.getElementById('f-name');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

beforeAll(() => initAjaxForms());

describe('Speicherleiste', () => {
    it('erscheint bei Änderungen und sperrt ab 768 px den Seitenkopf', () => {
        setWide(true);
        mount(page);
        initSaveBar();

        edit('Neu');

        expect(document.querySelector('[data-save-bar]').hidden).toBe(false);
        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(true);
        expect(document.getElementById('g').hasAttribute('data-dirty')).toBe(true);
    });

    it('lässt den Seitenkopf unter 768 px bedienbar', () => {
        setWide(false);
        mount(page);
        initSaveBar();

        edit('Neu');

        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(false);
    });

    it('gibt den Seitenkopf nach Verwerfen frei und meldet swayy:discarded am Dokument', () => {
        setWide(true);
        mount(page);
        initSaveBar();
        const discarded = vi.fn();
        document.addEventListener('swayy:discarded', discarded, { once: true });

        edit('Neu');
        document.querySelector('[data-save-bar-discard]').click();

        expect(document.getElementById('f-name').value).toBe('Alt');
        expect(document.querySelector('[data-save-bar]').hidden).toBe(true);
        expect(document.querySelector('[data-page-header]').hasAttribute('inert')).toBe(false);
        expect(discarded).toHaveBeenCalledTimes(1);
    });
});
