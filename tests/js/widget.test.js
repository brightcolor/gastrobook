import { describe, expect, it, vi } from 'vitest';
import { attr, buildSnippets, initWidgetBuilder } from '../../resources/js/admin/widget.js';
import { mount } from './helpers.js';

const sources = {
    popupSrc: 'https://swayy.test/widget/demo/popup.js',
    embedSrc: 'https://swayy.test/embed/demo.js',
    bookingUrl: 'https://swayy.test/book/demo',
};

// Der Code, wie ihn der Browser auf der Website des Betriebs liest.
function parse(html) {
    return new DOMParser().parseFromString(`<body>${html}</body>`, 'text/html').body;
}

// Baut eine Seite mit allen Eingaben und Ausgaben von „Buchungsseite & Widget“.
function mountBuilder(extra = '') {
    mount(`
        <div data-widget-builder hidden data-popup-src="${sources.popupSrc}" data-embed-src="${sources.embedSrc}" data-booking-url="${sources.bookingUrl}" data-default-label="Jetzt reservieren" data-default-color="#0f766e"></div>
        <section>
            <input id="w-label" data-widget-input="label" value="">
            <input id="w-color" type="text" data-widget-input="color" value="#0f766e">
            <input id="w-float" type="checkbox" data-widget-input="float">
            <pre data-widget-snippet="popup"></pre>
            <button type="button" data-copy="" data-widget-copy="popup">Code kopieren</button>
            <pre data-widget-snippet="inline"></pre>
            <button type="button" data-copy="" data-widget-copy="inline">Code kopieren</button>
            <input id="w-link-label" data-widget-input="link-label" value="">
            <input id="w-link-color" type="text" data-widget-input="link-color" value="#123456">
            <pre data-widget-snippet="link"></pre>
            <button type="button" data-copy="" data-widget-copy="link">Code kopieren</button>
        </section>${extra}`);
}

function type(id, value) {
    const el = document.getElementById(id);
    el.value = value;
    el.dispatchEvent(new Event('input', { bubbles: true }));
}

describe('Code für die eigene Website', () => {
    it('baut Knopf, eingebettetes Formular und Link', () => {
        const code = buildSnippets({ ...sources, label: 'Tisch reservieren', color: '#0f766e', float: true, linkLabel: 'Jetzt reservieren', linkColor: '#123456' });

        expect(code.popup).toBe('<script src="https://swayy.test/widget/demo/popup.js" data-label="Tisch reservieren" data-color="#0f766e" data-float="1" defer></script>');
        expect(code.inline).toBe('<div id="swayy-widget"></div>\n<script src="https://swayy.test/embed/demo.js" defer></script>');
        expect(code.link).toContain('<a href="https://swayy.test/book/demo" target="_blank" rel="noopener"');
        expect(code.link).toContain('background:#123456;');
        expect(code.link.endsWith('\n  Jetzt reservieren\n</a>')).toBe(true);
    });

    it('maskiert Anführungszeichen und spitze Klammern im Text', () => {
        const code = buildSnippets({ ...sources, label: 'Tisch "jetzt" <b>', color: '#0f766e', float: false, linkLabel: 'Reservieren & <mehr>', linkColor: '#0f766e' });

        expect(code.popup).toContain('data-label="Tisch &quot;jetzt&quot; &lt;b&gt;"');
        expect(code.popup).not.toContain('data-float');
        expect(code.link).toContain('Reservieren &amp; &lt;mehr&gt;');
    });

    it('füllt Code und Kopierknopf und folgt den Eingaben', () => {
        mount(`
            <div data-widget-builder hidden data-popup-src="${sources.popupSrc}" data-embed-src="${sources.embedSrc}" data-booking-url="${sources.bookingUrl}" data-default-label="Jetzt reservieren"></div>
            <section>
                <input id="w-label" data-widget-input="label" value="">
                <input id="w-color" type="color" data-widget-input="color" value="#0f766e">
                <input id="w-float" type="checkbox" data-widget-input="float">
                <pre data-widget-snippet="popup"></pre>
                <button type="button" data-copy="" data-widget-copy="popup">Code kopieren</button>
            </section>`);
        initWidgetBuilder();

        const pre = document.querySelector('[data-widget-snippet="popup"]');
        const copy = document.querySelector('[data-widget-copy="popup"]');
        expect(pre.textContent).toContain('data-label="Jetzt reservieren"');
        expect(copy.dataset.copy).toBe(pre.textContent);

        const label = document.getElementById('w-label');
        label.value = 'Termin buchen';
        label.dispatchEvent(new Event('input', { bubbles: true }));

        expect(pre.textContent).toContain('data-label="Termin buchen"');
        expect(copy.dataset.copy).toBe(pre.textContent);
    });
});

