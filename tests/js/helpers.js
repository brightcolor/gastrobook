// Helfer für die JS-Tests der Verwaltung.

// Antwort mit JSON, wie sie die Controller über RespondsToSettingsForms senden.
export function jsonResponse(body, status = 200) {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

// Antwort ohne JSON, etwa eine Fehlerseite des Proxys.
export function htmlResponse(status) {
    return new Response('<html></html>', { status, headers: { 'Content-Type': 'text/html' } });
}

// Bildschirmbreite ab 768 px (md) an oder aus.
export function setWide(matches) {
    window.matchMedia = () => ({ matches, addEventListener() {}, removeEventListener() {} });
}

// HTML in den Body setzen; die Hinweis-Fläche des Layouts kommt immer dazu.
export function mount(html) {
    document.body.innerHTML = `${html}<div id="swayyToast" data-overlay-layer></div>`;
}
