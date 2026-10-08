// Rückfrage in der Zeile (x-inline-confirm): Der Knopf weicht der Frage mit
// Bestätigen und „Abbrechen“. Escape und „Abbrechen“ schließen sie und setzen
// ihr Formular zurück, der Fokus kehrt zum Knopf zurück. Gesendet wird über
// das Formular der Frage, mit data-ajax per fetch (forms.js); eine Ablehnung
// des Servers erscheint dann in der Zeile.

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

function close(root) {
    const { opener, panel } = parts(root);
    panel.hidden = true;
    panel.reset();
    opener.hidden = false;
    opener.focus();
}

export function initInlineConfirms() {
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
        if (cancel) close(cancel.closest('[data-inline-confirm]'));
    });

    // Capture-Phase: Escape schließt zuerst die Frage; eine umgebende
    // Seitenleiste bleibt offen (drawer.js prüft defaultPrevented).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape' || !(e.target instanceof Element)) return;
        const root = e.target.closest('[data-inline-confirm]');
        if (!root || parts(root).panel.hidden) return;
        e.preventDefault();
        close(root);
    }, true);
}
