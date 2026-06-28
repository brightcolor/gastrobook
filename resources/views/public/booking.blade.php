@extends('layouts.public')
@section('title', ($tenant->isSalon() ? 'Termin buchen' : 'Tisch reservieren') . ' – ' . $location->name)
@section('content')

<style>
/* Akkordeon-Step-Panels */
.step-panel[data-state="locked"] { opacity: 0.38; pointer-events: none; user-select: none; }
.step-panel[data-state="locked"]  .sp-body,
.step-panel[data-state="done"]    .sp-body { display: none; }
.step-panel:not([data-state="done"]) .sp-summary { display: none; }
.step-panel:not([data-state="done"]) .sp-edit    { display: none; }
.sp-num {
    display: inline-flex; align-items: center; justify-content: center;
    width: 1.75rem; height: 1.75rem; border-radius: 9999px;
    font-size: 0.6875rem; font-weight: 700; flex-shrink: 0;
    transition: background .18s ease, color .18s ease;
}
.step-panel[data-state="active"]  .sp-num { background: var(--brand); color: #fff; }
.step-panel[data-state="done"]    .sp-num { background: color-mix(in oklab, var(--brand) 13%, white); color: var(--brand); }
.step-panel[data-state="locked"]  .sp-num { background: #e7e5e4; color: #a8a29e; }
.step-panel[data-state="done"]    .sp-header { cursor: pointer; }
/* Hide native details marker; custom chevron is used instead */
details > summary { list-style: none; }
details > summary::-webkit-details-marker { display: none; }
</style>

<div class="overflow-hidden rounded-3xl bg-white shadow-2xl shadow-stone-500/20 ring-1 ring-black/5">

    {{-- ══ HERO ═══════════════════════════════════════════════════════════════ --}}
    <div class="booking-hero px-8 pb-8 pt-10 text-center">
        @if($location->brand_logo_path || $tenant->brand_logo_path)
            <div class="mx-auto mb-5 inline-flex h-24 w-24 items-center justify-center rounded-2xl bg-white/15 p-2.5 ring-2 ring-white/25 backdrop-blur-sm">
                <img src="{{ route('brand.location.logo', [$tenant->slug, $location->slug]) }}"
                     alt="{{ $location->name }}" class="h-full w-full object-contain drop-shadow">
            </div>
        @else
            <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-white/20 ring-2 ring-white/25 text-3xl">
                {{ $tenant->isSalon() ? '✂️' : '🍽️' }}
            </div>
        @endif
        <h1 class="text-3xl font-black tracking-tight text-white drop-shadow-sm">{{ $location->name }}</h1>
        @if($location->public_intro)
            <p class="mt-2 text-sm leading-relaxed text-white/70">{{ $location->public_intro }}</p>
        @endif
        @if(($upcomingEvents ?? 0) > 0)
            <a href="{{ route('events.index', [$tenant->slug, $location->slug]) }}"
               class="mt-4 inline-flex items-center gap-1.5 rounded-full bg-white/20 px-4 py-1.5 text-sm font-semibold text-white ring-1 ring-white/30 transition hover:bg-white/30">
                🎉 {{ $upcomingEvents }} {{ $upcomingEvents === 1 ? 'Event' : 'Events' }} – Tickets sichern
            </a>
        @endif
    </div>

    @if($errors->any())
        <div class="mx-5 mt-5 rounded-xl bg-red-50 p-4 text-sm text-red-800 sm:mx-6">
            <p class="mb-1 font-semibold">Bitte korrigieren:</p>
            <ul class="list-inside list-disc space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($tenant->isSalon())
    {{-- ══ SALON ═══════════════════════════════════════════════════════════════ --}}
        @if(($services ?? collect())->isEmpty())
            <div class="m-6 rounded-xl bg-amber-50 p-4 text-center text-sm text-amber-800">
                Es sind noch keine Leistungen konfiguriert. Bitte kontaktieren Sie uns direkt.
            </div>
        @else
        <form method="POST" action="{{ $storeUrl }}"
              id="salonBookingForm">
            @csrf
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

            <div class="divide-y divide-stone-100">

                {{-- Step 1: Leistungen --}}
                <div id="ss1" class="step-panel" data-state="active">
                    <div class="sp-header flex items-center gap-3 px-5 py-4 sm:px-6" onclick="salonEdit(1)">
                        <span class="sp-num">1</span>
                        <span class="flex-1 text-sm font-semibold">Leistungen wählen</span>
                        <span class="sp-summary truncate text-sm text-stone-400" id="ss1Summary"></span>
                        <button type="button" class="sp-edit ml-3 shrink-0 text-xs font-semibold text-brand">Ändern</button>
                    </div>
                    <div class="sp-body px-5 pb-5 sm:px-6">
                        <p class="mb-3 text-xs text-stone-400">Mehrere Leistungen kombinierbar.</p>
                        <div class="flex flex-wrap gap-2" id="serviceButtons">
                            @foreach($services as $svc)
                                <button type="button" data-service-id="{{ $svc->id }}"
                                        data-duration="{{ $svc->duration_minutes }}"
                                        data-price="{{ $svc->price_minor }}"
                                        class="service-pill rounded-full border-2 border-stone-200 px-4 py-2 text-sm font-semibold transition-all hover:border-brand hover:bg-brand/5 active:scale-[0.97]">
                                    {{ $svc->name }}
                                    <span class="ml-1 font-normal text-stone-400">· {{ $svc->durationFormatted() }}@if($svc->price_minor > 0) · {{ $svc->priceFormatted() }}@endif</span>
                                </button>
                            @endforeach
                        </div>
                        <div id="serviceInputs"></div>
                        <div id="serviceSummary" class="mt-3 hidden rounded-xl bg-stone-50 px-4 py-3 text-sm">
                            <span class="font-semibold">Gesamt:</span>
                            <span id="summaryDuration"></span><span id="summaryPrice"></span>
                        </div>
                        <button type="button" id="salonNextStep1"
                                class="mt-4 hidden rounded-xl px-5 py-2.5 text-sm font-bold text-white transition-all active:scale-[0.98]"
                                style="background:var(--brand)">
                            Weiter →
                        </button>
                    </div>
                </div>

                {{-- Step 2: Mitarbeiter + Datum + Uhrzeit --}}
                <div id="ss2" class="step-panel" data-state="locked">
                    <div class="sp-header flex items-center gap-3 px-5 py-4 sm:px-6" onclick="salonEdit(2)">
                        <span class="sp-num">2</span>
                        <span class="flex-1 text-sm font-semibold">Wann &amp; bei wem?</span>
                        <span class="sp-summary truncate text-sm text-stone-400" id="ss2Summary"></span>
                        <button type="button" class="sp-edit ml-3 shrink-0 text-xs font-semibold text-brand">Ändern</button>
                    </div>
                    <div class="sp-body space-y-4 px-5 pb-5 sm:px-6">
                        <div>
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-400">Mitarbeiter:in</p>
                            <div id="staffButtons" class="flex flex-wrap gap-2"></div>
                            <input type="hidden" name="staff_member_id" id="staffMemberId" value="0">
                            <p id="noStaffHint" class="mt-2 hidden rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">Keine Person kann alle Ihre Leistungen alleine ausführen.</p>
                        </div>
                        <div>
                            <label for="salonDate" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-stone-400">Datum</label>
                            <input type="date" name="date" id="salonDate"
                                   min="{{ now($location->timezone)->toDateString() }}"
                                   max="{{ now($location->timezone)->addDays($settings->max_advance_days)->toDateString() }}"
                                   value="{{ old('date', now($location->timezone)->toDateString()) }}"
                                   class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3 text-base">
                        </div>
                        <div>
                            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-400">Uhrzeit</p>
                            <div id="salonSlotContainer" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                                <p class="col-span-full text-sm text-stone-400">Wählen Sie zuerst eine Leistung.</p>
                            </div>
                            <input type="hidden" name="time" id="salonTimeInput" value="{{ old('time') }}" required>
                        </div>
                    </div>
                </div>

                {{-- Step 3: Kontaktdaten --}}
                <div id="ss3" class="step-panel" data-state="locked">
                    <div class="sp-header flex items-center gap-3 px-5 py-4 sm:px-6">
                        <span class="sp-num">3</span>
                        <span class="text-sm font-semibold">Ihre Angaben</span>
                    </div>
                    <div class="sp-body space-y-4 px-5 pb-6 sm:px-6">
                        <div>
                            <label for="salonName" class="mb-1.5 block text-sm font-semibold">Name *</label>
                            <input type="text" name="name" id="salonName" required value="{{ old('name') }}" autocomplete="name"
                                   class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                            @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="salonEmail" class="mb-1.5 block text-sm font-semibold">E-Mail *</label>
                            <input type="email" name="email" id="salonEmail" required value="{{ old('email') }}" autocomplete="email"
                                   class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                            @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label for="salonPhone" class="mb-1.5 block text-sm font-semibold">Telefon <span class="font-normal text-stone-400">(optional)</span></label>
                            <input type="tel" name="phone" id="salonPhone" value="{{ old('phone') }}" autocomplete="tel"
                                   class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                        </div>
                        @if($settings->fieldRule('note') !== 'hidden')
                        <details {{ old('note') ? 'open' : '' }} class="group">
                            <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-semibold text-stone-500 hover:text-stone-700">
                                <svg class="h-4 w-4 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                                Besondere Wünsche?
                            </summary>
                            <div class="mt-3">
                                <label for="salonNote" class="mb-1.5 block text-sm font-semibold">Anmerkung</label>
                                <textarea name="note" id="salonNote" rows="2" placeholder="Was sollen wir wissen?"
                                          class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('note') }}</textarea>
                            </div>
                        </details>
                        @endif
                        <div class="space-y-3 border-t border-stone-100 pt-4 text-sm">
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" name="privacy_accepted" value="1" required
                                       class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                                <span class="text-stone-600">Ich habe die @if($tenant->privacy_url)<a href="{{ $tenant->privacy_url }}" target="_blank" rel="noopener" class="text-brand underline">Datenschutzhinweise</a>@else Datenschutzhinweise @endif gelesen und akzeptiere die Verarbeitung meiner Daten. *</span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3">
                                <input type="checkbox" name="newsletter" value="1"
                                       class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                                <span class="text-stone-400">Newsletter erhalten (jederzeit widerrufbar).</span>
                            </label>
                        </div>
                        <button type="submit"
                                class="btn-brand flex w-full items-center justify-center gap-2 rounded-xl py-4 text-base font-bold text-white transition-all active:scale-[0.99]">
                            Termin buchen
                            <svg class="h-5 w-5 opacity-80" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                        </button>
                    </div>
                </div>

            </div>
        </form>

        <script>
        (function () {
            const slotsUrl    = @json(route('booking.slots', [$tenant->slug, $location->slug]));
            const serviceData = @json($services->mapWithKeys(fn($s) => [$s->id => ['staff' => $s->staff->where('is_active', true)->map(fn($m) => ['id' => $m->id, 'name' => $m->name])->values()]])->all());

            const staffInput     = document.getElementById('staffMemberId');
            const dateInput      = document.getElementById('salonDate');
            const timeInput      = document.getElementById('salonTimeInput');
            const slotContainer  = document.getElementById('salonSlotContainer');
            const staffButtons   = document.getElementById('staffButtons');
            const serviceInputs  = document.getElementById('serviceInputs');
            const summaryBox     = document.getElementById('serviceSummary');
            const summaryDur     = document.getElementById('summaryDuration');
            const summaryPrc     = document.getElementById('summaryPrice');
            const noStaffHint    = document.getElementById('noStaffHint');
            const nextBtn        = document.getElementById('salonNextStep1');
            const ss1Summary     = document.getElementById('ss1Summary');
            const ss2Summary     = document.getElementById('ss2Summary');

            const selected = [];

            function sStep(id, state) {
                const el = document.getElementById(id);
                el.dataset.state = state;
                if (state === 'active') {
                    const body = el.querySelector('.sp-body');
                    if (body) { body.classList.remove('reveal-up'); void body.offsetHeight; body.classList.add('reveal-up'); }
                    setTimeout(() => el.scrollIntoView({behavior: 'smooth', block: 'nearest'}), 60);
                }
            }

            function salonEdit(n) {
                sStep('ss' + n, 'active');
                for (let i = n + 1; i <= 3; i++) sStep('ss' + i, 'locked');
                if (n <= 2) timeInput.value = '';
            }
            window.salonEdit = salonEdit;

            function fmtDuration(min) {
                const h = Math.floor(min / 60), m = min % 60;
                return h === 0 ? m + ' Min.' : (m === 0 ? h + ' Std.' : h + ' Std. ' + m + ' Min.');
            }
            function fmtDate(d) {
                const dt = new Date(d + 'T00:00:00');
                return isNaN(dt) ? d : dt.toLocaleDateString('de-DE', {weekday: 'short', day: '2-digit', month: '2-digit'});
            }

            function eligibleStaff() {
                if (!selected.length) return [];
                let inter = null;
                selected.forEach(id => {
                    const staff = (serviceData[id] || {}).staff || [];
                    const ids = new Set(staff.map(m => m.id));
                    inter = inter === null ? staff.slice() : inter.filter(m => ids.has(m.id));
                });
                return inter || [];
            }

            function refresh() {
                serviceInputs.innerHTML = '';
                let dur = 0, price = 0;
                selected.forEach(id => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = 'service_ids[]'; inp.value = id;
                    serviceInputs.appendChild(inp);
                    const pill = document.querySelector('.service-pill[data-service-id="' + id + '"]');
                    dur += parseInt(pill.dataset.duration, 10);
                    price += parseInt(pill.dataset.price, 10);
                });
                if (!selected.length) { summaryBox.classList.add('hidden'); nextBtn.classList.add('hidden'); return; }
                summaryDur.textContent = fmtDuration(dur);
                summaryPrc.textContent = price > 0 ? ' · ' + (price / 100).toFixed(2).replace('.', ',') + ' €' : '';
                summaryBox.classList.remove('hidden');
                nextBtn.classList.remove('hidden');

                const staff = eligibleStaff();
                staffButtons.innerHTML = '<button type="button" data-staff-id="0" class="staff-btn rounded-xl border-2 border-brand bg-stone-50 px-4 py-2 text-sm font-semibold transition-all">Beliebig</button>';
                staff.forEach(m => {
                    const b = document.createElement('button');
                    b.type = 'button'; b.dataset.staffId = m.id;
                    b.className = 'staff-btn rounded-xl border-2 border-stone-200 px-4 py-2 text-sm font-semibold transition-all hover:border-brand hover:bg-brand/5';
                    b.textContent = m.name; staffButtons.appendChild(b);
                });
                staffButtons.querySelectorAll('.staff-btn').forEach(b => b.addEventListener('click', () => selectStaff(b)));
                staffInput.value = 0;
                noStaffHint.classList.toggle('hidden', staff.length > 0);
            }

            function selectStaff(btn) {
                document.querySelectorAll('.staff-btn').forEach(b => b.classList.remove('border-brand', 'bg-brand', 'text-white'));
                btn.classList.add('border-brand', 'bg-brand', 'text-white');
                staffInput.value = btn.dataset.staffId;
                loadSalonSlots();
            }

            function toggleService(pill) {
                const id = parseInt(pill.dataset.serviceId, 10);
                const idx = selected.indexOf(id);
                if (idx === -1) {
                    selected.push(id);
                    pill.classList.add('border-brand', 'bg-brand', 'text-white');
                    pill.querySelectorAll('span').forEach(s => { s.classList.remove('text-stone-400'); s.classList.add('text-white/70'); });
                } else {
                    selected.splice(idx, 1);
                    pill.classList.remove('border-brand', 'bg-brand', 'text-white');
                    pill.querySelectorAll('span').forEach(s => { s.classList.add('text-stone-400'); s.classList.remove('text-white/70'); });
                }
                refresh();
            }

            nextBtn.addEventListener('click', () => {
                const names = selected.map(id => {
                    const p = document.querySelector('.service-pill[data-service-id="' + id + '"]');
                    return p ? p.textContent.trim().split('·')[0].trim() : '';
                }).filter(Boolean).join(', ');
                ss1Summary.textContent = names;
                sStep('ss1', 'done');
                sStep('ss2', 'active');
                loadSalonSlots();
            });

            dateInput.addEventListener('change', loadSalonSlots);

            function makeSalonSlot(time) {
                const btn = document.createElement('button');
                btn.type = 'button'; btn.textContent = time; btn.dataset.time = time;
                btn.className = 'slot-btn rounded-xl border-2 border-stone-200 py-3 text-sm font-bold tracking-wide transition-all hover:border-brand hover:bg-brand/5 hover:shadow-sm active:scale-[0.97]';
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('border-brand', 'bg-brand', 'text-white'));
                    btn.classList.add('border-brand', 'bg-brand', 'text-white');
                    timeInput.value = time;
                    ss2Summary.textContent = fmtDate(dateInput.value) + ' · ' + time + ' Uhr';
                    sStep('ss2', 'done');
                    sStep('ss3', 'active');
                });
                return btn;
            }

            function renderSalonSlots(slots) {
                slotContainer.innerHTML = '';
                const groups = [
                    { label: 'Vormittag',  test: h => h < 12 },
                    { label: 'Mittag',     test: h => h >= 12 && h < 14 },
                    { label: 'Nachmittag', test: h => h >= 14 && h < 18 },
                    { label: 'Abend',      test: h => h >= 18 },
                ];
                let any = false;
                groups.forEach(g => {
                    const times = slots.filter(t => g.test(parseInt(t.split(':')[0], 10)));
                    if (!times.length) return;
                    const lbl = document.createElement('p'); lbl.className = 'slot-group-label'; lbl.textContent = g.label;
                    slotContainer.appendChild(lbl);
                    times.forEach(t => slotContainer.appendChild(makeSalonSlot(t))); any = true;
                });
                if (!any) slots.forEach(t => slotContainer.appendChild(makeSalonSlot(t)));
            }

            async function loadSalonSlots() {
                if (!selected.length || !dateInput.value) return;
                slotContainer.innerHTML = '<p class="col-span-full animate-pulse text-sm text-stone-400">Lade Termine…</p>';
                timeInput.value = '';
                try {
                    const params = new URLSearchParams();
                    params.set('date', dateInput.value);
                    params.set('staff_member_id', staffInput.value || 0);
                    selected.forEach(id => params.append('service_ids[]', id));
                    const res = await fetch(slotsUrl + '?' + params.toString(), {headers: {Accept: 'application/json'}});
                    const data = await res.json();
                    if (!data.slots || !data.slots.length) {
                        slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-red-50 px-3 py-2.5 text-sm font-medium text-red-700">An diesem Tag sind leider keine Termine verfügbar.</p>';
                        return;
                    }
                    renderSalonSlots(data.slots);
                } catch (e) {
                    slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-700">Fehler – bitte erneut versuchen.</p>';
                }
            }

            document.querySelectorAll('.service-pill').forEach(pill => pill.addEventListener('click', () => toggleService(pill)));
        })();
        </script>
        @endif

    @else
    {{-- ══ RESTAURANT: AKKORDEON ═══════════════════════════════════════════════ --}}
    <form method="POST" action="{{ $storeUrl }}"
          id="bookingForm">
        @csrf
        <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

        <div class="divide-y divide-stone-100">

            {{-- ── Step 1: Personenzahl ─────────────────────────────────────── --}}
            <div id="sp1" class="step-panel" data-state="active">
                <div class="sp-header flex items-center gap-3 px-5 py-4 sm:px-6" onclick="if(this.closest('.step-panel').dataset.state==='done')editStep(1)">
                    <span class="sp-num">1</span>
                    <span class="flex-1 text-sm font-semibold">Wie viele Personen?</span>
                    <span class="sp-summary text-sm text-stone-400" id="sp1Summary"></span>
                    <button type="button" class="sp-edit ml-3 text-xs font-semibold text-brand hover:underline" onclick="event.stopPropagation();editStep(1)">Ändern</button>
                </div>
                <div class="sp-body px-5 pb-5 sm:px-6">
                    <div class="grid grid-cols-4 gap-2 sm:grid-cols-5" id="partyButtons">
                        @for($i = $settings->min_party_online; $i <= min($settings->max_party_online, $settings->min_party_online + 8); $i++)
                            <button type="button" data-party="{{ $i }}"
                                    class="party-btn rounded-2xl border-2 border-stone-200 py-3.5 text-xl font-black transition-all duration-150 hover:border-brand hover:bg-brand/5 active:scale-95">
                                {{ $i }}
                            </button>
                        @endfor
                    </div>
                    <input type="hidden" name="party_size" id="partySize" value="{{ old('party_size') }}" required>
                    @error('party_size')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            {{-- ── Step 2: Datum & Uhrzeit ──────────────────────────────────── --}}
            <div id="sp2" class="step-panel" data-state="locked">
                <div class="sp-header flex items-center gap-3 px-5 py-4 sm:px-6" onclick="if(this.closest('.step-panel').dataset.state==='done')editStep(2)">
                    <span class="sp-num">2</span>
                    <span class="flex-1 text-sm font-semibold">Wann?</span>
                    <span class="sp-summary text-sm text-stone-400" id="sp2Summary"></span>
                    <button type="button" class="sp-edit ml-3 text-xs font-semibold text-brand hover:underline" onclick="event.stopPropagation();editStep(2)">Ändern</button>
                </div>
                <div class="sp-body space-y-4 px-5 pb-5 sm:px-6">
                    <input type="date" name="date" id="date" required
                           min="{{ now($location->timezone)->toDateString() }}"
                           max="{{ now($location->timezone)->addDays($settings->max_advance_days)->toDateString() }}"
                           value="{{ old('date', now($location->timezone)->toDateString()) }}"
                           class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3 text-base">
                    @error('date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

                    <div id="slotContainer" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <p class="col-span-full text-sm text-stone-400">Wählen Sie zuerst Ihre Personenzahl.</p>
                    </div>
                    <input type="hidden" name="time" id="timeInput" value="{{ old('time') }}" required>
                    <div id="alternatives" class="hidden rounded-xl bg-amber-50 p-3 text-sm text-amber-900"></div>
                    @error('time')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror

                </div>
            </div>

            {{-- ── Tischplan (eigener Block, bleibt sichtbar wenn Schritt 2 zugeklappt ist) ── --}}
            @if($settings->public_floorplan_enabled)
            <div id="floorplanSection" class="hidden px-5 pb-5 sm:px-6">
                {{-- Stage 1: Zone cards (shown when zones exist) --}}
                <div id="zoneStage" class="hidden">
                    <p class="mb-3 text-sm font-semibold">Bereich wählen</p>
                    <div id="zoneCards" class="grid grid-cols-2 gap-3 sm:grid-cols-3"></div>
                </div>
                {{-- Stage 2: Floor plan (always present, hidden until zone chosen or "all areas") --}}
                <div id="planStage">
                    <div id="zoneBackRow" class="mb-3 hidden">
                        <button type="button" id="zoneBackBtn"
                                class="inline-flex items-center gap-1.5 rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-600 hover:border-brand hover:text-brand transition-colors">
                            ← Anderen Bereich wählen
                        </button>
                        <span id="zoneActiveLabel" class="ml-2 text-sm font-semibold"></span>
                    </div>
                    <div class="mb-3 flex items-center justify-between">
                        <span class="text-sm font-semibold">Tisch wählen <span class="font-normal text-stone-400">(optional)</span></span>
                        <span class="flex gap-3 text-xs text-stone-400">
                            <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-[#34d399]"></span>frei</span>
                            <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm bg-[#d6d3d1]"></span>belegt</span>
                        </span>
                    </div>
                    <div id="roomTabs" class="mb-2 flex flex-wrap gap-2"></div>
                    <div id="floorplanCanvas" class="relative w-full overflow-hidden rounded-xl border-2 border-stone-100 bg-stone-50" style="height:280px"></div>
                    <p class="mt-2 text-xs text-stone-500">Tippen Sie auf einen freien Tisch – oder leer lassen für automatische Zuteilung.</p>
                </div>
                <input type="hidden" name="table_id" id="tableId" value="">
            </div>
            @endif

            {{-- ── Step 3: Kontaktdaten ──────────────────────────────────────── --}}
            <div id="sp3" class="step-panel" data-state="locked">
                <div class="sp-header flex items-center gap-3 px-5 py-4 sm:px-6">
                    <span class="sp-num">3</span>
                    <span class="text-sm font-semibold">Ihre Angaben</span>
                </div>
                <div class="sp-body space-y-4 px-5 pb-6 sm:px-6">
                    <div>
                        <label for="name" class="mb-1.5 block text-sm font-semibold">Name *</label>
                        <input type="text" name="name" id="name" required value="{{ old('name') }}" autocomplete="name"
                               class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                        @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    @if($settings->fieldRule('email') !== 'hidden')
                    <div>
                        <label for="email" class="mb-1.5 block text-sm font-semibold">E-Mail {{ $settings->fieldRule('email') === 'required' ? '*' : '' }}</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}" autocomplete="email"
                               @if($settings->fieldRule('email') === 'required') required @endif
                               class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                        @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    @endif
                    @if($settings->fieldRule('phone') !== 'hidden')
                    <div>
                        <label for="phone" class="mb-1.5 block text-sm font-semibold">Telefon {{ $settings->fieldRule('phone') === 'required' ? '*' : '' }}</label>
                        <input type="tel" name="phone" id="phone" value="{{ old('phone') }}" autocomplete="tel"
                               @if($settings->fieldRule('phone') === 'required') required @endif
                               class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                        @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    @endif
                    @php $hasExtras = $settings->fieldRule('occasion') !== 'hidden' || $settings->fieldRule('allergies') !== 'hidden' || $settings->fieldRule('note') !== 'hidden'; @endphp
                    @if($hasExtras)
                    <details {{ old('occasion') || old('allergies') || old('note') ? 'open' : '' }} class="group">
                        <summary class="flex cursor-pointer list-none items-center gap-2 text-sm font-semibold text-stone-500 hover:text-stone-700">
                            <svg class="h-4 w-4 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            Besondere Wünsche?
                        </summary>
                        <div class="mt-3 space-y-4">
                            @if($settings->fieldRule('occasion') !== 'hidden')
                            <div>
                                <label for="occasion" class="mb-1.5 block text-sm font-semibold">Anlass</label>
                                <select name="occasion" id="occasion" class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                                    <option value="">–</option>
                                    @foreach(['Geburtstag','Jahrestag','Geschäftsessen','Familienfeier','Date','Sonstiges'] as $occ)
                                        <option @selected(old('occasion') === $occ)>{{ $occ }}</option>
                                    @endforeach
                                </select>
                            </div>
                            @endif
                            @if($settings->fieldRule('allergies') !== 'hidden')
                            <div>
                                <label for="allergies" class="mb-1.5 block text-sm font-semibold">Allergien / Unverträglichkeiten</label>
                                <input type="text" name="allergies" id="allergies" value="{{ old('allergies') }}"
                                       placeholder="z. B. Laktose, Nüsse…"
                                       class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                            </div>
                            @endif
                            @if($settings->fieldRule('note') !== 'hidden')
                            <div>
                                <label for="note" class="mb-1.5 block text-sm font-semibold">Anmerkung</label>
                                <textarea name="note" id="note" rows="2" placeholder="Was sollen wir wissen?"
                                          class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('note') }}</textarea>
                            </div>
                            @endif
                        </div>
                    </details>
                    @endif

                    <div class="space-y-3 border-t border-stone-100 pt-4 text-sm">
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" name="privacy_accepted" value="1" required
                                   class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                            <span class="text-stone-600">Ich habe die @if($tenant->privacy_url)<a href="{{ $tenant->privacy_url }}" target="_blank" rel="noopener" class="text-brand underline">Datenschutzhinweise</a>@else Datenschutzhinweise @endif gelesen und akzeptiere die Verarbeitung meiner Daten. *</span>
                        </label>
                        <label class="flex cursor-pointer items-start gap-3">
                            <input type="checkbox" name="newsletter" value="1"
                                   class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                            <span class="text-stone-400">Newsletter mit Angeboten erhalten (jederzeit widerrufbar).</span>
                        </label>
                    </div>

                    <button type="submit"
                            class="btn-brand flex w-full items-center justify-center gap-2 rounded-xl py-4 text-base font-bold text-white transition-all active:scale-[0.99]">
                        Jetzt reservieren
                        <svg class="h-5 w-5 opacity-80" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                    </button>
                </div>
            </div>

        </div>{{-- /divide-y --}}
    </form>

    @if(session('alternatives'))
    <div class="mx-5 mb-5 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900 sm:mx-6">
        <p class="font-semibold">Dieser Zeitpunkt ist leider nicht mehr verfügbar.</p>
        @if(!empty(session('alternatives')['same_day']))
            <p class="mt-1">Alternative Zeiten: {{ implode(' · ', session('alternatives')['same_day']) }}</p>
        @endif
    </div>
    @endif

    <script>
    (function () {
        const slotsUrl      = @json(route('booking.slots', [$tenant->slug, $location->slug]));
        const floorplanUrl  = @json(route('booking.floorplan', [$tenant->slug, $location->slug]));
        const partyInput    = document.getElementById('partySize');
        const dateInput     = document.getElementById('date');
        const timeInput     = document.getElementById('timeInput');
        const slotContainer = document.getElementById('slotContainer');
        const altBox        = document.getElementById('alternatives');
        const sp1Summary    = document.getElementById('sp1Summary');
        const sp2Summary    = document.getElementById('sp2Summary');
        const fpSection     = document.getElementById('floorplanSection');
        const fpCanvas      = document.getElementById('floorplanCanvas');
        const roomTabsEl    = document.getElementById('roomTabs');
        const tableIdInput  = document.getElementById('tableId');
        const zoneStage     = document.getElementById('zoneStage');
        const planStage     = document.getElementById('planStage');
        const zoneCards     = document.getElementById('zoneCards');
        const zoneBackRow   = document.getElementById('zoneBackRow');
        const zoneActiveLabel = document.getElementById('zoneActiveLabel');
        let fpRooms = [];
        let activeZoneFilter = null; // null = no filter, zone object = filtered

        function pointInPolygon(px, py, points) {
            let inside = false;
            for (let i = 0, j = points.length - 1; i < points.length; j = i++) {
                const [xi, yi] = points[i], [xj, yj] = points[j];
                if (((yi > py) !== (yj > py)) && (px < (xj - xi) * (py - yi) / (yj - yi) + xi)) inside = !inside;
            }
            return inside;
        }

        function tablesInZone(tables, zone) {
            return tables.filter(t => {
                const cx = t.pos_x + t.width / 2, cy = t.pos_y + t.height / 2;
                return pointInPolygon(cx, cy, zone.points);
            });
        }

        function stepState(id, state) {
            const el = document.getElementById(id);
            el.dataset.state = state;
            if (state === 'active') {
                const body = el.querySelector('.sp-body');
                if (body) { body.classList.remove('reveal-up'); void body.offsetHeight; body.classList.add('reveal-up'); }
                setTimeout(() => el.scrollIntoView({behavior: 'smooth', block: 'nearest'}), 60);
            }
        }

        function editStep(n) {
            stepState('sp' + n, 'active');
            for (let i = n + 1; i <= 3; i++) stepState('sp' + i, 'locked');
            if (n <= 2) { timeInput.value = ''; resetFp(); }
        }
        window.editStep = editStep;

        function fmtDate(d) {
            const dt = new Date(d + 'T00:00:00');
            return isNaN(dt) ? d : dt.toLocaleDateString('de-DE', {weekday: 'short', day: '2-digit', month: '2-digit'});
        }

        function selectParty(btn) {
            document.querySelectorAll('.party-btn').forEach(b => {
                b.classList.remove('border-brand', 'bg-brand', 'text-white');
                b.querySelectorAll('span').forEach(s => { s.classList.remove('text-white'); s.classList.add('text-stone-400'); });
            });
            btn.classList.add('border-brand', 'bg-brand', 'text-white');
            btn.querySelectorAll('span').forEach(s => { s.classList.remove('text-stone-400'); s.classList.add('text-white'); });
            partyInput.value = btn.dataset.party;
            sp1Summary.textContent = btn.dataset.party + (btn.dataset.party === '1' ? ' Person' : ' Personen');
            stepState('sp1', 'done');
            stepState('sp2', 'active');
            stepState('sp3', 'locked');
            loadSlots();
        }
        document.querySelectorAll('.party-btn').forEach(btn => btn.addEventListener('click', () => selectParty(btn)));
        dateInput.addEventListener('change', loadSlots);

        function resetFp() {
            if (!fpSection) return;
            fpSection.classList.add('hidden');
            if (tableIdInput) tableIdInput.value = '';
            fpRooms = [];
            activeZoneFilter = null;
            if (zoneStage) { zoneStage.classList.add('hidden'); zoneStage.classList.remove('block'); }
            if (planStage) planStage.classList.remove('hidden');
            if (zoneBackRow) zoneBackRow.classList.add('hidden');
        }

        function makeSlotBtn(time) {
            const btn = document.createElement('button');
            btn.type = 'button'; btn.textContent = time; btn.dataset.time = time;
            btn.className = 'slot-btn rounded-xl border-2 border-stone-200 py-3 text-sm font-bold tracking-wide transition-all hover:border-brand hover:bg-brand/5 hover:shadow-sm active:scale-[0.97]';
            btn.addEventListener('click', () => {
                document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('border-brand', 'bg-brand', 'text-white'));
                btn.classList.add('border-brand', 'bg-brand', 'text-white');
                timeInput.value = time;
                sp2Summary.textContent = fmtDate(dateInput.value) + ' · ' + time + ' Uhr';
                stepState('sp2', 'done');
                stepState('sp3', 'active');
                loadFp();
            });
            return btn;
        }

        function renderSlots(slots) {
            slotContainer.innerHTML = '';
            const groups = [
                { label: 'Vormittag',  test: h => h < 12 },
                { label: 'Mittag',     test: h => h >= 12 && h < 14 },
                { label: 'Nachmittag', test: h => h >= 14 && h < 18 },
                { label: 'Abend',      test: h => h >= 18 },
            ];
            let any = false;
            groups.forEach(g => {
                const times = slots.filter(t => g.test(parseInt(t.split(':')[0], 10)));
                if (!times.length) return;
                const lbl = document.createElement('p');
                lbl.className = 'slot-group-label'; lbl.textContent = g.label;
                slotContainer.appendChild(lbl);
                times.forEach(t => slotContainer.appendChild(makeSlotBtn(t)));
                any = true;
            });
            if (!any) slots.forEach(t => slotContainer.appendChild(makeSlotBtn(t)));
        }

        async function pickNextSlot(date, time) {
            dateInput.value = date;
            await loadSlots();
            const btn = slotContainer.querySelector('.slot-btn[data-time="' + time + '"]');
            if (btn) btn.click(); else { timeInput.value = time; loadFp(); }
        }

        async function loadSlots() {
            if (!partyInput.value || !dateInput.value) return;
            slotContainer.innerHTML = '<p class="col-span-full animate-pulse text-sm text-stone-400">Verfügbare Zeiten werden geladen…</p>';
            altBox.classList.add('hidden'); resetFp();
            try {
                const res = await fetch(slotsUrl + '?date=' + dateInput.value + '&party_size=' + partyInput.value, {headers: {Accept: 'application/json'}});
                const data = await res.json();
                slotContainer.innerHTML = '';
                if (!data.slots || !data.slots.length) {
                    if (data.oversized) {
                        slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-stone-100 px-3 py-2.5 text-sm text-stone-600">Für Gruppen ab ' + (parseInt(data.max_party) + 1) + ' Personen nehmen wir Reservierungen gerne direkt entgegen.</p>';
                        altBox.innerHTML = 'Kontaktieren Sie uns, wir finden eine Lösung für Sie' + (data.phone ? ': <a class="font-semibold underline" href="tel:' + data.phone.replace(/\s/g, '') + '">' + data.phone + '</a>' : '.');
                        altBox.classList.remove('hidden'); return;
                    }
                    const head = document.createElement('p');
                    head.className = 'col-span-full rounded-xl bg-amber-50 px-3 py-2.5 text-sm font-medium text-amber-800';
                    head.textContent = 'An diesem Tag ist für ' + partyInput.value + ' Personen leider kein Tisch mehr frei – aber kein Problem:';
                    slotContainer.appendChild(head);
                    if (data.next_slots && data.next_slots.length) {
                        const sub = document.createElement('p');
                        sub.className = 'col-span-full mt-2 text-xs font-semibold uppercase tracking-wide text-stone-500';
                        sub.textContent = 'So klappt es – nächste freie Termine:';
                        slotContainer.appendChild(sub);
                        data.next_slots.forEach(s => {
                            const b = document.createElement('button');
                            b.type = 'button';
                            b.className = 'rounded-xl border-2 border-stone-200 px-2 py-2.5 text-center transition-all hover:border-brand hover:bg-brand/5 active:scale-[0.97]';
                            b.innerHTML = '<span class="block text-[10px] font-semibold uppercase tracking-wide text-stone-400">' + fmtDate(s.date) + '</span><span class="block text-base font-bold">' + s.time + '</span>';
                            b.addEventListener('click', () => pickNextSlot(s.date, s.time));
                            slotContainer.appendChild(b);
                        });
                    }
                    if (data.waitlist_available) { altBox.innerHTML = 'Kein Termin passend? Tragen Sie sich auf die <strong>Warteliste</strong> ein – wir melden uns, sobald etwas frei wird.'; altBox.classList.remove('hidden'); }
                    return;
                }
                renderSlots(data.slots);
            } catch (e) {
                slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-stone-50 px-3 py-2.5 text-sm text-stone-500">Kurze Unterbrechung – bitte Seite neu laden.</p>';
            }
        }

        async function loadFp() {
            if (!fpSection || !timeInput.value) return;
            tableIdInput.value = '';
            activeZoneFilter = null;
            try {
                const res = await fetch(floorplanUrl + '?date=' + dateInput.value + '&time=' + timeInput.value + '&party_size=' + partyInput.value, {headers: {Accept: 'application/json'}});
                const data = await res.json();
                fpRooms = data.rooms || [];
                if (!fpRooms.length) { fpSection.classList.add('hidden'); return; }
                fpSection.classList.remove('hidden'); fpSection.classList.remove('reveal-up'); void fpSection.offsetHeight; fpSection.classList.add('reveal-up');
                buildRoomTabs();
                // Check if any room has zones → show zone stage first
                const allZones = fpRooms.flatMap(r => r.zones || []);
                if (allZones.length > 0) {
                    buildZoneCards(fpRooms[0], 0);
                } else {
                    if (zoneStage) zoneStage.classList.add('hidden');
                    if (planStage) planStage.classList.remove('hidden');
                    renderRoom(0);
                }
            } catch (e) { fpSection.classList.add('hidden'); }
        }

        function buildZoneCards(room, roomIdx) {
            if (!zoneStage || !zoneCards) return;
            if (!room.zones || !room.zones.length) {
                zoneStage.classList.add('hidden');
                if (planStage) planStage.classList.remove('hidden');
                renderRoom(roomIdx); return;
            }
            zoneCards.innerHTML = '';
            room.zones.forEach(zone => {
                const inZone = tablesInZone(room.tables, zone);
                const avail  = inZone.filter(t => t.status === 'available').length;
                const card = document.createElement('button');
                card.type = 'button';
                card.className = 'zone-card text-left rounded-xl border-2 border-stone-200 bg-white overflow-hidden transition-all hover:border-stone-400 hover:shadow-sm active:scale-[0.98]';
                card.innerHTML = `<div style="height:6px;background:${zone.color}"></div>
                    <div class="p-3">
                        <p class="font-bold text-sm text-stone-800">${zone.name}</p>
                        <p class="text-xs text-stone-400 mt-0.5">${inZone.length} Tische · ${avail} frei</p>
                        <p class="mt-2 text-xs font-semibold text-brand">Auswählen →</p>
                    </div>`;
                card.addEventListener('click', () => selectZone(zone, roomIdx));
                zoneCards.appendChild(card);
            });
            // "Alle Bereiche" card
            const allCard = document.createElement('button');
            allCard.type = 'button';
            allCard.className = 'zone-card text-left rounded-xl border-2 border-stone-200 bg-stone-50 overflow-hidden transition-all hover:border-stone-400 hover:shadow-sm active:scale-[0.98]';
            allCard.innerHTML = `<div style="height:6px;background:#e7e5e4"></div>
                <div class="p-3">
                    <p class="font-bold text-sm text-stone-700">Alle Bereiche</p>
                    <p class="text-xs text-stone-400 mt-0.5">Gesamten Plan anzeigen</p>
                    <p class="mt-2 text-xs font-semibold text-stone-500">Öffnen →</p>
                </div>`;
            allCard.addEventListener('click', () => selectZone(null, roomIdx));
            zoneCards.appendChild(allCard);
            zoneStage.classList.remove('hidden');
            if (planStage) planStage.classList.add('hidden');
        }

        function selectZone(zone, roomIdx) {
            activeZoneFilter = zone;
            if (zoneStage) zoneStage.classList.add('hidden');
            if (planStage) { planStage.classList.remove('hidden'); }
            if (zoneBackRow) zoneBackRow.classList.remove('hidden');
            if (zoneActiveLabel) zoneActiveLabel.textContent = zone ? zone.name : '';
            renderRoom(roomIdx);
        }

        document.getElementById('zoneBackBtn')?.addEventListener('click', () => {
            activeZoneFilter = null;
            if (tableIdInput) tableIdInput.value = '';
            if (zoneBackRow) zoneBackRow.classList.add('hidden');
            const roomIdx = parseInt(document.querySelector('.room-tab.border-brand')?.dataset?.idx || '0', 10);
            buildZoneCards(fpRooms[roomIdx] || fpRooms[0], roomIdx);
        });

        function buildRoomTabs() {
            roomTabsEl.innerHTML = '';
            fpRooms.forEach((room, i) => {
                const b = document.createElement('button'); b.type = 'button';
                b.textContent = (room.is_outdoor ? '☀ ' : '') + room.name;
                b.dataset.idx = i;
                b.className = 'room-tab rounded-full border-2 px-3 py-1.5 text-sm font-semibold transition-all ' + (i === 0 ? 'border-brand bg-stone-50' : 'border-stone-200 hover:border-brand hover:bg-brand/5');
                b.addEventListener('click', () => {
                    document.querySelectorAll('.room-tab').forEach(t => t.classList.remove('border-brand','bg-brand','text-white'));
                    b.classList.add('border-brand','bg-brand','text-white');
                    activeZoneFilter = null;
                    if (zoneBackRow) zoneBackRow.classList.add('hidden');
                    const zones = room.zones || [];
                    if (zones.length > 0) { buildZoneCards(room, i); } else { renderRoom(i); }
                });
                roomTabsEl.appendChild(b);
            });
            roomTabsEl.classList.toggle('hidden', fpRooms.length < 2);
        }

        function renderRoom(idx) {
            const room = fpRooms[idx]; if (!room) return;
            fpCanvas.innerHTML = '';
            const pad = 20; let maxX = 0, maxY = 0;
            room.tables.forEach(t => { maxX = Math.max(maxX, t.pos_x + t.width); maxY = Math.max(maxY, t.pos_y + t.height); });
            const cw = fpCanvas.clientWidth || 600, ch = fpCanvas.clientHeight || 280;
            const scale = Math.min((cw - pad * 2) / Math.max(maxX, 1), (ch - pad * 2) / Math.max(maxY, 1), 1);
            const colors = { available: '#34d399', occupied: '#d6d3d1', unsuitable: '#fde68a', unavailable: '#e7e5e4' };

            // Zone highlight overlay (SVG)
            const zones = room.zones || [];
            if (zones.length > 0) {
                const ns = 'http://www.w3.org/2000/svg';
                const svg = document.createElementNS(ns, 'svg');
                svg.setAttribute('xmlns', ns);
                Object.assign(svg.style, { position: 'absolute', inset: '0', width: '100%', height: '100%', pointerEvents: 'none' });
                zones.forEach(z => {
                    if (!z.points || z.points.length < 3) return;
                    const isActive = activeZoneFilter && activeZoneFilter.id === z.id;
                    const isOther  = activeZoneFilter && activeZoneFilter.id !== z.id;
                    const pts = z.points.map(([x, y]) => `${pad + x * scale},${pad + y * scale}`).join(' ');
                    const poly = document.createElementNS(ns, 'polygon');
                    poly.setAttribute('points', pts);
                    poly.setAttribute('fill', z.color);
                    poly.setAttribute('fill-opacity', isActive ? 0.18 : (isOther ? 0.04 : z.opacity / 100));
                    poly.setAttribute('stroke', z.color);
                    poly.setAttribute('stroke-width', isActive ? '2' : '1');
                    poly.setAttribute('stroke-opacity', isOther ? '0.3' : '0.7');
                    svg.appendChild(poly);
                });
                fpCanvas.appendChild(svg);
            }

            room.tables.forEach(t => {
                const inActiveZone = activeZoneFilter
                    ? pointInPolygon(t.pos_x + t.width / 2, t.pos_y + t.height / 2, activeZoneFilter.points)
                    : true;
                const el = document.createElement('button'); el.type = 'button';
                el.title = 'Tisch ' + t.name + ' · ' + t.capacity + ' Pers.'; el.dataset.tableId = t.id;
                const selectable = t.selectable && inActiveZone;
                Object.assign(el.style, {
                    position: 'absolute',
                    left: (pad + t.pos_x * scale) + 'px', top: (pad + t.pos_y * scale) + 'px',
                    width: Math.max(28, t.width * scale) + 'px', height: Math.max(28, t.height * scale) + 'px',
                    background: colors[t.status] || '#d6d3d1',
                    borderRadius: t.shape === 'round' ? '50%' : '8px',
                    border: '2px solid rgba(0,0,0,.12)',
                    fontSize: '11px', fontWeight: '600', color: '#1c1917',
                    transform: t.rotation ? 'rotate(' + t.rotation + 'deg)' : '',
                    opacity: (activeZoneFilter && !inActiveZone) ? '0.3' : '1',
                });
                el.textContent = t.name;
                if (selectable) {
                    el.style.cursor = 'pointer';
                    el.addEventListener('click', () => {
                        const was = tableIdInput.value === String(t.id);
                        fpCanvas.querySelectorAll('button').forEach(b => b.style.outline = '');
                        tableIdInput.value = was ? '' : t.id;
                        if (!was) { el.style.outline = '3px solid var(--brand)'; el.style.outlineOffset = '2px'; }
                    });
                } else {
                    el.disabled = true;
                    el.style.cursor = (activeZoneFilter && !inActiveZone) ? 'default' : 'not-allowed';
                }
                fpCanvas.appendChild(el);
            });
        }

        // Restore state from old() values (form resubmission with errors)
        const oldParty = @json(old('party_size'));
        const oldTime  = @json(old('time'));
        if (oldParty) {
            const def = document.querySelector('.party-btn[data-party="' + oldParty + '"]');
            if (def) {
                def.classList.add('border-brand', 'bg-brand', 'text-white');
                def.querySelectorAll('span').forEach(s => { s.classList.remove('text-stone-400'); s.classList.add('text-white'); });
                partyInput.value = oldParty;
                sp1Summary.textContent = oldParty + (oldParty === '1' ? ' Person' : ' Personen');
                document.getElementById('sp1').dataset.state = 'done';
                document.getElementById('sp2').dataset.state = 'active';
                loadSlots().then(() => {
                    if (oldTime) {
                        const btn = slotContainer.querySelector('.slot-btn[data-time="' + oldTime + '"]');
                        if (btn) {
                            btn.classList.add('border-brand', 'bg-brand', 'text-white');
                            timeInput.value = oldTime;
                            sp2Summary.textContent = fmtDate(dateInput.value) + ' · ' + oldTime + ' Uhr';
                            document.getElementById('sp2').dataset.state = 'done';
                            document.getElementById('sp3').dataset.state = 'active';
                        }
                    }
                });
            }
        }
    })();
    </script>
    @endif

