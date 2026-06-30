@extends('layouts.admin')
@section('title', 'Tischplan')
@php($canEdit = auth()->user()->canInTenant('floorplan.update', app(\App\Support\TenantContext::class)->tenant(), $location))
@section('content')
<div class="fp">
    <div class="fp-bar">
        <div class="fp-bar-l">
            <h1 class="text-2xl font-bold">Tischplan</h1>
            <span class="fp-live"><span class="fp-live-dot"></span>live</span>
        </div>
        <div class="fp-bar-r">
            <div class="fp-when">
                <input type="date" id="planDate" value="{{ $date }}">
                <span class="fp-when-sep">·</span>
                <input type="time" id="planTime" value="{{ now($location->timezone)->format('H:i') }}">
            </div>
            <button id="comboToggle" class="fp-btn" title="Tischkombinationen verwalten">🔗 <span>Kombinationen</span></button>
            @if($canEdit)
                <button id="editToggle" class="fp-btn">✏️ <span>Bearbeiten</span></button>
                <button id="saveLayout" class="fp-btn fp-btn-save hidden">💾 <span>Speichern</span></button>
            @endif
        </div>
    </div>

    @if($canEdit)
        <div id="editHint" class="fp-hint hidden">
            <span>✦ Bearbeiten aktiv</span>
            <div class="fp-edit-seg">
                <button id="editSubTables" class="on">🪑 Tische</button>
                <button id="editSubZones">◈ Zonen</button>
            </div>
            <span id="editHintTip" class="fp-hint-tip">Ziehen zum Verschieben · ⟳ drehen · am Raster ausgerichtet · „Speichern" nicht vergessen</span>
        </div>
    @endif

    <div class="fp-legend">
        @foreach([['frei', 'free'], ['bald belegt', 'soon'], ['reserviert/wartet', 'awaiting'], ['belegt', 'occupied'], ['No-Show-Risiko', 'no_show_risk'], ['blockiert', 'blocked']] as [$label, $key])
            <span class="fp-leg"><span class="fp-leg-dot lg-{{ $key }}"></span>{{ $label }}</span>
        @endforeach
        <span class="fp-leg-sep"></span>
        <span class="fp-leg"><span class="fp-chip occ"></span>Platz belegt</span>
        <span class="fp-leg"><span class="fp-chip"></span>Platz frei</span>
    </div>

    <div id="zoneLegend" class="fp-zone-legend hidden"></div>

    @foreach($rooms as $room)
        <div class="fp-room-wrap" data-room-wrap="{{ $room->id }}">
            <div class="fp-room-head">
                <div class="fp-room-title">
                    <span class="fp-room-ic">{{ $room->is_outdoor ? '🌤' : '🪟' }}</span>
                    <h2>{{ $room->name }}</h2>
                    @if($room->is_outdoor)<span class="fp-tag">Außenbereich</span>@endif
                    <span class="fp-room-meta" data-meta="{{ $room->id }}"></span>
                </div>
                @if($canEdit)
                    <div class="room-edit hidden">
                        <button type="button" class="add-table fp-mini fp-mini-dark" data-room="{{ $room->id }}">＋ Tisch</button>
                        <label class="fp-mini cursor-pointer">
                            🖼 <span>Hintergrund</span>
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="bg-upload hidden" data-room="{{ $room->id }}">
                        </label>
                        <button type="button" class="bg-clear fp-mini fp-mini-ghost {{ $room->background_path ? '' : 'hidden' }}" data-room="{{ $room->id }}">Hintergrund entfernen</button>
                    </div>
                @endif
            </div>
            <div class="floor-scroll">
                <div class="floor-room"
                     data-room="{{ $room->id }}"
                     data-w="{{ $room->plan_width }}" data-h="{{ $room->plan_height }}"
                     data-width-m="{{ $room->plan_width_m ?? '' }}" data-height-m="{{ $room->plan_height_m ?? '' }}"
                     style="@if($room->background_path)background-image:url('{{ route('admin.floorplan.background', $room) }}');@endif">
                    <div class="grid-overlay"></div>
                    <svg class="zones-svg-layer" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"></svg>
                </div>
                <div class="fp-ruler" data-ruler="{{ $room->id }}"></div>
            </div>
        </div>
    @endforeach
</div>

{{-- Reassign banner --}}
<div id="reassignBanner" class="hidden fixed top-0 left-0 right-0 z-40 flex items-center justify-between gap-4 bg-teal-700 px-6 py-3 text-sm font-semibold text-white shadow-lg">
    <span>🔄 <span id="reassignLabel">Tisch wechseln – Zieltisch anklicken</span></span>
    <button id="reassignCancel" class="rounded-lg bg-white/20 px-4 py-1.5 hover:bg-white/30">Abbrechen</button>
</div>

{{-- Reservation popup --}}
<div id="tablePopup" class="fp-popup hidden"></div>

{{-- Combinations modal --}}
<div id="comboBack" class="fp-modal-back hidden">
    <div class="fp-modal" style="max-width:520px;display:flex;flex-direction:column;max-height:90vh">
        <div class="fp-modal-head" style="flex:none;justify-content:space-between">
            <div class="flex items-center gap-2">
                <span>🔗</span>
                <h3>Tischkombinationen</h3>
            </div>
            <button id="comboPanelClose"
                    style="width:30px;height:30px;border-radius:8px;border:1px solid #e7e5e4;background:#fff;cursor:pointer;font-size:13px;color:#78716c;flex:none">✕</button>
        </div>
        <div class="fp-modal-body" style="overflow-y:auto;flex:1">
            <p class="mb-3 text-xs text-stone-500">Verbinden Sie mehrere Tische zu einer Kombination für größere Gruppen. Die Kapazität wird automatisch vorgeschlagen.</p>
            <div id="comboList" class="space-y-2 text-sm"></div>

            @if($canEdit)
            <div class="mt-4 border-t border-stone-100 pt-4">
                <button id="openComboForm" class="fp-btn fp-btn-save w-full justify-center">＋ Neue Kombination</button>

                <div id="comboForm" class="mt-4 hidden space-y-3">
                    <div>
                        <p class="mb-2 text-xs font-semibold text-stone-500">Tische wählen (min. 2)</p>
                        <div id="comboTableChecks" class="max-h-52 space-y-1 overflow-y-auto rounded-xl border border-stone-200 p-2 text-sm">
                            @forelse($joinableTables as $jt)
                                <label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-stone-50">
                                    <input type="checkbox" class="combo-check rounded" value="{{ $jt->id }}"
                                           data-name="{{ $jt->name }}"
                                           data-cap="{{ $jt->max_capacity }}"
                                           data-shape="{{ $jt->shape }}">
                                    <span class="font-semibold">{{ $jt->name }}</span>
                                    <span class="text-stone-400">{{ $jt->room?->name }}</span>
                                    <span class="ml-auto text-stone-500">{{ $jt->max_capacity }} Pl.</span>
                                    @if($jt->shape === 'round')<span class="text-xs text-stone-400">⬭</span>@else<span class="text-xs text-stone-400">▭</span>@endif
                                </label>
                            @empty
                                <p class="py-3 text-center text-xs text-stone-400">Keine kombinierbaren Tische vorhanden. Tische mit der Eigenschaft „kombinierbar" im Tischplan anlegen.</p>
                            @endforelse
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-stone-500">Name der Kombination</label>
                        <input id="comboNameInput" type="text" maxlength="80" placeholder="z. B. T1 + T2"
                               class="w-full rounded-lg border-2 border-stone-200 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none">
                    </div>
                    <div>
                        <div class="flex gap-2">
                            <div class="flex-1">
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Min. Personen</label>
                                <input id="comboMinInput" type="number" min="1" placeholder="2"
                                       class="w-full rounded-lg border-2 border-stone-200 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none">
                            </div>
                            <div class="flex-1">
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Max. Personen</label>
                                <input id="comboMaxInput" type="number" min="1" placeholder="8"
                                       class="w-full rounded-lg border-2 border-stone-200 px-3 py-2 text-sm focus:border-teal-600 focus:outline-none">
                            </div>
                        </div>
                        <p id="comboHint" class="mt-1.5 hidden rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800"></p>
                    </div>
                    <p id="comboErr" class="hidden text-xs font-semibold text-red-600"></p>
                    <div class="flex gap-2">
                        <button id="cancelComboForm" class="fp-btn flex-1 justify-center">Abbrechen</button>
                        <button id="saveComboBtn" class="fp-btn fp-btn-save flex-1 justify-center">Anlegen</button>
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</div>

{{-- New table modal --}}
@if($canEdit)
<div id="newTableBack" class="fp-modal-back hidden">
    <div class="fp-modal">
        <div class="fp-modal-head"><span>🪑</span><h3>Neuer Tisch</h3></div>
        <form id="newTableForm" class="fp-modal-body">
            <input type="hidden" name="room_id">
            <label class="fp-field">
                <span>Name / Nummer</span>
                <input name="name" required maxlength="40" placeholder="z. B. 12" autocomplete="off">
            </label>
            <div class="fp-field">
                <span>Form</span>
                <div class="fp-seg" id="shapeSeg">
                    <button type="button" data-shape="rect" class="on">▭ Eckig</button>
                    <button type="button" data-shape="round">⬭ Rund</button>
                </div>
                <input type="hidden" name="shape" value="rect">
            </div>
            <div class="fp-grid2">
                <label class="fp-field"><span>Plätze min.</span>
                    <input name="min_capacity" type="number" min="1" max="50" value="2" required></label>
                <label class="fp-field"><span>Plätze max.</span>
                    <input name="max_capacity" type="number" min="1" max="50" value="4" required></label>
            </div>
            <p id="newTableErr" class="fp-err hidden"></p>
            <div class="fp-modal-foot">
                <button type="button" id="newTableCancel" class="fp-btn">Abbrechen</button>
                <button type="submit" class="fp-btn fp-btn-save">Anlegen</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Edit table modal --}}
