// Formulare mit data-ajax senden per fetch und melden Ergebnis und Fehler an
// Ort und Stelle. Texte für Antworten ohne JSON (Proxy-Fehlerseite,
// Weiterleitung, abgebrochene Verbindung) kommen aus
// App\Support\AdminJsonErrors::forClient() über window.SwayyAdmin.problemTexts.

import { showToast } from './toast.js';

let problemCount = 0;

function texts() {
    return window.SwayyAdmin?.problemTexts ?? {};
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

// Nach dem Speichern gilt der gespeicherte Stand als Vorgabe: „Verwerfen"
// (form.reset) kehrt dann zu ihm zurück.
function commitDefaults(form) {
    for (const el of form.elements) {
        if (el instanceof HTMLInputElement) {
            if (el.type === 'checkbox' || el.type === 'radio') {
                el.defaultChecked = el.checked;
            } else if (el.type !== 'file') {
                el.defaultValue = el.value;
            }
        } else if (el instanceof HTMLTextAreaElement) {
            el.defaultValue = el.value;
        } else if (el instanceof HTMLSelectElement) {
            for (const option of el.options) option.defaultSelected = option.selected;
        }
    }
}

export async function submitForm(form) {
    clearProblems(form);
    const submitter = form.querySelector('[type="submit"]');
    submitter?.setAttribute('aria-busy', 'true');

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

        commitDefaults(form);
        form.dispatchEvent(new CustomEvent('swayy:saved', { detail: json }));
        showToast(json.message);
        if (json.reload) setTimeout(() => window.location.reload(), 700);
        return true;
    } catch {
        const message = texts().network || '';
        showToast(message, { error: true });
        showProblems(form, null, [message]);
        return false;
    } finally {
        submitter?.removeAttribute('aria-busy');
    }
}

export function initAjaxForms() {
    document.querySelectorAll('form[data-ajax]').forEach((form) => {
        form.addEventListener('submit', (e) => {
            e.preventDefault();
            submitForm(form);
        });
    });
}
