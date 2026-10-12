import { describe, expect, it, vi } from 'vitest';
import { initSettingsAnchors, targetFor } from '../../resources/js/admin/settings-anchors.js';

describe('alte Sprungmarken', () => {
    const map = { zeiten: '/admin/settings/opening-hours', widget: '/admin/settings/booking-page#popup' };

    it('liefert das Ziel für eine alte Marke', () => {
        document.body.innerHTML = '<main></main>';
        expect(targetFor('#zeiten', map, document)).toBe('/admin/settings/opening-hours');
        expect(targetFor('#widget', map, document)).toBe('/admin/settings/booking-page#popup');
    });

    it('lässt Marken in Ruhe, die es auf der Seite gibt oder die unbekannt sind', () => {
        document.body.innerHTML = '<section id="zeiten"></section>';
        expect(targetFor('#zeiten', map, document)).toBeNull();
        expect(targetFor('#saison', map, document)).toBeNull();
        expect(targetFor('', map, document)).toBeNull();
    });

    it('hält Namen aus dem Objektprototyp nicht für Marken', () => {
        document.body.innerHTML = '<main></main>';
        expect(targetFor('#constructor', map, document)).toBeNull();
        expect(targetFor('#toString', map, document)).toBeNull();
    });
});

describe('Weiterleitung alter Sprungmarken', () => {
    const anchors = JSON.stringify({ zeiten: '/admin/settings/opening-hours', widget: '/admin/settings/booking-page#popup' });

    function windowAt(hash) {
        return { location: { hash, replace: vi.fn() } };
    }

    it('leitet eine alte Marke auf die neue Seite um', () => {
        document.body.innerHTML = `<div hidden data-settings-anchors='${anchors}'></div>`;
        const win = windowAt('#zeiten');

        initSettingsAnchors(win, document);

        expect(win.location.replace).toHaveBeenCalledWith('/admin/settings/opening-hours');
    });

    it('übernimmt das Sprungziel der neuen Seite', () => {
        document.body.innerHTML = `<div hidden data-settings-anchors='${anchors}'></div>`;
        const win = windowAt('#widget');

        initSettingsAnchors(win, document);

        expect(win.location.replace).toHaveBeenCalledWith('/admin/settings/booking-page#popup');
    });

    it('bleibt ruhig ohne Zuordnung, bei unbekannter Marke und bei einer Marke der Seite', () => {
        const win = windowAt('#zeiten');
        document.body.innerHTML = '<main></main>';
        initSettingsAnchors(win, document);

        document.body.innerHTML = `<div hidden data-settings-anchors='${anchors}'></div>`;
        initSettingsAnchors(windowAt('#saison'), document);
        initSettingsAnchors(windowAt(''), document);

        document.body.innerHTML = `<div hidden data-settings-anchors='${anchors}'></div><section id="zeiten"></section>`;
        const own = windowAt('#zeiten');
        initSettingsAnchors(own, document);

        expect(win.location.replace).not.toHaveBeenCalled();
        expect(own.location.replace).not.toHaveBeenCalled();
    });

    it('übersteht eine beschädigte Zuordnung und eine ungültige Adresse', () => {
        document.body.innerHTML = '<div hidden data-settings-anchors="{kaputt"></div>';
        const broken = windowAt('#zeiten');
        initSettingsAnchors(broken, document);

        document.body.innerHTML = `<div hidden data-settings-anchors='${anchors}'></div>`;
        const badHash = windowAt('#%');
        initSettingsAnchors(badHash, document);

        expect(broken.location.replace).not.toHaveBeenCalled();
        expect(badHash.location.replace).not.toHaveBeenCalled();
    });
});
