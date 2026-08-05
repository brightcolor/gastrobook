@extends('layouts.admin')
@section('title', 'Marketing')
@section('content')

<div class="mb-1 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Marketing<span class="tip" tabindex="0" data-tip="Automatische Nachrichten an Gäste, die eingewilligt haben: Geburtstagsgruß, Erinnerung nach dem Besuch, „lange nicht gesehen“. Läuft täglich von selbst – du schreibst den Text einmal.">?</span></h1>
    <span class="rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold text-stone-600">
        {{ $consenting }} Gäste mit Werbe-Einwilligung
    </span>
</div>
<p class="mb-5 text-sm text-stone-500">
    Es werden ausschließlich Gäste angeschrieben, die der Werbung zugestimmt haben und schon einmal
    bei <strong>{{ $location->name }}</strong> waren. Jede Nachricht enthält einen Abmeldelink.
</p>

<div class="space-y-4">
    @forelse($campaigns as $campaign)
        <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-bold">
                        {{ $campaign->name }}
                        <span class="ml-1 rounded-full px-2 py-0.5 text-xs font-semibold {{ $campaign->is_active ? 'bg-emerald-100 text-emerald-800' : 'bg-stone-200 text-stone-600' }}">
                            {{ $campaign->is_active ? 'aktiv' : 'pausiert' }}
                        </span>
                    </h2>
                    <p class="mt-1 text-xs text-stone-500">
                        {{ $campaign->typeLabel() }} · {{ $campaign->timingLabel() }}
                        · ab {{ $campaign->min_visits }} {{ $campaign->min_visits === 1 ? 'Besuch' : 'Besuchen' }}
                    </p>
                    <p class="mt-1 text-xs text-stone-400">
                        {{ $campaign->sends_count }} verschickt gesamt
                        · <strong>heute {{ $reach[$campaign->id] ?? 0 }}</strong> {{ ($reach[$campaign->id] ?? 0) === 1 ? 'Gast' : 'Gäste' }} an der Reihe
                        @if($campaign->last_run_at) · zuletzt geprüft {{ $campaign->last_run_at->format('d.m.Y H:i') }} @endif
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-xs">
                    <form method="POST" action="{{ route('admin.marketing.toggle', $campaign) }}">
                        @csrf
                        <button class="rounded-lg px-3 py-2 font-semibold {{ $campaign->is_active ? 'bg-stone-100 text-stone-700 hover:bg-stone-200' : 'bg-stone-900 text-white' }}">
                            {{ $campaign->is_active ? 'Pausieren' : 'Aktivieren' }}
                        </button>
                    </form>
                    <form method="POST" action="{{ route('admin.marketing.test', $campaign) }}">
                        @csrf
                        <button class="font-semibold text-teal-700 hover:underline">Testmail an mich</button>
                    </form>
                    <form method="POST" action="{{ route('admin.marketing.destroy', $campaign) }}"
                          onsubmit="return confirm('Kampagne löschen?')">
                        @csrf @method('DELETE')
                        <button class="text-red-500 hover:underline">Löschen</button>
                    </form>
                </div>
            </div>

            <details class="mt-3 border-t border-stone-100 pt-3">
                <summary class="cursor-pointer text-sm font-semibold text-teal-700">Text und Zeitpunkt bearbeiten</summary>
                <form method="POST" action="{{ route('admin.marketing.update', $campaign) }}" class="mt-3 space-y-3 text-sm">
                    @csrf @method('PUT')
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="block sm:col-span-1">Name (intern)
                            <input type="text" name="name" required value="{{ $campaign->name }}" class="mt-1 w-full rounded-lg border-stone-200">
                        </label>
                        <label class="block">
                            @if($campaign->type === 'birthday') Tage vor dem Geburtstag
                            @elseif($campaign->type === 'rebooking') Tage nach dem Besuch
                            @else Tage ohne Besuch @endif
                            <input type="number" name="offset_days" required min="0" max="730" value="{{ $campaign->offset_days }}" class="mt-1 w-full rounded-lg border-stone-200">
                        </label>
                        <label class="block">Erst ab … Besuchen<span class="tip" tabindex="0" data-tip="Schützt vor Nachrichten an Gäste, die nur einmal kurz da waren. 1 = jeder, der schon einmal da war.">?</span>
                            <input type="number" name="min_visits" required min="0" max="100" value="{{ $campaign->min_visits }}" class="mt-1 w-full rounded-lg border-stone-200">
                        </label>
                    </div>
                    <label class="block">Betreff
                        <input type="text" name="subject" required value="{{ $campaign->subject }}" class="mt-1 w-full rounded-lg border-stone-200">
                    </label>
                    <label class="block">Text
                        <textarea name="body" required rows="10" class="mt-1 w-full rounded-lg border-stone-200 font-mono text-xs">{{ $campaign->body }}</textarea>
                    </label>
                    <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Speichern</button>
                </form>
            </details>
        </div>
    @empty
        <div class="rounded-2xl bg-white p-5 text-sm text-stone-500 shadow-sm ring-1 ring-stone-100">
            Noch keine Kampagne angelegt. Unten kannst du eine starten – sie verschickt erst etwas,
            wenn du sie ausdrücklich aktivierst.
        </div>
    @endforelse
