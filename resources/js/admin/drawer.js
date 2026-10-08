// Seitenleiste zum Anlegen und Bearbeiten (x-drawer): von rechts, auf dem
// Smartphone bildschirmfüllend. Ein Element mit data-drawer-open="{id}"
// öffnet sie, ebenso die Adresse mit #{id} (Suche in den Einstellungen).
// Escape, [data-drawer-close] und erfolgreiches Speichern (swayy:saved)
// schließen sie; die Formulare darin kehren zum gespeicherten Stand zurück,
// der Fokus zum öffnenden Element. Solange sie offen ist, ist alles andere
// gesperrt (inert), ausgenommen Ebenen mit data-overlay-layer (Hinweis,
// Bestätigungsdialog).

import { focusables } from './dom.js';

let installed = false;
let current = null;

function open(drawer, opener = null) {
    if (current?.drawer === drawer) return;
    if (current) close();

    const covered = [...document.body.children].filter(
        (el) => el !== drawer && !el.matches('script, [data-overlay-layer]') && !el.hasAttribute('inert'),
    );
    covered.forEach((el) => el.setAttribute('inert', ''));
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
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
    drawer.querySelectorAll('form').forEach((form) => form.reset());
    covered.forEach((el) => el.removeAttribute('inert'));
    document.body.style.overflow = '';
    if (window.location.hash === `#${drawer.id}`) {
        history.replaceState(null, '', window.location.pathname + window.location.search);
    }

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
    const id = decodeURIComponent(window.location.hash.slice(1));
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

    document.addEventListener('swayy:saved', (e) => {
        if (current && e.target instanceof Node && current.drawer.contains(e.target)) close();
    });

    window.addEventListener('hashchange', openFromHash);
}
