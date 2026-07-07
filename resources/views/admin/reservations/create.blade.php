@extends('layouts.admin')
@section('title', 'Neue Reservierung')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Neue Reservierung</h1>

<form method="POST" action="{{ route('admin.reservations.store') }}" class="max-w-3xl space-y-5 rounded-2xl bg-white p-6 shadow-sm ring-1 ring-stone-100">
    @csrf
    <div class="grid gap-4 sm:grid-cols-4">
        <div>
            <label class="mb-1 block text-sm font-semibold">Datum *</label>
            <input type="date" name="date" required value="{{ old('date', $prefill['date']) }}" class="w-full rounded-xl border-stone-200">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold">Uhrzeit *</label>
            <input type="time" name="time" required value="{{ old('time', $prefill['time']) }}" step="900" class="w-full rounded-xl border-stone-200">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold">Personen *</label>
            <input type="number" name="party_size" required min="1" max="100" value="{{ old('party_size', $prefill['party_size']) }}" class="w-full rounded-xl border-stone-200">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold">Dauer (Min.)</label>
            <input type="number" name="duration_minutes" min="30" max="600" step="15" placeholder="Standard" value="{{ old('duration_minutes') }}" class="w-full rounded-xl border-stone-200">
        </div>
    </div>

    <div class="relative">
        <label class="mb-1 block text-sm font-semibold">Name *</label>
        <input type="text" name="name" id="guestName" required autocomplete="off" value="{{ old('name') }}" class="w-full rounded-xl border-stone-200">
        <div id="guestSuggest" class="absolute z-10 mt-1 hidden w-full rounded-xl border border-stone-200 bg-white shadow-lg"></div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-semibold">E-Mail</label>
            <input type="email" name="email" id="guestEmail" value="{{ old('email') }}" class="w-full rounded-xl border-stone-200">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold">Telefon</label>
            <input type="tel" name="phone" id="guestPhone" value="{{ old('phone') }}" class="w-full rounded-xl border-stone-200">
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-semibold">Quelle</label>
            <select name="source" class="w-full rounded-xl border-stone-200">
                <option value="manual">Manuell / vor Ort</option>
                <option value="phone">Telefon</option>
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold">Anlass</label>
            <input type="text" name="occasion" value="{{ old('occasion') }}" class="w-full rounded-xl border-stone-200">
        </div>
    </div>

    <div>
        <div class="mb-1 flex items-center justify-between">
            <label class="block text-sm font-semibold">Tische <span class="font-normal text-stone-400">(leer = automatische Zuweisung)</span></label>
            <span class="flex gap-3 text-xs text-stone-400">
                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-emerald-400"></span>frei</span>
                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-stone-300"></span>belegt</span>
                <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-amber-300"></span>Größe passt nicht</span>
            </span>
        </div>
        <div id="fpRoomTabs" class="mb-2 flex flex-wrap gap-2"></div>
        <div id="fpCanvas" class="relative w-full overflow-hidden rounded-xl border-2 border-stone-100 bg-stone-50" style="height:260px">
            <p id="fpHint" class="absolute inset-0 flex items-center justify-center px-4 text-center text-sm text-stone-400">Tischplan lädt…</p>
        </div>
        <p id="fpSelected" class="mt-2 text-xs text-stone-500">Kein Tisch gewählt – Zuweisung erfolgt automatisch.</p>
        <div id="fpInputs">
            @foreach(collect(old('table_ids', $prefill['table_id'] ? [$prefill['table_id']] : []))->filter() as $tid)
                <input type="hidden" name="table_ids[]" value="{{ $tid }}">
            @endforeach
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-semibold">Allergien</label>
            <input type="text" name="allergies" value="{{ old('allergies') }}" class="w-full rounded-xl border-stone-200">
        </div>
        <div>
            <label class="mb-1 block text-sm font-semibold">Gastnotiz</label>
            <input type="text" name="note" value="{{ old('note') }}" class="w-full rounded-xl border-stone-200">
        </div>
    </div>

    <div>
        <label class="mb-1 block text-sm font-semibold">Interne Notiz</label>
        <input type="text" name="internal_note" value="{{ old('internal_note') }}" class="w-full rounded-xl border-stone-200">
    </div>

    <label class="flex items-center gap-2 text-sm">
        <input type="checkbox" name="force" value="1"> Verfügbarkeitsprüfung übergehen (Überbuchung – wird protokolliert)
    </label>

    <button class="w-full rounded-xl bg-stone-900 py-3.5 font-bold text-white hover:bg-stone-700 sm:w-auto sm:px-8">Reservierung anlegen</button>
