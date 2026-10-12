// Seite „Ausnahmen“: die Auswahlkarten „Was ist anders?“ und der Satz „So wirkt es“.
//
// Aufbau (resources/views/admin/settings/exceptions.blade.php):
//   <div data-drawer>                                   Seitenleiste zum Anlegen oder Bearbeiten
//     <form data-preview-url data-preview-delay>
//       <input type="checkbox" name="effects[]" value="closed|hours|limit|lock">
//       <input type="hidden" name="key">                beim Bearbeiten: der Eintrag
//     <p data-effect-sentence aria-live="polite"
//        data-text-start data-text-incomplete data-text-unavailable>
//
// „Geschlossen“ schließt die anderen Wirkungen aus (syncEffects). Der Satz kommt
// vom Server (POST an data-preview-url mit den Feldern des Formulars, auch
// ungespeichert), nach der Wartezeit seit der letzten Eingabe und beim Öffnen
// der Seitenleiste. Die Abschnitte der Wirkungen blendet reveal.js
// (data-show-if="effects[]:hours") ein und aus; ihre Felder bleiben im Formular
// und werden mitgesendet, der Server wertet nur die gewählten Wirkungen aus.
// Texte stehen als data-text-* am Satz, damit sie zu Restaurant und Salon passen.
// Elemente mit data-preset="tisch" sind aus ?tisch=… vorbelegt (siehe dropPreset).

const EFFECTS_FIELD = 'effects[]';
const EXCLUSIVE_EFFECT = 'closed';

let installed = false;
// Vorschau je Formular: Wartezeit und laufende Anfrage.
const previews = new WeakMap();
const watched = new WeakSet();

const formOf = (el) => el?.closest?.('form[data-preview-url]') ?? null;
const effectBoxes = (form) => [...form.querySelectorAll(`input[type="checkbox"][name="${CSS.escape(EFFECTS_FIELD)}"]`)];

// „Geschlossen“ steht allein: Wer es anhakt, wählt die anderen Wirkungen ab; wer
// eine andere anhakt, wählt „Geschlossen“ ab. Danach meldet ein input-Ereignis am
// Formular den neuen Stand (Abschnitte, Seitenleiste, Vorschau).
export function syncEffects(form, changed) {
    if (!changed?.checked) return;
    effectBoxes(form).forEach((box) => {
        const exclusive = box.value === EXCLUSIVE_EFFECT;
        if (box !== changed && (changed.value === EXCLUSIVE_EFFECT ? !exclusive : exclusive)) box.checked = false;
    });
    form.dispatchEvent(new Event('input', { bubbles: true }));
}

// --- Satz „So wirkt es“ -------------------------------------------------------

function stateOf(form) {
    if (!previews.has(form)) previews.set(form, { timer: null, controller: null });
    return previews.get(form);
}

// Der Satz steht im Fuß der Seitenleiste, außerhalb des Formulars.
const sentenceOf = (form) => (form.closest('[data-drawer]') ?? form).querySelector('[data-effect-sentence]');

// Der gleiche Text bleibt stehen, damit die Live-Region nur bei neuem Inhalt spricht.
function show(target, text) {
    if (target.textContent !== text) target.textContent = text;
}

async function readJson(response) {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

// Der Satz braucht den ersten Tag; bis dahin erklärt der Anfangstext, was zu tun ist.
const hasStart = (form) => (form.querySelector('[name="from"]')?.value ?? '') !== '';

async function runPreview(form) {
    const target = sentenceOf(form);
    // Ein Formular, das nicht mehr auf der Seite steht oder in einer geschlossenen Seitenleiste liegt, braucht keine Vorschau.
    if (!form.isConnected || !target || form.closest('[data-drawer]')?.hidden) return;

    const state = stateOf(form);
    state.controller?.abort();
    if (!hasStart(form)) {
        state.controller = null;
        show(target, target.dataset.textStart ?? '');
        return;
    }

    const controller = new AbortController();
    state.controller = controller;
    // Die Anfrage geht an die Vorschau-Route (POST). Ein _method des Formulars (PUT beim Bearbeiten) würde sie umleiten.
    const data = new FormData(form);
    data.delete('_method');

    try {
        const response = await fetch(form.dataset.previewUrl, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': String(data.get('_token') ?? ''),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: data,
            credentials: 'same-origin',
            signal: controller.signal,
        });
        const json = await readJson(response);
        // Eine neuere Anfrage hat diese abgelöst.
        if (state.controller !== controller) return;
        if (response.ok && typeof json?.sentence === 'string') {
            show(target, json.sentence);
        } else if (response.status === 422 && json?.errors) {
            // Die Meldung des Servers nennt Ursache und nächsten Schritt, etwa bei einem Tag, den eine andere Ausnahme schon belegt.
            const first = Object.values(json.errors).flat()[0];
            show(target, first ? (target.dataset.textIncomplete ?? ':message').replace(':message', first) : (target.dataset.textUnavailable ?? ''));
        } else if (response.status === 404 && typeof json?.message === 'string') {
            // Der bearbeitete Eintrag ist inzwischen gelöscht.
            show(target, json.message);
        } else {
            show(target, target.dataset.textUnavailable ?? '');
        }
    } catch (error) {
        if (error?.name === 'AbortError' || state.controller !== controller) return;
        show(target, target.dataset.textUnavailable ?? '');
    }
}

