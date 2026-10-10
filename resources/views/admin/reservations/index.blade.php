@extends('layouts.admin')
@section('title', 'Reservierungen')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Reservierungsbuch</h1>
    {{-- Unter 768 px bezieht sich das Ausklappfeld des Exports auf diese Zeile und bleibt auch nach einem Umbruch im Bild. --}}
    <div class="relative flex flex-wrap gap-2">
        {{-- Ansicht: Liste | Timeline --}}
        <div class="flex rounded-xl bg-stone-200 p-1 text-sm font-semibold">
            <a href="{{ request()->fullUrlWithQuery(['view' => null]) }}"
               class="rounded-lg px-3 py-1.5 {{ $timeline ? 'text-stone-500 hover:text-stone-800' : 'bg-white shadow-sm' }}">Liste</a>
            <a href="{{ request()->fullUrlWithQuery(['view' => 'timeline']) }}"
               class="rounded-lg px-3 py-1.5 {{ $timeline ? 'bg-white shadow-sm' : 'text-stone-500 hover:text-stone-800' }}">Timeline</a>
        </div>
        <div class="md:relative">
            <button id="exportBtn"
                    class="rounded-xl bg-stone-200 px-4 py-2.5 text-sm font-semibold hover:bg-stone-300">↓ Export</button>
            <span class="tip tip-left" tabindex="0" data-tip="Lädt die Reservierungen des gewählten Zeitraums als Tabelle herunter (CSV) – zum Weiterarbeiten in Excel, für die Buchhaltung oder fürs Archiv. Es wird nichts verändert.">?</span>
            <div id="exportDrop"
                 class="absolute right-0 top-full z-20 mt-1 hidden w-72 max-w-full rounded-2xl bg-white p-4 shadow-xl ring-1 ring-stone-100">
                <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-stone-500">Zeitraum wählen</p>
                <div class="mb-3 grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-xs text-stone-500">Von</label>
                        <input type="date" id="exportFrom" value="{{ $from ?? now()->toDateString() }}"
                               class="w-full rounded-lg border-stone-200 text-sm">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs text-stone-500">Bis</label>
                        <input type="date" id="exportUntil" value="{{ $to ?? now()->toDateString() }}"
                               class="w-full rounded-lg border-stone-200 text-sm">
                    </div>
                </div>
                <a id="exportLink"
                   href="{{ route('admin.reservations.export', ['from' => $from ?? now()->toDateString(), 'until' => $to ?? now()->toDateString()]) }}"
                   class="block w-full rounded-xl bg-stone-900 px-4 py-2 text-center text-sm font-semibold text-white hover:bg-stone-700">
                    CSV herunterladen
                </a>
            </div>
        </div>
        <script>
            (function () {
                const btn = document.getElementById('exportBtn');
                const drop = document.getElementById('exportDrop');
                const link = document.getElementById('exportLink');
                const base = @json(route('admin.reservations.export'));
                function updateLink() {
                    const f = document.getElementById('exportFrom').value;
                    const u = document.getElementById('exportUntil').value;
                    link.href = base + '?from=' + f + '&until=' + u;
                }
                btn.addEventListener('click', e => { e.stopPropagation(); drop.classList.toggle('hidden'); });
                document.addEventListener('click', () => drop.classList.add('hidden'));
                drop.addEventListener('click', e => e.stopPropagation());
                document.getElementById('exportFrom').addEventListener('change', updateLink);
                document.getElementById('exportUntil').addEventListener('change', updateLink);
                link.addEventListener('click', () => drop.classList.add('hidden'));
            })();
        </script>
        @if($canCreate)
            <a href="{{ route('admin.reservations.create', ['date' => $from ?? now()->toDateString()]) }}"
               class="rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">+ Neu</a>
        @endif
    </div>
</div>

@php
    $presets = [
        'today' => 'Heute', 'yesterday' => 'Gestern', 'this_week' => 'Diese Woche',
        'last_week' => 'Letzte Woche', 'this_month' => 'Dieser Monat', 'last_month' => 'Letzter Monat',
        'last_7_days' => 'Letzte 7 Tage', 'last_30_days' => 'Letzte 30 Tage', 'all' => 'Alle',
    ];
    // Aktive Filter für das Banner und den Leerzustand der Liste.
    $activeFilters = [
        'Zeitraum' => request('q') ? null : ($preset === 'all' ? null : $rangeLabel),
        'Status' => request('status') ? __('reservations.status.' . request('status')) : null,
        'Quelle' => request('source') ? __('reservations.source.' . request('source')) : null,
        'Raum' => request('room_id') ? optional($rooms->firstWhere('id', request('room_id')))->name : null,
        'Suche' => request('q'),
    ];
    $hasActiveFilters = array_filter($activeFilters, fn ($v) => $v !== null && $v !== '' && $v !== []) !== [];