</form>

<script>
(function () {
    const input = document.getElementById('guestName');
    const box = document.getElementById('guestSuggest');
    let timer = null;
    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            if (input.value.length < 2) { box.classList.add('hidden'); return; }
            const res = await fetch('{{ route('admin.guests.suggest') }}?q=' + encodeURIComponent(input.value), {headers: {Accept: 'application/json'}});
            const guests = await res.json();
            if (!guests.length) { box.classList.add('hidden'); return; }
            box.innerHTML = '';
            guests.forEach(g => {
                const div = document.createElement('button');
                div.type = 'button';
                div.className = 'block w-full px-4 py-2.5 text-left text-sm hover:bg-stone-50';
                div.innerHTML = '<strong>' + g.name + (g.vip ? ' ⭐' : '') + '</strong> · ' + (g.phone || '') + ' · ' + g.visits + ' Besuche' + (g.allergies ? ' · ⚠️ ' + g.allergies : '');
                div.addEventListener('click', () => {
                    input.value = g.name;
                    if (g.email) document.getElementById('guestEmail').value = g.email;
                    if (g.phone) document.getElementById('guestPhone').value = g.phone;
                    box.classList.add('hidden');
                });
                box.appendChild(div);
            });
            box.classList.remove('hidden');
        }, 250);
    });
    document.addEventListener('click', e => { if (!box.contains(e.target) && e.target !== input) box.classList.add('hidden'); });
})();

/* ── Visual floor plan picker (mirrors the public booking plan) ──────────
   Loads availability for the chosen date/time/party/duration; staff can
   select multiple tables. Occupied tables become selectable only when the
   overbooking checkbox is ticked. */