describe('Maskierung', () => {
    it('maskiert &, Anführungszeichen und spitze Klammern genau einmal', () => {
        expect(attr('a & b "c" <d> &lt;')).toBe('a &amp; b &quot;c&quot; &lt;d&gt; &amp;lt;');
        expect(attr(42)).toBe('42');
    });

    it('hält jede Eingabe in ihrem Attribut und im Text, auch nach dem Parsen im Browser', () => {
        const evil = '"><script>alert(1)</script><img src=x onerror=alert(2)> & \'';
        const code = buildSnippets({ popupSrc: `https://swayy.test/a${evil}`, embedSrc: `https://swayy.test/b${evil}`, bookingUrl: `https://swayy.test/c${evil}`, label: evil, color: '#0f766e', float: true, linkLabel: evil, linkColor: '#0f766e' });

        // Knopf: genau ein script-Element, und die Werte kommen unverändert zurück.
        const popup = parse(code.popup);
        expect(popup.children).toHaveLength(1);
        const script = popup.querySelector('script');
        expect(script.getAttribute('src')).toBe(`https://swayy.test/a${evil}`);
        expect(script.dataset.label).toBe(evil);
        expect(script.dataset.color).toBe('#0f766e');
        expect([...script.attributes].map((a) => a.name)).toEqual(['src', 'data-label', 'data-color', 'data-float', 'defer']);

        // Eingebettetes Formular: ein div und ein script.
        const inline = parse(code.inline);
        expect([...inline.children].map((el) => el.tagName)).toEqual(['DIV', 'SCRIPT']);
        expect(inline.querySelector('script').getAttribute('src')).toBe(`https://swayy.test/b${evil}`);

        // Link: ein a-Element ohne Kind-Elemente; der Text bleibt Text.
        const link = parse(code.link);
        expect(link.children).toHaveLength(1);
        const a = link.querySelector('a');
        expect(a.getAttribute('href')).toBe(`https://swayy.test/c${evil}`);
        expect([...a.attributes].map((attribute) => attribute.name)).toEqual(['href', 'target', 'rel', 'style']);
        expect(a.children).toHaveLength(0);
        expect(a.textContent.trim()).toBe(evil);
    });
});

describe('Farben', () => {
    it.each([
        ['red'],
        ['#12345'],
        ['#1234567'],
        ['#ggg000'],
        ['0f766e'],
        ['#0f766e;background:url(https://evil.test/x)'],
        ['#0f766e" onload="alert(1)'],
        ['#0f766e"><script>alert(1)</script>'],
        ['url(javascript:alert(1))'],
        [''],
    ])('ersetzt den Wert „%s“ durch die Vorgabe', (bad) => {
        const code = buildSnippets({ ...sources, label: 'Reservieren', color: bad, float: false, linkLabel: 'Reservieren', linkColor: bad, defaultColor: '#0f766e' });

        expect(code.popup).toContain('data-color="#0f766e"');
        expect(code.link).toContain('background:#0f766e;');
        expect(code.popup).not.toContain('evil');
        expect(code.link).not.toContain('evil');
        expect(code.link).not.toContain('alert');
        expect(parse(code.popup).children).toHaveLength(1);
        expect(parse(code.link).children).toHaveLength(1);
    });

    it('lässt die Farbe weg, wenn weder Eingabe noch Vorgabe eine Farbe in der Schreibweise #rrggbb ist', () => {
        const code = buildSnippets({ ...sources, label: 'Reservieren', color: 'red', float: false, linkLabel: 'Reservieren', linkColor: 'red', defaultColor: 'blue' });

        expect(code.popup).toBe('<script src="https://swayy.test/widget/demo/popup.js" data-label="Reservieren" defer></script>');
        expect(code.link).not.toContain('background');
    });

    it('übernimmt Farben in Groß- und Kleinschreibung', () => {
        const code = buildSnippets({ ...sources, label: 'Reservieren', color: '#ABCDEF', float: false, linkLabel: 'Reservieren', linkColor: '#abcdef' });

        expect(code.popup).toContain('data-color="#ABCDEF"');
        expect(code.link).toContain('background:#abcdef;');
    });
});

