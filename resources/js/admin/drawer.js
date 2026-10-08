// Seitenleiste zum Anlegen und Bearbeiten (x-drawer): von rechts, auf dem
// Smartphone bildschirmfüllend. Ein Element mit data-drawer-open="{id}"
// öffnet sie, ebenso die Adresse mit #{id} (Suche in den Einstellungen).
// Escape, [data-drawer-close] und erfolgreiches Speichern des Hauptformulars
// schließen sie; die Formulare darin kehren zum gespeicherten Stand zurück,
// der Fokus zum öffnenden Element. Solange sie offen ist, ist alles andere
// gesperrt (inert), ausgenommen Ebenen mit data-overlay-layer (Hinweis,
// Bestätigungsdialog).
//
// Hauptformular ist das Formular, auf das ein Knopf mit form="…" in der
// Seitenleiste zeigt (Fußzeile). Weitere Formulare darin (etwa das Logo)
// speichern für sich, die Seitenleiste bleibt dann offen. Geänderte
// Formulare in der offenen Seitenleiste tragen data-dirty; ein angefordertes
// Neuladen (forms.js) wartet, bis sie gespeichert oder verworfen sind.

import { focusables } from './dom.js';
import { snapshot } from './forms.js';

let installed = false;
let current = null;
const baselines = new WeakMap();

function closesOnSave(drawer, form) {
    const primary = [...drawer.querySelectorAll('button[type="submit"][form]')].map((button) => button.getAttribute('form'));
    return primary.length === 0 || primary.includes(form.id);
}

function trackChange(e) {
    const form = e.target?.form;
    if (!current || !(form instanceof HTMLFormElement) || !current.drawer.contains(form)) return;
    form.toggleAttribute('data-dirty', snapshot(form) !== baselines.get(form));
}

function open(drawer, opener = null) {
    if (current?.drawer === drawer) return;
    if (current) close();

    const covered = [...document.body.children].filter(
        (el) => el !== drawer && !el.matches('script, [data-overlay-layer]') && !el.hasAttribute('inert'),
    );
    covered.forEach((el) => el.setAttribute('inert', ''));
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
    drawer.querySelectorAll('form').forEach((form) => baselines.set(form, snapshot(form)));
    current = { drawer, opener, covered };

    const dialog = drawer.querySelector('[role="dialog"]') ?? drawer;
    const body = drawer.querySelector('[data-drawer-body]') ?? dialog;
    const target = drawer.querySelector('[autofocus]') ?? focusables(body)[0] ?? dialog;
    if (target === dialog) dialog.tabIndex = -1;
    target.focus();
}

function close() {
    if (!current) return;
    const { drawer, opener, covered } = current;
    current = null;

    drawer.hidden = true;
    drawer.querySelectorAll('form').forEach((form) => {
        form.reset();
        form.removeAttribute('data-dirty');
    });
    covered.forEach((el) => el.removeAttribute('inert'));
    document.body.style.overflow = '';
    if (window.location.hash === `#${drawer.id}`) {
        history.replaceState(null, '', window.location.pathname + window.location.search);
    }
    // Ein Neuladen, das auf diese Seitenleiste gewartet hat, darf jetzt laufen.
    drawer.dispatchEvent(new CustomEvent('swayy:discarded', { bubbles: true }));

    if (opener?.isConnected) {
        opener.focus();
        return;
    }
    const heading = document.querySelector('[data-page-header] h1');
    if (heading) {
        heading.tabIndex = -1;
        heading.focus();
    }
}

function openFromHash() {
    let id = '';
    try {
        id = decodeURIComponent(window.location.hash.slice(1));
    } catch {
        // Kaputte Kodierung in der Adresse (etwa „#%“): keine Seitenleiste.
        return;
    }
    const drawer = id ? document.getElementById(id) : null;
    if (drawer?.hasAttribute('data-drawer')) open(drawer);
}

export function initDrawers() {
    openFromHash();
    if (installed) return;
    installed = true;

    document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        const opener = e.target.closest('[data-drawer-open]');
        if (opener) {
            const drawer = document.getElementById(opener.dataset.drawerOpen);
            if (drawer?.hasAttribute('data-drawer')) {
                e.preventDefault();
                open(drawer, opener);
            }
            return;
        }
        if (current && current.drawer.contains(e.target) && e.target.closest('[data-drawer-close]')) close();
    });

    // Eine offene Rückfrage in der Zeile schließt Escape zuerst
    // (inline-confirm.js setzt dann defaultPrevented).
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && current && !e.defaultPrevented) close();
    });

    document.addEventListener('input', trackChange);
    document.addEventListener('change', trackChange);

    document.addEventListener('swayy:saved', (e) => {
        const form = e.target;
        if (!current || !(form instanceof HTMLFormElement) || !current.drawer.contains(form)) return;
        if (closesOnSave(current.drawer, form)) {
            close();
            return;
        }
        baselines.set(form, snapshot(form));
        form.removeAttribute('data-dirty');
    });

    window.addEventListener('hashchange', openFromHash);
}