(function () {
    const canvas = document.getElementById('fpCanvas');
    const tabs = document.getElementById('fpRoomTabs');
    const hint = document.getElementById('fpHint');
    const selectedLabel = document.getElementById('fpSelected');
    const inputsBox = document.getElementById('fpInputs');
    const forceBox = document.querySelector('input[name="force"]');
    const url = '{{ route('admin.reservations.floorplan-availability') }}';

    let rooms = [];
    let activeRoom = null;
    const selected = new Map(); // id -> name
    // Preselect from old input / prefill
    inputsBox.querySelectorAll('input').forEach(i => selected.set(parseInt(i.value, 10), 'Tisch #' + i.value));

    const field = n => document.querySelector(`[name="${n}"]`);

    function syncInputs() {
        inputsBox.innerHTML = '';
        selected.forEach((name, id) => {
            const i = document.createElement('input');
            i.type = 'hidden'; i.name = 'table_ids[]'; i.value = id;
            inputsBox.appendChild(i);
        });
        selectedLabel.textContent = selected.size
            ? 'Gewählt: ' + [...selected.values()].join(', ')
            : 'Kein Tisch gewählt – Zuweisung erfolgt automatisch.';
    }

    function renderTabs() {
        tabs.innerHTML = '';
        rooms.forEach(room => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = room.name;
            b.className = 'rounded-full border px-3 py-1 text-xs font-semibold transition '
                + (room.id === activeRoom
                    ? 'border-stone-900 bg-stone-900 text-white'
                    : 'border-stone-200 bg-white text-stone-600 hover:border-stone-400');
            b.addEventListener('click', () => { activeRoom = room.id; renderTabs(); renderRoom(); });
            tabs.appendChild(b);
        });
    }

    function tableColors(t) {
        if (selected.has(t.id)) return 'background:#0f766e;border-color:#0f766e;color:#fff';
        if (t.status === 'occupied' || t.status === 'blocked') return 'background:#e7e5e4;border-color:#d6d3d1;color:#a8a29e';
        if (t.status === 'unsuitable') return 'background:#fef3c7;border-color:#fcd34d;color:#92400e';
        return 'background:#d1fae5;border-color:#34d399;color:#065f46';
    }

    function renderRoom() {
        const room = rooms.find(r => r.id === activeRoom);
        canvas.querySelectorAll('.fp-t').forEach(el => el.remove());
        hint.classList.toggle('hidden', !!room);
        if (!room) return;

        const scale = Math.min(canvas.clientWidth / (room.plan_width || 800), 260 / (room.plan_height || 500));
        room.tables.forEach(t => {
            const el = document.createElement('button');
            el.type = 'button';
            el.className = 'fp-t absolute flex items-center justify-center text-[10px] font-extrabold transition-transform';
            el.style.cssText = `left:${t.pos_x * scale}px;top:${t.pos_y * scale}px;width:${t.width * scale}px;height:${t.height * scale}px;`
                + `border:2px solid;border-radius:${t.shape === 'round' ? '9999px' : '6px'};`
                + `transform:rotate(${t.rotation}deg);${tableColors(t)}`;
            el.textContent = t.name;
            const blocked = t.status === 'blocked';
            const occupied = t.status === 'occupied';
            el.title = t.name + ' · ' + t.capacity + ' P.'
                + (occupied ? ' · belegt' : blocked ? ' · Raum gesperrt' : t.status === 'unsuitable' ? ' · Größe passt nicht' : ' · frei');
            el.addEventListener('click', () => {
                if (blocked) return;
                if (occupied && !(forceBox && forceBox.checked) && !selected.has(t.id)) {
                    selectedLabel.textContent = t.name + ' ist in diesem Zeitfenster belegt – für Überbuchung erst die Checkbox unten aktivieren.';
                    return;
                }
                selected.has(t.id) ? selected.delete(t.id) : selected.set(t.id, t.name);
                syncInputs();
                renderRoom();
            });
            canvas.appendChild(el);
        });
    }

    let timer = null;
    async function load() {
        const date = field('date')?.value, time = field('time')?.value, party = field('party_size')?.value;
        if (!date || !time || !party) return;
        hint.textContent = 'Tischplan lädt…';
        hint.classList.remove('hidden');
        try {
            const params = new URLSearchParams({ date, time, party_size: party });
            const dur = field('duration_minutes')?.value;
            if (dur) params.set('duration_minutes', dur);
            const res = await fetch(url + '?' + params, { headers: { Accept: 'application/json' } });
            if (!res.ok) throw new Error(res.status);
            const data = await res.json();
            rooms = data.rooms || [];
            // Resolve names of preselected tables now that we know them
            rooms.forEach(r => r.tables.forEach(t => { if (selected.has(t.id)) selected.set(t.id, t.name); }));
            syncInputs();
            if (!rooms.length) { hint.textContent = 'Keine Tische angelegt.'; tabs.innerHTML = ''; return; }
            if (!rooms.some(r => r.id === activeRoom)) activeRoom = rooms[0].id;
            renderTabs();
            renderRoom();
        } catch (e) {
            hint.textContent = 'Tischplan konnte nicht geladen werden.';
            hint.classList.remove('hidden');
        }
    }

    ['date', 'time', 'party_size', 'duration_minutes'].forEach(n => {
        field(n)?.addEventListener('change', () => { clearTimeout(timer); timer = setTimeout(load, 150); });
    });
    forceBox?.addEventListener('change', renderRoom);
    window.addEventListener('resize', () => { clearTimeout(timer); timer = setTimeout(renderRoom, 150); });
    load();
})();
</script>
@endsection
