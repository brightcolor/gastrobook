// Kurzer Hinweis unten („Gespeichert"), per Live-Region angesagt.
// Anzeigedauer aus config/swayy.php (ui.toast_ms) über window.SwayyAdmin.

let timer;

export function showToast(message, { error = false, undo = null } = {}) {
    const el = document.getElementById('swayyToast');
    if (!el) return;

    el.replaceChildren();
    const text = document.createElement('span');
    text.textContent = message;
    el.append(text);

    if (typeof undo === 'function') {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'ml-3 rounded px-2 py-1 font-semibold underline underline-offset-2 hover:bg-white/10';
        button.textContent = 'Rückgängig';
        button.addEventListener('click', () => {
            hide(el);
            undo();
        });
        el.append(button);
    }

    el.dataset.state = error ? 'error' : 'ok';
    el.classList.remove('opacity-0', 'pointer-events-none');
    clearTimeout(timer);
    timer = setTimeout(() => hide(el), window.SwayyAdmin?.ui?.toastMs ?? 3200);
}

function hide(el) {
    el.classList.add('opacity-0', 'pointer-events-none');
}
