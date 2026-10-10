// Navigation der Verwaltung: Symbolleiste am Tablet, „Mehr“ auf dem
// Smartphone, Standortwahl ohne Inline-Handler, Suche im Einstellungsbereich.

import { keyboardFocused } from './dom.js';

const RAIL_KEY = 'swayy.nav.collapsed';

// Ohne gespeicherten Wert startet die Symbolleiste eingeklappt: Am Tablet
// bleibt so Platz für Live-Board und Tischplan.
function readCollapsed() {
    try {
        const value = window.localStorage.getItem(RAIL_KEY);
        return value === null ? true : value === '1';
    } catch {
        return true;
    }
}

function writeCollapsed(value) {
    try {
        window.localStorage.setItem(RAIL_KEY, value ? '1' : '0');
    } catch {
        // Gesperrter Speicher (privates Fenster): Der Zustand gilt bis zum Neuladen.
    }
}

export function initRail() {
    const rail = document.querySelector('[data-nav-rail]');
    const toggle = document.querySelector('[data-nav-collapse]');
    if (!rail || !toggle) return;

    const apply = (collapsed) => {
        rail.dataset.collapsed = collapsed ? 'true' : 'false';
        toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        toggle.setAttribute('aria-label', collapsed ? 'Navigation ausklappen' : 'Navigation einklappen');
    };

    const setCollapsed = (collapsed) => {
        apply(collapsed);
        writeCollapsed(collapsed);
    };

    apply(readCollapsed());

    toggle.addEventListener('click', () => setCollapsed(rail.dataset.collapsed !== 'true'));
    // Die Schaltflächen der eingeklappten Leiste (Standort) klappen sie aus.
    document.querySelectorAll('[data-nav-expand]').forEach((el) => {
        el.addEventListener('click', () => {
            setCollapsed(false);
            toggle.focus();
        });
    });

    initRailTips(rail);
}

// Bezeichnungen der schmalen Leiste: Sie erscheinen neben dem Symbol bei
// Zeiger und Tastaturfokus, Escape blendet sie aus. Den Namen für
// Screenreader trägt der Eintrag selbst (verborgene Bezeichnung, aria-label
// oder sr-only-Text), deshalb ist der Hinweis aria-hidden.
function initRailTips(rail) {
    const tip = document.createElement('div');
    tip.className = 'nav-tip';
    tip.setAttribute('aria-hidden', 'true');
    tip.hidden = true;
    const bubble = document.createElement('span');
    tip.append(bubble);
    document.body.append(tip);

    let current = null;

    // Leer, solange die Bezeichnung sichtbar in der Leiste steht.
    const labelOf = (el) => {
        const label = el.querySelector('.nav-label');
        if (label) return label.getBoundingClientRect().width > 1 ? '' : label.textContent.trim();
        return (el.getAttribute('aria-label') || el.querySelector('.sr-only')?.textContent || '').trim();
    };

    const hide = () => {
        // Ein eigener title erscheint wieder, sobald der Hinweis weg ist.
        if (current && current.dataset.tipTitle !== undefined) {
            current.title = current.dataset.tipTitle;
            delete current.dataset.tipTitle;
        }
        current = null;
        tip.hidden = true;
    };

    const show = (el) => {
        const text = labelOf(el);
        if (!text) return;
        hide();
        current = el;
        if (el.title) {
            el.dataset.tipTitle = el.title;
            el.removeAttribute('title');
        }
        bubble.textContent = text;
        const box = el.getBoundingClientRect();
        tip.style.left = `${box.right}px`;
        tip.style.top = `${box.top + box.height / 2}px`;
        tip.hidden = false;
    };

    rail.querySelectorAll('a, button').forEach((el) => {
        el.addEventListener('mouseenter', () => show(el));
        el.addEventListener('mouseleave', (e) => {
            if (!tip.contains(e.relatedTarget)) hide();
        });
        el.addEventListener('focus', () => {
            if (keyboardFocused(el)) show(el);
        });
        el.addEventListener('blur', hide);
        el.addEventListener('click', hide);
    });
    tip.addEventListener('mouseleave', (e) => {
        if (!current || !current.contains(e.relatedTarget)) hide();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && current) hide();
    });
    rail.addEventListener('scroll', hide, true);
    window.addEventListener('resize', hide);
}

