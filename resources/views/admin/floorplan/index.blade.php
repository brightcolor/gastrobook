@extends('layouts.admin')
@section('title', 'Tischplan')
@php($canEdit = auth()->user()->canInTenant('floorplan.update', app(\App\Support\TenantContext::class)->tenant(), $location))
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Tischplan</h1>
    <div class="flex items-center gap-2">
        <input type="date" id="planDate" value="{{ $date }}" class="rounded-lg border-stone-200 text-sm">
        <input type="time" id="planTime" value="{{ now($location->timezone)->format('H:i') }}" class="rounded-lg border-stone-200 text-sm">
        @if($canEdit)
            <button id="editToggle" class="rounded-lg bg-stone-200 px-3 py-2 text-sm font-semibold">✏️ Bearbeiten</button>
            <button id="saveLayout" class="hidden rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">💾 Speichern</button>
        @endif
    </div>
</div>

<div class="mb-3 flex flex-wrap gap-3 text-xs">
    @foreach([['frei', 'bg-emerald-200'], ['bald belegt', 'bg-amber-200'], ['reserviert/wartet', 'bg-orange-300'], ['belegt', 'bg-blue-300'], ['No-Show-Risiko', 'bg-red-300'], ['blockiert', 'bg-stone-400']] as [$label, $cls])
        <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded {{ $cls }}"></span>{{ $label }}</span>
    @endforeach
    <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-full bg-slate-700"></span>Platz belegt</span>
    <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded-full border border-slate-400 bg-slate-200"></span>Platz frei</span>
</div>

@foreach($rooms as $room)
    <div class="mb-6" data-room-wrap="{{ $room->id }}">
        <div class="mb-2 flex flex-wrap items-center gap-2">
            <h2 class="font-bold">{{ $room->name }} @if($room->is_outdoor)<span class="text-sm font-normal text-stone-500">(Außenbereich)</span>@endif</h2>
            @if($canEdit)
                <div class="room-edit ml-auto hidden flex-wrap items-center gap-2 text-sm">
                    <button type="button" class="add-table rounded-lg bg-stone-900 px-3 py-1.5 font-semibold text-white" data-room="{{ $room->id }}">＋ Tisch</button>
                    <label class="cursor-pointer rounded-lg bg-stone-200 px-3 py-1.5 font-semibold">
                        🖼 Hintergrund
                        <input type="file" accept="image/png,image/jpeg,image/webp" class="bg-upload hidden" data-room="{{ $room->id }}">
                    </label>
                    <button type="button" class="bg-clear rounded-lg bg-stone-100 px-3 py-1.5 font-semibold text-stone-600 {{ $room->background_path ? '' : 'hidden' }}" data-room="{{ $room->id }}">Hintergrund entfernen</button>
                </div>
            @endif
        </div>
        <div class="floor-scroll overflow-auto rounded-2xl border border-stone-200 bg-white shadow-sm">
            <div class="floor-room relative"
                 data-room="{{ $room->id }}"
                 data-w="{{ $room->plan_width }}" data-h="{{ $room->plan_height }}"
                 style="width:{{ (int) round($room->plan_width * 0.6) }}px;height:{{ (int) round($room->plan_height * 0.6) }}px;
                        background-color:#fff;background-position:center;background-repeat:no-repeat;background-size:cover;
                        @if($room->background_path)background-image:url('{{ route('admin.floorplan.background', $room) }}');@endif">
                <div class="grid-overlay pointer-events-none absolute inset-0"
                     style="background-image:radial-gradient(circle,rgba(120,113,108,.25) 1px,transparent 1px);background-size:20px 20px;"></div>
            </div>
        </div>
    </div>
@endforeach

{{-- Reservation popup --}}
<div id="tablePopup" class="fixed inset-x-4 bottom-4 z-50 hidden rounded-2xl border border-stone-200 bg-white p-4 shadow-2xl md:inset-x-auto md:right-6 md:w-96"></div>

