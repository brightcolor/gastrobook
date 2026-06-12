@extends('layouts.admin')
@section('title', 'Tischplan')
@section('content')
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Tischplan</h1>
    <div class="flex items-center gap-2">
        <input type="date" id="planDate" value="{{ $date }}" class="rounded-lg border-stone-200 text-sm">
        <input type="time" id="planTime" value="{{ now($location->timezone)->format('H:i') }}" class="rounded-lg border-stone-200 text-sm">
        @if(auth()->user()->canInTenant('floorplan.update', app(\App\Support\TenantContext::class)->tenant(), $location))
            <button id="editToggle" class="rounded-lg bg-stone-200 px-3 py-2 text-sm font-semibold">✏️ Bearbeiten</button>
            <button id="saveLayout" class="hidden rounded-lg bg-emerald-600 px-3 py-2 text-sm font-semibold text-white">💾 Layout speichern</button>
        @endif
    </div>
</div>

<div class="mb-3 flex flex-wrap gap-3 text-xs">
    @foreach([['frei', 'bg-emerald-200'], ['bald belegt', 'bg-amber-200'], ['reserviert/wartet', 'bg-orange-300'], ['belegt', 'bg-blue-300'], ['No-Show-Risiko', 'bg-red-300'], ['blockiert', 'bg-stone-400']] as [$label, $cls])
        <span class="flex items-center gap-1.5"><span class="inline-block h-3 w-3 rounded {{ $cls }}"></span>{{ $label }}</span>
    @endforeach
</div>

@foreach($rooms as $room)
    <div class="mb-6">
        <h2 class="mb-2 font-bold">{{ $room->name }} @if($room->is_outdoor)<span class="text-sm font-normal text-stone-500">(Außenbereich)</span>@endif</h2>
        <div class="floor-room relative overflow-hidden rounded-2xl border border-stone-200 bg-white shadow-sm"
             data-room="{{ $room->id }}"
             style="width:100%;max-width:{{ $room->plan_width }}px;height:{{ $room->plan_height * 0.6 }}px;background-image:radial-gradient(circle,#e7e5e4 1px,transparent 1px);background-size:20px 20px;">
        </div>
    </div>
@endforeach

{{-- Reservation popup --}}
<div id="tablePopup" class="fixed inset-x-4 bottom-4 z-50 hidden rounded-2xl border border-stone-200 bg-white p-4 shadow-2xl md:inset-x-auto md:right-6 md:w-96"></div>