@if($canEdit)
<div id="tableEditBack" class="fp-modal-back hidden">
    <div class="fp-modal">
        <div class="fp-modal-head"><span>✎</span><h3>Tisch bearbeiten</h3></div>
        <form id="tableEditForm" class="fp-modal-body">
            <input type="hidden" name="id">
            <label class="fp-field">
                <span>Name / Nummer</span>
                <input name="name" required maxlength="40" autocomplete="off">
            </label>
            <div class="fp-grid2">
                <label class="fp-field"><span>Plätze min.</span>
                    <input name="min_capacity" type="number" min="1" max="50" required></label>
                <label class="fp-field"><span>Plätze max.</span>
                    <input name="max_capacity" type="number" min="1" max="50" required></label>
            </div>
            <div class="fp-field">
                <span>Eigenschaften</span>
                <div class="space-y-1.5 text-sm">
                    <label class="flex items-center gap-2"><input type="checkbox" name="online_bookable" class="rounded"> Online buchbar</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="joinable" class="rounded"> Kombinierbar</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="outdoor" class="rounded"> Außenbereich</label>
                    <label class="flex items-center gap-2"><input type="checkbox" name="accessible" class="rounded"> Barrierefrei</label>
                </div>
            </div>
            <p id="tableEditErr" class="fp-err hidden"></p>
            <div class="fp-modal-foot">
                <button type="button" id="tableEditCancel" class="fp-btn">Abbrechen</button>
                <button type="submit" class="fp-btn fp-btn-save">Speichern</button>
            </div>
        </form>
    </div>
</div>
@endif

{{-- Zone modal --}}
@if($canEdit)
<div id="zoneModalBack" class="fp-modal-back hidden">
    <div class="fp-modal">
        <div class="fp-modal-head"><span>◈</span><h3 id="zoneModalTitle">Zone anlegen</h3></div>
        <div class="fp-modal-body">
            <input type="hidden" id="zoneModalId">
            <label class="fp-field">
                <span>Name</span>
                <input id="zoneModalName" maxlength="80" placeholder="z. B. VIP-Bereich" autocomplete="off">
            </label>
            <div class="fp-field">
                <span>Farbe</span>
                <div style="display:flex;align-items:center;gap:10px">
                    <input id="zoneModalColor" type="color" value="#60a5fa"
                           style="width:44px;height:36px;border-radius:8px;border:2px solid #e7e5e4;padding:2px;cursor:pointer">
                    <span id="zoneModalColorHex" style="font-size:13px;color:#78716c;font-family:monospace">#60a5fa</span>
                </div>
            </div>
            <label class="fp-field">
                <span>Transparenz — <span id="zoneModalOpacityVal">25</span> %</span>
                <input id="zoneModalOpacity" type="range" min="0" max="100" value="25"
                       style="width:100%;accent-color:#0f766e">
            </label>
            <p id="zoneModalErr" class="fp-err hidden"></p>
            <div class="fp-modal-foot" style="justify-content:space-between">
                <button type="button" id="zoneModalDelete" class="fp-btn hidden" style="color:#dc2626;border-color:#fca5a5">Löschen</button>
                <div style="display:flex;gap:10px">
                    <button type="button" id="zoneModalCancel" class="fp-btn">Abbrechen</button>
                    <button type="button" id="zoneModalSave" class="fp-btn fp-btn-save">Speichern</button>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

