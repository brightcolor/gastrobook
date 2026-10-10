import { describe, expect, it } from 'vitest';
import { initMoreSheet } from '../../resources/js/admin/nav.js';
import { mount } from './helpers.js';

const layout = `
    <main id="main-content"><button type="button" data-more-open aria-expanded="false">Mehr</button></main>
    <div id="swayyMore" hidden>
        <div data-more-close></div>
        <div role="dialog"><a href="/admin">Übersicht</a></div>
    </div>`;

const escape = () => document.activeElement.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

describe('„Mehr“ auf dem Smartphone', () => {
    it('bleibt offen, wenn ein Dialog darüber Escape schon verbraucht hat', () => {
        mount(layout);
        initMoreSheet();
        document.querySelector('[data-more-open]').click();

        const consume = (e) => { if (e.key === 'Escape') e.preventDefault(); };
        document.addEventListener('keydown', consume, true);
        try {
            escape();
            expect(document.getElementById('swayyMore').hidden).toBe(false);
        } finally {
            document.removeEventListener('keydown', consume, true);
        }

        escape();
        expect(document.getElementById('swayyMore').hidden).toBe(true);
    });
});
