@extends('layouts.public', ['tenant' => $tenant])
@section('title', 'Termin umbuchen')
@section('content')
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 shadow-sm">
    <h1 class="text-center text-2xl font-bold">Termin umbuchen</h1>
    <p class="mt-2 text-center text-sm text-stone-600">
        Aktuell: <strong>{{ $reservation->localStart()->format('d.m.Y · H:i') }} Uhr</strong> ({{ $reservation->code }})
    </p>

    @if($tooLate)
        <div class="mt-6 rounded-xl bg-amber-50 p-4 text-center text-sm text-amber-800">
            Die Umbuchungsfrist ist abgelaufen. Bitte kontaktieren Sie uns direkt.
        </div>
    @else
        @if($errors->any())
            <div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('booking.reschedule.post', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
              class="mt-6 space-y-5" id="rescheduleForm">
            @csrf
            <div>
                <label for="date" class="mb-2 block text-sm font-semibold">Neues Datum</label>
                <input type="date" name="date" id="date" required
                       min="{{ now($location->timezone)->toDateString() }}"
                       max="{{ now($location->timezone)->addDays($location->effectiveSettings()->max_advance_days)->toDateString() }}"
                       value="{{ now($location->timezone)->toDateString() }}"
                       class="w-full rounded-xl border-2 border-stone-200 px-4 py-3 text-lg">
            </div>
            <div>
                <label class="mb-2 block text-sm font-semibold">Neue Uhrzeit</label>
                <div id="slotContainer" class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                    <p class="col-span-full text-sm text-stone-500">Bitte Datum wählen.</p>
                </div>
                <input type="hidden" name="time" id="timeInput" required>
            </div>
            <button type="submit" class="btn-brand w-full rounded-xl py-3.5 text-lg font-bold text-white shadow hover:opacity-90">
                Umbuchen
            </button>
        </form>

        <a href="{{ route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="mt-4 block text-center text-sm text-stone-500 underline">Zurück</a>

        <script>
        (function () {
            const slotsUrl = @json(route('booking.slots', [$tenant->slug, $location->slug]));
            const isSalon = @json($isSalon);
            const partySize = @json($reservation->party_size);
            const serviceIds = @json($serviceIds);
            const staffId = @json($staffId);
            const dateInput = document.getElementById('date');
            const timeInput = document.getElementById('timeInput');
            const slotContainer = document.getElementById('slotContainer');

            async function loadSlots() {
                if (!dateInput.value) return;
                slotContainer.innerHTML = '<p class="col-span-full text-sm text-stone-500">Lade verfügbare Zeiten…</p>';
                timeInput.value = '';
                const params = new URLSearchParams();
                params.set('date', dateInput.value);
                if (isSalon) {
                    serviceIds.forEach(id => params.append('service_ids[]', id));
                    params.set('staff_member_id', staffId || 0);
                } else {
                    params.set('party_size', partySize);
                }
                try {
                    const res = await fetch(slotsUrl + '?' + params.toString(), {headers: {Accept: 'application/json'}});
                    const data = await res.json();
                    slotContainer.innerHTML = '';
                    if (!data.slots || data.slots.length === 0) {
                        slotContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">An diesem Tag sind keine Zeiten verfügbar.</p>';
                        return;
                    }
                    data.slots.forEach(t => {
                        const b = document.createElement('button');
                        b.type = 'button'; b.textContent = t;
                        b.className = 'slot-btn rounded-xl border-2 border-stone-200 py-2.5 font-semibold hover:border-brand';
                        b.addEventListener('click', () => {
                            document.querySelectorAll('.slot-btn').forEach(x => x.classList.remove('border-brand', 'bg-stone-50'));
                            b.classList.add('border-brand', 'bg-stone-50');
                            timeInput.value = t;
                        });
                        slotContainer.appendChild(b);
                    });
                } catch (e) {
                    slotContainer.innerHTML = '<p class="col-span-full text-sm text-red-600">Fehler beim Laden.</p>';
                }
            }
            dateInput.addEventListener('change', loadSlots);
            loadSlots();
        })();
        </script>
    @endif
</div>
@endsection
