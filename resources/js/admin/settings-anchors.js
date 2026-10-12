// Alte Sprungmarken der früheren Einstellungsseite (#zeiten, #raeume, #widget …).
// Wer einen alten Link oder ein Lesezeichen öffnet, landet nach der
// Weiterleitung von /admin/settings auf einer Einstellungsseite, die die Marke
// nicht kennt. Das Layout legt dort die Zuordnung Marke → Adresse der neuen
// Seite in data-settings-anchors ab; sie enthält nur Seiten, die der Benutzer
// öffnen darf (Navigation::legacyAnchors()).

/**
 * Ziel für eine alte Marke. Leer, wenn die Marke fehlt, unbekannt ist oder die
 * Seite ein Element mit dieser ID hat (dann springt der Browser dorthin).
 */
export function targetFor(hash, map, doc = document) {
    const name = String(hash ?? '').replace(/^#/, '');
    if (name === '' || !Object.prototype.hasOwnProperty.call(map ?? {}, name)) return null;
    if (doc.getElementById(name)) return null;
    return map[name] || null;
}

export function initSettingsAnchors(win = window, doc = document) {
    const holder = doc.querySelector('[data-settings-anchors]');
    if (!holder) return;

    let map;
    try {
        map = JSON.parse(holder.getAttribute('data-settings-anchors') || '{}');
    } catch {
        return;
    }

    let hash = '';
    try {
        // Eine ungültige Prozentkodierung in der Adresse bleibt ohne Wirkung.
        hash = decodeURIComponent(win.location.hash);
    } catch {
        return;
    }

    const target = targetFor(hash, map, doc);
    if (target) win.location.replace(target);
}
