// Gemeinsame Vorbereitung der JS-Tests: Werte, die sonst das Layout über
// window.SwayyAdmin liefert, die Hinweis-Fläche und was jsdom fehlt.
import { afterEach, beforeEach, vi } from 'vitest';

beforeEach(() => {
    window.SwayyAdmin = {
        ui: { toastMs: 3200, reloadDelayMs: 0 },
        problemTexts: {
            default: 'Der Server hat mit Status :status geantwortet. Bitte später erneut versuchen.',
            500: 'Auf dem Server ist ein Fehler aufgetreten. Bitte später erneut versuchen.',
            unclear: 'Unklar, ob gespeichert wurde. Bitte die Seite neu laden und prüfen.',
            network: 'Keine Verbindung zum Server. Bitte die Verbindung prüfen und erneut speichern.',
        },
    };
    // jsdom kennt matchMedia nicht; setWide() aus helpers.js stellt die Breite ein.
    window.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
    document.body.innerHTML = '';
    document.body.removeAttribute('style');
});

afterEach(() => {
    vi.useRealTimers();
    history.replaceState(null, '', '/');
});
