// Hinweise und Felder, die nur bei bestimmten Eingaben gelten.
// data-show-if="capacity_mode:person,hybrid" zeigt das Element, solange das
// Feld capacity_mode einen dieser Werte hat. Mehrere Bedingungen, getrennt
// durch „;“, müssen alle zutreffen: "fields[email]:hidden;fields[phone]:hidden".
// Gesucht wird im Formular des Elements, sonst im ganzen Dokument. Felder in
// verborgenen Bereichen werden weiter gesendet.

let installed = false;

// Wert eines Feldes nach Name: gewählte Option, Haken (sonst das versteckte
// 0-Feld von x-checkbox) oder Eingabe.
function currentValue(scope, name) {
    const fields = [...scope.querySelectorAll(`[name="${CSS.escape(name)}"]`)];
    const choices = fields.filter((el) => el.type === 'radio' || el.type === 'checkbox');
    if (choices.length > 0) {
        const picked = choices.find((el) => el.checked);
        if (picked) return picked.value;
        return fields.find((el) => el.type === 'hidden')?.value ?? '';
    }
    return fields.at(-1)?.value ?? '';
}

function matches(el) {
    const scope = el.closest('form') ?? document;
    return (el.dataset.showIf || '').split(';').filter(Boolean).every((condition) => {
        // Der erste Doppelpunkt trennt Feldname und Werte; ein Wert darf selbst Doppelpunkte tragen ("08:00").
        const at = condition.indexOf(':');
        const name = condition.slice(0, at);
        const allowed = condition.slice(at + 1).split(',');
        return allowed.includes(currentValue(scope, name));
    });
}

export function applyReveals(root = document) {
    root.querySelectorAll('[data-show-if]').forEach((el) => {
        el.hidden = !matches(el);
    });
}

export function initReveals() {
    applyReveals();
    if (installed) return;
    installed = true;
    document.addEventListener('input', () => applyReveals());
    document.addEventListener('change', () => applyReveals());
    // reset läuft vor dem Zurücksetzen; die Hinweise folgen danach.
    document.addEventListener('reset', () => queueMicrotask(() => applyReveals()), true);
}