{{-- New table modal --}}
@if($canEdit)
<div id="newTableBack" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl">
        <h3 class="text-lg font-bold">Neuer Tisch</h3>
        <form id="newTableForm" class="mt-4 space-y-3">
            <input type="hidden" name="room_id">
            <div>
                <label class="mb-1 block text-sm font-semibold">Name / Nummer</label>
                <input name="name" required maxlength="40" placeholder="z. B. 12" class="w-full rounded-lg border-2 border-stone-200 px-3 py-2">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="mb-1 block text-sm font-semibold">Plätze min.</label>
                    <input name="min_capacity" type="number" min="1" max="50" value="2" required class="w-full rounded-lg border-2 border-stone-200 px-3 py-2">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-semibold">Plätze max.</label>
                    <input name="max_capacity" type="number" min="1" max="50" value="4" required class="w-full rounded-lg border-2 border-stone-200 px-3 py-2">
                </div>
            </div>
            <div>
                <label class="mb-1 block text-sm font-semibold">Form</label>
                <select name="shape" class="w-full rounded-lg border-2 border-stone-200 px-3 py-2">
                    <option value="rect">Eckig</option>
                    <option value="round">Rund</option>
                </select>
            </div>
            <p id="newTableErr" class="hidden text-sm text-red-600"></p>
            <div class="flex gap-2 pt-1">
                <button type="submit" class="flex-1 rounded-lg bg-emerald-600 py-2.5 font-semibold text-white">Anlegen</button>
                <button type="button" id="newTableCancel" class="rounded-lg bg-stone-200 px-4 py-2.5 font-semibold">Abbrechen</button>
            </div>
        </form>
    </div>
</div>
@endif

