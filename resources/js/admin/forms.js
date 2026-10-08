// Formulare mit data-ajax senden per fetch und melden Ergebnis und Fehler an
// Ort und Stelle. Texte für Antworten ohne JSON (Proxy-Fehlerseite,
// Weiterleitung, abgebrochene Verbindung) kommen aus
// App\Support\AdminJsonErrors::forClient() über window.SwayyAdmin.problemTexts.
//
// Ein Handler am Dokument (Capture-Phase) übernimmt alle Formulare mit
// data-ajax, auch solche, die später erscheinen (Seitenleisten, Rückfragen in
// der Zeile). Der Bestätigungsdialog des Layouts und der Doppelklickschutz in
// app.js sehen das Ereignis danach als erledigt (defaultPrevented).
//
// data-reset-on-error: Das Formular kehrt bei einem Fehler zum gespeicherten
// Stand zurück, etwa eine Rollenauswahl, die sofort speichert.

import { page } from './dom.js';
import { showToast } from './toast.js';

let problemCount = 0;
let inFlight = 0;
let reloadRequested = false;
let installed = false;
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

function describedBy(el, add = null, remove = () => false) {
    const ids = (el.getAttribute('aria-describedby') || '').split(' ').filter((id) => id && !remove(id));
    if (add && !ids.includes(add)) ids.push(add);
    if (ids.length) {
        el.setAttribute('aria-describedby', ids.join(' '));
    } else {
        el.removeAttribute('aria-describedby');
    }
}

function clearProblems(form) {
    form.querySelector('[data-form-problems]')?.remove();
    form.querySelectorAll('[data-ajax-error]').forEach((el) => el.remove());
    form.querySelectorAll('[data-ajax-invalid]').forEach((el) => {
        el.removeAttribute('aria-invalid');
        el.removeAttribute('data-ajax-invalid');
        describedBy(el, null, (id) => id.startsWith('formProblems') || id === `${el.id}-error`);
    });
}

// Fehlertext direkt am Feld, mit derselben ID wie bei x-field ({id}-error):
// unter dem Feld in seinem Block (Beschriftung, Feld, Hilfesatz).
function showFieldError(form, field, text) {
    const errorId = `${field.id}-error`;
    let note = document.getElementById(errorId);
    if (!note) {
        note = document.createElement('p');
        note.id = errorId;
        note.className = 'text-[0.8125rem] text-red-700';
        const block = form.querySelector(`label[for="${CSS.escape(field.id)}"]`)?.parentElement ?? field.parentElement;
        block.append(note);
    }
    note.dataset.ajaxError = '';
    note.textContent = text;
    describedBy(field, errorId);
}

// Eintrag der Zusammenfassung: mit Feld als Link, der den Fokus dorthin setzt.
function summaryEntry({ message, field }) {
    if (!field?.id) return document.createTextNode(message);
    const link = document.createElement('a');
    link.href = `#${field.id}`;
    link.className = 'underline underline-offset-2';
    link.textContent = message;
    link.addEventListener('click', (e) => {
        e.preventDefault();
        field.focus();
    });
    return link;
}

// Zusammenfassung oben im Formular mit Links zu den Feldern, dazu der Text am
// Feld und aria-invalid ("hours.0.opens_at" heißt im Formular
// "hours[0][opens_at]"). Der Fokus springt zum ersten gemeldeten Feld.
function showProblems(form, json, messages) {
    const box = document.createElement('div');
    box.id = `formProblems${++problemCount}`;
    box.dataset.formProblems = '';
    box.setAttribute('role', 'alert');
    box.tabIndex = -1;
    box.className = 'rounded-lg border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800';

    const entries = [];
    let first = null;
    Object.entries(json?.errors ?? {}).forEach(([key, list]) => {
        const [head, ...rest] = key.split('.');
        const name = head + rest.map((part) => `[${part}]`).join('');
        const fields = [...form.querySelectorAll(`[name="${CSS.escape(name)}"], [name="${CSS.escape(name)}[]"]`)];
        const text = [].concat(list).join(' ');
        const field = fields[0] ?? null;
        fields.forEach((el) => {
            el.setAttribute('aria-invalid', 'true');
            el.setAttribute('data-ajax-invalid', '');
            // Felder ohne eigene ID (etwa Optionsfelder) verweisen auf die Zusammenfassung.
            if (!el.id) describedBy(el, box.id);
        });
        if (field?.id) showFieldError(form, field, text);
        first ??= field;
        entries.push({ message: text, field });
    });
    if (entries.length === 0) messages.forEach((message) => entries.push({ message, field: null }));

    if (entries.length === 1) {
        box.append(summaryEntry(entries[0]));
    } else {
        const list = document.createElement('ul');
        list.className = 'list-disc space-y-1 pl-4';
        entries.forEach((entry) => {
            const item = document.createElement('li');
            item.append(summaryEntry(entry));
            list.append(item);
        });
        box.append(list);
    }

    form.prepend(box);
    (first ?? box).focus();
}

function fail(form, json, messages) {
    showToast(messages.join(' · '), { error: true });
    if (form.hasAttribute('data-reset-on-error')) form.reset();
    showProblems(form, json, messages);
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

// Neu geladen wird erst, wenn keine Anfrage mehr läuft und kein Formular
// ungespeicherte Änderungen trägt (data-dirty, savebar.js), und nur einmal je
// Anforderung. Bleibt etwas offen, lädt die nächste erfolgreiche Speicherung
// oder „Verwerfen“ neu. Die Wartezeit kommt aus config/swayy.php
// (ui.reload_delay_ms), damit „Gespeichert“ lesbar bleibt.
function reloadWhenIdle() {
    setTimeout(() => {
        if (inFlight === 0 && reloadRequested && !document.querySelector('form[data-dirty]')) {
            reloadRequested = false;
            page.reload();
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
            fail(form, json, problemsOf(res, json));
            return false;
        }

        // Gespeichert meldet nur, wer es bestätigt. Eine Antwort ohne JSON
        // (etwa nach einer Weiterleitung) lässt offen, ob die Änderung ankam.
        if (!json || typeof json.message !== 'string') {
            fail(form, null, [texts().unclear || '']);
            return false;
        }

        commitDefaults(values);
        sentSnapshots.set(form, sent);
        form.dispatchEvent(new CustomEvent('swayy:saved', { detail: json, bubbles: true }));
        showToast(json.message);
        if (json.reload) reloadRequested = true;
        return true;
    } catch {
        fail(form, null, [texts().network || '']);
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
    if (installed) return;
    installed = true;

    document.addEventListener('submit', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-ajax')) return;
        e.preventDefault();
        submitForm(form);
    }, true);

    // Zurückgesetzt („Verwerfen“, Schließen einer Seitenleiste) gilt der
    // gespeicherte Stand; Fehlerhinweise zu den verworfenen Eingaben
    // verschwinden mit ihnen.
    document.addEventListener('reset', (e) => {
        const form = e.target;
        if (form instanceof HTMLFormElement && form.hasAttribute('data-ajax')) clearProblems(form);
    }, true);

    // Nach „Verwerfen“ kann ein zurückgestelltes Neuladen nachgeholt werden.
    document.addEventListener('swayy:discarded', () => {
        if (reloadRequested) reloadWhenIdle();
    });
}
