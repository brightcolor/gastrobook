@extends('layouts.admin')
@section('title', 'Mitarbeiter')
@section('content')
<div class="mb-5 flex items-center justify-between">
    <h1 class="text-2xl font-bold">Mitarbeiter – {{ $location->name }}</h1>
</div>

{{-- Neuen Mitarbeiter anlegen --}}
<div class="mb-6 rounded-2xl bg-white p-5 shadow-sm">
    <h2 class="mb-4 font-bold">Mitarbeiter:in anlegen</h2>
    <form method="POST" action="{{ route('admin.staff.store') }}" class="space-y-3">
        @csrf
        <div class="grid gap-3 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Name *</label>
                <input type="text" name="name" required maxlength="120" value="{{ old('name') }}"
                       class="w-full rounded-lg border-stone-200 text-sm" placeholder="z.B. Anna Müller">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Kurzbiografie (optional)</label>
                <input type="text" name="bio" maxlength="500" value="{{ old('bio') }}"
                       class="w-full rounded-lg border-stone-200 text-sm" placeholder="z.B. Spezialistin für Colorationen">
            </div>
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe (Kalender)</label>
                <input type="color" name="color" value="{{ old('color', '#0d9488') }}"
                       class="h-9 w-12 rounded border-stone-200">
            </div>
        </div>
        @if($services->isNotEmpty())
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Angebotene Leistungen</label>
                <div class="flex flex-wrap gap-3">
                    @foreach($services as $svc)
                        <label class="flex items-center gap-1 text-sm">
                            <input type="checkbox" name="service_ids[]" value="{{ $svc->id }}"
                                   @checked(in_array($svc->id, old('service_ids', [])))>
                            {{ $svc->name }} ({{ $svc->durationFormatted() }})
                        </label>
                    @endforeach
                </div>
            </div>
        @endif
        <div class="flex items-center gap-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="is_active" value="1" @checked(old('is_active', true))>
                Aktiv (buchbar)
            </label>
            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                Anlegen
            </button>
        </div>
    </form>
</div>

{{-- Mitarbeiterliste --}}
@if($members->isEmpty())
    <p class="rounded-2xl bg-white p-6 text-center text-sm text-stone-500 shadow-sm">Noch keine Mitarbeiter angelegt.</p>