@endphp

{{-- Banner der aktiven Filter mit „Filter löschen“ --}}
<x-active-filters :reset="route('admin.reservations.index')" :filters="$activeFilters" />

<form method="GET" class="mb-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100">
    {{-- Zeitbereich: Schnellauswahl oder freier Bereich --}}
    <div class="mb-3">
        <p class="mb-1.5 text-xs font-semibold text-stone-500">Zeitraum
            <span class="tip" tabindex="0" data-tip="Bestimmt, welche Tage in der Liste erscheinen. Die Schnellauswahl deckt die üblichen Fälle ab; mit „Von/Bis“ wählst du einen beliebigen Bereich. Die Auswahl gilt auch für den Export.">?</span></p>
        <div class="flex flex-wrap items-end gap-2">
            <div class="flex flex-wrap gap-1.5">
                @foreach($presets as $key => $label)
                    <button type="submit" name="range" value="{{ $key }}" @if($preset === $key) aria-current="true" @endif
                            class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition
                                {{ $preset === $key ? 'bg-stone-900 text-white ring-stone-900' : 'bg-white text-stone-600 ring-stone-200 hover:ring-stone-400' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <div class="flex flex-wrap items-end gap-1.5">
                <div>
                    <label for="filterFrom" class="mb-0.5 block text-xs text-stone-600">Von</label>
                    <input type="date" name="from" id="filterFrom" value="{{ $from }}" class="rounded-lg border-stone-200 text-sm">
                </div>
                <span class="pb-2 text-stone-500" aria-hidden="true">–</span>
                <div>
                    <label for="filterTo" class="mb-0.5 block text-xs text-stone-600">Bis</label>
                    <input type="date" name="to" id="filterTo" value="{{ $to }}" class="rounded-lg border-stone-200 text-sm">
                </div>
                <button type="submit" name="range" value="custom"
                        class="rounded-lg bg-stone-200 px-3 py-2 text-xs font-semibold hover:bg-stone-300">Anwenden</button>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3">
    <div>
        <div class="mb-1 flex items-center">
            <label for="filterStatus" class="text-xs font-semibold text-stone-500">Status</label>
            <span class="tip" tabindex="0" data-tip="Zeigt nur Reservierungen mit dem gewählten Bearbeitungsstand – z. B. nur offene Anfragen, die noch auf deine Bestätigung warten, oder nur No-Shows.">?</span>
        </div>
        <select name="status" id="filterStatus" class="rounded-lg border-stone-200 text-sm" data-autosubmit>
            <option value="">Alle</option>
            @foreach($statuses as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ __('reservations.status.' . $s->value) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <div class="mb-1 flex items-center">
            <label for="filterSource" class="text-xs font-semibold text-stone-500">Quelle</label>
            <span class="tip" tabindex="0" data-tip="Woher die Buchung kam: über deine Buchungsseite (online), telefonisch von euch eingetragen, als Laufgast oder über die Warteliste.">?</span>
        </div>
        <select name="source" id="filterSource" class="rounded-lg border-stone-200 text-sm" data-autosubmit>
            <option value="">Alle</option>
            @foreach(['online', 'manual', 'phone', 'walk_in', 'api'] as $src)
                <option value="{{ $src }}" @selected(request('source') === $src)>{{ __('reservations.source.' . $src) }}</option>
            @endforeach
        </select>
    </div>
    @if($rooms->isNotEmpty())
        <div>
            <div class="mb-1 flex items-center">
                <label for="filterRoom" class="text-xs font-semibold text-stone-500">Raum</label>
                <span class="tip" tabindex="0" data-tip="Zeigt nur Reservierungen an Tischen im gewählten Raum – praktisch, wenn Terrasse und Innenbereich getrennt betreut werden.">?</span>
            </div>
            <select name="room_id" id="filterRoom" class="rounded-lg border-stone-200 text-sm" data-autosubmit>
                <option value="">Alle</option>
                @foreach($rooms as $room)
                    <option value="{{ $room->id }}" @selected(request('room_id') == $room->id)>{{ $room->name }}</option>
                @endforeach
            </select>
        </div>
    @endif
    <div class="grow">
        <div class="mb-1 flex items-center">
            <label for="filterSearch" class="text-xs font-semibold text-stone-500">Suche (Name, Tel., E-Mail, Nr.)</label>
            <span class="tip" tabindex="0" data-tip="Findet einen Gast über Namen, Telefonnummer, E-Mail-Adresse oder die Buchungsnummer. Ideal, wenn jemand anruft: einfach Name oder Nummer eintippen.">?</span>
        </div>
        <input type="search" name="q" id="filterSearch" value="{{ request('q') }}" placeholder="Suchen…" class="w-full rounded-lg border-stone-200 text-sm">
    </div>
    <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Filtern</button>
    </div>
</form>

@if($timeline)
    @include('admin.reservations._timeline')
@else

{{-- Bulk action bar: appears when rows are selected. The checkboxes live in
     the table but belong to this form via the HTML form attribute (row action
     forms would otherwise nest). --}}
<form id="bulkForm" method="POST" action="{{ route('admin.reservations.bulk-transition') }}"
      data-confirm="Die Aktion wird auf alle ausgewählten Reservierungen angewendet. Nicht erlaubte Statuswechsel werden übersprungen."
      data-confirm-title="Sammelaktion ausführen?" data-confirm-ok="Ausführen">
    @csrf
    <div id="bulkBar" class="mb-4 hidden flex-wrap items-center gap-3 rounded-2xl border border-teal-200 bg-teal-50 px-4 py-3 text-sm">
        <span class="font-semibold text-teal-900" role="status"><span id="bulkCount">0</span> ausgewählt</span>
        <span class="tip tip-right" tabindex="0" data-tip="Wendet denselben Schritt auf alle angehakten Reservierungen an – spart das einzelne Durchklicken. Schritte, die bei einer Buchung gerade nicht möglich sind, werden dabei übersprungen.">?</span>
        <select name="status" aria-label="Schritt für die ausgewählten Reservierungen" class="rounded-lg border-teal-200 text-sm">
            <option value="confirmed">Bestätigen</option>
            <option value="no_show">No-Show</option>
            <option value="completed">Auschecken</option>
            <option value="cancelled_by_restaurant">Stornieren (Restaurant)</option>
        </select>
        <button class="rounded-lg bg-stone-900 px-4 py-1.5 text-xs font-bold text-white">Anwenden</button>
        <button type="button" id="bulkClear" class="inline-flex min-h-8 items-center rounded px-2 text-xs font-semibold text-teal-700 hover:underline">Auswahl aufheben</button>
    </div>
</form>

@if($reservations->isNotEmpty())
    <label class="mb-2 flex min-h-11 items-center gap-3 px-1 text-sm font-semibold md:hidden">
        <input type="checkbox" class="bulk-all rounded" aria-controls="reservationList"> Alle auswählen
    </label>
@endif
<div class="rounded-2xl bg-white shadow-sm ring-1 ring-stone-100 md:overflow-x-auto">
    {{-- Unter 768 px wird aus der Tabelle eine Liste mit zwei Zeilen je Reservierung. Die Rollen halten die Tabellensemantik, die display: block und grid sonst in manchen Screenreadern verlieren. --}}
    <table id="reservationList" role="table" class="w-full text-sm max-md:block md:min-w-[42rem]">
        <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500 max-md:hidden">
            <tr>
                <th class="w-8 px-3 py-3"><input type="checkbox" class="bulk-all rounded" aria-label="Alle auswählen"><span class="tip" tabindex="0" data-tip="Hakt alle Reservierungen der Liste an. Oben erscheint dann eine Leiste, mit der du für alle zusammen denselben Schritt ausführen kannst.">?</span></th>
                <th class="px-4 py-3">Wann<span class="tip" tabindex="0" data-tip="Tag und Uhrzeit des Besuchs. Für die nächsten Tage steht „Heute“ oder „Morgen“ da, damit sie sofort ins Auge fallen.">?</span></th>
                <th class="px-4 py-3">Gast</th>
                <th class="px-4 py-3">Pers.<span class="tip" tabindex="0" data-tip="Personen – für wie viele Gäste der Tisch reserviert ist.">?</span></th>
                <th class="px-4 py-3">Tisch</th>
                <th class="px-4 py-3">Status<span class="tip" tabindex="0" data-tip="Der Bearbeitungsstand: Anfrage wartet auf deine Bestätigung · Bestätigt ist zugesagt · Da sitzt gerade am Tisch · Abgeschlossen ist gegangen · No-Show ist nicht erschienen.">?</span></th>
                <th class="px-4 py-3">Quelle<span class="tip" tabindex="0" data-tip="Woher die Buchung kam – über deine Buchungsseite, von euch selbst eingetragen, als Laufgast oder aus der Warteliste.">?</span></th>
                <th class="px-4 py-3">Gebucht<span class="tip" tabindex="0" data-tip="Wann die Reservierung bei euch eingegangen ist. Hilft bei Rückfragen und zeigt, wie kurzfristig gebucht wurde.">?</span></th>
                <th class="px-4 py-3">Aktionen<span class="tip tip-left" tabindex="0" data-tip="Bringt die Reservierung einen Schritt weiter. „Bestätigen“ sagt dem Gast per E-Mail zu, „Ablehnen“ sagt ab. „Angekommen“ markiert, dass die Gäste da sind. „No-Show“ hält fest, dass niemand kam; das zählt beim Gast mit und kann eine Anzahlung einbehalten. „Auschecken“ gibt den Tisch wieder frei.">?</span></th>
            </tr>
        </thead>
        <tbody role="rowgroup" class="divide-y divide-stone-50 max-md:block max-md:divide-stone-200 [&>tr:hover]:bg-stone-50/70">
            @forelse($reservations as $r)
                @php
                    $tableNames = $r->tables->pluck('name')->implode(', ');
                    // Dieselben Stati wie in der Aktionsliste dieser Zeile: Ohne Aktion entfällt die zweite Zeile am Smartphone.
                    $hasActions = in_array($r->status->value, ['requested', 'confirmed', 'seated'], true);
                @endphp
                {{-- Unter 768 px: Raster aus Auswahl, Gast mit Kurzzeile, Status und darunter den Aktionen. --}}
                <tr role="row" class="hover:bg-stone-50 max-md:grid max-md:grid-cols-[auto_minmax(0,1fr)_auto] max-md:items-start max-md:gap-x-3 max-md:gap-y-2 max-md:px-3 max-md:py-3">
                    <td class="px-3 py-3 {{ $hasActions ? 'max-md:row-span-2 ' : '' }}max-md:-mt-1 max-md:p-0" role="cell"><label class="flex h-8 w-8 items-center justify-center"><input type="checkbox" form="bulkForm" name="ids[]" value="{{ $r->id }}" class="bulk-check rounded" aria-label="{{ $r->guest_name_snapshot }} auswählen"></label></td>
                    <td class="whitespace-nowrap px-4 py-3 max-md:hidden" role="cell">
                        <div class="text-xs font-medium text-stone-500">{{ $r->localDayLabel() }}</div>
                        <div class="font-semibold">{{ $r->localStart()->format('H:i') }}<span class="font-normal text-stone-400">–{{ $r->localEnd()->format('H:i') }}</span></div>
                    </td>
                    <td class="min-w-0 px-4 py-3 max-md:p-0" role="cell">
                        <a href="{{ route('admin.reservations.show', $r) }}" class="font-semibold hover:underline max-md:inline-block max-md:py-1 [overflow-wrap:anywhere]">{{ $r->guest_name_snapshot }}</a>
                        @if($r->guest?->is_vip)<span title="VIP"><span aria-hidden="true">⭐</span><span class="sr-only">VIP</span></span>@endif
                        {{-- Der Allergietext steht je Breite genau einmal im Zugriffsbaum: ab 768 px als sr-only-Span, darunter als sichtbare Zeile (das jeweils andere ist per display: none ausgeblendet). --}}
                        @if($r->allergy_note)<span title="Allergien: {{ $r->allergy_note }}"><span aria-hidden="true">⚠️</span><span class="sr-only max-md:hidden">Allergien: {{ $r->allergy_note }}</span></span>@endif
                        @if($r->no_show_risk >= 50)<span class="rounded bg-red-100 px-1.5 text-xs text-red-700">Risiko</span>@endif
                        @if($r->allergy_note)<p class="line-clamp-2 text-[0.8125rem] font-semibold text-red-700 md:hidden [overflow-wrap:anywhere]">Allergien: {{ $r->allergy_note }}</p>@endif
                        <p class="text-[0.8125rem] text-stone-600 md:hidden">{{ $r->localDayLabel() }} {{ $r->localStart()->format('H:i') }}–{{ $r->localEnd()->format('H:i') }} · {{ $r->party_size }} Pers.{{ $tableNames !== '' ? ' · '.$tableNames : '' }} · {{ __('reservations.source.' . $r->source) }}</p>
                    </td>
                    <td class="px-4 py-3 max-md:hidden" role="cell">{{ $r->party_size }}</td>
                    <td class="px-4 py-3 max-md:hidden" role="cell">{{ $tableNames !== '' ? $tableNames : '–' }}</td>
                    <td class="px-4 py-3 max-md:p-0" role="cell"><x-status-badge :status="$r->status" /></td>
                    <td class="px-4 py-3 text-stone-500 max-md:hidden" role="cell">{{ __('reservations.source.' . $r->source) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-xs text-stone-500 max-md:hidden" role="cell">
                        @if($gebucht = $r->localCreatedAt())
                            <span title="{{ $gebucht->translatedFormat('l, d.m.Y H:i') }} Uhr">
                                {{ $gebucht->format('d.m.y') }}
                                <span class="text-stone-400">{{ $gebucht->format('H:i') }}</span>
                            </span>
                        @else
                            –
                        @endif
                    </td>
                    <td class="px-4 py-3 max-md:col-span-2 max-md:col-start-2 max-md:p-0{{ $hasActions ? '' : ' max-md:hidden' }}" role="cell">
                        <div class="flex flex-wrap gap-1.5 md:flex-nowrap">
                            @if($r->status->value === 'requested')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="confirmed">
                                    <button class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white max-md:min-h-10 max-md:px-3">Bestätigen</button>
                                </form>
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="rejected">
                                    <button class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700 max-md:min-h-10 max-md:px-3">Ablehnen</button>
                                </form>
                            @elseif($r->status->value === 'confirmed')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="seated">
                                    <button class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white max-md:min-h-10 max-md:px-3">Angekommen</button>
                                </form>
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="no_show">
                                    <button class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700 max-md:min-h-10 max-md:px-3">No-Show</button>
                                </form>
                            @elseif($r->status->value === 'seated')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}"
                                      data-confirm="Der Tisch wird freigegeben und die Reservierung abgeschlossen."
                                      data-confirm-title="Gäste auschecken?" data-confirm-ok="✓ Auschecken">@csrf
                                    <input type="hidden" name="status" value="completed">
                                    <button class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-bold text-white shadow-sm hover:bg-emerald-700 max-md:min-h-10">✓ Auschecken</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr role="row" class="max-md:block">
                    <td colspan="9" role="cell" class="px-4 py-8 text-center text-stone-600 max-md:block">
                        @if($hasActiveFilters)
                            <p>Mit den gewählten Filtern passt keine Reservierung.</p>
                            <div class="mt-3 flex justify-center">
                                <a href="{{ route('admin.reservations.index') }}" class="btn btn-secondary">Filter zurücksetzen</a>
                            </div>
                        @else
                            {{-- Ohne Filter ist das Buch leer; der einzige Schritt ist die erste Reservierung (nur mit dem Recht dazu). --}}
                            <p>Noch keine Reservierungen.</p>
                            @if($canCreate)
                                <div class="mt-3 flex justify-center">
                                    <a href="{{ route('admin.reservations.create') }}" class="btn btn-primary">+ Neu</a>
                                </div>
                            @endif
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $reservations->links() }}</div>

<script>
(function () {
    const bar = document.getElementById('bulkBar');
    const count = document.getElementById('bulkCount');
    const alls = [...document.querySelectorAll('.bulk-all')];
    const boxes = () => [...document.querySelectorAll('.bulk-check')];
    if (!bar || alls.length === 0) return;

    function sync() {
        const checked = boxes().filter(b => b.checked).length;
        count.textContent = checked;
        bar.classList.toggle('hidden', checked === 0);
        bar.classList.toggle('flex', checked > 0);
        alls.forEach(all => {
            all.checked = checked > 0 && checked === boxes().length;
            all.indeterminate = checked > 0 && checked < boxes().length;
        });
    }

    alls.forEach(all => all.addEventListener('change', () => { boxes().forEach(b => b.checked = all.checked); sync(); }));
    boxes().forEach(b => b.addEventListener('change', sync));
    document.getElementById('bulkClear').addEventListener('click', () => { boxes().forEach(b => b.checked = false); sync(); });
    // Nach „Zurück“ stellt der Browser angehakte Kästchen oft wieder her; Leiste und Zähler ziehen sofort nach.
    sync();
})();
</script>

@endif {{-- /list view --}}
@endsection
