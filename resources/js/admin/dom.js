// Kleine Helfer für die Module der Verwaltung.

// Ob ein Element per Tastatur fokussiert ist. Wo der Selektor :focus-visible
// fehlt (ältere Browser, jsdom), gilt der Fokus als per Zeiger gesetzt.
export function keyboardFocused(el) {
    try {
        return el.matches(':focus-visible');
    } catch {
        return false;
    }
}

// Neuladen der Seite; als Objekt, damit Tests den Aufruf beobachten können.
export const page = {
    reload() {
        window.location.reload();
    },
};

const FOCUSABLE = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(', ');

// Bedienbare Elemente in einem Bereich, in Dokumentreihenfolge, ohne solche
// in verborgenen Teilen.
export function focusables(root) {
    return [...root.querySelectorAll(FOCUSABLE)].filter((el) => !el.closest('[hidden]'));
}
