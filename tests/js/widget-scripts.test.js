import fs from 'node:fs';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { mount } from './helpers.js';

// Die Skripte popup.js und embed.js laufen auf der Website des Betriebs. Sie stehen als Heredoc in
// PublicBookingController (popupScript, embedScript). Der Test liest sie dort, setzt für die
// PHP-Ausdrücke Werte ein und führt sie in jsdom aus; document.currentScript ist das Skript-Element
// mit den data-Attributen, die der Betrieb setzt. Findet er die Skripte nicht mehr, scheitert er laut.
const source = fs.readFileSync(path.resolve(process.cwd(), 'app/Http/Controllers/Public/PublicBookingController.php'), 'utf8');

function heredoc(method) {
    const start = source.indexOf(`public function ${method}(`);
    expect(start).toBeGreaterThan(-1);
    const open = source.indexOf('<<<JS', start);
    const close = source.indexOf('\nJS;', open);
    const closeIndented = source.indexOf('\n        JS;', open);
    const end = [close, closeIndented].filter((i) => i > -1).sort((a, b) => a - b)[0];
    expect(open).toBeGreaterThan(-1);
    expect(end).toBeGreaterThan(open);
    return source.slice(source.indexOf('\n', open) + 1, end);
}

// Ersetzt {$name} und {$this->jsString(…)} durch JSON-Werte aus values (Schlüssel: Variablenname).
function fill(js, values) {
    return js.replace(/\{\$([A-Za-z]+)\}/g, (match, name) => {
        expect(values, `Kein Testwert für ${match}`).toHaveProperty(name);
        return values[name];
    });
}

function run(js, script) {
    // eslint-disable-next-line no-new-func
    new Function('document', 'window', js.replaceAll('document.currentScript', '__script').replace('(function () {', 'var __script = window.__swayyScript;\n(function () {'))(document, window);
    return script;
}

const hostile = '<img src=x onerror="window.__pwned=1">';
const salonName = 'Salon "Glanz" & <b>Co</b>';

function popup({ label = null, title, defaultLabel }) {
    mount('<div id="host"></div>');
    const script = document.createElement('script');
    if (label !== null) script.dataset.label = label;
    document.getElementById('host').appendChild(script);
    window.__swayyScript = script;
    const js = fill(heredoc('popupScript'), {
        src: JSON.stringify('https://swayy.test/book/demo/sonne'),
        title: JSON.stringify(title),
        defColor: JSON.stringify('#0f766e'),
        defaultColor: '#0f766e',
        defLabel: JSON.stringify(defaultLabel),
    });
    run(js, script);
    return document.querySelector('.swayy-btn');
}

afterEach(() => {
    delete window.__pwned;
    delete window.__swayyScript;
    document.head.innerHTML = '';
});

describe('popup.js auf der Website des Betriebs', () => {
    it('setzt den Knopftext als Text, auch wenn data-label Markup enthält', () => {
        const btn = popup({ label: hostile, title: 'Reservierung – Sonne', defaultLabel: 'Jetzt reservieren' });

        expect(btn.textContent).toBe(hostile);
        expect(btn.querySelector('img')).toBeNull();
        expect(btn.querySelector('svg')).not.toBeNull();
        expect(window.__pwned).toBeUndefined();
    });

    it('baut das Fenster ohne Markup aus Namen und Knopftext', () => {
        const btn = popup({ label: hostile, title: `Terminbuchung – ${salonName}`, defaultLabel: 'Termin buchen' });
        btn.click();

        const modal = document.querySelector('.swayy-modal');
        expect(modal.querySelector('.swayy-modal-title').textContent).toBe(hostile);
        expect(modal.querySelector('img, b')).toBeNull();
        const iframe = modal.querySelector('iframe');
        expect(iframe.getAttribute('title')).toBe(`Terminbuchung – ${salonName}`);
        expect(iframe.getAttribute('src')).toBe('https://swayy.test/book/demo/sonne');
        expect(window.__pwned).toBeUndefined();

        modal.querySelector('.swayy-close').click();
        expect(document.querySelector('.swayy-overlay')).toBeNull();
    });

    it('nimmt ohne data-label den Knopftext, den der Server vorgibt', () => {
        expect(popup({ title: 'Terminbuchung – Glanz', defaultLabel: 'Termin buchen' }).textContent).toBe('Termin buchen');
        expect(popup({ title: 'Reservierung – Sonne', defaultLabel: 'Jetzt reservieren' }).textContent).toBe('Jetzt reservieren');
    });

    it('übernimmt die Höhe nur von der eigenen Buchungsseite', () => {
        const btn = popup({ title: 'Reservierung – Sonne', defaultLabel: 'Jetzt reservieren' });
        btn.click();
        const iframe = document.querySelector('.swayy-iframe');

        window.dispatchEvent(new MessageEvent('message', { data: { swayyHeight: 900 }, origin: 'https://evil.test' }));
        expect(iframe.style.minHeight).toBe('');

        window.dispatchEvent(new MessageEvent('message', { data: { swayyHeight: 900 }, origin: 'https://swayy.test' }));
        expect(iframe.style.minHeight).toBe('920px');

        window.dispatchEvent(new MessageEvent('message', { data: { swayyHeight: '1px;display:none' }, origin: 'https://swayy.test' }));
        expect(iframe.style.minHeight).toBe('920px');
    });
});

describe('embed.js auf der Website des Betriebs', () => {
    function embed(title) {
        mount('<div id="swayy-widget"></div>');
        window.__swayyScript = null;
        const js = fill(heredoc('embedScript'), {
            src: JSON.stringify('https://swayy.test/book/demo/sonne'),
            title: JSON.stringify(title),
        });
        run(js, null);
        return document.querySelector('#swayy-widget iframe');
    }

    it('trägt den Titel, den der Server vorgibt, und folgt nur der eigenen Buchungsseite', () => {
        const iframe = embed(`Termin buchen – ${salonName}`);
        expect(iframe.getAttribute('title')).toBe(`Termin buchen – ${salonName}`);

        window.dispatchEvent(new MessageEvent('message', { data: { swayyHeight: 700 }, origin: 'https://evil.test' }));
        expect(iframe.style.height).toBe('');
        window.dispatchEvent(new MessageEvent('message', { data: { swayyHeight: 700 }, origin: 'https://swayy.test' }));
        expect(iframe.style.height).toBe('700px');
    });
});
