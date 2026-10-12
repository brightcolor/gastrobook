// Seite „Öffnungszeiten“: Tage öffnen und schließen, Hinweise am Zeitfenster,
// Zeiten auf andere Tage übertragen und die Vorschau „So sehen Gäste es“.
//
// Aufbau (resources/views/admin/settings/opening-hours.blade.php):
//   <form data-preview-url data-preview-delay data-duration data-windows-max data-time-step [data-salon]>
//     <fieldset data-day="0" data-day-name="Montag" data-open="1">
//       <input type="checkbox" data-day-open>            Schalter, der nichts sendet
//       <div data-rows> … Zeilen (rows.js) mit [data-hint] … </div>
//       <button data-copy-open="0">                      öffnet die Seitenleiste
//     <section data-preview> … Zeiten, Satz, Gruppen
//   Seitenleiste #tage-uebertragen: data-copy-day, data-copy-preset, data-copy-apply
//
// Ein geschlossener Tag sendet nichts: Seine Felder sind gesperrt und
// verborgen. Der Schalter trägt keinen Namen und schaltet darum nie einen
// gespeicherten Zustand zurück. Die Texte der Vorschau stehen als data-text-*
// am Element, damit sie zu Restaurant und Salon passen.

import { initRows } from './rows.js';

const MINUTES_PER_DAY = 1440;

let installed = false;
let copyCounter = 0;
// Tag, von dem die Seitenleiste „Übertragen“ gerade kopiert.
let copyFrom = null;
// Vorschau je Formular: gewählter Tag, Wartezeit, laufende Anfrage.
const previews = new WeakMap();

function minutesOf(value) {
    const match = /^(\d{2}):(\d{2})(?::\d{2})?$/.exec(value ?? '');
    return match ? Number(match[1]) * 60 + Number(match[2]) : null;
}

// Hinweis bei einem Fenster, das über Mitternacht geht.
export function overnightHint(opens, closes, nextDayName) {
    const from = minutesOf(opens);
    const to = minutesOf(closes);
    if (from === null || to === null || to >= from) return null;
    return `Endet am Folgetag (${nextDayName}) um ${closes.slice(0, 5)}.`;
}

// Hinweis bei einem Fenster mit gleicher Öffnungs- und Schließzeit: Es dauert 24 Stunden, so liest es auch die Buchungsseite.
export function allDayHint(opens, closes) {
    const from = minutesOf(opens);
    if (from === null || from !== minutesOf(closes)) return null;
    return 'Rund um die Uhr geöffnet: Das Fenster dauert 24 Stunden.';
}

// Hinweis bei einem Fenster, in dem kein Besuch Platz hat.
export function shortHint(opens, closes, durationMinutes, salon = false) {
    const from = minutesOf(opens);
    const to = minutesOf(closes);
    if (from === null || to === null || from === to || !(durationMinutes > 0)) return null;
    const length = to > from ? to - from : to - from + MINUTES_PER_DAY;
    if (length >= durationMinutes) return null;
    return salon
        ? `Kürzer als ein Termin (${durationMinutes} Minuten): Hier finden Kunden keine Uhrzeit.`
        : `Kürzer als ein Besuch (${durationMinutes} Minuten): Hier finden Gäste keine Uhrzeit.`;
}

// Eine Zeit neben dem Raster der Seite (data-time-step, Minuten) bekommt ihr Feld mit Minutenschritten, wie der Server es
// rendert (OpeningHoursRules::timeStep): Sonst hielte die Prüfung des Browsers das Speichern der ganzen Woche an.
function fitStep(field, form) {
    const grid = Number.parseInt(form.dataset.timeStep ?? '', 10);
    if (field.type !== 'time' || !(grid >= 1)) return;
    const minutes = minutesOf(field.value);
    field.step = String((minutes === null || minutes % grid === 0 ? grid : 1) * 60);
}

const formOf = (el) => el?.closest?.('form[data-preview-url]') ?? null;
const dayOf = (form, weekday) => form.querySelector(`[data-day="${weekday}"]`);
const rowsOf = (day) => [...day.querySelectorAll('[data-rows-list] [data-row]')];
const isOpen = (day) => day.dataset.open === '1';
const fieldOf = (row, name) => row.querySelector(`[name$="[${name}]"]`);
const valueOf = (row, name) => fieldOf(row, name)?.value ?? '';