<script>
(function () {
    const stateUrl = @json(route('admin.floorplan.state'));
    const posUrl = @json(route('admin.floorplan.positions'));
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

    function render() {
        document.querySelectorAll('.floor-room').forEach(room => room.querySelectorAll('.table-el').forEach(el => el.remove()));
        tablesData.forEach(t => {
            const room = document.querySelector('.floor-room[data-room="' + t.room_id + '"]');
            if (!room) return;
            const el = document.createElement('div');
            el.className = 'table-el absolute flex cursor-pointer select-none flex-col items-center justify-center text-xs font-bold shadow';
            el.style.cssText = `left:${t.pos_x * 0.6}px;top:${t.pos_y * 0.6}px;width:${t.width * 0.6}px;height:${t.height * 0.6}px;` +
                `background:${statusColors[t.status] || '#e7e5e4'};transform:rotate(${t.rotation}deg);` +
                `border-radius:${t.shape === 'round' ? '9999px' : '10px'};border:2px solid rgba(0,0,0,.15);`;
            el.dataset.id = t.id;
            el.innerHTML = `<span>${t.name}</span><span class="font-normal">${t.capacity}</span>` +
                (t.current ? `<span class="font-normal">${t.current.name.split(' ').pop()} ·${t.current.party}P</span>` : '') +
                (t.upcoming && !t.current ? `<span class="font-normal text-stone-600">${t.upcoming.at}</span>` : '');
            el.addEventListener('click', () => editMode ? null : showPopup(t));
            if (editMode) makeDraggable(el, room);
            room.appendChild(el);
        });
    }

    function showPopup(t) {
        let html = `<div class="flex items-center justify-between"><h3 class="font-bold">Tisch ${t.name}</h3>
            <button onclick="document.getElementById('tablePopup').classList.add('hidden')" class="text-stone-400">✕</button></div>`;
        if (t.current) {
            html += `<p class="mt-2 text-sm"><strong>${t.current.name}</strong> · ${t.current.party} Personen · bis ${t.current.until} Uhr</p>
                <a href="/admin/reservations/${t.current.id}" class="mt-3 block rounded-xl bg-stone-900 py-2.5 text-center text-sm font-semibold text-white">Reservierung öffnen</a>`;
        } else if (t.upcoming) {
            html += `<p class="mt-2 text-sm">Nächste Reservierung: <strong>${t.upcoming.name}</strong> um ${t.upcoming.at} Uhr (${t.upcoming.party} P.)</p>
                <a href="/admin/reservations/${t.upcoming.id}" class="mt-3 block rounded-xl bg-stone-900 py-2.5 text-center text-sm font-semibold text-white">Reservierung öffnen</a>`;
        } else if (t.status === 'blocked') {
            html += `<p class="mt-2 text-sm text-stone-500">Dieser Tisch ist aktuell gesperrt.</p>`;
        } else {
            html += `<p class="mt-2 text-sm text-emerald-700">Frei.</p>
                <a href="{{ route('admin.reservations.create') }}?date=${dateInput.value}&time=${timeInput.value}" class="mt-3 block rounded-xl bg-emerald-600 py-2.5 text-center text-sm font-semibold text-white">Hier reservieren</a>
                <a href="{{ route('admin.walkins.index') }}" class="mt-2 block rounded-xl bg-stone-200 py-2.5 text-center text-sm font-semibold">Walk-in platzieren</a>`;
        }
        popup.innerHTML = html;
        popup.classList.remove('hidden');
    }

    function makeDraggable(el, room) {
        el.style.cursor = 'move';
        let startX, startY, origX, origY;
        const onMove = e => {
            const p = e.touches ? e.touches[0] : e;
            el.style.left = Math.max(0, origX + (p.clientX - startX)) + 'px';
            el.style.top = Math.max(0, origY + (p.clientY - startY)) + 'px';
        };
        const onUp = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('mouseup', onUp);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('touchend', onUp);
            const t = tablesData.find(x => x.id == el.dataset.id);
            t.pos_x = Math.round(parseFloat(el.style.left) / 0.6);
            t.pos_y = Math.round(parseFloat(el.style.top) / 0.6);
        };
        const onDown = e => {
            const p = e.touches ? e.touches[0] : e;
            startX = p.clientX; startY = p.clientY;
            origX = parseFloat(el.style.left); origY = parseFloat(el.style.top);
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
            document.addEventListener('touchmove', onMove, {passive: false});
            document.addEventListener('touchend', onUp);
            e.preventDefault();
        };
        el.addEventListener('mousedown', onDown);
        el.addEventListener('touchstart', onDown, {passive: false});
    }

    const editToggle = document.getElementById('editToggle');
    const saveBtn = document.getElementById('saveLayout');
    if (editToggle) {
        editToggle.addEventListener('click', () => {
            editMode = !editMode;
            editToggle.classList.toggle('bg-amber-300', editMode);
            saveBtn.classList.toggle('hidden', !editMode);
            render();
        });
        saveBtn.addEventListener('click', async () => {
            await fetch(posUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                body: JSON.stringify({tables: tablesData.map(t => ({id: t.id, pos_x: t.pos_x, pos_y: t.pos_y, rotation: t.rotation}))}),
            });
            editMode = false;
            editToggle.classList.remove('bg-amber-300');
            saveBtn.classList.add('hidden');
            load();
        });
    }

    dateInput.addEventListener('change', load);
    timeInput.addEventListener('change', load);
    load();
    setInterval(() => { if (!editMode) load(); }, 30000); // live refresh every 30s
})();
</script>
@endsection