@else
    <div class="space-y-3">
        @foreach($members as $member)
            <details class="rounded-2xl bg-white shadow-sm">
                <summary class="flex cursor-pointer items-center gap-3 p-4 hover:bg-stone-50">
                    @if($member->color)
                        <span class="inline-block h-5 w-5 rounded-full" style="background:{{ $member->color }}"></span>
                    @endif
                    <span class="font-semibold">{{ $member->name }}</span>
                    @if($member->bio)
                        <span class="text-xs text-stone-400">{{ $member->bio }}</span>
                    @endif
                    @if($member->services->isNotEmpty())
                        <span class="rounded-full bg-teal-50 px-2 py-0.5 text-xs text-teal-700">
                            {{ $member->services->pluck('name')->join(', ') }}
                        </span>
                    @endif
                    @if(!$member->is_active)
                        <span class="rounded-full bg-amber-100 px-2 py-0.5 text-xs text-amber-700">inaktiv</span>
                    @endif
                    <span class="ml-auto text-xs text-stone-400">bearbeiten ▾</span>
                </summary>
                <div class="border-t border-stone-100 p-5">
                    <form method="POST" action="{{ route('admin.staff.update', $member) }}" class="space-y-3">
                        @csrf @method('PUT')
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Name *</label>
                                <input type="text" name="name" required maxlength="120" value="{{ $member->name }}"
                                       class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Kurzbiografie</label>
                                <input type="text" name="bio" maxlength="500" value="{{ $member->bio }}"
                                       class="w-full rounded-lg border-stone-200 text-sm">
                            </div>
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Farbe</label>
                                <input type="color" name="color" value="{{ $member->color ?? '#0d9488' }}"
                                       class="h-9 w-12 rounded border-stone-200">
                            </div>
                        </div>
                        @if($services->isNotEmpty())
                            <div>
                                <label class="mb-1 block text-xs font-semibold text-stone-500">Angebotene Leistungen</label>
                                <div class="flex flex-wrap gap-3">
                                    @foreach($services as $svc)
                                        <label class="flex items-center gap-1 text-sm">
                                            <input type="checkbox" name="service_ids[]" value="{{ $svc->id }}"
                                                   @checked($member->services->contains($svc))>
                                            {{ $svc->name }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" name="is_active" value="1" @checked($member->is_active)>
                                Aktiv
                            </label>
                            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">
                                Speichern
                            </button>
                        </div>
                    </form>

                    {{-- Arbeitszeiten --}}
                    <div class="mt-5 border-t border-stone-100 pt-5">
                        <h3 class="mb-1 font-semibold">Arbeitszeiten</h3>
                        <p class="mb-3 text-xs text-stone-400">Ohne Eintrag gelten die Öffnungszeiten des Standorts. Mit Einträgen ist die/der Mitarbeiter:in nur in diesen Fenstern buchbar.</p>
                        <form method="POST" action="{{ route('admin.staff.working-hours', $member) }}">
                            @csrf @method('PUT')
                            @php($weekdays = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'])
                            <div class="space-y-2" id="wh-{{ $member->id }}">
                                @foreach($member->workingHours->sortBy('weekday')->values() as $i => $wh)
                                    <div class="wh-row flex items-center gap-2 text-sm">
                                        <select name="hours[{{ $i }}][weekday]" class="rounded-lg border-stone-200">
                                            @foreach($weekdays as $wd => $name)<option value="{{ $wd }}" @selected($wh->weekday == $wd)>{{ $name }}</option>@endforeach
                                        </select>
                                        <input type="time" name="hours[{{ $i }}][starts_at]" value="{{ substr($wh->starts_at, 0, 5) }}" class="rounded-lg border-stone-200">
                                        <span>–</span>
                                        <input type="time" name="hours[{{ $i }}][ends_at]" value="{{ substr($wh->ends_at, 0, 5) }}" class="rounded-lg border-stone-200">
                                        <button type="button" onclick="this.closest('.wh-row').remove()" class="text-red-500">✕</button>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-2 flex items-center gap-3">
                                <button type="button" class="text-sm text-teal-700 underline"
                                        onclick="addWhRow({{ $member->id }})">+ Zeitfenster</button>
                                <button type="submit" class="rounded-lg bg-stone-900 px-4 py-1.5 text-sm font-semibold text-white">Arbeitszeiten speichern</button>
                            </div>
                        </form>
                    </div>

                    {{-- Abwesenheiten --}}
                    <div class="mt-5 border-t border-stone-100 pt-5">
                        <h3 class="mb-3 font-semibold">Abwesenheiten (Urlaub / Krank)</h3>
                        @if($member->absences->isNotEmpty())
                            <div class="mb-3 space-y-1 text-sm">
                                @foreach($member->absences as $absence)
                                    <div class="flex items-center justify-between rounded-lg bg-stone-50 px-3 py-2">
                                        <span>
                                            {{ $absence->starts_at->setTimezone($location->timezone)->format('d.m.Y H:i') }}
                                            – {{ $absence->ends_at->setTimezone($location->timezone)->format('d.m.Y H:i') }}
                                            @if($absence->reason)<span class="text-stone-400">· {{ $absence->reason }}</span>@endif
                                        </span>
                                        <form method="POST" action="{{ route('admin.staff.absences.destroy', $absence) }}">
                                            @csrf @method('DELETE')
                                            <button type="submit" class="text-red-500 hover:underline">entfernen</button>
                                        </form>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        <form method="POST" action="{{ route('admin.staff.absences.store', $member) }}"
                              class="grid grid-cols-2 gap-2 text-sm sm:grid-cols-5">
                            @csrf
                            <div><label class="mb-1 block text-xs text-stone-500">Von Datum</label>
                                <input type="date" name="starts_on" required class="w-full rounded-lg border-stone-200"></div>
                            <div><label class="mb-1 block text-xs text-stone-500">Uhrzeit (opt.)</label>
                                <input type="time" name="starts_time" class="w-full rounded-lg border-stone-200"></div>
                            <div><label class="mb-1 block text-xs text-stone-500">Bis Datum</label>
                                <input type="date" name="ends_on" required class="w-full rounded-lg border-stone-200"></div>
                            <div><label class="mb-1 block text-xs text-stone-500">Uhrzeit (opt.)</label>
                                <input type="time" name="ends_time" class="w-full rounded-lg border-stone-200"></div>
                            <div class="flex items-end"><button type="submit" class="w-full rounded-lg bg-stone-900 px-4 py-2 font-semibold text-white">Eintragen</button></div>
                            <div class="col-span-2 sm:col-span-5"><input type="text" name="reason" maxlength="120" placeholder="Grund (optional, z. B. Urlaub)" class="w-full rounded-lg border-stone-200"></div>
                        </form>
                    </div>

                    {{-- Löschen --}}
                    <div class="mt-5 border-t border-stone-100 pt-4">
                        <form method="POST" action="{{ route('admin.staff.destroy', $member) }}"
                              onsubmit="return confirm('Mitarbeiter:in wirklich löschen?')">
                            @csrf @method('DELETE')
                            <button type="submit" class="rounded-lg border border-red-200 px-3 py-2 text-sm text-red-600 hover:bg-red-50">
                                Mitarbeiter:in löschen
                            </button>
                        </form>
                    </div>
                </div>
            </details>
        @endforeach
    </div>

    <script>
    function addWhRow(memberId) {
        const container = document.getElementById('wh-' + memberId);
        const idx = container.querySelectorAll('.wh-row').length;
        const days = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
        const row = document.createElement('div');
        row.className = 'wh-row flex items-center gap-2 text-sm';
        row.innerHTML = '<select name="hours[' + idx + '][weekday]" class="rounded-lg border-stone-200">'
            + days.map((d, i) => '<option value="' + i + '">' + d + '</option>').join('')
            + '</select>'
            + '<input type="time" name="hours[' + idx + '][starts_at]" value="09:00" class="rounded-lg border-stone-200">'
            + '<span>–</span>'
            + '<input type="time" name="hours[' + idx + '][ends_at]" value="18:00" class="rounded-lg border-stone-200">'
            + '<button type="button" onclick="this.closest(\'.wh-row\').remove()" class="text-red-500">✕</button>';
        container.appendChild(row);
    }
    </script>
@endif
@endsection