</div>

<div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
    <h2 class="mb-1 font-bold">Neue Kampagne</h2>
    <p class="mb-3 text-xs text-stone-500">
        Verfügbare Platzhalter: <code>{guest_name}</code>, <code>{first_name}</code>,
        <code>{location_name}</code>, <code>{business_name}</code>, <code>{booking_link}</code>,
        <code>{unsubscribe_link}</code>. Fehlt der Abmeldelink im Text, wird er automatisch angehängt.
    </p>

    <div class="mb-3 flex flex-wrap gap-2">
        @foreach($suggestions as $type => $suggestion)
            <button type="button" class="suggestion rounded-lg bg-stone-100 px-3 py-2 text-xs font-semibold hover:bg-stone-200"
                    data-type="{{ $type }}"
                    data-name="{{ $suggestion['name'] }}"
                    data-offset="{{ $suggestion['offset_days'] }}"
                    data-subject="{{ $suggestion['subject'] }}"
                    data-body="{{ $suggestion['body'] }}">
                Vorlage: {{ $suggestion['name'] }}
            </button>
        @endforeach
    </div>

    <form method="POST" action="{{ route('admin.marketing.store') }}" class="space-y-3 text-sm" id="newCampaign">
        @csrf
        <div class="grid gap-3 sm:grid-cols-4">
            <label class="block">Art
                <select name="type" id="campaignType" required class="mt-1 w-full rounded-lg border-stone-200">
                    <option value="birthday">Geburtstagsgruß</option>
                    <option value="rebooking">Erinnerung nach dem Besuch</option>
                    <option value="winback">Lange nicht gesehen</option>
                </select>
            </label>
            <label class="block">Name (intern)
                <input type="text" name="name" id="campaignName" required value="{{ old('name') }}" class="mt-1 w-full rounded-lg border-stone-200">
            </label>
            <label class="block">Tage<span class="tip" tabindex="0" data-tip="Geburtstag: wie viele Tage vorher. Nach dem Besuch: wie viele Tage danach. Lange nicht gesehen: ab wie vielen Tagen ohne Besuch.">?</span>
                <input type="number" name="offset_days" id="campaignOffset" required min="0" max="730" value="{{ old('offset_days', 0) }}" class="mt-1 w-full rounded-lg border-stone-200">
            </label>
            <label class="block">Erst ab … Besuchen
                <input type="number" name="min_visits" required min="0" max="100" value="{{ old('min_visits', 1) }}" class="mt-1 w-full rounded-lg border-stone-200">
            </label>
        </div>
        <label class="block">Betreff
            <input type="text" name="subject" id="campaignSubject" required value="{{ old('subject') }}" class="mt-1 w-full rounded-lg border-stone-200">
        </label>
        <label class="block">Text
            <textarea name="body" id="campaignBody" required rows="10" class="mt-1 w-full rounded-lg border-stone-200 font-mono text-xs">{{ old('body') }}</textarea>
        </label>
        <button class="rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Kampagne anlegen</button>
        <p class="text-xs text-stone-500">Neue Kampagnen sind zunächst pausiert.</p>
    </form>
</div>

<script>
document.querySelectorAll('.suggestion').forEach(function (button) {
    button.addEventListener('click', function () {
        document.getElementById('campaignType').value = button.dataset.type;
        document.getElementById('campaignName').value = button.dataset.name;
        document.getElementById('campaignOffset').value = button.dataset.offset;
        document.getElementById('campaignSubject').value = button.dataset.subject;
        document.getElementById('campaignBody').value = button.dataset.body;
        document.getElementById('newCampaign').scrollIntoView({ behavior: 'smooth', block: 'center' });
    });
});
</script>
@endsection
