// Zeilen hinzufügen und entfernen (Tischzeiten, Arbeitszeiten). Aufbau:
//   <div data-rows data-rows-max="20">
//     <div data-rows-list> … Zeilen mit data-row … </div>
//     <template data-rows-template> eine Zeile mit __INDEX__ </template>
//     <button type="button" data-rows-add>Hinzufügen</button>
//   </div>
// „Hinzufügen“ hängt eine Kopie der Vorlage an und ersetzt __INDEX__ durch
// eine neue Kennung (n1, n2 …), „Entfernen“ (data-rows-remove in der Zeile)
// nimmt die Zeile heraus. Danach meldet ein input-Ereignis am Formular die
// Änderung (Speicherleiste, Seitenleiste). Beim Zurücksetzen des Formulars
// („Verwerfen“, Schließen der Seitenleiste) kehren die Zeilen zum
// gespeicherten Stand zurück; nach dem Speichern gilt der neue Stand.

let installed = false;
let counter = 0;
const saved = new WeakMap();

const listOf = (root) => root.querySelector('[data-rows-list]');

// Der gespeicherte Stand wird vor der ersten Änderung festgehalten; die
// Attribute im HTML tragen dann noch die Werte vom Server.
function remember(root) {
    const list = listOf(root);
    if (list && !saved.has(list)) saved.set(list, list.innerHTML);
}

function sync(root) {
    const add = root.querySelector('[data-rows-add]');
    const max = Number.parseInt(root.dataset.rowsMax ?? '', 10);
    if (add && Number.isFinite(max)) add.disabled = listOf(root).querySelectorAll('[data-row]').length >= max;
}

function notify(root) {
    (root.closest('form') ?? root).dispatchEvent(new Event('input', { bubbles: true }));
}

function addRow(root) {
    remember(root);
    const template = root.querySelector('template[data-rows-template]');
    const holder = document.createElement('div');
    holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', `n${++counter}`).trim();
    const row = holder.firstElementChild;
    listOf(root).append(row);
    sync(root);
    notify(root);
    row.querySelector('input:not([type="hidden"]), select, textarea')?.focus();
}

function removeRow(button) {
    const root = button.closest('[data-rows]');
    remember(root);
    button.closest('[data-row]').remove();
    sync(root);
    notify(root);
    root.querySelector('[data-rows-add]')?.focus();
}

export function initRows() {
    document.querySelectorAll('[data-rows]').forEach(sync);
    if (installed) return;
    installed = true;

    document.addEventListener('click', (e) => {
        if (!(e.target instanceof Element)) return;
        const add = e.target.closest('[data-rows-add]');
        if (add) {
            addRow(add.closest('[data-rows]'));
            return;
        }
        const remove = e.target.closest('[data-rows-remove]');
        if (remove) removeRow(remove);
    });

    // reset läuft vor dem Zurücksetzen: Die wiederhergestellten Zeilen tragen
    // ihre gespeicherten Werte als Vorgabe, das Zurücksetzen bestätigt sie.
    document.addEventListener('reset', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        form.querySelectorAll('[data-rows]').forEach((root) => {
            const list = listOf(root);
            if (saved.has(list)) list.innerHTML = saved.get(list);
            sync(root);
        });
    }, true);

    // Nach dem Speichern hat forms.js die gesendeten Werte als Vorgabe gesetzt.
    document.addEventListener('swayy:saved', (e) => {
        const form = e.target;
        if (!(form instanceof HTMLFormElement)) return;
        form.querySelectorAll('[data-rows]').forEach((root) => saved.set(listOf(root), listOf(root).innerHTML));
    });
}
