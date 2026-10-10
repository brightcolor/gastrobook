import { describe, expect, it } from 'vitest';
import { applyReveals, initReveals } from '../../resources/js/admin/reveal.js';
import { mount } from './helpers.js';

describe('Hinweise je nach Eingabe', () => {
    it('zeigt ein Feld nur bei passenden Werten einer Auswahl', () => {
        mount(`
            <form>
                <label><input type="radio" name="capacity_mode" value="table" checked> Tische</label>
                <label><input type="radio" name="capacity_mode" value="person"> Personen</label>
                <div id="covers" data-show-if="capacity_mode:person,hybrid">Höchstzahl</div>
            </form>`);
        initReveals();
        expect(document.getElementById('covers').hidden).toBe(true);

        const person = document.querySelector('[value="person"]');
        person.checked = true;
        person.dispatchEvent(new Event('change', { bubbles: true }));

        expect(document.getElementById('covers').hidden).toBe(false);
    });

    it('verlangt bei mehreren Bedingungen alle', () => {
        mount(`
            <form>
                <select name="fields[email]"><option value="optional" selected>Freiwillig</option><option value="hidden">Ausgeblendet</option></select>
                <select name="fields[phone]"><option value="optional">Freiwillig</option><option value="hidden" selected>Ausgeblendet</option></select>
                <p id="warn" data-show-if="fields[email]:hidden;fields[phone]:hidden">Hinweis</p>
            </form>`);
        applyReveals();
        expect(document.getElementById('warn').hidden).toBe(true);

        document.querySelector('[name="fields[email]"]').value = 'hidden';
        applyReveals();

        expect(document.getElementById('warn').hidden).toBe(false);
    });

    it('trennt Feldname und Werte am ersten Doppelpunkt, damit Uhrzeiten als Wert gehen', () => {
        mount(`
            <form>
                <select name="opens_at"><option value="07:30" selected>07:30</option><option value="08:00">08:00</option></select>
                <p id="late" data-show-if="opens_at:08:00,09:30">Spät</p>
            </form>`);
        applyReveals();
        expect(document.getElementById('late').hidden).toBe(true);

        document.querySelector('[name="opens_at"]').value = '08:00';
        applyReveals();

        expect(document.getElementById('late').hidden).toBe(false);
    });

    it('zeigt nach dem Zurücksetzen wieder den gespeicherten Stand', async () => {
        mount(`
            <form id="f">
                <label><input type="radio" name="capacity_mode" value="table" checked> Tische</label>
                <label><input type="radio" name="capacity_mode" value="person"> Personen</label>
                <div id="covers" data-show-if="capacity_mode:person">Höchstzahl</div>
            </form>`);
        initReveals();
        const person = document.querySelector('[value="person"]');
        person.checked = true;
        person.dispatchEvent(new Event('change', { bubbles: true }));
        expect(document.getElementById('covers').hidden).toBe(false);

        document.getElementById('f').reset();
        await Promise.resolve();

        expect(document.getElementById('covers').hidden).toBe(true);
    });

    it('liest bei einem Kästchen mit verstecktem Feld die 0, solange der Haken fehlt', () => {
        mount(`
            <form>
                <input type="hidden" name="waitlist_enabled" value="0">
                <input type="checkbox" name="waitlist_enabled" value="1">
                <p id="on" data-show-if="waitlist_enabled:1">An</p>
                <p id="off" data-show-if="waitlist_enabled:0">Aus</p>
            </form>`);
        applyReveals();

        expect(document.getElementById('on').hidden).toBe(true);
        expect(document.getElementById('off').hidden).toBe(false);
    });
});
