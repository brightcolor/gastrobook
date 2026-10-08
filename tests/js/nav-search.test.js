import { describe, expect, it } from 'vitest';
import { initSettingsSearch } from '../../resources/js/admin/nav.js';
import { mount } from './helpers.js';

const menu = `
    <input id="s" type="search" data-settings-search aria-controls="s-list" data-examples="Öffnungszeiten|Anzahlung">
    <div id="s-list">
        <div data-settings-group>
            <a href="/admin/settings/data" data-settings-item data-label="Daten" data-keywords="Export#export|Sicherung#export|Betrieb löschen#betrieb-loeschen">
                Daten <small data-settings-hit hidden></small>
            </a>
            <a href="/admin/settings#zeiten" data-settings-item data-label="Öffnungszeiten" data-keywords="Ruhetag|Saison#saison">
                Öffnungszeiten <small data-settings-hit hidden></small>
            </a>
        </div>
        <p data-settings-noresult role="status"></p>
    </div>`;

function search(text) {
    const input = document.getElementById('s');
    input.value = text;
    input.dispatchEvent(new Event('input'));
}

describe('Suche in den Einstellungen', () => {
    it('führt bei einem Begriff mit Ziel direkt zur Karte', () => {
        mount(menu);
        initSettingsSearch();

        search('export');

        const data = document.querySelector('[data-label="Daten"]');
        expect(data.hidden).toBe(false);
        expect(data.getAttribute('href')).toBe('/admin/settings/data#export');
        expect(data.querySelector('[data-settings-hit]').textContent).toBe('Export');
        expect(document.querySelector('[data-label="Öffnungszeiten"]').hidden).toBe(true);
    });

    it('ersetzt ein vorhandenes Ziel und stellt die Adresse ohne Suche wieder her', () => {
        mount(menu);
        initSettingsSearch();
        const hours = document.querySelector('[data-label="Öffnungszeiten"]');

        search('saison');
        expect(hours.getAttribute('href')).toBe('/admin/settings#saison');

        search('');
        expect(hours.getAttribute('href')).toBe('/admin/settings#zeiten');
        expect(hours.querySelector('[data-settings-hit]').hidden).toBe(true);
    });

    it('nennt ohne Treffer die Beispiele aus den Einstellungen', () => {
        mount(menu);
        initSettingsSearch();

        search('xyz');

        expect(document.querySelector('[data-settings-noresult]').textContent)
            .toBe('Zu „xyz“ passt keine Einstellung. Versuch einen anderen Begriff, etwa „Öffnungszeiten“ oder „Anzahlung“.');
    });
});