<script>
(function () {
    let SCALE = 0.8;            // px per plan unit (1 unit = 1 cm); recomputed to fit
    const SNAP = 10; // grid snap in plan units
    const stateUrl = @json(route('admin.floorplan.state'));
    const posUrl = @json(route('admin.floorplan.positions'));
    const tableStoreUrl = @json($canEdit ? route('admin.floorplan.tables.store') : '');
    const tableUpdateBase = @json($canEdit ? url('/admin/floorplan/tables') : '');
    const bgBase = @json(url('/admin/floorplan/rooms'));
    const csrf = @json(csrf_token());
    const zoneIndexUrl  = @json(route('admin.floorplan.zones.index'));
    const zoneStoreUrl  = @json($canEdit ? route('admin.floorplan.zones.store') : '');
    const zoneBaseUrl   = @json(url('/admin/floorplan/zones'));
    let editMode = false;
    let tablesData = [];
    let reservationsData = [];
    let selectedId = null;
    let reassigning = null; // {reservationId, tableId, name}
    let zoneMode = false;
    let zonesData = {}; // {roomId: [{id,name,color,opacity,points},...]}
    let drawPoints = [];
    let drawingRoomId = null;
    let editingZone = null; // {id, roomId, name, color, opacity, points}

    const dateInput = document.getElementById('planDate');
    const timeInput = document.getElementById('planTime');
    const popup = document.getElementById('tablePopup');
    const editHint = document.getElementById('editHint');

    // Pick a single px-per-cm scale so the LARGEST room fits the available
    // canvas column, and size every room div accordingly. One shared scale
    // keeps rooms comparable (1 cm is the same number of px everywhere) and
    // lets arbitrarily large rooms render without overflow — tables and zones
    // use the same SCALE, so they always stay proportional and never overlap.
    function computeScale() {
        const rooms = [...document.querySelectorAll('.floor-room')];
        if (!rooms.length) return;
        const host = rooms[0].parentElement;
        const avail = Math.max(320, (host ? host.clientWidth : 900) - 8);
        const MAX_W = Math.min(avail, 1100);   // px
        const MAX_H = 760;                      // px per room
        let maxW = 1, maxH = 1;
        rooms.forEach(r => {
            maxW = Math.max(maxW, +r.dataset.w || 1000);
            maxH = Math.max(maxH, +r.dataset.h || 700);
        });
        SCALE = Math.max(0.04, Math.min(0.9, MAX_W / maxW, MAX_H / maxH));
        rooms.forEach(r => {
            r.style.width  = Math.round((+r.dataset.w || 1000) * SCALE) + 'px';
            r.style.height = Math.round((+r.dataset.h || 700) * SCALE) + 'px';
        });
    }

    async function load() {
        computeScale();
        const res = await fetch(stateUrl + '?date=' + dateInput.value + '&time=' + timeInput.value, {headers: {Accept: 'application/json'}});
        const data = await res.json();
        tablesData = data.tables;
        reservationsData = data.reservations;
        zonesData = {};
        if (data.zones) {
            Object.entries(data.zones).forEach(([rid, zs]) => { zonesData[+rid] = zs; });
        }
        render();
        document.querySelectorAll('.floor-room').forEach(room => {
            renderZones(+room.dataset.room);
            renderRuler(+room.dataset.room);
        });
        renderZoneLegend();
    }

    function esc(s) { return (s ?? '').toString().replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }

    // Chairs placed the way guests really sit: round = around the circle,
    // long rectangles = along the two long sides with a head at each end when
    // needed, near-square = one block per side. Occupied seats are filled.
    function chairsHtml(t, w, h) {
        const n = Math.max(0, t.seats || 0);
        if (!n) return '';
        const occ = Math.min(t.occupied || 0, n);
        const cs = Math.max(11, Math.min(18, Math.round(Math.min(w, h) / 3)));
        const off = cs / 2 + 4;
        const cw = Math.round(cs * 1.1);
        let idx = 0, out = '';
        const seat = (x, y, ang) => {
            const cls = idx < occ ? 'chair occ' : 'chair';
            idx++;
            return `<span class="${cls}" style="left:${x - cw / 2}px;top:${y - cs / 2}px;width:${cw}px;height:${cs}px;transform:rotate(${ang}deg)"></span>`;
        };
        // edge placers: distribute `c` seats evenly along one side, backrest out
        const top = (c) => { for (let i = 0; i < c; i++) out += seat(w * (i + 1) / (c + 1), -off, 0); };
        const bottom = (c) => { for (let i = 0; i < c; i++) out += seat(w * (i + 1) / (c + 1), h + off, 180); };
        const left = (c) => { for (let i = 0; i < c; i++) out += seat(-off, h * (i + 1) / (c + 1), 270); };
        const right = (c) => { for (let i = 0; i < c; i++) out += seat(w + off, h * (i + 1) / (c + 1), 90); };

        if (t.shape === 'round') {
            const r = Math.max(w, h) / 2 + off;
            for (let i = 0; i < n; i++) {
                const a = (Math.PI * 2 * i) / n - Math.PI / 2;
                out += seat(w / 2 + r * Math.cos(a), h / 2 + r * Math.sin(a), a * 180 / Math.PI + 90);
            }
            return out;
        }

        const horizontal = w >= h;          // long sides run left-right?
        const ratio = Math.max(w, h) / Math.min(w, h);

        if (n <= 2) {
            // a couple faces each other across the table
            horizontal ? (top(1), bottom(n - 1)) : (left(1), right(n - 1));
            return out;
        }

        if (ratio < 1.35) {
            // near-square: a block of chairs on each side
            const base = Math.floor(n / 4), rem = n % 4;
            const cnt = [0, 1, 2, 3].map(k => base + (k < rem ? 1 : 0)); // top,bottom,left,right
            top(cnt[0]); bottom(cnt[1]); left(cnt[2]); right(cnt[3]);
            return out;
        }

        // elongated table: long sides carry the guests, ends used as heads
        const heads = n >= 8 ? 2 : (n % 2 === 1 ? 1 : 0);
        const rest = n - heads;
        const sideA = Math.ceil(rest / 2), sideB = rest - sideA;
        if (horizontal) {
            top(sideA); bottom(sideB);
            if (heads >= 1) left(1);
            if (heads >= 2) right(1);
        } else {
            left(sideA); right(sideB);
            if (heads >= 1) top(1);
            if (heads >= 2) bottom(1);
        }
        return out;
    }

    function roomMeta() {
        document.querySelectorAll('[data-meta]').forEach(span => {
            const id = +span.dataset.meta;
            const ts = tablesData.filter(t => t.room_id === id);
            const seats = ts.reduce((s, t) => s + (t.seats || 0), 0);
            span.textContent = ts.length ? `${ts.length} Tische · ${seats} Plätze` : 'noch keine Tische';
        });
    }

    function render() {
        document.querySelectorAll('.floor-room').forEach(room => {
            room.querySelectorAll('.table-el').forEach(el => el.remove());
            room.classList.toggle('is-editing', editMode);
        });
        document.querySelectorAll('.room-edit').forEach(b => b.classList.toggle('hidden', !editMode));
        if (editHint) editHint.classList.toggle('hidden', !editMode);

        tablesData.forEach(t => {
            const room = document.querySelector('.floor-room[data-room="' + t.room_id + '"]');
            if (!room) return;
            const w = t.width * SCALE, h = t.height * SCALE;
            const el = document.createElement('div');
            el.className = 'table-el st-' + t.status + (t.shape === 'round' ? ' is-round' : '') + (t.id === selectedId ? ' selected' : '');
            el.style.left = (t.pos_x * SCALE) + 'px';
            el.style.top = (t.pos_y * SCALE) + 'px';
            el.style.width = w + 'px';
            el.style.height = h + 'px';
            el.style.setProperty('--rot', (t.rotation || 0) + 'deg');
            el.dataset.id = t.id;

            const occLine = (t.occupied > 0)
                ? `<span class="t-occ">${t.occupied}/${t.seats}</span>`
                : `<span class="t-seats">${t.seats} 🪑</span>`;
            const guest = (t.current && !editMode) ? `<span class="t-guest">${esc(t.current.name.split(' ').pop())}</span>` : '';
            const next = (t.upcoming && !t.current && !editMode) ? `<span class="t-next">ab ${t.upcoming.at}</span>` : '';

            // Tag dots from current or upcoming reservation
            const tagSource = t.current || t.upcoming;
            const tagDots = (!editMode && tagSource?.tags?.length)
                ? `<span class="t-tags">${tagSource.tags.map(tg => `<span class="t-tag-dot" style="background:${tg.color}" title="${esc(tg.name)}"></span>`).join('')}</span>`
                : '';

            // Reassign-mode highlight
            if (reassigning) {
                const isSource = t.id === reassigning.tableId;
                el.classList.toggle('reassign-source', isSource);
                el.classList.toggle('reassign-target', !isSource && t.status === 'free');
            }

            el.innerHTML = (editMode ? '' : chairsHtml(t, w, h))
                + `<div class="tbl-label" style="transform:rotate(${-(t.rotation || 0)}deg)">`
                + `<span class="t-name"><span class="st-dot"></span>${esc(t.name)}</span>`
                + `${editMode ? '' : occLine}${guest}${next}${tagDots}</div>`
                + (editMode ? `<button class="edit-btn" title="Eigenschaften bearbeiten">✎</button><button class="rot-btn" title="Drehen">⟳</button>` : '');

            if (editMode) {
                makeDraggable(el, room);
                const rb = el.querySelector('.rot-btn');
                rb.addEventListener('pointerdown', e => e.stopPropagation());
                rb.addEventListener('click', e => {
                    e.stopPropagation();
                    let r = ((t.rotation || 0) + 45);
                    if (r > 180) r -= 360;
                    t.rotation = r;
                    el.style.setProperty('--rot', r + 'deg');
                    el.querySelector('.tbl-label').style.transform = `rotate(${-r}deg)`;
                });
                const eb = el.querySelector('.edit-btn');
                eb.addEventListener('pointerdown', e => e.stopPropagation());
                eb.addEventListener('click', e => { e.stopPropagation(); openTableEdit(t); });
            } else {
                el.addEventListener('click', () => {
                    if (reassigning) {
                        if (t.id !== reassigning.tableId) doReassign(t.id);
                    } else {
                        showPopup(t);
                    }
                });
            }
            room.appendChild(el);
        });
        roomMeta();
    }

    function showPopup(t) {
        const pct = t.seats ? Math.round((t.occupied || 0) / t.seats * 100) : 0;
        let body;
        if (t.current) {
            const cur = t.current.party, full = cur >= t.seats;
            const tagBadges = (t.current.tags?.length)
                ? `<div class="pp-tags">${t.current.tags.map(tg => `<span class="pp-tag" style="background:${tg.color}22;color:${tg.color}">${esc(tg.name)}</span>`).join('')}</div>`
                : '';
            body = `<p class="pp-line"><strong>${esc(t.current.name)}</strong></p>
                <p class="pp-sub">bis ${t.current.until} Uhr</p>
                ${tagBadges}
                <div class="pp-party">
                    <span class="pp-plabel">Gäste am Tisch</span>
                    <div class="pp-step-row">
                        <button class="pp-step" data-d="-1" ${cur <= 1 ? 'disabled' : ''}>−</button>
                        <span class="pp-pcount">${cur} / ${t.seats}</span>
                        <button class="pp-step" data-d="1" ${full ? 'disabled' : ''}>＋</button>
                    </div>
                </div>
                ${full ? '<p class="pp-note">Tisch voll – für mehr Gäste einen größeren oder zusätzlichen Tisch nutzen.</p>' : ''}
                ${['seated', 'partially_arrived'].includes(t.current.status)
                    ? `<button class="pp-btn pp-green" data-checkout="${t.current.id}">✓ Auschecken (Gäste gegangen)</button>` : ''}
                <button class="pp-btn pp-soft" data-reassign="${t.current.id}" data-table="${t.id}" data-name="${esc(t.current.name)}">🔄 Tisch wechseln</button>
                <a href="/admin/reservations/${t.current.id}" class="pp-btn pp-soft">Reservierung öffnen</a>`;
        } else if (t.upcoming) {
            body = `<p class="pp-line">Nächste: <strong>${esc(t.upcoming.name)}</strong></p>
                <p class="pp-sub">um ${t.upcoming.at} Uhr · ${t.upcoming.party} P.</p>
                <a href="/admin/reservations/${t.upcoming.id}" class="pp-btn pp-dark">Reservierung öffnen</a>`;
        } else if (t.status === 'blocked') {
            body = `<p class="pp-sub">Dieser Tisch ist aktuell gesperrt.</p>`;
        } else {
            body = `<p class="pp-sub" style="color:#047857">Frei.</p>
                <a href="{{ route('admin.reservations.create') }}?date=${dateInput.value}&time=${timeInput.value}&table_id=${t.id}" class="pp-btn pp-green">Hier reservieren</a>
                <a href="{{ route('admin.walkins.index') }}" class="pp-btn pp-soft">Walk-in platzieren</a>`;
        }
        popup.innerHTML = `<div class="pp-head st-${t.status}">
                <div><div class="pp-title">Tisch ${esc(t.name)}</div>
                <div class="pp-cap">${t.occupied || 0}/${t.seats} Plätze belegt</div></div>
                <button class="pp-x" onclick="document.getElementById('tablePopup').classList.add('hidden')">✕</button>
            </div>
            <div class="pp-bar"><span style="width:${pct}%"></span></div>
            <div class="pp-body">${body}</div>`;
        popup.classList.remove('hidden');

        popup.querySelectorAll('.pp-step').forEach(b => b.addEventListener('click', () => {
            const next = (t.current.party || 0) + (+b.dataset.d);
            if (next >= 1) setParty(t.id, t.current.id, next);
        }));
        const co = popup.querySelector('[data-checkout]');
        if (co) co.addEventListener('click', () => checkout(co.dataset.checkout));
        const ra = popup.querySelector('[data-reassign]');
        if (ra) ra.addEventListener('click', () => startReassign(+ra.dataset.reassign, +ra.dataset.table, ra.dataset.name));
    }

    function startReassign(reservationId, tableId, name) {
        reassigning = { reservationId, tableId, name };
        popup.classList.add('hidden');
        document.getElementById('reassignLabel').textContent = `Tisch wechseln für „${name}" – Zieltisch anklicken`;
        document.getElementById('reassignBanner').classList.remove('hidden');
        render();
    }

    function cancelReassign() {
        reassigning = null;
        document.getElementById('reassignBanner').classList.add('hidden');
        render();
    }

    async function doReassign(targetTableId) {
        const banner = document.getElementById('reassignBanner');
        banner.style.opacity = '0.6';
        try {
            const res = await fetch('/admin/reservations/' + reassigning.reservationId + '/tables', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                body: JSON.stringify({ table_ids: [targetTableId] }),
            });
            if (!res.ok) {
                const j = await res.json().catch(() => ({}));
                alert(j.message || 'Tisch konnte nicht geändert werden.');
            }
        } finally {
            banner.style.opacity = '';
            cancelReassign();
            load();
        }
    }

    document.getElementById('reassignCancel').addEventListener('click', cancelReassign);

    async function checkout(reservationId) {
        if (!confirm('Tisch auschecken – die Gäste sind gegangen?')) return;
        const res = await fetch('/admin/reservations/' + reservationId + '/transition', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
            body: JSON.stringify({status: 'completed'}),
        });
        if (!res.ok) { alert('Auschecken fehlgeschlagen. Bitte erneut versuchen.'); return; }
        popup.classList.add('hidden');
        load();
    }

    async function setParty(tableId, reservationId, size) {
        const res = await fetch('/admin/reservations/' + reservationId + '/party', {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
            body: JSON.stringify({party_size: size}),
        });
        if (!res.ok) {
            const j = await res.json().catch(() => ({}));
            alert(j.message || 'Personenzahl konnte nicht geändert werden.');
            return;
        }
        await load();
        const t = tablesData.find(x => x.id === tableId);
        if (t) showPopup(t); // reopen with refreshed occupancy
    }

    function makeDraggable(el, room) {
        let sx, sy, ox, oy, rw, rh, ew, eh, moved;
        el.addEventListener('pointerdown', e => {
            if (!editMode || e.target.classList.contains('rot-btn')) return;
            el.setPointerCapture(e.pointerId);
            sx = e.clientX; sy = e.clientY; moved = false;
            ox = parseFloat(el.style.left) || 0; oy = parseFloat(el.style.top) || 0;
            rw = room.clientWidth; rh = room.clientHeight; ew = el.offsetWidth; eh = el.offsetHeight;
            el.classList.add('dragging');
            e.preventDefault();
        });
        el.addEventListener('pointermove', e => {
            if (!el.hasPointerCapture(e.pointerId)) return;
            const dx = e.clientX - sx, dy = e.clientY - sy;
            if (Math.abs(dx) > 3 || Math.abs(dy) > 3) moved = true;
            el.style.left = Math.min(Math.max(0, ox + dx), Math.max(0, rw - ew)) + 'px';
            el.style.top = Math.min(Math.max(0, oy + dy), Math.max(0, rh - eh)) + 'px';
        });
        const end = e => {
            try { el.releasePointerCapture(e.pointerId); } catch (_) {}
            el.classList.remove('dragging');
            const t = tablesData.find(x => x.id == el.dataset.id);
            if (!t) return;
            if (moved) {
                // snap to grid
                let px = Math.round((parseFloat(el.style.left) || 0) / SCALE / SNAP) * SNAP;
                let py = Math.round((parseFloat(el.style.top) || 0) / SCALE / SNAP) * SNAP;
                t.pos_x = px; t.pos_y = py;
                el.style.left = (px * SCALE) + 'px';
                el.style.top = (py * SCALE) + 'px';
            } else {
                // tap = select
                selectedId = selectedId === t.id ? null : t.id;
                document.querySelectorAll('.table-el').forEach(x =>
                    x.classList.toggle('selected', +x.dataset.id === selectedId));
            }
        };
        el.addEventListener('pointerup', end);
        el.addEventListener('pointercancel', end);
    }

    // ---- Zone rendering ----
    function renderZones(roomId) {
        const svg = document.querySelector(`.floor-room[data-room="${roomId}"] .zones-svg-layer`);
        if (!svg) return;
        const room = document.querySelector(`.floor-room[data-room="${roomId}"]`);
        const w = room ? room.clientWidth : 0;
        const h = room ? room.clientHeight : 0;
        svg.setAttribute('viewBox', `0 0 ${w} ${h}`);
        svg.setAttribute('width', w);
        svg.setAttribute('height', h);
        const drawEls = [...svg.querySelectorAll('.draw-el')];
        svg.innerHTML = '';
        drawEls.forEach(el => svg.appendChild(el));
        (zonesData[roomId] || []).forEach(z => {
            if (!z.points || z.points.length < 3) return;
            const pts = z.points.map(([x, y]) => `${x * SCALE},${y * SCALE}`).join(' ');
            const poly = document.createElementNS('http://www.w3.org/2000/svg', 'polygon');
            poly.setAttribute('points', pts);
            poly.setAttribute('fill', z.color);
            poly.setAttribute('fill-opacity', z.opacity / 100);
            poly.setAttribute('stroke', z.color);
            poly.setAttribute('stroke-width', '1.5');
            poly.setAttribute('stroke-opacity', '0.8');
            poly.dataset.zoneId = z.id;
            poly.style.pointerEvents = zoneMode ? 'all' : 'none';
            if (zoneMode) {
                poly.style.cursor = 'pointer';
                poly.addEventListener('click', e => { e.stopPropagation(); openZoneModal(z, roomId); });
            }
            svg.appendChild(poly);
            // Label in centroid
            const cx = z.points.reduce((s, p) => s + p[0], 0) / z.points.length * SCALE;
            const cy = z.points.reduce((s, p) => s + p[1], 0) / z.points.length * SCALE;
            const text = document.createElementNS('http://www.w3.org/2000/svg', 'text');
            text.setAttribute('x', cx); text.setAttribute('y', cy);
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('dominant-baseline', 'middle');
            text.setAttribute('fill', z.color);
            text.setAttribute('font-size', '12');
            text.setAttribute('font-weight', '700');
            text.setAttribute('pointer-events', 'none');
            text.textContent = z.name;
            svg.appendChild(text);
        });
    }

    function renderZoneLegend() {
        const el = document.getElementById('zoneLegend');
        if (!el) return;
        const all = Object.values(zonesData).flat();
        if (!all.length) { el.classList.add('hidden'); return; }
        const seen = new Set();
        const unique = all.filter(z => !seen.has(z.id) && seen.add(z.id));
        el.innerHTML = '<span class="fp-zone-leg-title">Zonen:</span>'
            + unique.map(z => `<span class="fp-leg"><span class="fp-leg-dot" style="background:${z.color};border-radius:50%"></span>${esc(z.name)}</span>`).join('');
        el.classList.remove('hidden');
    }

    function renderRuler(roomId) {
        const el = document.querySelector(`.fp-ruler[data-ruler="${roomId}"]`);
        if (!el) return;
        const room = document.querySelector(`.floor-room[data-room="${roomId}"]`);
        if (!room) { el.classList.add('hidden'); return; }
        const wm = parseFloat(room.dataset.widthM || '0');
        const planW = parseFloat(room.dataset.w || '0');
        if (!wm || !planW) { el.classList.add('hidden'); return; }
        const pixelW = planW * SCALE;
        const pxPerM = pixelW / wm;
        let stepM = 1;
        if (wm > 20) stepM = 5;
        else if (wm > 10) stepM = 2;
        const steps = Math.floor(wm / stepM);
        let html = `<div style="position:relative;height:18px;width:${pixelW}px;overflow:hidden">`;
        for (let i = 1; i <= steps; i++) {
            const x = pxPerM * stepM * i;
            html += `<span style="position:absolute;left:${x}px;transform:translateX(-50%);font-size:9px;color:#a8a29e;white-space:nowrap">${i * stepM}m</span>`;
        }
        html += `<div style="position:absolute;top:0;left:0;right:0;height:1px;background:#e7e5e4"></div></div>`;
        el.innerHTML = html;
        el.classList.remove('hidden');
    }

    // ---- Zone draw ----
    function updateDrawPreview(roomId) {
        const svg = document.querySelector(`.floor-room[data-room="${roomId}"] .zones-svg-layer`);
        if (!svg) return;
        svg.querySelectorAll('.draw-el').forEach(el => el.remove());
        if (!drawPoints.length) return;
        const ns = 'http://www.w3.org/2000/svg';
        if (drawPoints.length >= 2) {
            const line = document.createElementNS(ns, 'polyline');
            line.setAttribute('points', drawPoints.map(([x, y]) => `${x * SCALE},${y * SCALE}`).join(' '));
            line.setAttribute('fill', 'none');
            line.setAttribute('stroke', '#0f766e');
            line.setAttribute('stroke-width', '2');
            line.setAttribute('stroke-dasharray', '6 3');
            line.classList.add('draw-el');
            svg.appendChild(line);
        }
        drawPoints.forEach(([x, y], i) => {
            const c = document.createElementNS(ns, 'circle');
            c.setAttribute('cx', x * SCALE); c.setAttribute('cy', y * SCALE);
            c.setAttribute('r', (i === 0 && drawPoints.length >= 3) ? 8 : 5);
            c.setAttribute('fill', i === 0 ? '#0f766e' : '#fff');
            c.setAttribute('stroke', '#0f766e'); c.setAttribute('stroke-width', '2');
            c.classList.add('draw-el');
            svg.appendChild(c);
        });
    }

    function addVertex(planX, planY, roomId) {
        if (drawingRoomId !== null && drawingRoomId !== roomId) {
            drawPoints = []; // switched room – start fresh
        }
        drawingRoomId = roomId;
        if (drawPoints.length >= 3) {
            const [fx, fy] = drawPoints[0];
            if (Math.sqrt((planX - fx) ** 2 + (planY - fy) ** 2) < 12) {
                closePolygon(roomId); return;
            }
        }
        drawPoints.push([planX, planY]);
        updateDrawPreview(roomId);
    }

    function closePolygon(roomId) {
        if (drawPoints.length < 3) return;
        editingZone = null;
        openZoneModal(null, roomId);
    }

    function cancelDraw() {
        drawPoints = []; drawingRoomId = null; editingZone = null;
        document.querySelectorAll('.zones-svg-layer .draw-el').forEach(el => el.remove());
    }

    // ---- Zone modal ----
    function openZoneModal(zone, roomId) {
        const back  = document.getElementById('zoneModalBack');
        if (!back) return;
        const isNew = !zone || !zone.id;
        document.getElementById('zoneModalTitle').textContent = isNew ? 'Zone anlegen' : 'Zone bearbeiten';
        document.getElementById('zoneModalId').value = zone?.id || '';
        document.getElementById('zoneModalName').value = zone?.name || '';
        const col = zone?.color || '#60a5fa';
        document.getElementById('zoneModalColor').value = col;
        document.getElementById('zoneModalColorHex').textContent = col;
        const op = zone?.opacity ?? 25;
        document.getElementById('zoneModalOpacity').value = op;
        document.getElementById('zoneModalOpacityVal').textContent = op;
        document.getElementById('zoneModalErr').classList.add('hidden');
        document.getElementById('zoneModalDelete').classList.toggle('hidden', isNew);
        if (!isNew) editingZone = { ...zone, roomId };
        back.classList.remove('hidden'); back.classList.add('flex');
        document.getElementById('zoneModalName').focus();
    }

    function closeZoneModal() {
        const back = document.getElementById('zoneModalBack');
        if (!back) return;
        back.classList.add('hidden'); back.classList.remove('flex');
        if (editingZone) { editingZone = null; } else { cancelDraw(); }
    }

    async function saveZone() {
        const nameEl  = document.getElementById('zoneModalName');
        const colorEl = document.getElementById('zoneModalColor');
        const opEl    = document.getElementById('zoneModalOpacity');
        const idEl    = document.getElementById('zoneModalId');
        const errEl   = document.getElementById('zoneModalErr');
        const saveBt  = document.getElementById('zoneModalSave');
        if (!nameEl.value.trim()) {
            errEl.textContent = 'Bitte einen Namen eingeben.';
            errEl.classList.remove('hidden'); return;
        }
        errEl.classList.add('hidden');
        saveBt.disabled = true;
        const payload = { name: nameEl.value.trim(), color: colorEl.value, opacity: parseInt(opEl.value, 10) };
        const existingId = idEl.value;
        let url, method;
        if (existingId) {
            url = zoneBaseUrl + '/' + existingId; method = 'PUT';
            payload.points = editingZone?.points || [];
        } else {
            url = zoneStoreUrl; method = 'POST';
            payload.room_id = drawingRoomId;
            payload.points = drawPoints;
        }
        try {
            const res = await fetch(url, {
                method, headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                body: JSON.stringify(payload),
            });
            const j = await res.json().catch(() => ({}));
            if (!res.ok) {
                errEl.textContent = j.message || (j.errors ? Object.values(j.errors).flat()[0] : `Fehler ${res.status}`);
                errEl.classList.remove('hidden'); return;
            }
            const roomId = j.room_id || editingZone?.roomId || drawingRoomId;
            if (!zonesData[roomId]) zonesData[roomId] = [];
            if (existingId) {
                const idx = zonesData[roomId].findIndex(z => z.id == existingId);
                if (idx >= 0) zonesData[roomId][idx] = j; else zonesData[roomId].push(j);
            } else {
                zonesData[roomId].push(j);
            }
            closeZoneModal();
            cancelDraw();
            renderZones(roomId);
            renderZoneLegend();
        } catch {
            errEl.textContent = 'Netzwerkfehler.';
            errEl.classList.remove('hidden');
        } finally { saveBt.disabled = false; }
    }

    async function deleteZone() {
        if (!editingZone?.id) return;
        if (!confirm('Zone löschen?')) return;
        const res = await fetch(zoneBaseUrl + '/' + editingZone.id, {
            method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
        });
        if (!res.ok) { alert('Zone konnte nicht gelöscht werden.'); return; }
        const roomId = editingZone.roomId;
        if (zonesData[roomId]) zonesData[roomId] = zonesData[roomId].filter(z => z.id !== editingZone.id);
        closeZoneModal();
        renderZones(roomId);
        renderZoneLegend();
    }

    // Zone modal wiring
    (function () {
        const back = document.getElementById('zoneModalBack');
        if (!back) return;
        document.getElementById('zoneModalCancel').addEventListener('click', closeZoneModal);
        back.addEventListener('click', e => { if (e.target === back) closeZoneModal(); });
        document.getElementById('zoneModalSave').addEventListener('click', saveZone);
        document.getElementById('zoneModalDelete').addEventListener('click', deleteZone);
        document.getElementById('zoneModalColor').addEventListener('input', e => {
            document.getElementById('zoneModalColorHex').textContent = e.target.value;
        });
        document.getElementById('zoneModalOpacity').addEventListener('input', e => {
            document.getElementById('zoneModalOpacityVal').textContent = e.target.value;
        });
    })();

    // ---- Edit mode + save ----
    const editToggle  = document.getElementById('editToggle');
    const saveBtn     = document.getElementById('saveLayout');
    const editHintTip = document.getElementById('editHintTip');
    const subBtnTables = document.getElementById('editSubTables');
    const subBtnZones  = document.getElementById('editSubZones');

    function applyEditSubMode(subMode) {
        // subMode: 'tables' | 'zones'
        zoneMode = subMode === 'zones';
        subBtnTables?.classList.toggle('on', !zoneMode);
        subBtnZones?.classList.toggle('on', zoneMode);
        if (editHintTip) {
            editHintTip.textContent = zoneMode
                ? 'Klicken = Vertex setzen · Doppelklick = Polygon schließen · Zone anklicken = bearbeiten'
                : 'Ziehen zum Verschieben · ⟳ drehen · am Raster ausgerichtet · „Speichern" nicht vergessen';
        }
        if (!zoneMode) cancelDraw();
        document.querySelectorAll('.floor-room').forEach(room => {
            room.style.cursor = zoneMode ? 'crosshair' : '';
            renderZones(+room.dataset.room);
        });
        if (!zoneMode) render();
    }

    if (editToggle) {
        editToggle.addEventListener('click', () => {
            editMode = !editMode;
            editToggle.classList.toggle('on', editMode);
            saveBtn.classList.toggle('hidden', !editMode);
            editHint?.classList.toggle('hidden', !editMode);
            popup.classList.add('hidden');
            selectedId = null;
            if (!editMode) {
                // leaving edit mode: reset zone sub-mode
                zoneMode = false;
                cancelDraw();
                subBtnTables?.classList.add('on');
                subBtnZones?.classList.remove('on');
                document.querySelectorAll('.floor-room').forEach(r => { r.style.cursor = ''; renderZones(+r.dataset.room); });
            }
            render();
        });

        subBtnTables?.addEventListener('click', () => applyEditSubMode('tables'));
        subBtnZones?.addEventListener('click',  () => applyEditSubMode('zones'));

        saveBtn.addEventListener('click', async () => {
            saveBtn.disabled = true;
            await fetch(posUrl, {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                body: JSON.stringify({tables: tablesData.map(t => ({id: t.id, pos_x: t.pos_x, pos_y: t.pos_y, rotation: t.rotation}))}),
            });
            saveBtn.disabled = false;
            editMode = false;
            zoneMode = false;
            cancelDraw();
            editToggle.classList.remove('on');
            saveBtn.classList.add('hidden');
            editHint?.classList.add('hidden');
            subBtnTables?.classList.add('on');
            subBtnZones?.classList.remove('on');
            document.querySelectorAll('.floor-room').forEach(r => r.style.cursor = '');
            selectedId = null;
            load();
        });
    }

    // Floor-room click → zone vertex when in zone mode
    document.querySelectorAll('.floor-room').forEach(room => {
        room.addEventListener('click', e => {
            if (!zoneMode) return;
            if (e.target.closest('.table-el')) return;
            if (e.target.tagName === 'polygon' && e.target.dataset.zoneId) return;
            const rect = room.getBoundingClientRect();
            const planX = Math.round((e.clientX - rect.left) / SCALE / 10) * 10;
            const planY = Math.round((e.clientY - rect.top) / SCALE / 10) * 10;
            addVertex(planX, planY, +room.dataset.room);
        });
        room.addEventListener('dblclick', e => {
            if (!zoneMode) return;
            e.preventDefault();
            closePolygon(+room.dataset.room);
        });
    });

    // ---- New table modal ----
    const modal = document.getElementById('newTableBack');
    if (modal) {
        const form = document.getElementById('newTableForm');
        const errEl = document.getElementById('newTableErr');
        const seg = document.getElementById('shapeSeg');
        seg.querySelectorAll('button').forEach(b => b.addEventListener('click', () => {
            seg.querySelectorAll('button').forEach(x => x.classList.remove('on'));
            b.classList.add('on');
            form.shape.value = b.dataset.shape;
        }));
        document.querySelectorAll('.add-table').forEach(b => b.addEventListener('click', () => {
            form.reset();
            form.room_id.value = b.dataset.room;
            form.shape.value = 'rect';
            seg.querySelectorAll('button').forEach((x, i) => x.classList.toggle('on', i === 0));
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
            selectedId = j.table.id;
            closeModal();
            render();
        });
    }

    // ---- Edit table modal ----
    const editModal = document.getElementById('tableEditBack');
    let editModalOpen = null;
    if (editModal) {
        const eForm = document.getElementById('tableEditForm');
        const eErr = document.getElementById('tableEditErr');
        const closeEdit = () => { editModal.classList.add('hidden'); editModal.classList.remove('flex'); editModalOpen = null; };
        document.getElementById('tableEditCancel').addEventListener('click', closeEdit);
        editModal.addEventListener('click', e => { if (e.target === editModal) closeEdit(); });

        // Called from the per-table ✎ button (function declaration → hoisted).
        window.openTableEditImpl = function (t) {
            editModalOpen = t.id;
            eErr.classList.add('hidden');
            eForm.id.value = t.id;
            eForm.name.value = t.name || '';
            eForm.min_capacity.value = t.min_capacity ?? 2;
            eForm.max_capacity.value = t.max_capacity ?? 4;
            eForm.online_bookable.checked = !!t.online_bookable;
            eForm.joinable.checked = !!t.joinable;
            eForm.outdoor.checked = !!t.outdoor;
            eForm.accessible.checked = !!t.accessible;
            editModal.classList.remove('hidden');
            editModal.classList.add('flex');
        };

        eForm.addEventListener('submit', async e => {
            e.preventDefault();
            eErr.classList.add('hidden');
            const id = eForm.id.value;
            const payload = {
                name: eForm.name.value,
                min_capacity: eForm.min_capacity.value,
                max_capacity: eForm.max_capacity.value,
                online_bookable: eForm.online_bookable.checked,
                joinable: eForm.joinable.checked,
                outdoor: eForm.outdoor.checked,
                accessible: eForm.accessible.checked,
            };
            const res = await fetch(`${tableUpdateBase}/${id}`, {
                method: 'PUT',
                headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json'},
                body: JSON.stringify(payload),
            });
            if (!res.ok) {
                const j = await res.json().catch(() => ({}));
                eErr.textContent = j.message || (j.errors ? Object.values(j.errors)[0][0] : 'Konnte nicht gespeichert werden.');
                eErr.classList.remove('hidden');
                return;
            }
            const j = await res.json();
            const idx = tablesData.findIndex(x => x.id == id);
            if (idx !== -1) tablesData[idx] = { ...tablesData[idx], ...j.table };
            closeEdit();
            render();
        });
    }

    function openTableEdit(t) {
        if (window.openTableEditImpl) window.openTableEditImpl(t);
    }

    // ---- Background upload / clear ----
    document.querySelectorAll('.bg-upload').forEach(input => input.addEventListener('change', async () => {
        const file = input.files[0];
        if (!file) return;
        const roomId = input.dataset.room;
        const fd = new FormData();
        fd.append('image', file);
        const res = await fetch(`${bgBase}/${roomId}/background`, {
            method: 'POST', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}, body: fd,
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
        await fetch(`${bgBase}/${roomId}/background`, {method: 'DELETE', headers: {'X-CSRF-TOKEN': csrf, Accept: 'application/json'}});
        document.querySelector('.floor-room[data-room="' + roomId + '"]').style.backgroundImage = '';
        btn.classList.add('hidden');
    }));

    dateInput.addEventListener('change', load);
    timeInput.addEventListener('change', load);

    // Re-fit the canvas when the window/column width changes, then re-place
    // tables and zones at the new scale.
    let resizeTimer = null;
    window.addEventListener('resize', () => {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            computeScale();
            render();
            document.querySelectorAll('.floor-room').forEach(room => {
                renderZones(+room.dataset.room);
                renderRuler(+room.dataset.room);
            });
        }, 150);
    });

    load();
    setInterval(() => { if (!editMode) load(); }, 30000);
})();
</script>

