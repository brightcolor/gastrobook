@extends('layouts.public')
@section('title', ($tenant->isSalon() ? 'Termin buchen' : 'Tisch reservieren') . ' – ' . $location->name)
@section('content')
<div class="rounded-2xl bg-white p-6 shadow-sm">
    @if($location->brand_logo_path || $tenant->brand_logo_path)
        <img src="{{ asset('storage/' . ($location->brand_logo_path ?? $tenant->brand_logo_path)) }}" alt="" class="mx-auto mb-4 h-16 object-contain">
    @endif
    <h1 class="text-center text-2xl font-bold">{{ $location->name }}</h1>
    @if($location->public_intro)
        <p class="mt-2 text-center text-sm text-stone-600">{{ $location->public_intro }}</p>
    @endif
    @if(($upcomingEvents ?? 0) > 0)
        <p class="mt-3 text-center">
            <a href="{{ route('events.index', [$tenant->slug, $location->slug]) }}"
               class="inline-block rounded-full bg-amber-50 px-4 py-1.5 text-sm font-semibold text-amber-800 hover:bg-amber-100">
                🎉 {{ $upcomingEvents }} {{ $upcomingEvents === 1 ? 'Event' : 'Events' }} – jetzt Tickets sichern
            </a>
        </p>
    @endif

    @if($tenant->isSalon())
        {{-- ===== SALON BUCHUNGSFORMULAR ===== --}}
        @if(($services ?? collect())->isEmpty())
            <div class="mt-6 rounded-xl bg-amber-50 p-4 text-center text-sm text-amber-800">
                Es sind noch keine Leistungen konfiguriert. Bitte kontaktieren Sie uns direkt.
            </div>
        @else
        <form method="POST" action="{{ route('booking.store', [$tenant->slug, $location->slug]) }}"
              class="mt-6 space-y-5" id="salonBookingForm">
            @csrf
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

            {{-- Step 1: Leistungen wählen (Mehrfachauswahl per Pills) --}}
            <div>
                <label class="mb-2 block text-sm font-semibold">Leistungen wählen * <span class="font-normal text-stone-400">(mehrere kombinierbar)</span></label>
                <div class="flex flex-wrap gap-2" id="serviceButtons">
                    @foreach($services as $svc)
                        <button type="button" data-service-id="{{ $svc->id }}"
                                data-duration="{{ $svc->duration_minutes }}"
                                data-price="{{ $svc->price_minor }}"
                                class="service-pill rounded-full border-2 border-stone-200 px-4 py-2 text-sm font-semibold hover:border-brand">
                            {{ $svc->name }}
                            <span class="ml-1 font-normal text-stone-500">· {{ $svc->durationFormatted() }}@if($svc->price_minor > 0) · {{ $svc->priceFormatted() }}@endif</span>
                        </button>
                    @endforeach
                </div>
                {{-- Hidden inputs service_ids[] werden per JS befüllt --}}
                <div id="serviceInputs"></div>
                <div id="serviceSummary" class="mt-3 hidden rounded-xl bg-stone-50 px-4 py-2 text-sm">
                    <span class="font-semibold">Gesamt:</span>
                    <span id="summaryDuration"></span><span id="summaryPrice"></span>
                </div>
            </div>

            {{-- Step 2: Mitarbeiter wählen (optional) --}}
            <div id="staffSection" class="hidden">
                <label class="mb-2 block text-sm font-semibold">Mitarbeiter:in (optional)</label>
                <div id="staffButtons" class="flex flex-wrap gap-2"></div>
                <input type="hidden" name="staff_member_id" id="staffMemberId" value="0">
                <p id="noStaffHint" class="mt-1 hidden text-xs text-amber-700">Keine:r Ihrer gewählten Leistungen kann von einer einzelnen Person zusammen ausgeführt werden – bitte Auswahl anpassen.</p>
            </div>

            {{-- Step 3: Datum --}}
            <div id="dateSection" class="hidden">
                <label for="date" class="mb-2 block text-sm font-semibold">Datum *</label>
                <input type="date" name="date" id="date"
                       min="{{ now($location->timezone)->toDateString() }}"
                       max="{{ now($location->timezone)->addDays($settings->max_advance_days)->toDateString() }}"
                       value="{{ old('date', now($location->timezone)->toDateString()) }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3 text-lg">
            </div>

            {{-- Step 4: Uhrzeit / Slots --}}
            <div id="slotSection" class="hidden">
                <label class="mb-2 block text-sm font-semibold">Uhrzeit *</label>
                <div id="slotContainer" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <p class="col-span-full text-sm text-stone-500">Bitte Leistung und Datum wählen.</p>
                </div>
                <input type="hidden" name="time" id="timeInput" value="{{ old('time') }}" required>
            </div>

            {{-- Step 5: Kontaktdaten --}}
            <div class="space-y-3 border-t border-stone-100 pt-4" id="contactSection">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold">Name *</label>
                    <input type="text" name="name" id="name" required value="{{ old('name') }}"
                           class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                </div>
                <div>
                    <label for="email" class="mb-1 block text-sm font-semibold">E-Mail *</label>
                    <input type="email" name="email" id="email" required value="{{ old('email') }}"
                           class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                </div>
                <div>
                    <label for="phone" class="mb-1 block text-sm font-semibold">Telefon (optional)</label>
                    <input type="tel" name="phone" id="phone" value="{{ old('phone') }}"
                           class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                </div>
                @if($settings->fieldRule('note') !== 'hidden')
                    <div>
                        <label for="note" class="mb-1 block text-sm font-semibold">Anmerkung (optional)</label>
                        <textarea name="note" id="note" rows="2"
                                  class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('note') }}</textarea>
                    </div>
                @endif
            </div>

            {{-- Datenschutz --}}
            <div class="space-y-2 border-t border-stone-100 pt-4 text-sm">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="privacy_accepted" value="1" required class="mt-1">
                    <span>Ich habe die @if($tenant->privacy_url)<a href="{{ $tenant->privacy_url }}" target="_blank" rel="noopener" class="underline">Datenschutzhinweise</a>@else Datenschutzhinweise @endif gelesen und akzeptiere die Verarbeitung meiner Daten zur Terminabwicklung. *</span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="newsletter" value="1" class="mt-1">
                    <span>Ich möchte Angebote und Neuigkeiten per E-Mail erhalten (jederzeit widerrufbar).</span>
                </label>
            </div>

            <button type="submit" class="btn-brand w-full rounded-xl py-4 text-lg font-bold text-white shadow hover:opacity-90">
                Termin buchen
            </button>
        </form>

        @if(session('alternatives'))
            <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Dieser Zeitpunkt ist leider nicht verfügbar. Bitte einen anderen Termin wählen.</p>
            </div>
        @endif

        <script>
        (function () {
            const slotsUrl = @json(route('booking.slots', [$tenant->slug, $location->slug]));
            // serviceId -> { staff: [{id,name}], staffIds: Set }
            const serviceData = @json($services->mapWithKeys(fn($s) => [$s->id => ['staff' => $s->staff->where('is_active', true)->map(fn($m) => ['id' => $m->id, 'name' => $m->name])->values()]])->all());

            const staffInput = document.getElementById('staffMemberId');
            const dateInput = document.getElementById('date');
            const timeInput = document.getElementById('timeInput');
            const slotContainer = document.getElementById('slotContainer');
            const staffSection = document.getElementById('staffSection');
            const dateSection = document.getElementById('dateSection');
            const slotSection = document.getElementById('slotSection');
            const staffButtons = document.getElementById('staffButtons');
            const serviceInputs = document.getElementById('serviceInputs');
            const summary = document.getElementById('serviceSummary');
            const summaryDuration = document.getElementById('summaryDuration');
            const summaryPrice = document.getElementById('summaryPrice');
            const noStaffHint = document.getElementById('noStaffHint');

            const selected = []; // ordered list of service ids

            function show(el) { el.classList.remove('hidden'); }
            function hide(el) { el.classList.add('hidden'); }

            function fmtDuration(min) {
                const h = Math.floor(min / 60), m = min % 60;
                if (h === 0) return m + ' Min.';
                return m === 0 ? h + ' Std.' : h + ' Std. ' + m + ' Min.';
            }

            function toggleService(pill) {
                const id = parseInt(pill.dataset.serviceId, 10);
                const idx = selected.indexOf(id);
                if (idx === -1) {
                    selected.push(id);
                    pill.classList.add('border-brand', 'bg-stone-50');
                } else {
                    selected.splice(idx, 1);
                    pill.classList.remove('border-brand', 'bg-stone-50');
                }
                refresh();
            }

            function eligibleStaff() {
                // Intersection of staff across all selected services
                if (selected.length === 0) return [];
                let inter = null;
                selected.forEach(id => {
                    const staff = (serviceData[id] || {}).staff || [];
                    const ids = new Set(staff.map(m => m.id));
                    if (inter === null) { inter = staff.slice(); }
                    else { inter = inter.filter(m => ids.has(m.id)); }
                });
                return inter || [];
            }

            function refresh() {
                // Hidden inputs
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
                    hide(summary); hide(staffSection); hide(dateSection); hide(slotSection);
                    return;
                }

                summaryDuration.textContent = fmtDuration(dur);
                summaryPrice.textContent = price > 0 ? ' · ' + (price / 100).toFixed(2).replace('.', ',') + ' €' : '';
                show(summary);

                // Rebuild eligible staff buttons
                const staff = eligibleStaff();
                staffButtons.innerHTML = '<button type="button" data-staff-id="0" class="staff-btn rounded-xl border-2 border-brand bg-stone-50 px-4 py-2 text-sm font-semibold">Beliebig</button>';
                staff.forEach(m => {
                    const b = document.createElement('button');
                    b.type = 'button'; b.dataset.staffId = m.id;
                    b.className = 'staff-btn rounded-xl border-2 border-stone-200 px-4 py-2 text-sm font-semibold hover:border-brand';
                    b.textContent = m.name;
                    staffButtons.appendChild(b);
                });
                staffButtons.querySelectorAll('.staff-btn').forEach(b => b.addEventListener('click', () => selectStaff(b)));
                staffInput.value = 0;
                noStaffHint.classList.toggle('hidden', staff.length > 0);

                show(staffSection); show(dateSection); show(slotSection);
                loadSlots();
            }

            function selectStaff(btn) {
                document.querySelectorAll('.staff-btn').forEach(b => b.classList.remove('border-brand', 'bg-stone-50'));
                btn.classList.add('border-brand', 'bg-stone-50');
                staffInput.value = btn.dataset.staffId;
                loadSlots();
            }

            async function loadSlots() {
                if (selected.length === 0 || !dateInput.value) return;
                slotContainer.innerHTML = '<p class="col-span-full text-sm text-stone-500">Lade verfügbare Termine…</p>';
                timeInput.value = '';
                try {
                    const params = new URLSearchParams();
                    params.set('date', dateInput.value);
                    params.set('staff_member_id', staffInput.value || 0);
                    selected.forEach(id => params.append('service_ids[]', id));
                    const res = await fetch(slotsUrl + '?' + params.toString(), {headers: {Accept: 'application/json'}});
                    const data = await res.json();
                    slotContainer.innerHTML = '';
                    if (!data.slots || data.slots.length === 0) {
                        slotContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">An diesem Tag sind keine Termine verfügbar.</p>';
                        return;
                    }
                    data.slots.forEach(time => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.textContent = time;
                        btn.className = 'slot-btn rounded-xl border-2 border-stone-200 py-2.5 font-semibold hover:border-brand';
                        btn.addEventListener('click', () => {
                            document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('border-brand', 'bg-stone-50'));
                            btn.classList.add('border-brand', 'bg-stone-50');
                            timeInput.value = time;
                        });
                        slotContainer.appendChild(btn);
                    });
                } catch (e) {
                    slotContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">Fehler beim Laden. Bitte erneut versuchen.</p>';
                }
            }

            document.querySelectorAll('.service-pill').forEach(pill => pill.addEventListener('click', () => toggleService(pill)));
            dateInput.addEventListener('change', loadSlots);
        })();
        </script>
        @endif

    @else
        {{-- ===== RESTAURANT BUCHUNGSFORMULAR ===== --}}
        <form method="POST" action="{{ route('booking.store', [$tenant->slug, $location->slug]) }}" class="mt-6 space-y-5" id="bookingForm">
            @csrf
            <input type="text" name="website" class="hidden" tabindex="-1" autocomplete="off" aria-hidden="true">

            {{-- Step 1: party size --}}
            <div>
                <label class="mb-2 block text-sm font-semibold">Personen</label>
                <div class="grid grid-cols-4 gap-2" id="partyButtons">
                    @for($i = $settings->min_party_online; $i <= min($settings->max_party_online, $settings->min_party_online + 7); $i++)
                        <button type="button" data-party="{{ $i }}"
                                class="party-btn rounded-xl border-2 border-stone-200 py-3 text-lg font-semibold hover:border-brand">{{ $i }}</button>
                    @endfor
                </div>
                <input type="hidden" name="party_size" id="partySize" value="{{ old('party_size', 2) }}" required>
            </div>

            {{-- Step 2: date --}}
            <div>
                <label for="date" class="mb-2 block text-sm font-semibold">Datum</label>
                <input type="date" name="date" id="date" required
                       min="{{ now($location->timezone)->toDateString() }}"
                       max="{{ now($location->timezone)->addDays($settings->max_advance_days)->toDateString() }}"
                       value="{{ old('date', now($location->timezone)->toDateString()) }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3 text-lg">
            </div>

            {{-- Step 3: time slots --}}
            <div>
                <label class="mb-2 block text-sm font-semibold">Uhrzeit</label>
                <div id="slotContainer" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <p class="col-span-full text-sm text-stone-500">Bitte Personenzahl und Datum wählen.</p>
                </div>
                <input type="hidden" name="time" id="timeInput" value="{{ old('time') }}" required>
                <div id="alternatives" class="mt-3 hidden rounded-xl bg-amber-50 p-3 text-sm text-amber-900"></div>
            </div>

            {{-- Step 4: contact --}}
            <div class="space-y-3 border-t border-stone-100 pt-4">
                <div>
                    <label for="name" class="mb-1 block text-sm font-semibold">Name *</label>
                    <input type="text" name="name" id="name" required value="{{ old('name') }}"
                           class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                </div>
                @if($settings->fieldRule('email') !== 'hidden')
                    <div>
                        <label for="email" class="mb-1 block text-sm font-semibold">E-Mail {{ $settings->fieldRule('email') === 'required' ? '*' : '' }}</label>
                        <input type="email" name="email" id="email" value="{{ old('email') }}"
                               @if($settings->fieldRule('email') === 'required') required @endif
                               class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                    </div>
                @endif
                @if($settings->fieldRule('phone') !== 'hidden')
                    <div>
                        <label for="phone" class="mb-1 block text-sm font-semibold">Telefon {{ $settings->fieldRule('phone') === 'required' ? '*' : '' }}</label>
                        <input type="tel" name="phone" id="phone" value="{{ old('phone') }}"
                               @if($settings->fieldRule('phone') === 'required') required @endif
                               class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                    </div>
                @endif
                @if($settings->fieldRule('occasion') !== 'hidden')
                    <div>
                        <label for="occasion" class="mb-1 block text-sm font-semibold">Anlass (optional)</label>
                        <select name="occasion" id="occasion" class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                            <option value="">–</option>
                            @foreach(['Geburtstag', 'Jahrestag', 'Geschäftsessen', 'Familienfeier', 'Date', 'Sonstiges'] as $occ)
                                <option @selected(old('occasion') === $occ)>{{ $occ }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                @if($settings->fieldRule('allergies') !== 'hidden')
                    <div>
                        <label for="allergies" class="mb-1 block text-sm font-semibold">Allergien / Unverträglichkeiten (optional)</label>
                        <input type="text" name="allergies" id="allergies" value="{{ old('allergies') }}"
                               class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">
                    </div>
                @endif
                @if($settings->fieldRule('note') !== 'hidden')
                    <div>
                        <label for="note" class="mb-1 block text-sm font-semibold">Anmerkung (optional)</label>
                        <textarea name="note" id="note" rows="2"
                                  class="w-full rounded-xl border-2 border-stone-200 px-4 py-3">{{ old('note') }}</textarea>
                    </div>
                @endif
            </div>

            {{-- Consents --}}
            <div class="space-y-2 border-t border-stone-100 pt-4 text-sm">
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="privacy_accepted" value="1" required class="mt-1">
                    <span>Ich habe die @if($tenant->privacy_url)<a href="{{ $tenant->privacy_url }}" target="_blank" rel="noopener" class="underline">Datenschutzhinweise</a>@else Datenschutzhinweise @endif gelesen und akzeptiere die Verarbeitung meiner Daten zur Reservierungsabwicklung. *</span>
                </label>
                <label class="flex items-start gap-2">
                    <input type="checkbox" name="newsletter" value="1" class="mt-1">
                    <span>Ich möchte den Newsletter mit Angeboten und Veranstaltungen erhalten (jederzeit widerrufbar).</span>
                </label>
            </div>

            <button type="submit" class="btn-brand w-full rounded-xl py-4 text-lg font-bold text-white shadow hover:opacity-90">
                Jetzt reservieren
            </button>
        </form>

        @if(session('alternatives'))
            <div class="mt-4 rounded-2xl bg-amber-50 p-4 text-sm text-amber-900">
                <p class="font-semibold">Dieser Zeitpunkt ist leider nicht mehr verfügbar.</p>
                @if(!empty(session('alternatives')['same_day']))
                    <p class="mt-1">Alternative Zeiten: {{ implode(' · ', session('alternatives')['same_day']) }}</p>
                @endif
                @if(!empty(session('alternatives')['other_days']))
                    <p class="mt-1">Andere Tage mit freien Tischen: {{ implode(' · ', session('alternatives')['other_days']) }}</p>
                @endif
            </div>
        @endif

        @push('scripts')@endpush
        <script>
        (function () {
            const slotsUrl = @json(route('booking.slots', [$tenant->slug, $location->slug]));
            const partyInput = document.getElementById('partySize');
            const dateInput = document.getElementById('date');
            const timeInput = document.getElementById('timeInput');
            const slotContainer = document.getElementById('slotContainer');
            const altBox = document.getElementById('alternatives');

            function selectParty(btn) {
                document.querySelectorAll('.party-btn').forEach(b => b.classList.remove('border-brand', 'bg-stone-50'));
                btn.classList.add('border-brand', 'bg-stone-50');
                partyInput.value = btn.dataset.party;
                loadSlots();
            }
            document.querySelectorAll('.party-btn').forEach(btn => btn.addEventListener('click', () => selectParty(btn)));
            dateInput.addEventListener('change', loadSlots);

            async function loadSlots() {
                if (!partyInput.value || !dateInput.value) return;
                slotContainer.innerHTML = '<p class="col-span-full text-sm text-stone-500">Lade verfügbare Zeiten…</p>';
                altBox.classList.add('hidden');
                try {
                    const res = await fetch(slotsUrl + '?date=' + dateInput.value + '&party_size=' + partyInput.value, {headers: {Accept: 'application/json'}});
                    const data = await res.json();
                    slotContainer.innerHTML = '';
                    if (!data.slots || data.slots.length === 0) {
                        slotContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">An diesem Tag sind leider keine Tische verfügbar.</p>';
                        if (data.alternatives) {
                            let html = '';
                            if (data.alternatives.other_days?.length) html += 'Freie Tage: ' + data.alternatives.other_days.join(' · ');
                            if (data.waitlist_available) html += '<br>Tipp: Sie können sich auf die Warteliste setzen lassen.';
                            if (html) { altBox.innerHTML = html; altBox.classList.remove('hidden'); }
                        }
                        return;
                    }
                    data.slots.forEach(time => {
                        const btn = document.createElement('button');
                        btn.type = 'button';
                        btn.textContent = time;
                        btn.className = 'slot-btn rounded-xl border-2 border-stone-200 py-2.5 font-semibold hover:border-brand';
                        btn.addEventListener('click', () => {
                            document.querySelectorAll('.slot-btn').forEach(b => b.classList.remove('border-brand', 'bg-stone-50'));
                            btn.classList.add('border-brand', 'bg-stone-50');
                            timeInput.value = time;
                        });
                        slotContainer.appendChild(btn);
                    });
                } catch (e) {
                    slotContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">Fehler beim Laden. Bitte erneut versuchen.</p>';
                }
            }

            const def = document.querySelector('.party-btn[data-party="{{ old('party_size', 2) }}"]');
            if (def) selectParty(def);
        })();
        </script>
    @endif
</div>
@endsection
