// Minutenfelder mit data-minutes-hint zeigen darunter, was die Zahl bedeutet
// („entspricht 2 Std. 30 Min.“), im Element mit
// data-minutes-hint-for="{ID des Feldes}" (x-field mit :minutes="true").
// Unter einer Stunde bleibt der Hinweis leer; die Einheit steht am Feld.

let installed = false;

export function describeMinutes(value) {
    const total = Number.parseInt(String(value), 10);
    if (!Number.isFinite(total) || total < 0) return '';
    const days = Math.floor(total / 1440);
    const hours = Math.floor((total % 1440) / 60);
    const minutes = total % 60;
    const parts = [];
    if (days > 0) parts.push(days === 1 ? '1 Tag' : `${days} Tage`);
    if (hours > 0) parts.push(`${hours} Std.`);
    if (minutes > 0 || parts.length === 0) parts.push(`${minutes} Min.`);
    return parts.join(' ');
}

function update(input) {
    const hint = document.querySelector(`[data-minutes-hint-for="${CSS.escape(input.id)}"]`);
    if (!hint) return;
    hint.textContent = Number.parseInt(input.value, 10) >= 60 ? `entspricht ${describeMinutes(input.value)}` : '';
}

export function initMinuteHints() {
    document.querySelectorAll('input[data-minutes-hint]').forEach(update);
    if (installed) return;
    installed = true;
    document.addEventListener('input', (e) => {
        if (e.target instanceof HTMLInputElement && e.target.hasAttribute('data-minutes-hint')) update(e.target);
    });
    // reset läuft vor dem Zurücksetzen; der Hinweis folgt danach.
    document.addEventListener('reset', (e) => {
        const form = e.target;
        if (form instanceof HTMLFormElement) {
            queueMicrotask(() => form.querySelectorAll('input[data-minutes-hint]').forEach(update));
        }
    }, true);
}