<script>
// ── Tischkombinationen ────────────────────────────────────────────────────
(function () {
    const csrf      = @json(csrf_token());
    const canEdit   = @json($canEdit);
    const storeUrl  = @json($canEdit ? route('admin.settings.combinations.store') : '');
    const deleteBase = @json($canEdit ? url('/admin/settings/combinations') : '');
    const updateBase = @json($canEdit ? url('/admin/settings/combinations') : '');

    let combosData = @json($combosJson);
    let editingId = null;

    // ── Capacity suggestion ───────────────────────────────────────────────
    // Returns how many of a rect table's narrow-end (Stirnseite) seats exist.
    // Mirrors chairsHtml: elongated rects with odd seat count get 1 head;
    // those with ≥ 8 seats get 2 heads (one at each narrow end).
    // Round tables never have end seats.
    function headCount(shape, n) {
        if (shape !== 'rect') return 0;
        return n >= 8 ? 2 : (n % 2 === 1 ? 1 : 0);
    }

    // Correct algorithm for N tables pushed together in a line.
    //
    // Physical model: N tables → N-1 junctions. At each junction the two
    // touching narrow ends each lose their end seat (if one exists).
    // An "end" table in the line has 1 junction; a "middle" table has 2.
    //
    // To get the realistic (minimum) capacity we maximise subtractions:
    //   • Tables with 2 end seats should sit in MIDDLE positions so both
    //     ends face junctions.
    //   • Tables with 0/1 end seat go to the outer ends of the line.
    //
    // Formula (greedy match, sort both lists descending):
    //   middleSlots = max(0, N-2)   → each worth 2 junction sides
    //   endSlots    = 2             → each worth 1 junction side
    //   sub = Σ min(headCount_i, junctionCapacity_i)
    //
    // Simplified closed form:
    //   sub = h2*2 + h1 − max(0, h2 − middleSlots)
    // where h2 = #tables with 2 heads, h1 = #tables with 1 head.
    function suggestCapacity(tables) {
        if (tables.length < 2) return 0;
        const total = tables.reduce((s, t) => s + t.max_capacity, 0);
        const n = tables.length;
        let h2 = 0, h1 = 0;
        tables.forEach(t => {
            const h = headCount(t.shape, t.max_capacity);
            if (h >= 2) h2++;
            else if (h === 1) h1++;
        });
        const middleSlots = Math.max(0, n - 2);
        const sub = h2 * 2 + h1 - Math.max(0, h2 - middleSlots);
        return Math.max(total - sub, 1);
    }

    // ── Modal open/close ─────────────────────────────────────────────────
    const comboBack = document.getElementById('comboBack');
    const comboBtn  = document.getElementById('comboToggle');
    const closeBtn  = document.getElementById('comboPanelClose');

    function openComboModal()  { comboBack.classList.remove('hidden'); comboBack.classList.add('flex'); }
    function closeComboModal() { comboBack.classList.add('hidden'); comboBack.classList.remove('flex'); }

    comboBtn?.addEventListener('click', openComboModal);
    closeBtn?.addEventListener('click', closeComboModal);
    comboBack?.addEventListener('click', e => { if (e.target === comboBack) closeComboModal(); });

    // ── Render combo list ─────────────────────────────────────────────────
    function renderList() {
        const list = document.getElementById('comboList');
        if (!list) return;
        if (combosData.length === 0) {
            list.innerHTML = '<p class="py-4 text-center text-xs text-stone-400">Noch keine Kombinationen angelegt.</p>';
            return;
        }
        list.innerHTML = combosData.map(c => `
            <div class="combo-row flex items-start justify-between gap-2 rounded-xl border border-stone-100 bg-stone-50 px-3 py-2.5">
                <div class="min-w-0">
                    <p class="font-semibold text-stone-800">${c.name}</p>
                    <p class="mt-0.5 text-xs text-stone-500">${c.tables.map(t => t.name).join(' + ')} · ${c.min_capacity}–${c.max_capacity} Personen</p>
                </div>
                ${canEdit ? `<div class="flex shrink-0 gap-1">
                    <button class="combo-edit fp-mini fp-mini-ghost !py-1 !px-2 text-teal-600 hover:!border-teal-300 hover:bg-teal-50" data-id="${c.id}" title="Kombination bearbeiten">✎</button>
                    <button class="combo-del fp-mini fp-mini-ghost !py-1 !px-2 text-red-500 hover:!border-red-300 hover:bg-red-50 hover:text-red-700" data-id="${c.id}" title="Kombination löschen">✕</button>
                </div>` : ''}
            </div>
        `).join('');

        if (canEdit) {
            list.querySelectorAll('.combo-edit').forEach(btn => {
                btn.addEventListener('click', () => {
                    const c = combosData.find(x => x.id == btn.dataset.id);
                    if (c) openComboEdit(c);
                });
            });
            list.querySelectorAll('.combo-del').forEach(btn => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Tischkombination löschen?')) return;
                    btn.disabled = true;
                    const res = await fetch(`${deleteBase}/${btn.dataset.id}`, {
                        method: 'DELETE',
                        headers: { 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    });
                    if (res.ok) {
                        combosData = combosData.filter(c => c.id != btn.dataset.id);
                        renderList();
                    } else {
                        btn.disabled = false;
                        alert('Löschen fehlgeschlagen.');
                    }
                });
            });
        }
    }
    renderList();

    // ── Create form ───────────────────────────────────────────────────────
    const openBtn    = document.getElementById('openComboForm');
    const cancelBtn  = document.getElementById('cancelComboForm');
    const saveBtn    = document.getElementById('saveComboBtn');
    const form       = document.getElementById('comboForm');
    const nameInput  = document.getElementById('comboNameInput');
    const minInput   = document.getElementById('comboMinInput');
    const maxInput   = document.getElementById('comboMaxInput');
    const hintEl     = document.getElementById('comboHint');
    const errEl      = document.getElementById('comboErr');
    const checksWrap = document.getElementById('comboTableChecks');

    if (!canEdit || !openBtn) return;

    let nameManual = false;
    nameInput?.addEventListener('input', () => { nameManual = !!nameInput.value; });

    function resetForm() {
        checksWrap?.querySelectorAll('.combo-check').forEach(cb => cb.checked = false);
        if (nameInput)  { nameInput.value = ''; nameManual = false; }
        if (minInput)   minInput.value = '';
        if (maxInput)   maxInput.value = '';
        if (hintEl)     hintEl.classList.add('hidden');
        if (errEl)      errEl.classList.add('hidden');
    }

    openBtn.addEventListener('click', () => {
        editingId = null;
        if (saveBtn) saveBtn.textContent = 'Anlegen';
        resetForm();
        form.classList.remove('hidden');
        openBtn.classList.add('hidden');
    });
    cancelBtn?.addEventListener('click', () => {
        editingId = null;
        if (saveBtn) saveBtn.textContent = 'Anlegen';
        form.classList.add('hidden');
        openBtn.classList.remove('hidden');
    });

    // Pre-fill the form to edit an existing combination.
    function openComboEdit(c) {
        editingId = c.id;
        resetForm();
        const ids = c.tables.map(t => t.id);
        checksWrap?.querySelectorAll('.combo-check').forEach(cb => {
            cb.checked = ids.includes(parseInt(cb.value, 10));
        });
        if (nameInput) { nameInput.value = c.name; nameManual = true; }
        if (minInput)  minInput.value = c.min_capacity;
        if (maxInput)  maxInput.value = c.max_capacity;
        if (saveBtn)   saveBtn.textContent = 'Speichern';
        form.classList.remove('hidden');
        openBtn.classList.add('hidden');
    }

    function getSelectedTables() {
        return [...(checksWrap?.querySelectorAll('.combo-check:checked') || [])].map(cb => ({
            id:           parseInt(cb.value, 10),
            name:         cb.dataset.name,
            max_capacity: parseInt(cb.dataset.cap, 10),
            shape:        cb.dataset.shape,
        }));
    }

    function updateSuggestion() {
        const selected = getSelectedTables();
        if (selected.length < 2) {
            if (hintEl) hintEl.classList.add('hidden');
            if (!nameManual && nameInput) nameInput.value = selected.map(t => t.name).join(' + ');
            return;
        }

        if (!nameManual && nameInput) nameInput.value = selected.map(t => t.name).join(' + ');

        const suggested = suggestCapacity(selected);
        const rawTotal  = selected.reduce((s, t) => s + t.max_capacity, 0);
        if (maxInput) maxInput.value = suggested;
        if (minInput && !minInput.value) minInput.value = Math.max(Math.ceil(suggested / 2), 1);

        if (hintEl) {
            const parts = selected.map(t => `${t.name} (${t.max_capacity})`).join(' + ');
            const sub   = rawTotal - suggested;
            hintEl.textContent = sub > 0
                ? `${parts} = ${rawTotal} Pl., Stirnseiten-Abzug: −${sub} → ${suggested} Pl.`
                : `${parts} = ${suggested} Pl., keine Stirnseiten-Sitze abgezogen`;
            hintEl.classList.remove('hidden');
        }
    }

    checksWrap?.querySelectorAll('.combo-check').forEach(cb => cb.addEventListener('change', updateSuggestion));

    saveBtn?.addEventListener('click', async () => {
        const selected = getSelectedTables();
        if (selected.length < 2) {
            if (errEl) { errEl.textContent = 'Bitte mindestens zwei Tische auswählen.'; errEl.classList.remove('hidden'); }
            return;
        }
        if (!nameInput?.value.trim()) {
            if (errEl) { errEl.textContent = 'Bitte einen Namen eingeben.'; errEl.classList.remove('hidden'); }
            return;
        }
        if (!minInput?.value || !maxInput?.value) {
            if (errEl) { errEl.textContent = 'Bitte Min. und Max. Personen angeben.'; errEl.classList.remove('hidden'); }
            return;
        }
        if (errEl) errEl.classList.add('hidden');
        saveBtn.disabled = true;
        saveBtn.textContent = '…';

        const payload = {
            name:         nameInput.value.trim(),
            table_ids:    selected.map(t => t.id),
            min_capacity: parseInt(minInput.value, 10),
            max_capacity: parseInt(maxInput.value, 10),
        };

        try {
            const res = await fetch(editingId ? `${updateBase}/${editingId}` : storeUrl, {
                method: editingId ? 'PUT' : 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                body: JSON.stringify(payload),
            });
            const json = await res.json().catch(() => ({}));
            if (!res.ok) {
                const msg = json.errors ? Object.values(json.errors).flat().join(' · ') : (json.message || 'Fehler');
                if (errEl) { errEl.textContent = msg; errEl.classList.remove('hidden'); }
                return;
            }
            if (json.combination) {
                if (editingId) {
                    const idx = combosData.findIndex(c => c.id == editingId);
                    if (idx !== -1) combosData[idx] = json.combination; else combosData.push(json.combination);
                } else {
                    combosData.push(json.combination);
                }
            }
            editingId = null;
            renderList();
            form.classList.add('hidden');
            openBtn.classList.remove('hidden');
            resetForm();
        } catch {
            if (errEl) { errEl.textContent = 'Netzwerkfehler.'; errEl.classList.remove('hidden'); }
        } finally {
            saveBtn.disabled = false;
            saveBtn.textContent = editingId ? 'Speichern' : 'Anlegen';
        }
    });
})();
</script>