// Öffnet oder schließt einen Tag: Felder, Liste, Knöpfe und der Zustand für die Speicherleiste.
function setOpen(day, open) {
    day.dataset.open = open ? '1' : '0';
    day.dataset.changeState = open ? '1' : '0';
    const toggle = day.querySelector('[data-day-open]');
    if (toggle) toggle.checked = open;
    const list = day.querySelector('[data-rows-list]');
    if (list) {
        list.hidden = !open;
        list.querySelectorAll('input, select, textarea, button').forEach((el) => {
            el.disabled = !open;
        });
    }
    day.querySelector('[data-day-actions]')?.toggleAttribute('hidden', !open);
    day.querySelector('[data-closed-note]')?.toggleAttribute('hidden', open);
}

// Ein neuer Zustand ohne Zeile ist ein geschlossener Tag; die Warnung bei einer leeren Woche folgt dem Stand.
function reconcile(form) {
    const days = [...form.querySelectorAll('[data-day]')];
    days.forEach((day) => {
        if (!isOpen(day) || rowsOf(day).length > 0) return;
        setOpen(day, false);
        // Mit der letzten Zeile verschwindet auch der Knopf, der den Fokus bekäme (rows.js setzt ihn nach dem Entfernen):
        // Liegt der Fokus danach im Leeren oder in einem verborgenen Bereich, geht er an den Schalter des Tages.
        queueMicrotask(() => {
            const active = document.activeElement;
            if (!active || active === document.body || active.closest('[hidden]')) day.querySelector('[data-day-open]')?.focus();
        });
    });
    form.querySelector('[data-week-empty]')?.toggleAttribute('hidden', days.some(isOpen));
}

// Meldet eine Änderung am Formular, wie rows.js es nach dem Hinzufügen einer Zeile tut.
function notify(form) {
    form.dispatchEvent(new Event('input', { bubbles: true }));
}

function updateHints(form, day) {
    const duration = Number.parseInt(form.dataset.duration ?? '', 10);
    const salon = form.hasAttribute('data-salon');
    const nextName = dayOf(form, (Number(day.dataset.day) + 1) % 7)?.dataset.dayName ?? '';
    rowsOf(day).forEach((row) => {
        const opens = valueOf(row, 'opens_at');
        const closes = valueOf(row, 'closes_at');
        const texts = [shortHint(opens, closes, duration, salon), overnightHint(opens, closes, nextName), allDayHint(opens, closes)].filter(Boolean);
        const hint = row.querySelector('[data-hint]');
        // Gleicher Text bleibt stehen, damit die Live-Region nicht bei jeder Eingabe spricht.
        if (!hint || hint.dataset.text === texts.join('\n')) return;
        hint.dataset.text = texts.join('\n');
        hint.replaceChildren(...texts.map((text) => {
            const line = document.createElement('span');
            line.className = 'block';
            line.textContent = text;
            return line;
        }));
    });
}

function refreshAll(form) {
    form.querySelectorAll('[data-day]').forEach((day) => updateHints(form, day));
    reconcile(form);
}

// --- Vorschau ---------------------------------------------------------------

function stateOf(form) {
    if (!previews.has(form)) previews.set(form, { day: 0, timer: null, controller: null });
    return previews.get(form);
}

function windowsOf(day) {
    if (!day || !isOpen(day)) return [];
    return rowsOf(day)
        .map((row) => ({ opens_at: valueOf(row, 'opens_at'), closes_at: valueOf(row, 'closes_at') }))
        .filter((entry) => entry.opens_at !== '' && entry.closes_at !== '');
}

const clone = (root, selector) => root.querySelector(selector).content.firstElementChild.cloneNode(true);

function showMessage(root, text) {
    root.querySelector('[data-preview-times]').replaceChildren();
    root.querySelector('[data-preview-times]').hidden = true;
    root.querySelector('[data-preview-groups]').replaceChildren();
    root.querySelector('[data-preview-groups]').hidden = true;
    root.querySelector('[data-preview-reason]').textContent = text;
}

function render(root, data) {
    const times = root.querySelector('[data-preview-times]');
    times.replaceChildren(...(data.starts ?? []).map((time) => {
        const item = clone(root, 'template[data-preview-time-template]');
        item.textContent = time;
        return item;
    }));
    times.hidden = (data.starts ?? []).length === 0;
    root.querySelector('[data-preview-reason]').textContent = data.reason ?? '';

    const groups = root.querySelector('[data-preview-groups]');
    groups.replaceChildren(...(data.groups ?? []).map((group) => {
        const item = clone(root, 'template[data-preview-group-template]');
        item.querySelector('[data-group-label]').textContent = group.label;
        item.querySelector('[data-group-last]').textContent = group.last
            ? (root.dataset.textLast ?? '').replace(':time', group.last)
            : (root.dataset.textNone ?? '');
        return item;
    }));
    groups.hidden = (data.groups ?? []).length === 0;
}

