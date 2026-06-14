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
        <label class="mb-1 block text-sm font-semibold">Tische (leer = automatische Zuweisung)</label>
        <select name="table_ids[]" multiple size="6" class="w-full rounded-xl border-stone-200">
            @foreach($rooms as $room)
                <optgroup label="{{ $room->name }}">
                    @foreach($room->tables as $table)
                        <option value="{{ $table->id }}" @selected(collect(old('table_ids', $prefill['table_id'] ? [$prefill['table_id']] : []))->contains($table->id))>{{ $table->name }} ({{ $table->min_capacity }}–{{ $table->max_capacity }} P.)</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
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
</script>
@endsection
