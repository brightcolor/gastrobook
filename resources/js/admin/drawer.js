// Seitenleiste zum Anlegen und Bearbeiten (x-drawer): von rechts, auf dem
// Smartphone bildschirmfüllend. Ein Element mit data-drawer-open="{id}"
// öffnet sie, ebenso die Adresse mit #{id} (Suche in den Einstellungen).
// Solange sie offen ist, steht #{id} in der Adresse; ein Neuladen öffnet sie
// so wieder. Escape, [data-drawer-close] und erfolgreiches Speichern des
// Hauptformulars schließen sie; Formulare und Rückfragen darin kehren zum
// gespeicherten Stand zurück, der Fokus zum öffnenden Element. Beim Öffnen
// verschwinden Fehlerhinweise früherer Anfragen; beim Laden der Seite bleiben
// die vom Server mitgelieferten stehen. Ein Klick auf
// den abgedunkelten Hintergrund (data-drawer-backdrop) schließt nur, solange
// nichts ungespeichert ist. Solange sie offen ist, ist alles andere gesperrt
// (inert), ausgenommen Ebenen mit data-overlay-layer (Hinweis,
// Bestätigungsdialog).
//
// Hauptformular ist das Formular, auf das ein Knopf mit form="…" in der
// Seitenleiste zeigt (Fußzeile). Weitere Formulare darin (etwa das Logo)
// speichern für sich, die Seitenleiste bleibt dann offen. Geänderte
// Formulare in der offenen Seitenleiste tragen data-dirty; ein angefordertes
// Neuladen (forms.js) wartet, bis sie gespeichert oder verworfen sind.
// Das Speichern des Hauptformulars schließt die Seitenleiste nur, wenn kein
// weiteres Formular ungespeicherte Eingaben trägt. Sonst bleibt sie offen, und
// der Hinweis nennt die Abschnitte, die noch offen sind: ihren Namen aus
// data-drawer-section am Formular, sonst aus der Überschrift (h2 bis h4) des
// umgebenden Abschnitts (section).

import { focusables } from './dom.js';
import { clearProblems, snapshot } from './forms.js';
import { closeInlineConfirm } from './inline-confirm.js';
import { showToast } from './toast.js';

let installed = false;
let current = null;
const baselines = new WeakMap();

function closesOnSave(drawer, form) {
    const primary = [...drawer.querySelectorAll('button[type="submit"][form]')].map((button) => button.getAttribute('form'));
    return primary.length === 0 || primary.includes(form.id);
}

// Ein input-Ereignis kommt vom Feld oder, nach „Zeile entfernen“ (rows.js),
// vom Formular selbst.
function formOf(target) {
    if (target instanceof HTMLFormElement) return target;
    return target?.form ?? null;
}

function trackChange(e) {
    const form = formOf(e.target);
    if (!current || !(form instanceof HTMLFormElement) || !current.drawer.contains(form)) return;
    form.toggleAttribute('data-dirty', snapshot(form) !== baselines.get(form));
}

// Name des Abschnitts, den ein Formular in der Seitenleiste vertritt.
function sectionOf(form) {
    return form.dataset.drawerSection
        || form.closest('section')?.querySelector('h2, h3, h4')?.textContent.trim()
        || 'weitere Eingaben';
}

// Hinweis nach dem Speichern des Hauptformulars, solange andere Formulare offen sind.
function pendingNote(names) {
    return `Noch nicht gespeichert: ${names.join(' und ')}. Speichern oder mit „Abbrechen“ verwerfen.`;
}

// Sperrt alles außer der Seitenleiste: auf jeder Ebene von ihr bis zum
// <body> die Geschwister, ausgenommen Skripte und Ebenen mit data-overlay-layer.
function cover(drawer) {
    const covered = [];
    for (let node = drawer; node.parentElement && node !== document.body; node = node.parentElement) {
        [...node.parentElement.children].forEach((el) => {
            if (el !== node && !el.matches('script, [data-overlay-layer]') && !el.hasAttribute('inert')) {
                el.setAttribute('inert', '');
                covered.push(el);
            }
        });
    }
    return covered;
}

// keepProblems: Beim Laden der Seite bringt der Server Fehler nach einer
// Weiterleitung schon mit; sie bleiben stehen. In jedem späteren Fall sind
// Fehlerhinweise von Anfragen, die nach dem Schließen scheiterten, veraltet.
function open(drawer, opener = null, { keepProblems = false } = {}) {
    if (current?.drawer === drawer) return;
    if (current) close();

    const covered = cover(drawer);
    drawer.hidden = false;
    document.body.style.overflow = 'hidden';
    if (!keepProblems) drawer.querySelectorAll('form').forEach(clearProblems);
    drawer.querySelectorAll('form').forEach((form) => baselines.set(form, snapshot(form)));
    current = { drawer, opener, covered };
    if (window.location.hash !== `#${drawer.id}`) {
        history.replaceState(null, '', `${window.location.pathname}${window.location.search}#${drawer.id}`);
    }

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
    drawer.querySelectorAll('[data-inline-confirm]').forEach((root) => closeInlineConfirm(root, { focus: false }));
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

function openFromHash({ keepProblems = false } = {}) {
    let id = '';
    try {
        id = decodeURIComponent(window.location.hash.slice(1));
    } catch {
        // Kaputte Kodierung in der Adresse (etwa „#%“): keine Seitenleiste.
        return;
    }
    const drawer = id ? document.getElementById(id) : null;
    if (drawer?.hasAttribute('data-drawer')) open(drawer, null, { keepProblems });
}

export function initDrawers() {
    openFromHash({ keepProblems: true });
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
        if (!current || !current.drawer.contains(e.target)) return;
        if (e.target.closest('[data-drawer-backdrop]') && current.drawer.querySelector('form[data-dirty]')) {
            showToast('Die Eingaben sind noch nicht gespeichert. Speichern oder mit „Abbrechen“ verwerfen.');
            return;
        }
        if (e.target.closest('[data-drawer-close]')) close();
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
            const pending = [...current.drawer.querySelectorAll('form[data-dirty]')].filter((other) => other !== form);
            if (pending.length === 0) {
                close();
                return;
            }
            // Das Zurücksetzen beim Schließen verwürfe die Eingaben der übrigen Formulare. Die Seitenleiste bleibt offen;
            // forms.js zeigt danach detail.message, darum steht der Hinweis in derselben Meldung.
            const note = pendingNote([...new Set(pending.map(sectionOf))]);
            if (typeof e.detail?.message === 'string') {
                e.detail.message = `${e.detail.message} ${note}`;
            } else {
                showToast(note);
            }
        }
        baselines.set(form, snapshot(form));
        form.removeAttribute('data-dirty');
    });

    window.addEventListener('hashchange', () => openFromHash());
}
