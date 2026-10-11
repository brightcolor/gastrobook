// Minutenfelder mit data-minutes-hint zeigen darunter, was die Zahl bedeutet
// („entspricht 2 Std. 30 Min.“), im Element mit
// data-minutes-hint-for="{ID des Feldes}" (x-field mit :minutes="true").
// Unter einer Stunde bleibt der Hinweis leer; die Einheit steht am Feld.
// Stundenfelder (data-minutes-unit="hours", x-field mit minutes="hours")
// rechnen die Eingabe in Minuten um; der Hinweis erscheint ab einem Tag.

let installed = false;

const MINUTES_PER_HOUR = 60;
const MINUTES_PER_DAY = 1440;

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
    const hours = input.dataset.minutesUnit === 'hours';
    const total = Number.parseInt(input.value, 10) * (hours ? MINUTES_PER_HOUR : 1);
    hint.textContent = total >= (hours ? MINUTES_PER_DAY : MINUTES_PER_HOUR) ? `entspricht ${describeMinutes(total)}` : '';
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