describe('Eingaben der Seite', () => {
    it('folgt Knopftext, Farbe, schwebendem Knopf, Linktext und Linkfarbe', () => {
        mountBuilder();
        initWidgetBuilder();

        const popup = document.querySelector('[data-widget-snippet="popup"]');
        const link = document.querySelector('[data-widget-snippet="link"]');
        const inline = document.querySelector('[data-widget-snippet="inline"]');
        expect(inline.textContent).toBe(`<div id="swayy-widget"></div>\n<script src="${sources.embedSrc}" defer></script>`);
        expect(popup.textContent).not.toContain('data-float');

        type('w-label', '  Tisch sichern  ');
        type('w-color', '#aa3300');
        const float = document.getElementById('w-float');
        float.checked = true;
        float.dispatchEvent(new Event('change', { bubbles: true }));
        type('w-link-label', 'Hier buchen');
        type('w-link-color', '#003366');

        expect(popup.textContent).toBe(`<script src="${sources.popupSrc}" data-label="Tisch sichern" data-color="#aa3300" data-float="1" defer></script>`);
        expect(link.textContent).toContain('background:#003366;');
        expect(link.textContent.endsWith('\n  Hier buchen\n</a>')).toBe(true);
        document.querySelectorAll('[data-widget-copy]').forEach((button) => {
            expect(button.dataset.copy).toBe(document.querySelector(`[data-widget-snippet="${button.dataset.widgetCopy}"]`).textContent);
        });
    });

    it('nimmt für leere Texte die Bezeichnung des Betriebstyps', () => {
        mountBuilder();
        initWidgetBuilder();

        type('w-label', 'Anderer Text');
        type('w-label', '   ');
        type('w-link-label', '');

        expect(document.querySelector('[data-widget-snippet="popup"]').textContent).toContain('data-label="Jetzt reservieren"');
        expect(document.querySelector('[data-widget-snippet="link"]').textContent.endsWith('\n  Jetzt reservieren\n</a>')).toBe(true);
    });

    it('verwirft eine Farbe, die kein #rrggbb ist, und nimmt die Markenfarbe', () => {
        mountBuilder();
        initWidgetBuilder();

        type('w-color', 'red" onfocus="alert(1)');
        type('w-link-color', '#12');

        const popup = document.querySelector('[data-widget-snippet="popup"]').textContent;
        const link = document.querySelector('[data-widget-snippet="link"]').textContent;
        expect(popup).toContain('data-color="#0f766e"');
        expect(popup).not.toContain('onfocus');
        expect(link).toContain('background:#0f766e;');
    });

    it('maskiert Eingaben im angezeigten Code und legt kein Element an', () => {
        mountBuilder();
        initWidgetBuilder();

        type('w-label', '"><img src=x onerror=alert(1)>');

        const popup = document.querySelector('[data-widget-snippet="popup"]');
        expect(popup.children).toHaveLength(0);
        expect(popup.textContent).toContain('data-label="&quot;&gt;&lt;img src=x onerror=alert(1)&gt;"');
        expect(document.querySelectorAll('img')).toHaveLength(0);
    });

    it('arbeitet nach einem neuen Aufbau der Seite mit den aktuellen Elementen', () => {
        mountBuilder();
        initWidgetBuilder();
        mountBuilder();
        initWidgetBuilder();

        type('w-label', 'Neu');

        const pre = document.querySelector('[data-widget-snippet="popup"]');
        expect(pre.textContent).toContain('data-label="Neu"');
        expect(document.querySelector('[data-widget-copy="popup"]').dataset.copy).toBe(pre.textContent);
    });

    it('hängt seine Zuhörer nur einmal an', () => {
        mountBuilder();
        initWidgetBuilder();
        const spy = vi.spyOn(document, 'addEventListener');

        initWidgetBuilder();

        expect(spy.mock.calls.filter(([eventName]) => eventName === 'input' || eventName === 'change')).toHaveLength(0);
        spy.mockRestore();
    });

    it('tut ohne Einstellungen der Seite nichts', () => {
        mount('<input data-widget-input="label" value="x"><pre data-widget-snippet="popup"></pre>');

        expect(() => initWidgetBuilder()).not.toThrow();
        expect(document.querySelector('[data-widget-snippet="popup"]').textContent).toBe('');
    });
});
