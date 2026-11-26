import { afterEach, describe, expect, it, vi } from 'vitest';
import { DRAWER_LOAD_FAILED, initDrawers } from '../../resources/js/admin/drawer.js';
import { htmlResponse, jsonResponse, mount } from './helpers.js';

// Seitenleisten, die erst beim Öffnen vom Server kommen (data-drawer-src), etwa „Ausnahme bearbeiten“.

const SRC = '/admin/settings/exceptions/g-1/drawer';

const layout = `
    <div id="app">
        <div data-page-header><h1>Ausnahmen</h1></div>
        <button type="button" id="edit" data-drawer-open="ausnahme-g-1" data-drawer-src="${SRC}">Bearbeiten</button>
    </div>
    <div id="ausnahme-neu" data-drawer hidden><div role="dialog"><input id="neu-name"></div></div>`;

const drawerHtml = `
    <div id="ausnahme-g-1" data-drawer hidden>
        <div role="dialog" aria-modal="true">
            <h2>Ausnahme bearbeiten</h2>
            <div data-drawer-body>
                <form id="form-ausnahme-g-1"><input id="g1-label" name="label" value="Heiligabend"></form>
            </div>
            <button type="button" data-drawer-close id="close">Abbrechen</button>
        </div>
    </div>`;

const toast = () => document.getElementById('swayyToast').textContent;

function htmlOk(html) {
    return new Response(html, { status: 200, headers: { 'Content-Type': 'text/html' } });
}

afterEach(() => {
    vi.restoreAllMocks();
    history.replaceState(null, '', window.location.pathname);
});

describe('Nachgeladene Seitenleiste', () => {
    it('lädt beim ersten Öffnen, hängt sie hinter die übrigen und öffnet sie', async () => {
        mount(layout);
        initDrawers();
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(htmlOk(drawerHtml));
        const added = vi.fn();
        document.addEventListener('swayy:drawer-added', added, { once: true });

        document.getElementById('edit').click();

        await vi.waitFor(() => expect(document.getElementById('ausnahme-g-1')?.hidden).toBe(false));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(SRC);
        expect(document.getElementById('ausnahme-neu').nextElementSibling.id).toBe('ausnahme-g-1');
        expect(added).toHaveBeenCalledTimes(1);
        expect(document.activeElement.id).toBe('g1-label');
        expect(window.location.hash).toBe('#ausnahme-g-1');
        expect(document.getElementById('edit').hasAttribute('aria-busy')).toBe(false);
    });

    it('öffnet beim zweiten Mal ohne neue Anfrage', async () => {
        mount(layout);
        initDrawers();
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(htmlOk(drawerHtml));

        document.getElementById('edit').click();
        await vi.waitFor(() => expect(document.getElementById('ausnahme-g-1')?.hidden).toBe(false));
        document.getElementById('close').click();
        expect(document.getElementById('ausnahme-g-1').hidden).toBe(true);

        document.getElementById('edit').click();
        expect(document.getElementById('ausnahme-g-1').hidden).toBe(false);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        document.getElementById('close').click();
    });

    it('fragt bei einem Doppelklick nur einmal', async () => {
        mount(layout);
        initDrawers();
        const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(htmlOk(drawerHtml));

        document.getElementById('edit').click();
        document.getElementById('edit').click();

        await vi.waitFor(() => expect(document.getElementById('ausnahme-g-1')?.hidden).toBe(false));
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(document.querySelectorAll('#ausnahme-g-1')).toHaveLength(1);
        document.getElementById('close').click();
    });

    it('zeigt die Meldung des Servers, wenn es den Eintrag nicht mehr gibt', async () => {
        mount(layout);
        initDrawers();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(
            jsonResponse({ message: 'Diese Ausnahme gibt es nicht mehr. Bitte die Seite neu laden.' }, 404),
        );

        document.getElementById('edit').click();

        await vi.waitFor(() => expect(toast()).toBe('Diese Ausnahme gibt es nicht mehr. Bitte die Seite neu laden.'));
        expect(document.getElementById('ausnahme-g-1')).toBeNull();
        expect(document.getElementById('edit').hasAttribute('aria-busy')).toBe(false);
    });

    it('nennt ohne verwertbare Antwort Ursache und nächsten Schritt', async () => {
        mount(layout);
        initDrawers();
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(htmlResponse(502));

        document.getElementById('edit').click();
        await vi.waitFor(() => expect(toast()).toBe(DRAWER_LOAD_FAILED));

        vi.spyOn(globalThis, 'fetch').mockRejectedValue(new TypeError('Failed to fetch'));
        document.getElementById('edit').click();
        await vi.waitFor(() => expect(document.getElementById('edit').hasAttribute('aria-busy')).toBe(false));
        expect(toast()).toBe(DRAWER_LOAD_FAILED);
        expect(document.getElementById('ausnahme-g-1')).toBeNull();
    });

    it('öffnet über die Adresse #ausnahme-…, auch wenn sie erst geladen werden muss', async () => {
        mount(layout);
        vi.spyOn(globalThis, 'fetch').mockResolvedValue(htmlOk(drawerHtml));
        history.replaceState(null, '', `${window.location.pathname}#ausnahme-g-1`);

        initDrawers();

        await vi.waitFor(() => expect(document.getElementById('ausnahme-g-1')?.hidden).toBe(false));
        document.getElementById('close').click();
    });
});