<style>
    .fp { --r-free:#34d399; --d-free:#059669;
          --r-soon:#fbbf24; --d-soon:#d97706;
          --r-awaiting:#fb923c; --d-awaiting:#ea580c;
          --r-occupied:#60a5fa; --d-occupied:#2563eb;
          --r-no_show_risk:#f87171; --d-no_show_risk:#dc2626;
          --r-blocked:#a8a29e; --d-blocked:#78716c; }

    /* Toolbar */
    .fp-bar { display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:12px; margin-bottom:14px; }
    .fp-bar-l { display:flex; align-items:center; gap:10px; }
    .fp-live { display:inline-flex; align-items:center; gap:5px; font-size:12px; font-weight:600; color:#16a34a; background:#dcfce7; padding:3px 9px; border-radius:999px; }
    .fp-live-dot { width:7px; height:7px; border-radius:50%; background:#22c55e; box-shadow:0 0 0 0 rgba(34,197,94,.5); animation:fpPulse 2s infinite; }
    @keyframes fpPulse { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.5)} 70%{box-shadow:0 0 0 6px rgba(34,197,94,0)} 100%{box-shadow:0 0 0 0 rgba(34,197,94,0)} }
    .fp-bar-r { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
    .fp-when { display:flex; align-items:center; gap:6px; background:#fff; border:1px solid #e7e5e4; border-radius:10px; padding:4px 10px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .fp-when input { border:none; background:transparent; font-size:14px; color:#1c1917; padding:2px; }
    .fp-when input:focus { outline:none; }
    .fp-when-sep { color:#d6d3d1; }
    .fp-btn { display:inline-flex; align-items:center; gap:6px; border:1px solid #e7e5e4; background:#fff; color:#1c1917; border-radius:10px; padding:8px 14px; font-size:14px; font-weight:600; cursor:pointer; transition:all .12s; box-shadow:0 1px 2px rgba(0,0,0,.04); }
    .fp-btn:hover { border-color:#0f766e; }
    .fp-btn.on { background:#fef3c7; border-color:#f59e0b; color:#92400e; }
    .fp-btn-save { background:#0f766e; border-color:#0f766e; color:#fff; }
    .fp-btn-save:hover { background:#0d5f59; }

    /* The .fp-* rules here set display and (same specificity, later source order)
       would otherwise override Tailwind's .hidden — making toggled elements
       (Bearbeiten-Hinweis, Speichern-Button, Raum-Edit, Modals) permanently
       visible. Force .hidden to win so the view/edit toggle works. */
    .hidden { display: none !important; }

    .fp-hint { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:14px; padding:9px 14px; border-radius:12px;
        background:linear-gradient(90deg,#fef3c7,#fffbeb); border:1px solid #fde68a; font-size:13px; font-weight:600; color:#92400e; }
    .fp-hint-tip { font-weight:500; color:#a16207; }
    .fp-edit-seg { display:inline-flex; border:1px solid #d6d3d1; border-radius:8px; overflow:hidden; }
    .fp-edit-seg button { padding:4px 12px; font-size:12px; font-weight:600; background:#fff; color:#57534e; border:none; cursor:pointer; transition:background .1s,color .1s; }
    .fp-edit-seg button + button { border-left:1px solid #d6d3d1; }
    .fp-edit-seg button.on { background:#0f766e; color:#fff; }

    /* Legend */
    .fp-legend { display:flex; flex-wrap:wrap; align-items:center; gap:8px 14px; margin-bottom:18px; padding:10px 14px; background:#fafaf9; border:1px solid #f0efed; border-radius:12px; font-size:12px; color:#57534e; }
    .fp-leg { display:inline-flex; align-items:center; gap:6px; }
    .fp-leg-dot { width:12px; height:12px; border-radius:4px; }
    .fp-leg-sep { width:1px; height:16px; background:#e7e5e4; }
    .lg-free{background:var(--r-free)} .lg-soon{background:var(--r-soon)} .lg-awaiting{background:var(--r-awaiting)}
    .lg-occupied{background:var(--r-occupied)} .lg-no_show_risk{background:var(--r-no_show_risk)} .lg-blocked{background:var(--r-blocked)}
    .fp-chip { width:12px; height:12px; border-radius:50%; background:#e2e8f0; border:1px solid #94a3b8; }
    .fp-chip.occ { background:#475569; border-color:#1e293b; }

    /* Room */
    .fp-room-wrap { margin-bottom:26px; }
    .fp-room-head { display:flex; flex-wrap:wrap; align-items:center; gap:10px; margin-bottom:10px; }
    .fp-room-title { display:flex; align-items:center; gap:8px; }
    .fp-room-ic { font-size:18px; }
    .fp-room-title h2 { font-size:17px; font-weight:800; margin:0; }
    .fp-tag { font-size:11px; font-weight:600; color:#0c4a6e; background:#e0f2fe; padding:2px 8px; border-radius:999px; }
    .fp-room-meta { font-size:12px; color:#a8a29e; font-weight:500; }
    .room-edit { display:flex; flex-wrap:wrap; align-items:center; gap:8px; margin-left:auto; }
    .fp-mini { display:inline-flex; align-items:center; gap:5px; border:1px solid #e7e5e4; background:#fff; color:#1c1917; border-radius:9px; padding:6px 12px; font-size:13px; font-weight:600; cursor:pointer; transition:all .12s; }
    .fp-mini:hover { border-color:#0f766e; }
    .fp-mini-dark { background:#1c1917; border-color:#1c1917; color:#fff; }
    .fp-mini-dark:hover { background:#000; }
    .fp-mini-ghost { background:#fafaf9; color:#78716c; }

    .floor-scroll { overflow:auto; border-radius:18px; border:1px solid #e7e5e4; box-shadow:0 1px 3px rgba(0,0,0,.05), inset 0 1px 0 #fff; background:#fff; }
    .floor-room { position:relative; background-color:#fcfcfb; background-position:center; background-repeat:no-repeat; background-size:cover;
        width:800px; height:560px; /* fallback until JS computes the fitted scale */
        transition:box-shadow .2s; }
    .floor-room .grid-overlay { position:absolute; inset:0; pointer-events:none;
        background-image:linear-gradient(rgba(120,113,108,.06) 1px,transparent 1px),linear-gradient(90deg,rgba(120,113,108,.06) 1px,transparent 1px);
        background-size:20px 20px; }
    .floor-room.is-editing { box-shadow:inset 0 0 0 2px rgba(245,158,11,.5), inset 0 0 40px rgba(245,158,11,.08); }
    .floor-room.is-editing .grid-overlay { background-image:linear-gradient(rgba(245,158,11,.18) 1px,transparent 1px),linear-gradient(90deg,rgba(245,158,11,.18) 1px,transparent 1px); }

    /* Tables */
    .floor-room .table-el { position:absolute; box-sizing:border-box; display:flex; align-items:center; justify-content:center;
        border-radius:14px; border:2px solid var(--ring,#cbd5e1);
        background:linear-gradient(150deg,#ffffff 0%, var(--tint,#f5f5f4) 130%);
        color:#1c1917; font-weight:700; transform:rotate(var(--rot,0deg));
        box-shadow:0 1px 2px rgba(0,0,0,.10), 0 8px 16px -10px rgba(0,0,0,.4), inset 0 1px 0 rgba(255,255,255,.8);
        transition:box-shadow .12s, filter .12s; user-select:none; touch-action:none; }
    .floor-room .table-el.is-round { border-radius:9999px; }
    .floor-room.is-editing .table-el { cursor:grab; }
    .floor-room .table-el:hover { box-shadow:0 2px 6px rgba(0,0,0,.14), 0 14px 22px -12px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.8); filter:brightness(1.02); }
    .floor-room .table-el.dragging { cursor:grabbing; z-index:30; filter:brightness(1.03);
        box-shadow:0 6px 14px rgba(0,0,0,.22), 0 24px 36px -16px rgba(0,0,0,.55); }
    .floor-room .table-el.selected { outline:2px solid #0f766e; outline-offset:2px; }

    .table-el.st-free{--ring:var(--r-free);--tint:#ecfdf5;--dot:var(--d-free)}
    .table-el.st-soon{--ring:var(--r-soon);--tint:#fffbeb;--dot:var(--d-soon)}
    .table-el.st-awaiting{--ring:var(--r-awaiting);--tint:#fff7ed;--dot:var(--d-awaiting)}
    .table-el.st-occupied{--ring:var(--r-occupied);--tint:#eff6ff;--dot:var(--d-occupied)}
    .table-el.st-no_show_risk{--ring:var(--r-no_show_risk);--tint:#fef2f2;--dot:var(--d-no_show_risk)}
    .table-el.st-blocked{--ring:var(--r-blocked);--tint:#f5f5f4;--dot:var(--d-blocked); border-style:dashed}

    .floor-room .tbl-label { display:flex; flex-direction:column; align-items:center; justify-content:center; line-height:1.15; pointer-events:none; gap:1px; }
    .tbl-label .t-name { display:inline-flex; align-items:center; gap:4px; font-size:13px; font-weight:800; color:#1c1917; }
    .tbl-label .st-dot { width:7px; height:7px; border-radius:50%; background:var(--dot,#a8a29e); flex:none; box-shadow:0 0 0 2px rgba(255,255,255,.7); }
    .tbl-label .t-seats { font-size:10px; font-weight:600; color:#78716c; }
    .tbl-label .t-occ { font-size:11px; font-weight:800; color:#1e293b; background:rgba(255,255,255,.7); padding:0 6px; border-radius:999px; }
    .tbl-label .t-guest { font-size:10px; font-weight:600; color:#44403c; max-width:80px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
    .tbl-label .t-next { font-size:10px; font-weight:600; color:#78716c; }

    /* Chairs (seat + outward backrest) */
    .floor-room .chair { position:absolute; border-radius:5px 5px 4px 4px; background:linear-gradient(#efeae3,#ddd6cc);
        box-shadow:0 1px 2px rgba(0,0,0,.22); transform-origin:center; }
    .floor-room .chair::after { content:''; position:absolute; left:18%; right:18%; top:-2px; height:3px; border-radius:3px; background:rgba(120,113,108,.7); }
    .floor-room .chair.occ { background:linear-gradient(#64748b,#475569); }
    .floor-room .chair.occ::after { background:#1e293b; }

    /* Rotate handle (reveal on hover/select) */
    .floor-room .rot-btn { position:absolute; top:-11px; right:-11px; width:24px; height:24px; border-radius:9999px; border:2px solid #fff;
        background:#0f766e; color:#fff; font-size:13px; line-height:1; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,.3);
        opacity:0; transform:scale(.7); transition:opacity .12s, transform .12s; }
    .floor-room .table-el:hover .rot-btn, .floor-room .table-el.selected .rot-btn { opacity:1; transform:scale(1); }
    .floor-room .edit-btn { position:absolute; top:-11px; left:-11px; width:24px; height:24px; border-radius:9999px; border:2px solid #fff;
        background:#1c1917; color:#fff; font-size:12px; line-height:1; cursor:pointer; box-shadow:0 2px 5px rgba(0,0,0,.3);
        opacity:0; transform:scale(.7); transition:opacity .12s, transform .12s; }
    .floor-room .table-el:hover .edit-btn, .floor-room .table-el.selected .edit-btn { opacity:1; transform:scale(1); }

    /* Reservation popup */
    .fp-popup { position:fixed; inset-inline:16px; bottom:16px; z-index:50; border-radius:18px; overflow:hidden; background:#fff;
        border:1px solid #e7e5e4; box-shadow:0 20px 40px -12px rgba(0,0,0,.35); }
    @media(min-width:768px){ .fp-popup{ inset-inline:auto; right:24px; width:340px; } }
    .fp-popup .pp-head { display:flex; align-items:flex-start; justify-content:space-between; padding:14px 16px; }
    .fp-popup .pp-head.st-free{background:#ecfdf5} .fp-popup .pp-head.st-soon{background:#fffbeb}
    .fp-popup .pp-head.st-awaiting{background:#fff7ed} .fp-popup .pp-head.st-occupied{background:#eff6ff}
    .fp-popup .pp-head.st-no_show_risk{background:#fef2f2} .fp-popup .pp-head.st-blocked{background:#f5f5f4}
    .fp-popup .pp-title { font-size:17px; font-weight:800; }
    .fp-popup .pp-cap { font-size:12px; color:#78716c; margin-top:2px; }
    .fp-popup .pp-x { border:none; background:rgba(0,0,0,.05); width:28px; height:28px; border-radius:8px; cursor:pointer; color:#78716c; }
    .fp-popup .pp-bar { height:5px; background:#f0efed; } .fp-popup .pp-bar span { display:block; height:100%; background:#0f766e; transition:width .3s; }
    .fp-popup .pp-body { padding:14px 16px; }
    .fp-popup .pp-line { font-size:14px; } .fp-popup .pp-sub { font-size:13px; color:#57534e; margin-top:2px; }
    .fp-popup .pp-party { margin-top:12px; padding:10px 12px; background:#fafaf9; border:1px solid #f0efed; border-radius:12px; display:flex; align-items:center; justify-content:space-between; gap:10px; }
    .fp-popup .pp-plabel { font-size:13px; font-weight:600; color:#57534e; }
    .fp-popup .pp-step-row { display:flex; align-items:center; gap:10px; }
    .fp-popup .pp-step { width:34px; height:34px; border-radius:9px; border:1px solid #e7e5e4; background:#fff; font-size:18px; font-weight:800; cursor:pointer; line-height:1; color:#1c1917; }
    .fp-popup .pp-step:hover:not(:disabled) { border-color:#0f766e; color:#0f766e; }
    .fp-popup .pp-step:disabled { opacity:.4; cursor:default; }
    .fp-popup .pp-pcount { font-size:15px; font-weight:800; font-variant-numeric:tabular-nums; min-width:48px; text-align:center; }
    .fp-popup .pp-note { margin-top:8px; font-size:12px; color:#b45309; }
    .fp-popup .pp-btn { display:block; text-align:center; margin-top:12px; border-radius:12px; padding:10px; font-size:14px; font-weight:700; text-decoration:none; }
    .fp-popup .pp-dark { background:#1c1917; color:#fff; } .fp-popup .pp-green { background:#0f766e; color:#fff; }
    .fp-popup .pp-soft { background:#f5f5f4; color:#1c1917; }

    /* Modal */
    .fp-modal-back { position:fixed; inset:0; z-index:50; align-items:center; justify-content:center; padding:16px; background:rgba(28,25,23,.45); backdrop-filter:blur(3px); }
    .fp-modal-back.flex { display:flex; }
    .fp-modal { width:100%; max-width:380px; background:#fff; border-radius:20px; box-shadow:0 30px 60px -15px rgba(0,0,0,.5); overflow:hidden; }
    .fp-modal-head { display:flex; align-items:center; gap:10px; padding:18px 20px; background:#fafaf9; border-bottom:1px solid #f0efed; }
    .fp-modal-head span { font-size:20px; } .fp-modal-head h3 { font-size:17px; font-weight:800; margin:0; }
    .fp-modal-body { padding:18px 20px; display:flex; flex-direction:column; gap:14px; }
    .fp-field { display:flex; flex-direction:column; gap:5px; } .fp-field > span { font-size:13px; font-weight:600; color:#57534e; }
    .fp-field input { border:2px solid #e7e5e4; border-radius:10px; padding:9px 12px; font-size:15px; }
    .fp-field input:focus { outline:none; border-color:#0f766e; }
    .fp-grid2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
    .fp-seg { display:flex; gap:6px; } .fp-seg button { flex:1; border:2px solid #e7e5e4; background:#fff; border-radius:10px; padding:9px; font-weight:700; cursor:pointer; }
    .fp-seg button.on { border-color:#0f766e; background:#f0fdfa; color:#0f766e; }
    .fp-err { font-size:13px; color:#dc2626; }
    .fp-modal-foot { display:flex; gap:10px; justify-content:flex-end; }


    /* Tag dots on table tiles */
    .t-tags { display:flex; gap:3px; flex-wrap:wrap; justify-content:center; margin-top:2px; }
    .t-tag-dot { width:8px; height:8px; border-radius:50%; border:1.5px solid rgba(255,255,255,.6); flex-shrink:0; }

    /* Tags in popup */
    .pp-tags { display:flex; flex-wrap:wrap; gap:4px; margin-bottom:8px; }
    .pp-tag { font-size:11px; font-weight:600; padding:2px 8px; border-radius:99px; }

    /* Reassign mode highlight */
    .table-el.reassign-source { outline:3px solid #0d9488; outline-offset:3px; }
    .table-el.reassign-target { cursor:crosshair !important; outline:2px dashed #0d9488; outline-offset:2px; opacity:.85; }
    .table-el.reassign-target:hover { opacity:1; transform:scale(1.04); z-index:20; }

    /* Zone SVG layer */
    .floor-room .zones-svg-layer { position:absolute; inset:0; pointer-events:none; overflow:visible; z-index:5; }

    /* Zone legend */
    .fp-zone-legend { display:flex; flex-wrap:wrap; align-items:center; gap:8px 14px; margin-bottom:10px; padding:8px 14px;
        background:#f0fdfa; border:1px solid #99f6e4; border-radius:12px; font-size:12px; color:#134e4a; }
    .fp-zone-leg-title { font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#0f766e; }

    /* Ruler */
    .fp-ruler { padding:2px 0 0 0; font-size:9px; color:#a8a29e; overflow-x:auto; white-space:nowrap; }
    .fp-ruler.hidden { display:none; }
</style>
@endsection