</div>

{{-- Kontakt & Anfahrt --}}
@if($location->address_line1 || $location->city || $location->phone || $location->email)
<div class="mt-4 overflow-hidden rounded-3xl bg-white shadow-xl shadow-stone-400/15 ring-1 ring-black/5">
    <div class="p-5 sm:p-6">
        <p class="mb-4 text-xs font-bold uppercase tracking-widest text-stone-400">Kontakt &amp; Anfahrt</p>
        <div class="grid gap-3 text-sm sm:grid-cols-3">
            @if($location->address_line1 || $location->city)
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-stone-100 text-sm">📍</span>
                    <span class="pt-1 text-stone-700">{{ $location->address_line1 }}@if($location->address_line1 && ($location->postal_code || $location->city))<br>@endif{{ trim(($location->postal_code ? $location->postal_code.' ' : '').$location->city) }}</span>
                </div>
            @endif
            @if($location->phone)
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-stone-100 text-sm">📞</span>
                    <a href="tel:{{ preg_replace('/\s+/', '', $location->phone) }}" class="pt-1 font-semibold text-brand hover:underline">{{ $location->phone }}</a>
                </div>
            @endif
            @if($location->email)
                <div class="flex items-start gap-3">
                    <span class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-stone-100 text-sm">✉️</span>
                    <a href="mailto:{{ $location->email }}" class="break-all pt-1 font-semibold text-brand hover:underline">{{ $location->email }}</a>
                </div>
            @endif
        </div>
    </div>
</div>
@endif
@endsection
