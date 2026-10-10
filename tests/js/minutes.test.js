import { describe, expect, it } from 'vitest';
import { describeMinutes, initMinuteHints } from '../../resources/js/admin/minutes.js';
import { mount } from './helpers.js';

const field = `
    <form id="f">
        <input id="f-lead" name="min_lead_minutes" value="120" data-minutes-hint>
        <p data-minutes-hint-for="f-lead"></p>
    </form>`;

const hint = () => document.querySelector('[data-minutes-hint-for="f-lead"]').textContent;

function type(value) {
    const input = document.getElementById('f-lead');
    input.value = value;
    input.dispatchEvent(new Event('input', { bubbles: true }));
}

describe('Minuten in Worten', () => {
    it('rechnet in Tage, Stunden und Minuten um', () => {
        expect(describeMinutes('45')).toBe('45 Min.');
        expect(describeMinutes('120')).toBe('2 Std.');
        expect(describeMinutes('150')).toBe('2 Std. 30 Min.');
        expect(describeMinutes('1440')).toBe('1 Tag');
        expect(describeMinutes('1530')).toBe('1 Tag 1 Std. 30 Min.');
        expect(describeMinutes('10080')).toBe('7 Tage');
        expect(describeMinutes('0')).toBe('0 Min.');
        expect(describeMinutes('')).toBe('');
        expect(describeMinutes('-5')).toBe('');
    });

    it('schreibt den Hinweis ab einer Stunde unter das Feld und folgt der Eingabe', () => {
        mount(field);
        initMinuteHints();
        expect(hint()).toBe('entspricht 2 Std.');

        type('30');
        expect(hint()).toBe('');

        type('1440');
        expect(hint()).toBe('entspricht 1 Tag');
    });

    it('zeigt nach dem Zurücksetzen wieder den gespeicherten Wert', async () => {
        mount(field);
        initMinuteHints();
        type('30');

        document.getElementById('f').reset();
        await Promise.resolve();

        expect(hint()).toBe('entspricht 2 Std.');
    });
});