async function readJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

async function runPreview(form) {
    const root = form.querySelector('[data-preview]');
    const state = stateOf(form);
    const day = dayOf(form, state.day);
    // Ein Formular, das nicht mehr auf der Seite steht, braucht keine Vorschau mehr.
    if (!form.isConnected || !root || !day) return;

    state.controller?.abort();
    const controller = new AbortController();
    state.controller = controller;
    root.querySelector('[data-preview-note]').textContent = (root.dataset.textNote ?? '').replace(':day', day.dataset.dayName ?? '');

    try {
        const response = await fetch(form.dataset.previewUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': form.querySelector('input[name="_token"]')?.value ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ weekday: state.day, windows: windowsOf(day) }),
            credentials: 'same-origin',
            signal: controller.signal,
        });
        const json = await readJson(response);
        // Eine neuere Anfrage hat diese abgelöst.
        if (state.controller !== controller) return;
        if (response.ok && json) {
            render(root, json);
        } else if (response.status === 422 && json?.errors) {
            // Die Meldung des Servers nennt Ursache und nächsten Schritt, etwa bei überschneidenden Fenstern.
            showMessage(root, Object.values(json.errors).flat()[0] ?? root.dataset.textUnavailable ?? '');
        } else {
            showMessage(root, root.dataset.textUnavailable ?? '');
        }
    } catch (error) {
        if (error?.name === 'AbortError' || state.controller !== controller) return;
        showMessage(root, root.dataset.textUnavailable ?? '');
    }
}

// Rechnet die Vorschau nach der Wartezeit seit der letzten Eingabe neu.
function schedule(form) {
    const state = stateOf(form);
    clearTimeout(state.timer);
    const delay = Number.parseInt(form.dataset.previewDelay ?? '', 10);
    state.timer = setTimeout(() => runPreview(form), Number.isFinite(delay) ? delay : 0);
}

// Der zuletzt bearbeitete oder fokussierte Tag bestimmt die Vorschau.
function activate(form, day) {
    const state = stateOf(form);
    const weekday = Number(day.dataset.day);
    if (state.day === weekday) return false;
    state.day = weekday;
    return true;
}

// --- Auf andere Tage übertragen ---------------------------------------------

function rowFromTemplate(template) {
    const holder = document.createElement('div');
    holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', `c${++copyCounter}`).trim();
    return holder.firstElementChild;
}

// Ersetzt die Zeitfenster der Zieltage durch Kopien der Fenster des Ausgangstags
// und öffnet die Zieltage (ein geschlossener Ausgangstag schließt sie).
export function copyWindows(form, fromDay, toDays) {
    const source = dayOf(form, fromDay);
    if (!source) return;
    // Mehr Fenster als je Tag erlaubt gehen nicht mit (data-windows-max am Formular).
    const max = Number.parseInt(form.dataset.windowsMax ?? '', 10);
    const windows = (isOpen(source) ? rowsOf(source) : [])
        .slice(0, Number.isFinite(max) ? max : undefined)
        .map((row) => ({
            opens_at: valueOf(row, 'opens_at'),
            closes_at: valueOf(row, 'closes_at'),
            service_name: valueOf(row, 'service_name'),
        }));

    toDays.filter((weekday) => weekday !== fromDay).forEach((weekday) => {
        const target = dayOf(form, weekday);
        const list = target?.querySelector('[data-rows-list]');
        const template = target?.querySelector('template[data-rows-template]');
        if (!list || !template) return;
        list.replaceChildren();
        windows.forEach((entry) => {
            const row = rowFromTemplate(template);
            Object.entries(entry).forEach(([name, value]) => {
                const field = fieldOf(row, name);
                if (!field) return;
                field.value = value;
                fitStep(field, form);
            });
            list.append(row);
        });
        setOpen(target, windows.length > 0);
    });

    // Die Schaltfläche „Hinzufügen“ eines Tages folgt der neuen Zahl der Zeilen.
    initRows();
    notify(form);
}

