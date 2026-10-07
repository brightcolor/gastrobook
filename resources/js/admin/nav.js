// Navigation der Verwaltung: Symbolleiste am Tablet, „Mehr“ auf dem
// Smartphone, Standortwahl ohne Inline-Handler, Suche im Einstellungsbereich.

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
    // Der gespeicherte Zustand gilt sofort; animiert wird erst danach. Das
    // erzwungene Layout trennt beides, sonst liefe die Breite beim Laden ein.
    void rail.offsetWidth;
    window.requestAnimationFrame(() => {
        rail.dataset.navReady = '';
    });

    toggle.addEventListener('click', () => setCollapsed(rail.dataset.collapsed !== 'true'));
    // Die Schaltflächen der eingeklappten Leiste (Standort) klappen sie aus.
    document.querySelectorAll('[data-nav-expand]').forEach((el) => {
        el.addEventListener('click', () => {
            setCollapsed(false);
            toggle.focus();
        });
    });
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
        if (e.key === 'Escape' && !sheet.hidden) close();
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

export function initSettingsSearch() {
    document.querySelectorAll('[data-settings-search]').forEach((input) => {
        const list = document.getElementById(input.getAttribute('aria-controls'));
        if (!list) return;
        const noResult = list.querySelector('[data-settings-noresult]');

        input.addEventListener('input', () => {
            const query = input.value.trim().toLocaleLowerCase('de');
            let any = false;

            list.querySelectorAll('[data-settings-group]').forEach((group) => {
                let visible = 0;
                group.querySelectorAll('[data-settings-item]').forEach((link) => {
                    const label = (link.dataset.label || '').toLocaleLowerCase('de');
                    const words = (link.dataset.keywords || '').split('|').filter(Boolean);
                    let hit = '';
                    let match = query === '' || label.includes(query);
                    if (!match) {
                        hit = words.find((word) => word.toLocaleLowerCase('de').includes(query)) || '';
                        match = hit !== '';
                    }
                    link.hidden = !match;
                    const hint = link.querySelector('[data-settings-hit]');
                    if (hint) {
                        hint.textContent = hit;
                        hint.hidden = hit === '';
                    }
                    if (match) visible += 1;
                });
                group.hidden = visible === 0;
                if (visible > 0) any = true;
            });

            if (noResult) {
                noResult.hidden = any;
                noResult.textContent = any
                    ? ''
                    : `Zu „${input.value.trim()}“ passt keine Einstellung. Versuch einen anderen Begriff, etwa „Erinnerung“ oder „Stornofrist“.`;
            }
        });
    });
}
