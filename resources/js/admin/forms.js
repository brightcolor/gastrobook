// Formulare mit data-ajax senden per fetch und melden Ergebnis und Fehler an
// Ort und Stelle. Texte für Antworten ohne JSON (Proxy-Fehlerseite,
// Weiterleitung, abgebrochene Verbindung) kommen aus
// App\Support\AdminJsonErrors::forClient() über window.SwayyAdmin.problemTexts.

import { showToast } from './toast.js';

let problemCount = 0;
let inFlight = 0;
let reloadRequested = false;
const pending = new WeakMap();
const sentSnapshots = new WeakMap();

function texts() {
    return window.SwayyAdmin?.problemTexts ?? {};
}

// Stand eines Formulars als Text; die Speicherleiste vergleicht damit.
export const snapshot = (form) => new URLSearchParams(new FormData(form)).toString();

// Stand beim letzten erfolgreichen Absenden. Eingaben während der Anfrage
// gehören nicht dazu und bleiben danach ungespeichert.
export function savedSnapshot(form) {
    return sentSnapshots.get(form);
}

async function readJson(res) {
    if (!(res.headers.get('Content-Type') || '').includes('json')) return null;
    try {
        return await res.json();
    } catch {
        return null;
    }
}

function problemsOf(res, json) {
    if (json?.errors) return Object.values(json.errors).flat();
    if (json?.message) return [json.message];
    const t = texts();
    const text = t[res.status] || (res.status >= 500 ? t[500] : t.default) || '';
    return [text.replace(':status', String(res.status))];
}

function clearProblems(form) {
    form.querySelector('[data-form-problems]')?.remove();
    form.querySelectorAll('[data-ajax-invalid]').forEach((el) => {
        el.removeAttribute('aria-invalid');
        el.removeAttribute('data-ajax-invalid');
        const ids = (el.getAttribute('aria-describedby') || '').split(' ').filter((id) => id && !id.startsWith('formProblems'));
        if (ids.length) {
            el.setAttribute('aria-describedby', ids.join(' '));
        } else {
            el.removeAttribute('aria-describedby');
        }
    });
}

// Zusammenfassung oben im Formular, gemeldete Felder mit aria-invalid und
// Verweis auf die Zusammenfassung ("hours.0.opens_at" heißt im Formular
// "hours[0][opens_at]"). Der Fokus springt zum ersten gemeldeten Feld.
function showProblems(form, json, messages) {
    const box = document.createElement('div');
    box.id = `formProblems${++problemCount}`;
    box.dataset.formProblems = '';
    box.setAttribute('role', 'alert');
    box.tabIndex = -1;
    box.className = 'rounded-lg border border-red-200 border-l-4 border-l-red-700 bg-red-50 px-3 py-2 text-sm text-red-800';

    if (messages.length === 1) {
        box.textContent = messages[0];
    } else {
        const list = document.createElement('ul');
        list.className = 'list-disc space-y-1 pl-4';
        messages.forEach((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            list.append(item);
        });
        box.append(list);
    }

    let first = null;
    Object.keys(json?.errors ?? {}).forEach((key) => {
        const [head, ...rest] = key.split('.');
        const name = head + rest.map((part) => `[${part}]`).join('');
        form.querySelectorAll(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`).forEach((el) => {
            el.setAttribute('aria-invalid', 'true');
            el.setAttribute('data-ajax-invalid', '');
            el.setAttribute('aria-describedby', `${el.getAttribute('aria-describedby') || ''} ${box.id}`.trim());
            first ??= el;
        });
    });

    form.prepend(box);
    (first ?? box).focus();
}

// Werte beim Absenden. Nach dem Speichern werden sie zur Vorgabe, auf die
// „Verwerfen“ (form.reset) zurückkehrt.
function readValues(form) {
    const values = new Map();
    for (const el of form.elements) {
        if (el instanceof HTMLInputElement) {
            values.set(el, el.type === 'checkbox' || el.type === 'radio' ? el.checked : el.value);
        } else if (el instanceof HTMLTextAreaElement) {
            values.set(el, el.value);
        } else if (el instanceof HTMLSelectElement) {
            values.set(el, [...el.options].map((option) => option.selected));
        }
    }
    return values;
}

function commitDefaults(values) {
    values.forEach((value, el) => {
        if (el instanceof HTMLSelectElement) {
            [...el.options].forEach((option, i) => {
                option.defaultSelected = value[i] ?? false;
            });
        } else if (el.type === 'checkbox' || el.type === 'radio') {
            el.defaultChecked = value;
        } else if (el.type !== 'file') {
            el.defaultValue = value;
        }
    });
}

// Neu geladen wird erst, wenn keine Anfrage mehr läuft, und nur einmal je
// Anforderung. Die Wartezeit kommt aus config/swayy.php (ui.reload_delay_ms),
// damit „Gespeichert“ lesbar bleibt.
function reloadWhenIdle() {
    setTimeout(() => {
        if (inFlight === 0 && reloadRequested) {
            reloadRequested = false;
            window.location.reload();
        }
    }, window.SwayyAdmin?.ui?.reloadDelayMs);
}

async function send(form) {
    clearProblems(form);
    const submitter = form.querySelector('[type="submit"]');
    submitter?.setAttribute('aria-busy', 'true');
    const values = readValues(form);
    const sent = snapshot(form);
    inFlight++;

    try {
        const multipart = form.enctype === 'multipart/form-data';
        const body = multipart ? new FormData(form) : new URLSearchParams(new FormData(form));
        const headers = { Accept: 'application/json' };
        if (!multipart) headers['Content-Type'] = 'application/x-www-form-urlencoded';

        const res = await fetch(form.action || window.location.href, {
            method: (form.getAttribute('method') || 'POST').toUpperCase(),
            headers,
            body,
            credentials: 'same-origin',
        });
        const json = await readJson(res);

        if (!res.ok) {
            const messages = problemsOf(res, json);
            showToast(messages.join(' · '), { error: true });
            showProblems(form, json, messages);
            return false;
        }

        // Gespeichert meldet nur, wer es bestätigt. Eine Antwort ohne JSON
        // (etwa nach einer Weiterleitung) lässt offen, ob die Änderung ankam.
        if (!json || typeof json.message !== 'string') {
            const message = texts().unclear || '';
            showToast(message, { error: true });
            showProblems(form, null, [message]);
            return false;
        }

        commitDefaults(values);
        sentSnapshots.set(form, sent);
        form.dispatchEvent(new CustomEvent('swayy:saved', { detail: json }));
        showToast(json.message);
        if (json.reload) reloadRequested = true;
        return true;
    } catch {
        const message = texts().network || '';
        showToast(message, { error: true });
        showProblems(form, null, [message]);
        return false;
    } finally {
        submitter?.removeAttribute('aria-busy');
        inFlight--;
        if (reloadRequested) reloadWhenIdle();
    }
}

// Ein zweiter Aufruf während der Anfrage (Doppelklick, Enter und Speicherleiste)
// bekommt dieselbe Anfrage zurück; abgeschickt wird nur einmal.
export function submitForm(form) {
    if (!pending.has(form)) {
        pending.set(form, send(form).finally(() => pending.delete(form)));
    }
    return pending.get(form);
}

export function initAjaxForms() {
    document.querySelectorAll('form[data-ajax]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            submitForm(form);
        });
    });
}