function prepareCopy(drawer, weekday) {
    copyFrom = weekday;
    const form = document.querySelector('form[data-preview-url]');
    const name = form ? dayOf(form, weekday)?.dataset.dayName ?? '' : '';
    drawer.querySelectorAll('[data-copy-day]').forEach((box) => {
        const isSource = Number(box.dataset.copyDay) === weekday;
        box.checked = false;
        box.disabled = isSource;
        box.closest('[data-copy-choice]')?.toggleAttribute('hidden', isSource);
    });
    const note = drawer.querySelector('[data-copy-source]');
    if (note) note.textContent = (note.dataset.textSource ?? '').replace(':day', name);
    const error = drawer.querySelector('[data-copy-error]');
    if (error) error.hidden = true;
}

function applyCopy(drawer) {
    const form = document.querySelector('form[data-preview-url]');
    const days = [...drawer.querySelectorAll('[data-copy-day]:checked')].map((box) => Number(box.dataset.copyDay));
    const error = drawer.querySelector('[data-copy-error]');
    if (!form) return;
    if (days.length === 0) {
        if (error) error.hidden = false;
        return;
    }
    if (error) error.hidden = true;
    // Wurde die Seitenleiste über die Adresse geöffnet, ist der gewählte Tag der Ausgangstag.
    copyWindows(form, copyFrom ?? stateOf(form).day, days);
    // Schließen übernimmt drawer.js (Fokus zurück zum Knopf).
    drawer.querySelector('button[data-drawer-close]')?.click();
}

// --- Ereignisse -------------------------------------------------------------

function onInput(e) {
    const form = formOf(e.target);
    if (!form) return;
    const day = e.target.closest('[data-day]');
    if (day) {
        activate(form, day);
        updateHints(form, day);
        reconcile(form);
    } else {
        // Zeile hinzugefügt oder entfernt, Zeiten übertragen: alle Tage prüfen.
        refreshAll(form);
    }
    schedule(form);
}

function onFocus(e) {
    const form = formOf(e.target);
    const day = e.target.closest?.('[data-day]');
    if (form && day && activate(form, day)) schedule(form);
}

function onChange(e) {
    if (!(e.target instanceof Element)) return;
    const toggle = e.target.closest('[data-day-open]');
    const form = formOf(toggle);
    if (!toggle || !form) return;
    const day = toggle.closest('[data-day]');
    activate(form, day);
    setOpen(day, toggle.checked);
    // Ein geöffneter Tag ohne Zeile bekommt gleich eine, in die die Zeiten gehören.
    if (toggle.checked && rowsOf(day).length === 0) day.querySelector('[data-rows-add]')?.click();
    // Speicherleiste, Hinweise und Vorschau sehen den neuen Stand erst jetzt; der input vor change kam zu früh.
    notify(form);
}

function onClick(e) {
    if (!(e.target instanceof Element)) return;
    const opener = e.target.closest('[data-copy-open]');
    if (opener) {
        const drawer = document.getElementById(opener.dataset.drawerOpen ?? '');
        if (drawer) prepareCopy(drawer, Number(opener.dataset.copyOpen));
        return;
    }
    const preset = e.target.closest('[data-copy-preset]');
    if (preset) {
        const wanted = preset.dataset.copyPreset.split(',').map(Number);
        preset.closest('[data-drawer]')?.querySelectorAll('[data-copy-day]').forEach((box) => {
            box.checked = !box.disabled && wanted.includes(Number(box.dataset.copyDay));
        });
        return;
    }
    const apply = e.target.closest('[data-copy-apply]');
    if (apply) applyCopy(apply.closest('[data-drawer]'));
}

// reset läuft vor dem Zurücksetzen („Verwerfen“): Schalter, Felder und Hinweise folgen danach dem gespeicherten Stand.
function onReset(e) {
    const form = e.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-preview-url]')) return;
    queueMicrotask(() => {
        form.querySelectorAll('[data-day]').forEach((day) => setOpen(day, day.querySelector('[data-day-open]')?.checked ?? isOpen(day)));
        notify(form);
    });
}

function setup(form) {
    if (previews.has(form)) return;
    const state = stateOf(form);
    const first = [...form.querySelectorAll('[data-day]')].find(isOpen);
    state.day = first ? Number(first.dataset.day) : 0;
    refreshAll(form);
    runPreview(form);
}

export function initHours() {
    document.querySelectorAll('form[data-preview-url]').forEach(setup);
    if (installed) return;
    installed = true;

    document.addEventListener('input', onInput);
    document.addEventListener('focusin', onFocus);
    document.addEventListener('change', onChange);
    document.addEventListener('click', onClick);
    document.addEventListener('reset', onReset, true);
}