export function initMoreSheet() {
    const sheet = document.getElementById('swayyMore');
    const opener = document.querySelector('[data-more-open]');
    if (!sheet || !opener) return;
    // Hinter dem Blatt ist alles andere gesperrt: Inhalt, Kopfleiste und untere Leiste.
    const covered = [document.getElementById('main-content'), ...document.querySelectorAll('[data-sheet-inert]')]
        .filter(Boolean);

    const open = () => {
        sheet.hidden = false;
        opener.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
        covered.forEach((el) => el.setAttribute('inert', ''));
        sheet.querySelector('[role="dialog"] a, [role="dialog"] button, [role="dialog"] select')?.focus();
    };
    const close = () => {
        sheet.hidden = true;
        opener.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
        covered.forEach((el) => el.removeAttribute('inert'));
        opener.focus();
    };

    opener.addEventListener('click', open);
    sheet.querySelectorAll('[data-more-close]').forEach((el) => el.addEventListener('click', close));
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !sheet.hidden && !e.defaultPrevented) close();
    });
    // Ab 768 px gibt es kein Blatt mehr; ein offener Zustand würde Seite und Bildlauf sperren.
    window.matchMedia('(min-width: 768px)').addEventListener('change', (e) => {
        if (e.matches && !sheet.hidden) close();
    });
}

export function initAutosubmit() {
    document.addEventListener('change', (e) => {
        const el = e.target;
        if (el instanceof HTMLSelectElement && el.hasAttribute('data-autosubmit') && el.form) {
            el.form.requestSubmit();
        }
    });
}

// Suche im Einstellungsbereich. Suchbegriffe (data-keywords, getrennt durch |)
// tragen optional ein Ziel auf der Seite: „Export#export“. Ein Treffer mit Ziel
// führt direkt zur passenden Karte; ohne Suche gilt wieder die Adresse aus der
// Navigation. Die Beispiele im Hinweis ohne Treffer stehen am Suchfeld
// (data-examples, config/navigation.php: search_examples).
export function initSettingsSearch() {
    document.querySelectorAll('[data-settings-search]').forEach((input) => {
        const list = document.getElementById(input.getAttribute('aria-controls'));
        if (!list) return;
        const noResult = list.querySelector('[data-settings-noresult]');
        const entries = (link) => (link.dataset.keywords || '')
            .split('|')
            .filter(Boolean)
            .map((entry) => {
                const [word, anchor = ''] = entry.split('#');
                return { word, anchor };
            });
        list.querySelectorAll('[data-settings-item]').forEach((link) => {
            link.dataset.baseHref = link.getAttribute('href');
        });

        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('de');
            let any = false;

            list.querySelectorAll('[data-settings-group]').forEach((group) => {
                let visible = 0;
                group.querySelectorAll('[data-settings-item]').forEach((link) => {
                    const label = (link.dataset.label || '').toLocaleLowerCase('de');
                    let hit = null;
                    let match = query === '' || label.includes(query);
                    if (!match) {
                        hit = entries(link).find(({ word }) => word.toLocaleLowerCase('de').includes(query)) ?? null;
                        match = hit !== null;
                    }
                    link.hidden = !match;
                    const base = link.dataset.baseHref;
                    link.setAttribute('href', hit?.anchor ? `${base.split('#')[0]}#${hit.anchor}` : base);
                    const hint = link.querySelector('[data-settings-hit]');
                    if (hint) {
                        hint.textContent = hit?.word ?? '';
                        hint.hidden = hit === null;
                    }
                    if (match) visible += 1;
                });
                group.hidden = visible === 0;
                if (visible > 0) any = true;
            });

            // Die Live-Region bleibt im Dokument, nur ihr Text wechselt; so sagt
            // der Screenreader den Hinweis an.
            if (noResult) {
                const examples = (input.dataset.examples || '').split('|').filter(Boolean).slice(0, 2);
                const hint = examples.length === 2 ? ` Versuch einen anderen Begriff, etwa „${examples[0]}“ oder „${examples[1]}“.` : '';
                noResult.textContent = any ? '' : `Zu „${input.value.trim()}“ passt keine Einstellung.${hint}`;
            }
        });
    });
}
