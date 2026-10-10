// Rückfrage in der Zeile (x-inline-confirm): Der Knopf weicht der Frage mit
// Bestätigen und „Abbrechen“. Escape und „Abbrechen“ schließen sie und setzen
// ihr Formular zurück, der Fokus kehrt zum Knopf zurück. Gesendet wird über
// das Formular der Frage, mit data-ajax per fetch (forms.js); eine Ablehnung
// des Servers erscheint dann in der Zeile. Steht eine Frage beim Laden schon
// offen (nach einer Ablehnung mit Weiterleitung), bekommt sie den Fokus.

let installed = false;

function parts(root) {
    return {
        opener: root.querySelector('[data-inline-confirm-open]'),
        panel: root.querySelector('[data-inline-confirm-panel]'),
    };
}

function open(root) {
    const { opener, panel } = parts(root);
    opener.hidden = true;
    panel.hidden = false;
    (panel.querySelector('input:not([type="hidden"]), select, textarea') ?? panel.querySelector('[type="submit"]'))?.focus();
}

export function closeInlineConfirm(root, { focus = true } = {}) {
    const { opener, panel } = parts(root);
    if (!opener || !panel) return;
    panel.hidden = true;
    panel.reset();
    opener.hidden = false;
    if (focus) opener.focus();
}

function focusOpenPanel() {
    const panel = [...document.querySelectorAll('[data-inline-confirm-panel]')]
        .find((el) => !el.closest('[hidden]'));
    (panel?.querySelector('input:not([type="hidden"]), select, textarea') ?? panel?.querySelector('[type="submit"]'))?.focus();
}

export function initInlineConfirms() {
    focusOpenPanel();
    if (installed) return;
    installed = true;

    document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        const opener = e.target.closest('[data-inline-confirm-open]');
        if (opener) {
            open(opener.closest('[data-inline-confirm]'));
            return;
        }
        const cancel = e.target.closest('[data-inline-confirm-cancel]');
        if (cancel) closeInlineConfirm(cancel.closest('[data-inline-confirm]'));
    });

    // Capture-Phase: Escape schließt zuerst die Frage; eine umgebende
    // Seitenleiste bleibt offen (drawer.js prüft defaultPrevented).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !(e.target instanceof Element)) return;
        const root = e.target.closest('[data-inline-confirm]');
        if (!root || parts(root).panel.hidden) return;
        e.preventDefault();
        closeInlineConfirm(root);
    }, true);
}
