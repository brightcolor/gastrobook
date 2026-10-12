// Knopf mit data-copy="…" legt den Text in die Zwischenablage und meldet das
// als Hinweis (data-copy-message). Verweigert der Browser das Kopieren
// (Rechte, unsichere Verbindung), nennt der Hinweis den Weg von Hand.

import { showToast } from './toast.js';

let installed = false;

export function initCopyButtons() {
    if (installed) return;
    installed = true;

    document.addEventListener('click', async (e) => {
        if (!(e.target instanceof Element)) return;
        const button = e.target.closest('[data-copy]');
        if (!button) return;
        try {
            await navigator.clipboard.writeText(button.dataset.copy);
            showToast(button.dataset.copyMessage || 'Kopiert.');
        } catch {
            showToast('Der Browser erlaubt den Zugriff auf die Zwischenablage nicht. Bitte den Text markieren und mit Strg+C (Mac: ⌘+C) kopieren.', { error: true });
        }
    });
}
