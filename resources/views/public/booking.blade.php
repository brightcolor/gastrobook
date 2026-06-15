@extends('layouts.public')
@section('title', ($tenant->isSalon() ? 'Termin buchen' : 'Tisch reservieren') . ' – ' . $location->name)
@section('content')
<div class="overflow-hidden rounded-3xl bg-white shadow-2xl shadow-stone-500/20 ring-1 ring-black/5">

    {{-- ══ HERO HEADER ══════════════════════════════════════════════════════ --}}
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

    {{-- ══ FORMULAR ════════════════════════════════════════════════════════ --}}
    <div class="p-6 sm:p-8">

    @if($errors->any())
        <div class="mb-6 rounded-xl bg-red-50 p-4 text-sm text-red-800">
            <p class="mb-1 font-semibold">Bitte korrigieren Sie folgende Angaben:</p>
            <ul class="list-inside list-disc space-y-0.5">
                @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    @if($tenant->isSalon())
    {{-- ══════════════════════════════════════════════════════════════════════
         SALON-FORMULAR
    ══════════════════════════════════════════════════════════════════════ --}}
        @if(($services ?? collect())->isEmpty())
            <div class="rounded-xl bg-amber-50 p-4 text-center text-sm text-amber-800">
                Es sind noch keine Leistungen konfiguriert. Bitte kontaktieren Sie uns direkt.
            </div>
        @else
        <form method="POST" action="{{ route('booking.store', [$tenant->slug, $location->slug]) }}"
              class="space-y-7" id="salonBookingForm">
            @csrf
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

            {{-- Step 1: Leistungen --}}
            <div>
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="step-badge">1</span>
                    <span class="text-sm font-semibold">Leistungen wählen <span class="font-normal text-stone-400">(mehrere kombinierbar)</span></span>
                </div>
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
            </div>

            {{-- Step 2: Mitarbeiter --}}
            <div id="staffSection" class="hidden">
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="step-badge">2</span>
                    <span class="text-sm font-semibold">Mitarbeiter:in <span class="font-normal text-stone-400">(optional)</span></span>
                </div>
                <div id="staffButtons" class="flex flex-wrap gap-2"></div>
                <input type="hidden" name="staff_member_id" id="staffMemberId" value="0">
                <p id="noStaffHint" class="mt-2 hidden rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700">Keine:r Ihrer gewählten Leistungen kann von einer einzelnen Person zusammen ausgeführt werden.</p>
            </div>

            {{-- Step 3: Datum --}}
            <div id="dateSection" class="hidden">
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="step-badge">3</span>
                    <label for="date" class="text-sm font-semibold">Datum wählen</label>
                </div>
                <input type="date" name="date" id="date"
                       min="{{ now($location->timezone)->toDateString() }}"
                       max="{{ now($location->timezone)->addDays($settings->max_advance_days)->toDateString() }}"
                       value="{{ old('date', now($location->timezone)->toDateString()) }}"
                       class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3 text-base">
            </div>

            {{-- Step 4: Uhrzeit --}}
            <div id="slotSection" class="hidden">
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="step-badge">4</span>
                    <span class="text-sm font-semibold">Uhrzeit wählen</span>
                </div>
                <div id="slotContainer" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <p class="col-span-full text-sm text-stone-400">Bitte Leistung und Datum wählen.</p>
                </div>
                <input type="hidden" name="time" id="timeInput" value="{{ old('time') }}" required>
            </div>

            {{-- Booking-Summary-Strip --}}
            <div id="bookingSummary" class="hidden reveal-up rounded-2xl bg-stone-50 p-4 ring-1 ring-stone-200">
                <p class="mb-1 text-xs font-bold uppercase tracking-wider text-stone-400">Ihre Buchung</p>
                <p class="font-semibold text-stone-800" id="summaryText"></p>
            </div>

            {{-- Step 5: Kontaktdaten --}}
            <div class="space-y-4 border-t border-stone-100 pt-6">
                <div class="flex items-center gap-2.5">
                    <span class="step-badge">5</span>
                    <span class="text-sm font-semibold">Ihre Daten</span>
                </div>
                <div>
                    <label for="name" class="mb-1.5 block text-sm font-semibold">Name *</label>
                    <input type="text" name="name" id="name" required value="{{ old('name') }}" autocomplete="name"
                           class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                    @error('name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="email" class="mb-1.5 block text-sm font-semibold">E-Mail *</label>
                    <input type="email" name="email" id="email" required value="{{ old('email') }}" autocomplete="email"
                           class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                    @error('email')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="phone" class="mb-1.5 block text-sm font-semibold">Telefon <span class="font-normal text-stone-400">(optional)</span></label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone') }}" autocomplete="tel"
                           class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                    @error('phone')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
                @if($settings->fieldRule('note') !== 'hidden')
                <div>
                    <label for="note" class="mb-1.5 block text-sm font-semibold">Anmerkung <span class="font-normal text-stone-400">(optional)</span></label>
                    <textarea name="note" id="note" rows="2"
                              class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('note') }}</textarea>
                </div>
                @endif
            </div>

            {{-- Datenschutz --}}
            <div class="space-y-3 border-t border-stone-100 pt-5 text-sm">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" name="privacy_accepted" value="1" required
                           class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                    <span class="text-stone-700">Ich habe die @if($tenant->privacy_url)<a href="{{ $tenant->privacy_url }}" target="_blank" rel="noopener" class="text-brand underline">Datenschutzhinweise</a>@else Datenschutzhinweise @endif gelesen und akzeptiere die Verarbeitung meiner Daten. *</span>
                </label>
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" name="newsletter" value="1"
                           class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                    <span class="text-stone-500">Newsletter mit Angeboten erhalten (jederzeit widerrufbar).</span>
                </label>
            </div>

            <button type="submit"
                    class="btn-brand flex w-full items-center justify-center gap-2 rounded-xl py-4 text-lg font-bold text-white transition-all active:scale-[0.99]">
                Termin buchen
                <svg class="h-5 w-5 opacity-80" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </button>
        </form>

        @if(session('alternatives'))
        <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Dieser Zeitpunkt ist leider nicht verfügbar.</p>
        </div>
        @endif

        <script>
        (function () {
            const slotsUrl = @json(route('booking.slots', [$tenant->slug, $location->slug]));
            const serviceData = @json($services->mapWithKeys(fn($s) => [$s->id => ['staff' => $s->staff->where('is_active', true)->map(fn($m) => ['id' => $m->id, 'name' => $m->name])->values()]])->all());

            const staffInput    = document.getElementById('staffMemberId');
            const dateInput     = document.getElementById('date');
            const timeInput     = document.getElementById('timeInput');
            const slotContainer = document.getElementById('slotContainer');
            const staffSection  = document.getElementById('staffSection');
            const dateSection   = document.getElementById('dateSection');
            const slotSection   = document.getElementById('slotSection');
            const staffButtons  = document.getElementById('staffButtons');
            const serviceInputs = document.getElementById('serviceInputs');
            const summary       = document.getElementById('serviceSummary');
            const summaryDur    = document.getElementById('summaryDuration');
            const summaryPrc    = document.getElementById('summaryPrice');
            const noStaffHint   = document.getElementById('noStaffHint');
            const bookingSummary = document.getElementById('bookingSummary');
            const summaryText   = document.getElementById('summaryText');

            const selected = [];

            function reveal(el) {
                el.classList.remove('hidden');
                el.classList.remove('reveal-up');
                void el.offsetHeight;
                el.classList.add('reveal-up');
            }
            function hide(el) { el.classList.add('hidden'); }

            function fmtDuration(min) {
                const h = Math.floor(min / 60), m = min % 60;
                return h === 0 ? m + ' Min.' : (m === 0 ? h + ' Std.' : h + ' Std. ' + m + ' Min.');
            }

            function updateBookingSummary() {
                if (!timeInput.value || !dateInput.value || selected.length === 0) {
                    hide(bookingSummary); return;
                }
                const d = new Date(dateInput.value + 'T00:00:00');
                const dateStr = isNaN(d) ? dateInput.value : d.toLocaleDateString('de-DE', {weekday: 'short', day: '2-digit', month: '2-digit'});
                summaryText.textContent = selected.map(id => {
                    const p = document.querySelector('.service-pill[data-service-id="' + id + '"]');
                    return p ? p.textContent.trim().split('·')[0].trim() : id;
                }).join(', ') + ' · ' + dateStr + ' · ' + timeInput.value + ' Uhr';
                reveal(bookingSummary);
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

            function eligibleStaff() {
                if (selected.length === 0) return [];
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
                if (selected.length === 0) {
                    hide(summary); hide(staffSection); hide(dateSection); hide(slotSection); hide(bookingSummary); return;
                }
                summaryDur.textContent = fmtDuration(dur);
                summaryPrc.textContent = price > 0 ? ' · ' + (price / 100).toFixed(2).replace('.', ',') + ' €' : '';
                reveal(summary);
                const staff = eligibleStaff();
                staffButtons.innerHTML = '<button type="button" data-staff-id="0" class="staff-btn rounded-xl border-2 border-brand bg-stone-50 px-4 py-2 text-sm font-semibold transition-all">Beliebig</button>';
                staff.forEach(m => {
                    const b = document.createElement('button');
                    b.type = 'button'; b.dataset.staffId = m.id;
                    b.className = 'staff-btn rounded-xl border-2 border-stone-200 px-4 py-2 text-sm font-semibold transition-all hover:border-brand hover:bg-brand/5';
                    b.textContent = m.name;
                    staffButtons.appendChild(b);
                });
                staffButtons.querySelectorAll('.staff-btn').forEach(b => b.addEventListener('click', () => selectStaff(b)));
                staffInput.value = 0;
                noStaffHint.classList.toggle('hidden', staff.length > 0);
                reveal(staffSection); reveal(dateSection); reveal(slotSection);
                loadSlots();
            }

            function selectStaff(btn) {
                document.querySelectorAll('.staff-btn').forEach(b => b.classList.remove('border-brand', 'bg-brand', 'text-white'));
                btn.classList.add('border-brand', 'bg-brand', 'text-white');
                staffInput.value = btn.dataset.staffId;
                loadSlots();
            }

            function renderSlots(slots) {
                slotContainer.innerHTML = '';
                const groups = [
                    { label: 'Vormittag', test: h => h < 12 },
                    { label: 'Mittag',    test: h => h >= 12 && h < 14 },
                    { label: 'Nachmittag',test: h => h >= 14 && h < 18 },
                    { label: 'Abend',     test: h => h >= 18 },
                ];
                groups.forEach(g => {
                    const times = slots.filter(t => g.test(parseInt(t.split(':')[0], 10)));
                    if (times.length === 0) return;
                    const lbl = document.createElement('p');
                    lbl.className = 'slot-group-label';
                    lbl.textContent = g.label;
                    slotContainer.appendChild(lbl);
                    times.forEach(time => slotContainer.appendChild(makeSlotBtn(time)));
                });
            }

            function makeSlotBtn(time) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = time;
                btn.className = 'slot-btn rounded-xl border-2 border-stone-200 py-3 text-sm font-bold tracking-wide transition-all hover:border-brand hover:bg-brand/5 hover:shadow-sm active:scale-[0.97]';
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('border-brand', 'bg-brand', 'text-white'));
                    btn.classList.add('border-brand', 'bg-brand', 'text-white');
                    timeInput.value = time;
                    updateBookingSummary();
                });
                return btn;
            }

            async function loadSlots() {
                if (selected.length === 0 || !dateInput.value) return;
                slotContainer.innerHTML = '<p class="col-span-full animate-pulse text-sm text-stone-400">Lade Termine…</p>';
                timeInput.value = ''; hide(bookingSummary);
                try {
                    const params = new URLSearchParams();
                    params.set('date', dateInput.value);
                    params.set('staff_member_id', staffInput.value || 0);
                    selected.forEach(id => params.append('service_ids[]', id));
                    const res = await fetch(slotsUrl + '?' + params.toString(), {headers: {Accept: 'application/json'}});
                    const data = await res.json();
                    slotContainer.innerHTML = '';
                    if (!data.slots || data.slots.length === 0) {
                        slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-red-50 px-3 py-2.5 text-sm font-medium text-red-700">An diesem Tag sind leider keine Termine verfügbar.</p>';
                        return;
                    }
                    renderSlots(data.slots);
                } catch (e) {
                    slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-700">Fehler beim Laden. Bitte erneut versuchen.</p>';
                }
            }

            document.querySelectorAll('.service-pill').forEach(pill => pill.addEventListener('click', () => toggleService(pill)));
            dateInput.addEventListener('change', () => { loadSlots(); updateBookingSummary(); });
        })();
        </script>
        @endif

    @else
    {{-- ══════════════════════════════════════════════════════════════════════
         RESTAURANT-FORMULAR
    ══════════════════════════════════════════════════════════════════════ --}}
        <form method="POST" action="{{ route('booking.store', [$tenant->slug, $location->slug]) }}"
              class="space-y-7" id="bookingForm">
            @csrf
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

            {{-- Step 1: Personenzahl --}}
            <div>
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="step-badge">1</span>
                    <label class="text-sm font-semibold">Wie viele Personen?</label>
                </div>
                <div class="grid grid-cols-4 gap-2 sm:grid-cols-5" id="partyButtons">
                    @for($i = $settings->min_party_online; $i <= min($settings->max_party_online, $settings->min_party_online + 8); $i++)
                        <button type="button" data-party="{{ $i }}"
                                class="party-btn flex flex-col items-center justify-center rounded-2xl border-2 border-stone-200 py-4 transition-all duration-150 hover:border-brand hover:bg-brand/5 hover:shadow-md hover:shadow-brand/10 active:scale-95">
                            <span class="text-2xl font-black leading-none">{{ $i }}</span>
                            <span class="mt-0.5 text-[9px] font-bold uppercase tracking-widest text-stone-400">{{ $i === 1 ? 'Person' : 'Pers.' }}</span>
                        </button>
                    @endfor
                </div>
                <input type="hidden" name="party_size" id="partySize" value="{{ old('party_size', 2) }}" required>
                @error('party_size')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            {{-- Step 2: Datum --}}
            <div>
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="step-badge">2</span>
                    <label for="date" class="text-sm font-semibold">Datum wählen</label>
                </div>
                <input type="date" name="date" id="date" required
                       min="{{ now($location->timezone)->toDateString() }}"
                       max="{{ now($location->timezone)->addDays($settings->max_advance_days)->toDateString() }}"
                       value="{{ old('date', now($location->timezone)->toDateString()) }}"
                       class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3 text-base">
                @error('date')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            {{-- Step 3: Uhrzeit --}}
            <div>
                <div class="mb-3 flex items-center gap-2.5">
                    <span class="step-badge">3</span>
                    <label class="text-sm font-semibold">Uhrzeit wählen</label>
                </div>
                <div id="slotContainer" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <p class="col-span-full text-sm text-stone-400">Bitte Personenzahl und Datum wählen.</p>
                </div>
                <input type="hidden" name="time" id="timeInput" value="{{ old('time') }}" required>
                <div id="alternatives" class="mt-3 hidden rounded-xl bg-amber-50 p-3 text-sm text-amber-900"></div>
                @error('time')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            @if($settings->public_floorplan_enabled)
            {{-- Tischplan --}}
            <div id="floorplanSection" class="hidden">
                <div class="mb-3 flex items-center justify-between">
                    <div class="flex items-center gap-2.5">
                        <span class="step-badge">✦</span>
                        <label class="text-sm font-semibold">Tisch wählen <span class="font-normal text-stone-400">(optional)</span></label>
                    </div>
                    <span class="flex items-center gap-3 text-xs text-stone-400">
                        <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm" style="background:#34d399"></span> frei</span>
                        <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm" style="background:#d6d3d1"></span> belegt</span>
                    </span>
                </div>
                <div id="roomTabs" class="mb-2 flex flex-wrap gap-2"></div>
                <div id="floorplanCanvas" class="relative w-full overflow-hidden rounded-xl border-2 border-stone-100 bg-stone-50" style="height:340px"></div>
                <p id="floorplanHint" class="mt-2 text-xs text-stone-500">Tippen Sie auf einen freien Tisch – oder lassen Sie die Auswahl frei für automatische Zuteilung.</p>
                <input type="hidden" name="table_id" id="tableId" value="">
            </div>
            @endif

            {{-- Booking-Summary-Strip --}}
            <div id="bookingSummary" class="hidden rounded-2xl ring-1" style="background:color-mix(in oklab,var(--brand) 6%,white);ring-color:color-mix(in oklab,var(--brand) 20%,transparent)">
                <div class="px-4 py-3.5">
                    <p class="mb-0.5 text-[10px] font-bold uppercase tracking-widest" style="color:var(--brand)">Ihre Auswahl</p>
                    <p class="font-semibold text-stone-800" id="summaryText"></p>
                </div>
            </div>

            {{-- Step 4: Kontaktdaten --}}
            <div class="space-y-4 border-t border-stone-100 pt-6">
                <div class="flex items-center gap-2.5">
                    <span class="step-badge">4</span>
                    <span class="text-sm font-semibold">Ihre Daten</span>
                </div>
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
                @if($settings->fieldRule('occasion') !== 'hidden')
                <div>
                    <label for="occasion" class="mb-1.5 block text-sm font-semibold">Anlass <span class="font-normal text-stone-400">(optional)</span></label>
                    <select name="occasion" id="occasion" class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                        <option value="">–</option>
                        @foreach(['Geburtstag', 'Jahrestag', 'Geschäftsessen', 'Familienfeier', 'Date', 'Sonstiges'] as $occ)
                            <option @selected(old('occasion') === $occ)>{{ $occ }}</option>
                        @endforeach
                    </select>
                </div>
                @endif
                @if($settings->fieldRule('allergies') !== 'hidden')
                <div>
                    <label for="allergies" class="mb-1.5 block text-sm font-semibold">Allergien / Unverträglichkeiten <span class="font-normal text-stone-400">(optional)</span></label>
                    <input type="text" name="allergies" id="allergies" value="{{ old('allergies') }}"
                           class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                </div>
                @endif
                @if($settings->fieldRule('note') !== 'hidden')
                <div>
                    <label for="note" class="mb-1.5 block text-sm font-semibold">Anmerkung <span class="font-normal text-stone-400">(optional)</span></label>
                    <textarea name="note" id="note" rows="2"
                              class="public-input w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('note') }}</textarea>
                </div>
                @endif
            </div>

            {{-- Datenschutz --}}
            <div class="space-y-3 border-t border-stone-100 pt-5 text-sm">
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" name="privacy_accepted" value="1" required
                           class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                    <span class="text-stone-700">Ich habe die @if($tenant->privacy_url)<a href="{{ $tenant->privacy_url }}" target="_blank" rel="noopener" class="text-brand underline">Datenschutzhinweise</a>@else Datenschutzhinweise @endif gelesen und akzeptiere die Verarbeitung meiner Daten. *</span>
                </label>
                <label class="flex cursor-pointer items-start gap-3">
                    <input type="checkbox" name="newsletter" value="1"
                           class="mt-0.5 h-4 w-4 flex-shrink-0 rounded accent-[var(--brand)]">
                    <span class="text-stone-500">Newsletter mit Angeboten erhalten (jederzeit widerrufbar).</span>
                </label>
            </div>

            <button type="submit"
                    class="btn-brand flex w-full items-center justify-center gap-2 rounded-xl py-4 text-lg font-bold text-white transition-all active:scale-[0.99]">
                Jetzt reservieren
                <svg class="h-5 w-5 opacity-80" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
            </button>
        </form>

        @if(session('alternatives'))
        <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">Dieser Zeitpunkt ist leider nicht mehr verfügbar.</p>
            @if(!empty(session('alternatives')['same_day']))
                <p class="mt-1">Alternative Zeiten: {{ implode(' · ', session('alternatives')['same_day']) }}</p>
            @endif
        </div>
        @endif

        @push('scripts')@endpush
        <script>
        (function () {
            const slotsUrl    = @json(route('booking.slots', [$tenant->slug, $location->slug]));
            const partyInput  = document.getElementById('partySize');
            const dateInput   = document.getElementById('date');
            const timeInput   = document.getElementById('timeInput');
            const slotContainer = document.getElementById('slotContainer');
            const altBox      = document.getElementById('alternatives');
            const bookingSummary = document.getElementById('bookingSummary');
            const summaryText = document.getElementById('summaryText');

            const floorplanUrl   = @json(route('booking.floorplan', [$tenant->slug, $location->slug]));
            const fpSection      = document.getElementById('floorplanSection');
            const fpCanvas       = document.getElementById('floorplanCanvas');
            const roomTabs       = document.getElementById('roomTabs');
            const tableIdInput   = document.getElementById('tableId');
            let fpRooms = [];

            function reveal(el) {
                el.classList.remove('hidden');
                el.classList.remove('reveal-up');
                void el.offsetHeight;
                el.classList.add('reveal-up');
            }

            function fmtDate(d) {
                const dt = new Date(d + 'T00:00:00');
                return isNaN(dt) ? d : dt.toLocaleDateString('de-DE', {weekday: 'short', day: '2-digit', month: '2-digit'});
            }

            function updateSummary() {
                if (!partyInput.value || !dateInput.value || !timeInput.value) {
                    bookingSummary.classList.add('hidden'); return;
                }
                summaryText.textContent =
                    partyInput.value + ' ' + (partyInput.value === '1' ? 'Person' : 'Personen') +
                    ' · ' + fmtDate(dateInput.value) +
                    ' · ' + timeInput.value + ' Uhr';
                reveal(bookingSummary);
            }

            function selectParty(btn) {
                document.querySelectorAll('.party-btn').forEach(b => {
                    b.classList.remove('border-brand', 'bg-brand', 'text-white');
                    b.querySelectorAll('span').forEach(s => { s.classList.remove('text-white'); s.classList.add('text-stone-400'); });
                });
                btn.classList.add('border-brand', 'bg-brand', 'text-white');
                btn.querySelectorAll('span').forEach(s => { s.classList.remove('text-stone-400'); s.classList.add('text-white'); });
                partyInput.value = btn.dataset.party;
                loadSlots();
                updateSummary();
            }
            document.querySelectorAll('.party-btn').forEach(btn => btn.addEventListener('click', () => selectParty(btn)));
            dateInput.addEventListener('change', () => { loadSlots(); updateSummary(); });

            function resetFloorplan() {
                if (!fpSection) return;
                fpSection.classList.add('hidden');
                if (tableIdInput) tableIdInput.value = '';
                fpRooms = [];
            }

            async function pickNextSlot(date, time) {
                dateInput.value = date;
                await loadSlots();
                const btn = [...slotContainer.querySelectorAll('.slot-btn')].find(b => b.dataset.time === time);
                if (btn) { btn.click(); } else { timeInput.value = time; loadFloorplan(); updateSummary(); }
                slotContainer.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }

            function makeSlotBtn(time) {
                const btn = document.createElement('button');
                btn.type = 'button';
                btn.textContent = time;
                btn.dataset.time = time;
                btn.className = 'slot-btn rounded-xl border-2 border-stone-200 py-3 text-sm font-bold tracking-wide transition-all hover:border-brand hover:bg-brand/5 hover:shadow-sm active:scale-[0.97]';
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('border-brand', 'bg-brand', 'text-white'));
                    btn.classList.add('border-brand', 'bg-brand', 'text-white');
                    timeInput.value = time;
                    loadFloorplan();
                    updateSummary();
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
                    if (times.length === 0) return;
                    const lbl = document.createElement('p');
                    lbl.className = 'slot-group-label';
                    lbl.textContent = g.label;
                    slotContainer.appendChild(lbl);
                    times.forEach(t => slotContainer.appendChild(makeSlotBtn(t)));
                    any = true;
                });
                if (!any) {
                    slots.forEach(t => slotContainer.appendChild(makeSlotBtn(t)));
                }
            }

            async function loadSlots() {
                if (!partyInput.value || !dateInput.value) return;
                slotContainer.innerHTML = '<p class="col-span-full animate-pulse text-sm text-stone-400">Verfügbare Zeiten werden geladen…</p>';
                altBox.classList.add('hidden');
                resetFloorplan();
                bookingSummary.classList.add('hidden');
                try {
                    const res = await fetch(slotsUrl + '?date=' + dateInput.value + '&party_size=' + partyInput.value, {headers: {Accept: 'application/json'}});
                    const data = await res.json();
                    slotContainer.innerHTML = '';
                    if (!data.slots || data.slots.length === 0) {
                        if (data.oversized) {
                            slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-stone-100 px-3 py-2.5 text-sm text-stone-600">Für ' + partyInput.value + ' Personen ist online keine Reservierung möglich (max. ' + data.max_party + ').</p>';
                            let html = 'Für größere Gruppen kontaktieren Sie uns direkt';
                            html += data.phone ? ': <a class="font-semibold underline" href="tel:' + data.phone.replace(/\s/g, '') + '">' + data.phone + '</a>' : '.';
                            altBox.innerHTML = html; altBox.classList.remove('hidden');
                            return;
                        }
                        const head = document.createElement('p');
                        head.className = 'col-span-full rounded-xl bg-red-50 px-3 py-2.5 text-sm font-medium text-red-700';
                        head.textContent = 'Am ' + fmtDate(dateInput.value) + ' sind für ' + partyInput.value + ' Personen leider keine Tische frei.';
                        slotContainer.appendChild(head);
                        if (data.next_slots && data.next_slots.length) {
                            const sub = document.createElement('p');
                            sub.className = 'col-span-full mt-2 text-xs font-semibold uppercase tracking-wide text-stone-500';
                            sub.textContent = 'Nächste freie Termine:';
                            slotContainer.appendChild(sub);
                            data.next_slots.forEach(s => {
                                const btn = document.createElement('button');
                                btn.type = 'button';
                                btn.className = 'rounded-xl border-2 border-stone-200 px-2 py-2.5 text-center transition-all hover:border-brand hover:bg-brand/5 active:scale-[0.97]';
                                btn.innerHTML = '<span class="block text-[10px] font-semibold uppercase tracking-wide text-stone-400">' + fmtDate(s.date) + '</span><span class="block text-base font-bold">' + s.time + '</span>';
                                btn.addEventListener('click', () => pickNextSlot(s.date, s.time));
                                slotContainer.appendChild(btn);
                            });
                        }
                        if (data.waitlist_available) {
                            altBox.innerHTML = 'Kein Termin dabei? <strong>Warteliste</strong> nutzen.';
                            altBox.classList.remove('hidden');
                        }
                        return;
                    }
                    renderSlots(data.slots);
                } catch (e) {
                    slotContainer.innerHTML = '<p class="col-span-full rounded-xl bg-red-50 px-3 py-2.5 text-sm text-red-700">Fehler beim Laden – bitte erneut versuchen.</p>';
                }
            }

            async function loadFloorplan() {
                if (!fpSection || !timeInput.value) return;
                tableIdInput.value = '';
                try {
                    const url = floorplanUrl + '?date=' + dateInput.value + '&time=' + timeInput.value + '&party_size=' + partyInput.value;
                    const res = await fetch(url, {headers: {Accept: 'application/json'}});
                    const data = await res.json();
                    fpRooms = data.rooms || [];
                    if (!fpRooms.length) { fpSection.classList.add('hidden'); return; }
                    reveal(fpSection); buildRoomTabs(); renderRoom(0);
                } catch (e) { fpSection.classList.add('hidden'); }
            }

            function buildRoomTabs() {
                roomTabs.innerHTML = '';
                fpRooms.forEach((room, i) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.textContent = (room.is_outdoor ? '☀ ' : '') + room.name;
                    b.className = 'room-tab rounded-full border-2 px-3 py-1.5 text-sm font-semibold transition-all ' + (i === 0 ? 'border-brand bg-stone-50' : 'border-stone-200 hover:border-brand hover:bg-brand/5');
                    b.addEventListener('click', () => {
                        document.querySelectorAll('.room-tab').forEach(t => t.classList.remove('border-brand', 'bg-brand', 'text-white'));
                        b.classList.add('border-brand', 'bg-brand', 'text-white');
                        renderRoom(i);
                    });
                    roomTabs.appendChild(b);
                });
                roomTabs.classList.toggle('hidden', fpRooms.length < 2);
            }

            function renderRoom(index) {
                const room = fpRooms[index]; if (!room) return;
                fpCanvas.innerHTML = '';
                const pad = 20; let maxX = 0, maxY = 0;
                room.tables.forEach(t => { maxX = Math.max(maxX, t.pos_x + t.width); maxY = Math.max(maxY, t.pos_y + t.height); });
                const cw = fpCanvas.clientWidth || 600, ch = fpCanvas.clientHeight || 340;
                const scale = Math.min((cw - pad * 2) / Math.max(maxX, 1), (ch - pad * 2) / Math.max(maxY, 1), 1);
                const colors = { available: '#34d399', occupied: '#d6d3d1', unsuitable: '#fde68a', unavailable: '#e7e5e4' };
                room.tables.forEach(t => {
                    const el = document.createElement('button');
                    el.type = 'button'; el.title = 'Tisch ' + t.name + ' · ' + t.capacity + ' Pers.';
                    el.dataset.tableId = t.id;
                    Object.assign(el.style, {
                        position: 'absolute', left: (pad + t.pos_x * scale) + 'px', top: (pad + t.pos_y * scale) + 'px',
                        width: Math.max(28, t.width * scale) + 'px', height: Math.max(28, t.height * scale) + 'px',
                        background: colors[t.status] || '#d6d3d1',
                        borderRadius: t.shape === 'round' ? '50%' : '8px',
                        border: '2px solid rgba(0,0,0,.12)', fontSize: '11px', fontWeight: '600', color: '#1c1917',
                        transform: t.rotation ? 'rotate(' + t.rotation + 'deg)' : '',
                    });
                    el.textContent = t.name;
                    if (t.selectable) { el.style.cursor = 'pointer'; el.addEventListener('click', () => selectTable(t.id, el)); }
                    else { el.disabled = true; el.style.opacity = t.status === 'available' ? '1' : '.7'; el.style.cursor = 'not-allowed'; }
                    fpCanvas.appendChild(el);
                });
            }

            function selectTable(id, el) {
                const was = tableIdInput.value === String(id);
                fpCanvas.querySelectorAll('button').forEach(b => b.style.outline = '');
                tableIdInput.value = was ? '' : id;
                if (!was) { el.style.outline = '3px solid var(--brand)'; el.style.outlineOffset = '2px'; }
            }

            const def = document.querySelector('.party-btn[data-party="{{ old('party_size', 2) }}"]');
            if (def) selectParty(def);
        })();
        </script>
    @endif
    </div>
</div>

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