<script>
(function () {
    const SCALE = 0.6;
    const stateUrl = @json(route('admin.floorplan.state'));
    const posUrl = @json(route('admin.floorplan.positions'));
    const tableStoreUrl = @json($canEdit ? route('admin.floorplan.tables.store') : '');
    const bgBase = @json(url('/admin/floorplan/rooms'));
    const csrf = @json(csrf_token());
    const statusColors = {
        free: '#a7f3d0', soon: '#fde68a', awaiting: '#fdba74',
        occupied: '#93c5fd', no_show_risk: '#fca5a5', blocked: '#a8a29e',
    };
    let editMode = false;
    let tablesData = [];

    const dateInput = document.getElementById('planDate');
    const timeInput = document.getElementById('planTime');
    const popup = document.getElementById('tablePopup');

    async function load() {
        const res = await fetch(stateUrl + '?date=' + dateInput.value + '&time=' + timeInput.value, {headers: {Accept: 'application/json'}});
        const data = await res.json();
        tablesData = data.tables;
        render();
    }

    // Build the chairs (seats) around a table, colouring the occupied ones.
    function chairsHtml(t, w, h) {
        const n = Math.max(0, t.seats || 0);
        if (!n) return '';
        const occ = Math.min(t.occupied || 0, n);
        const cs = Math.max(8, Math.min(14, Math.round(Math.min(w, h) / 3.5)));
        const gap = 3;
        const seat = (x, y, i) => {
            const filled = i < occ;
            return `<span class="chair" style="left:${x - cs / 2}px;top:${y - cs / 2}px;width:${cs}px;height:${cs}px;`
                + `background:${filled ? '#334155' : '#e2e8f0'};border:1px solid ${filled ? '#1e293b' : '#94a3b8'};"></span>`;
        };
        let out = '';
        if (t.shape === 'round') {
            const r = Math.max(w, h) / 2 + cs / 2 + gap;
            for (let i = 0; i < n; i++) {
                const a = (Math.PI * 2 * i) / n - Math.PI / 2;
                out += seat(w / 2 + r * Math.cos(a), h / 2 + r * Math.sin(a), i);
            }
        } else {
            const top = Math.ceil(n / 2), bottom = n - top;
            const place = (count, yPos, from) => {
                for (let i = 0; i < count; i++) {
                    const x = (w * (i + 1)) / (count + 1);
                    out += seat(x, yPos, from + i);
                }
            };
            place(top, -(cs / 2 + gap), 0);
            place(bottom, h + cs / 2 + gap, top);
        }
        return out;
    }

    function render() {
        document.querySelectorAll('.floor-room').forEach(room => {
            room.querySelectorAll('.table-el').forEach(el => el.remove());
            room.classList.toggle('is-editing', editMode);
        });
        document.querySelectorAll('.room-edit').forEach(b => b.classList.toggle('hidden', !editMode));

        tablesData.forEach(t => {
            const room = document.querySelector('.floor-room[data-room="' + t.room_id + '"]');
            if (!room) return;
            const w = t.width * SCALE, h = t.height * SCALE;
            const el = document.createElement('div');
            el.className = 'table-el';
            el.style.cssText = `position:absolute;left:${t.pos_x * SCALE}px;top:${t.pos_y * SCALE}px;width:${w}px;height:${h}px;`
                + `background:${statusColors[t.status] || '#e7e5e4'};transform:rotate(${t.rotation}deg);`
                + `border-radius:${t.shape === 'round' ? '9999px' : '10px'};border:2px solid rgba(0,0,0,.18);`
                + 'display:flex;flex-direction:column;align-items:center;justify-content:center;'
                + 'font-size:11px;font-weight:700;color:#1c1917;box-shadow:0 1px 3px rgba(0,0,0,.15);user-select:none;touch-action:none;';
            el.dataset.id = t.id;

            const occLine = (t.occupied > 0)
                ? `<span style="font-weight:600">${t.occupied}/${t.seats} 🪑</span>`
                : `<span style="font-weight:600;color:#57534e">${t.seats} 🪑</span>`;
            const guest = (t.current && !editMode) ? `<span style="font-weight:500">${esc(t.current.name.split(' ').pop())}</span>` : '';
            const next = (t.upcoming && !t.current && !editMode) ? `<span style="font-weight:500;color:#57534e">ab ${t.upcoming.at}</span>` : '';
            el.innerHTML = `<span>${esc(t.name)}</span>${editMode ? '' : occLine}${guest}${next}`
                + (editMode ? '' : chairsHtml(t, w, h))
                + (editMode ? `<button class="rot-btn" title="Drehen">⟳</button>` : '');

            if (editMode) {
                makeDraggable(el, room);
                el.querySelector('.rot-btn').addEventListener('pointerdown', e => e.stopPropagation());
                el.querySelector('.rot-btn').addEventListener('click', e => {
                    e.stopPropagation();
                    let r = ((t.rotation || 0) + 45);
                    if (r > 180) r -= 360;
                    t.rotation = r;
                    el.style.transform = `rotate(${r}deg)`;
                });
            } else {
                el.addEventListener('click', () => showPopup(t));
            }
            room.appendChild(el);
        });
    }

    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    function showPopup(t) {
        let html = `<div class="flex items-center justify-between"><h3 class="font-bold">Tisch ${esc(t.name)}</h3>
            <button onclick="document.getElementById('tablePopup').classList.add('hidden')" class="text-stone-400">✕</button></div>
            <p class="mt-1 text-xs text-stone-500">${t.occupied || 0}/${t.seats} Plätze belegt</p>`;
        if (t.current) {
            html += `<p class="mt-2 text-sm"><strong>${esc(t.current.name)}</strong> · ${t.current.party} Personen · bis ${t.current.until} Uhr</p>
                <a href="/admin/reservations/${t.current.id}" class="mt-3 block rounded-xl bg-stone-900 py-2.5 text-center text-sm font-semibold text-white">Reservierung öffnen</a>`;
        } else if (t.upcoming) {
            html += `<p class="mt-2 text-sm">Nächste Reservierung: <strong>${esc(t.upcoming.name)}</strong> um ${t.upcoming.at} Uhr (${t.upcoming.party} P.)</p>
                <a href="/admin/reservations/${t.upcoming.id}" class="mt-3 block rounded-xl bg-stone-900 py-2.5 text-center text-sm font-semibold text-white">Reservierung öffnen</a>`;
        } else if (t.status === 'blocked') {
            html += `<p class="mt-2 text-sm text-stone-500">Dieser Tisch ist aktuell gesperrt.</p>`;
        } else {
            html += `<p class="mt-2 text-sm text-emerald-700">Frei.</p>
                <a href="{{ route('admin.reservations.create') }}?date=${dateInput.value}&time=${timeInput.value}&table_id=${t.id}" class="mt-3 block rounded-xl bg-emerald-600 py-2.5 text-center text-sm font-semibold text-white">Hier reservieren</a>
                <a href="{{ route('admin.walkins.index') }}" class="mt-2 block rounded-xl bg-stone-200 py-2.5 text-center text-sm font-semibold">Walk-in platzieren</a>`;
        }
        popup.innerHTML = html;
        popup.classList.remove('hidden');
    }

    function makeDraggable(el, room) {
        el.style.cursor = 'move';
        let sx, sy, ox, oy, rw, rh, ew, eh;
        el.addEventListener('pointerdown', e => {
            if (!editMode || e.target.classList.contains('rot-btn')) return;
            el.setPointerCapture(e.pointerId);
            sx = e.clientX; sy = e.clientY;
            ox = parseFloat(el.style.left) || 0; oy = parseFloat(el.style.top) || 0;
            rw = room.clientWidth; rh = room.clientHeight; ew = el.offsetWidth; eh = el.offsetHeight;
            el.style.zIndex = 20; el.style.opacity = '.85';
            e.preventDefault();
        });
        el.addEventListener('pointermove', e => {
            if (!el.hasPointerCapture(e.pointerId)) return;
            const nx = Math.min(Math.max(0, ox + (e.clientX - sx)), Math.max(0, rw - ew));
            const ny = Math.min(Math.max(0, oy + (e.clientY - sy)), Math.max(0, rh - eh));
            el.style.left = nx + 'px'; el.style.top = ny + 'px';
        });
        const end = e => {
            try { el.releasePointerCapture(e.pointerId); } catch (_) {}
            el.style.zIndex = ''; el.style.opacity = '';
            const t = tablesData.find(x => x.id == el.dataset.id);
            if (t) {
                t.pos_x = Math.round((parseFloat(el.style.left) || 0) / SCALE);
                t.pos_y = Math.round((parseFloat(el.style.top) || 0) / SCALE);
            }
        };
        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', end);
    }

    // ---- Edit mode + save ----
    const editToggle = document.getElementById('editToggle');
    const saveBtn = document.getElementById('saveLayout');
    if (editToggle) {
        editToggle.addEventListener('click', () => {
            editMode = !editMode;
            editToggle.classList.toggle('bg-amber-300', editMode);
            saveBtn.classList.toggle('hidden', !editMode);
            popup.classList.add('hidden');
            render();
        });
        saveBtn.addEventListener('click', async () => {
            saveBtn.disabled = true;
            await fetch(posUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                body: JSON.stringify({tables: tablesData.map(t => ({id: t.id, pos_x: t.pos_x, pos_y: t.pos_y, rotation: t.rotation}))}),
            });
            saveBtn.disabled = false;
            editMode = false;
            editToggle.classList.remove('bg-amber-300');
            saveBtn.classList.add('hidden');
            load();
        });
    }

    // ---- New table modal ----
    const modal = document.getElementById('newTableBack');
    if (modal) {
        const form = document.getElementById('newTableForm');
        const errEl = document.getElementById('newTableErr');
        document.querySelectorAll('.add-table').forEach(b => b.addEventListener('click', () => {
            form.reset();
            form.room_id.value = b.dataset.room;
            errEl.classList.add('hidden');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }));
        const closeModal = () => { modal.classList.add('hidden'); modal.classList.remove('flex'); };
        document.getElementById('newTableCancel').addEventListener('click', closeModal);
        modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
        form.addEventListener('submit', async e => {
            e.preventDefault();
            errEl.classList.add('hidden');
            const payload = Object.fromEntries(new FormData(form).entries());
            payload.pos_x = 40; payload.pos_y = 40;
            const res = await fetch(tableStoreUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                body: JSON.stringify(payload),
            });
            if (!res.ok) {
                const j = await res.json().catch(() => ({}));
                errEl.textContent = j.message || (j.errors ? Object.values(j.errors)[0][0] : 'Konnte nicht angelegt werden.');
                errEl.classList.remove('hidden');
                return;
            }
            const j = await res.json();
            tablesData.push(j.table);
            closeModal();
            render();
        });
    }

    // ---- Background upload / clear ----
    document.querySelectorAll('.bg-upload').forEach(input => input.addEventListener('change', async () => {
        const file = input.files[0];
        if (!file) return;
        const roomId = input.dataset.room;
        const fd = new FormData();
        fd.append('image', file);
        const res = await fetch(`${bgBase}/${roomId}/background`, {
            method: 'POST',
            headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
            body: fd,
        });
        input.value = '';
        if (!res.ok) { alert('Bild konnte nicht hochgeladen werden (max. 6 MB, JPG/PNG/WebP).'); return; }
        const j = await res.json();
        const room = document.querySelector('.floor-room[data-room="' + roomId + '"]');
        room.style.backgroundImage = `url('${j.url}?t=${Date.now()}')`;
        document.querySelector('.bg-clear[data-room="' + roomId + '"]')?.classList.remove('hidden');
    }));
    document.querySelectorAll('.bg-clear').forEach(btn => btn.addEventListener('click', async () => {
        const roomId = btn.dataset.room;
        await fetch(`${bgBase}/${roomId}/background`, {
            method: 'DELETE',
            headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
        });
        const room = document.querySelector('.floor-room[data-room="' + roomId + '"]');
        room.style.backgroundImage = '';
        btn.classList.add('hidden');
    }));

    dateInput.addEventListener('change', load);
    timeInput.addEventListener('change', load);
    load();
    setInterval(() => { if (!editMode) load(); }, 30000); // live refresh
})();
</script>

<style>
    .floor-room .chair { position: absolute; border-radius: 9999px; }
    .floor-room .rot-btn { position: absolute; top: -10px; right: -10px; width: 22px; height: 22px; border-radius: 9999px;
        border: none; background: #1c1917; color: #fff; font-size: 13px; line-height: 1; cursor: pointer; box-shadow: 0 1px 3px rgba(0,0,0,.3); }
    .floor-room.is-editing { cursor: crosshair; outline: 2px dashed #fbbf24; outline-offset: -2px; }
    .floor-room .table-el { transition: box-shadow .1s; }
</style>
@endsection