// Rechnet den Satz nach der Wartezeit seit der letzten Eingabe neu.
function schedule(form) {
    const state = stateOf(form);
    clearTimeout(state.timer);
    const delay = Number.parseInt(form.dataset.previewDelay ?? '', 10);
    state.timer = setTimeout(() => runPreview(form), Number.isFinite(delay) ? delay : 0);
}

// Beim Öffnen rechnet jedes Formular der Seitenleiste einmal sofort.
function previewOpened(drawer) {
    drawer.querySelectorAll('form[data-preview-url]').forEach((form) => {
        clearTimeout(stateOf(form).timer);
        runPreview(form);
    });
}

// Vorbelegung aus der Adresse (?tisch=3): Der Server hakt „Räume oder Tische sperren“ und den Tisch an und markiert beide
// mit data-preset="{Name des Parameters}". Sie gilt für das Öffnen über diese Adresse. Wer die Seitenleiste schließt, hat
// sie verwendet oder verworfen: Parameter und Markierung verschwinden, auch aus dem Ausgangsstand des Formulars. Sonst
// stellte „Abbrechen“ (form.reset) oder das Neuladen nach dem Speichern den Tisch wieder her, und die nächste Ausnahme
// träfe einen Tisch, den niemand gewählt hat.
function dropPreset(drawer) {
    const items = [...drawer.querySelectorAll('[data-preset]')];
    if (items.length === 0) return;
    const url = new URL(window.location.href);
    items.forEach((item) => {
        url.searchParams.delete(item.dataset.preset);
        item.defaultChecked = false;
        item.checked = false;
        item.removeAttribute('data-preset');
    });
    history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
    // Abschnitte, Seitenleiste und Vorschau sehen den neuen Stand.
    drawer.querySelectorAll('form').forEach((form) => form.dispatchEvent(new Event('input', { bubbles: true })));
}

// drawer.js öffnet und schließt über das Attribut hidden; das gilt auch für den Aufruf über die Adresse (#ausnahme-neu).
function watch(drawer) {
    if (watched.has(drawer) || !drawer.querySelector('form[data-preview-url]')) return;
    watched.add(drawer);
    new MutationObserver(() => {
        if (drawer.hidden) {
            dropPreset(drawer);
        } else {
            previewOpened(drawer);
        }
    }).observe(drawer, { attributes: true, attributeFilter: ['hidden'] });
    if (!drawer.hidden) {
        previewOpened(drawer);
    } else if (window.location.hash !== `#${drawer.id}`) {
        // Die Seite kam mit ?tisch=… und die Seitenleiste bleibt zu: Die Vorbelegung bliebe für später stehen.
        dropPreset(drawer);
    }
}

export function initExceptions() {
    document.querySelectorAll('[data-drawer]').forEach(watch);
    if (installed) return;
    installed = true;

    document.addEventListener('input', (e) => {
        const form = formOf(e.target);
        if (form) schedule(form);
    });

    document.addEventListener('change', (e) => {
        const form = formOf(e.target);
        if (!form) return;
        if (e.target.matches?.(`input[type="checkbox"][name="${CSS.escape(EFFECTS_FIELD)}"]`)) syncEffects(form, e.target);
        schedule(form);
    });
}
