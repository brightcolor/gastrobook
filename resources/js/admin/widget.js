// Code für die eigene Website (Einstellungen › Buchungsseite & Widget):
// Knopf mit Buchungsfenster, eingebettetes Formular und einfacher Link. Die
// Adressen stehen am Element [data-widget-builder] (data-popup-src,
// data-embed-src, data-booking-url, data-default-label, data-default-color);
// Eingaben mit data-widget-input ändern den Code sofort, die Knöpfe „Code
// kopieren“ (copy.js) bekommen den aktuellen Code in data-copy.
//
// Der Code landet auf fremden Websites. Jeder Wert, der aus einer Eingabe oder
// von der Seite stammt, wird für seine Stelle maskiert (attr, text), und Farben
// gelten in der Schreibweise #rrggbb.

// Farbe, die der Code übernimmt: #rrggbb, Groß- und Kleinbuchstaben.
const HEX_COLOR = /^#[0-9a-fA-F]{6}$/;

// Text für ein HTML-Attribut in doppelten Anführungszeichen: &, ", < und > maskiert.
export function attr(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

// Text zwischen zwei Tags.
function text(value) {
    return String(value ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
}

// Die gewählte Farbe, wenn sie #rrggbb ist, sonst die Vorgabe, sonst leer.
function colorOf(value, fallback) {
    const chosen = String(value ?? '');
    if (HEX_COLOR.test(chosen)) return chosen;
    const preset = String(fallback ?? '');
    return HEX_COLOR.test(preset) ? preset : '';
}

export function buildSnippets({ popupSrc, embedSrc, bookingUrl, label, color, float, linkLabel, linkColor, defaultColor = '' }) {
    const buttonColor = colorOf(color, defaultColor);
    // Mit Hintergrund steht die Schrift weiß darauf. Fehlt jede gültige Farbe, gilt die Linkfarbe der Website.
    const linkColors = colorOf(linkColor, defaultColor);
    const colors = linkColors ? `background:${attr(linkColors)};color:#fff;` : '';

    return {
        // Fehlt jede gültige Farbe, setzt das Skript des Betriebs seine Markenfarbe selbst.
        popup: `<script src="${attr(popupSrc)}" data-label="${attr(label)}"${buttonColor ? ` data-color="${attr(buttonColor)}"` : ''}${float ? ' data-float="1"' : ''} defer></script>`,
        inline: `<div id="swayy-widget"></div>\n<script src="${attr(embedSrc)}" defer></script>`,
        link: `<a href="${attr(bookingUrl)}" target="_blank" rel="noopener"\n`
            + `   style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;${colors}border-radius:10px;font-family:inherit;font-size:15px;font-weight:600;text-decoration:none;">\n`
            + `  ${text(linkLabel)}\n</a>`,
    };
}

let installed = false;

// Füllt Code und Kopierknöpfe neu aus den Einstellungen und Eingaben der Seite.
function render() {
    const config = document.querySelector('[data-widget-builder]');
    if (!config) return;
    const input = (name) => document.querySelector(`[data-widget-input="${name}"]`);
    const fallback = config.dataset.defaultLabel || '';

    const snippets = buildSnippets({
        popupSrc: config.dataset.popupSrc,
        embedSrc: config.dataset.embedSrc,
        bookingUrl: config.dataset.bookingUrl,
        label: input('label')?.value.trim() || fallback,
        color: input('color')?.value || '',
        float: input('float')?.checked ?? false,
        linkLabel: input('link-label')?.value.trim() || fallback,
        linkColor: input('link-color')?.value || '',
        defaultColor: config.dataset.defaultColor || '',
    });
    Object.entries(snippets).forEach(([kind, code]) => {
        document.querySelectorAll(`[data-widget-snippet="${kind}"]`).forEach((el) => {
            el.textContent = code;
        });
        document.querySelectorAll(`[data-widget-copy="${kind}"]`).forEach((el) => {
            el.dataset.copy = code;
        });
    });
}

export function initWidgetBuilder() {
    render();
    if (installed) return;
    installed = true;

    const onChange = (e) => {
        if (e.target instanceof Element && e.target.matches('[data-widget-input]')) render();
    };
    document.addEventListener('input', onChange);
    document.addEventListener('change', onChange);
}
