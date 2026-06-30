@extends('layouts.admin')
@section('title', 'Reservierungen')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Reservierungsbuch</h1>
    <div class="flex gap-2">
        <div class="relative">
            <button id="exportBtn"
                    class="rounded-xl bg-stone-200 px-4 py-2.5 text-sm font-semibold hover:bg-stone-300">↓ Export</button>
            <div id="exportDrop"
                 class="absolute right-0 top-full z-20 mt-1 hidden w-72 rounded-2xl bg-white p-4 shadow-xl ring-1 ring-stone-100">
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
        <a href="{{ route('admin.reservations.create', ['date' => $from ?? now()->toDateString()]) }}"
           class="rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">+ Neu</a>
    </div>
</div>

@php
    $presets = [
        'today' => 'Heute', 'yesterday' => 'Gestern', 'this_week' => 'Diese Woche',
        'last_week' => 'Letzte Woche', 'this_month' => 'Dieser Monat', 'last_month' => 'Letzter Monat',
        'last_7_days' => 'Letzte 7 Tage', 'last_30_days' => 'Letzte 30 Tage', 'all' => 'Alle',
    ];
@endphp

{{-- Aktiver-Filter-Banner + „Filter löschen" --}}
<x-active-filters :reset="route('admin.reservations.index')" :filters="[
    'Zeitraum' => request('q') ? null : ($preset === 'today' ? null : $rangeLabel),
    'Status'   => request('status') ? __('reservations.status.' . request('status')) : null,
    'Quelle'   => request('source') ? __('reservations.source.' . request('source')) : null,
    'Raum'     => request('room_id') ? optional($rooms->firstWhere('id', request('room_id')))->name : null,
    'Suche'    => request('q'),
]" />

<form method="GET" class="mb-4 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100">
    {{-- Kimai-style Zeitbereich --}}
    <div class="mb-3">
        <label class="mb-1.5 block text-xs font-semibold text-stone-500">Zeitraum</label>
        <div class="flex flex-wrap items-end gap-2">
            <div class="flex flex-wrap gap-1.5">
                @foreach($presets as $key => $label)
                    <button type="submit" name="range" value="{{ $key }}"
                            class="rounded-full px-3 py-1.5 text-xs font-semibold ring-1 transition
                                {{ $preset === $key ? 'bg-stone-900 text-white ring-stone-900' : 'bg-white text-stone-600 ring-stone-200 hover:ring-stone-400' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </div>
            <div class="flex items-end gap-1.5">
                <div>
                    <label class="mb-0.5 block text-[10px] text-stone-400">Von</label>
                    <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-stone-200 text-sm">
                </div>
                <span class="pb-2 text-stone-400">–</span>
                <div>
                    <label class="mb-0.5 block text-[10px] text-stone-400">Bis</label>
                    <input type="date" name="to" value="{{ $to }}" class="rounded-lg border-stone-200 text-sm">
                </div>
                <button type="submit" name="range" value="custom"
                        class="rounded-lg bg-stone-200 px-3 py-2 text-xs font-semibold hover:bg-stone-300">Anwenden</button>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3">
    <div>
        <label class="mb-1 block text-xs font-semibold text-stone-500">Status</label>
        <select name="status" class="rounded-lg border-stone-200 text-sm" onchange="this.form.submit()">
            <option value="">Alle</option>
            @foreach($statuses as $s)
                <option value="{{ $s->value }}" @selected(request('status') === $s->value)>{{ __('reservations.status.' . $s->value) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-semibold text-stone-500">Quelle</label>
        <select name="source" class="rounded-lg border-stone-200 text-sm" onchange="this.form.submit()">
            <option value="">Alle</option>
            @foreach(['online', 'manual', 'phone', 'walk_in', 'api'] as $src)
                <option value="{{ $src }}" @selected(request('source') === $src)>{{ __('reservations.source.' . $src) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="mb-1 block text-xs font-semibold text-stone-500">Raum</label>
        <select name="room_id" class="rounded-lg border-stone-200 text-sm" onchange="this.form.submit()">
            <option value="">Alle</option>
            @foreach($rooms as $room)
                <option value="{{ $room->id }}" @selected(request('room_id') == $room->id)>{{ $room->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="grow">
        <label class="mb-1 block text-xs font-semibold text-stone-500">Suche (Name, Tel., E-Mail, Nr.)</label>
        <input type="search" name="q" value="{{ request('q') }}" placeholder="Suchen…" class="w-full rounded-lg border-stone-200 text-sm">
    </div>
    <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Filtern</button>
    </div>
</form>

<div class="overflow-x-auto rounded-2xl bg-white shadow-sm ring-1 ring-stone-100">
    <table class="w-full min-w-[42rem] text-sm">
        <thead class="border-b border-stone-100 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
            <tr>
                <th class="px-4 py-3">Zeit</th>
                <th class="px-4 py-3">Gast</th>
                <th class="px-4 py-3">P.</th>
                <th class="px-4 py-3">Tisch</th>
                <th class="px-4 py-3">Status</th>
                <th class="px-4 py-3">Quelle</th>
                <th class="px-4 py-3">Aktionen</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-50 [&>tr:hover]:bg-stone-50/70">
            @forelse($reservations as $r)
                <tr class="hover:bg-stone-50">
                    <td class="px-4 py-3 font-semibold">{{ $r->localStart()->format('H:i') }}<span class="font-normal text-stone-400">–{{ $r->localEnd()->format('H:i') }}</span></td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.reservations.show', $r) }}" class="font-semibold hover:underline">{{ $r->guest_name_snapshot }}</a>
                        @if($r->guest?->is_vip)<span title="VIP">⭐</span>@endif
                        @if($r->allergy_note)<span title="Allergien: {{ $r->allergy_note }}">⚠️</span>@endif
                        @if($r->no_show_risk >= 50)<span class="rounded bg-red-100 px-1.5 text-xs text-red-700">Risiko</span>@endif
                    </td>
                    <td class="px-4 py-3">{{ $r->party_size }}</td>
                    <td class="px-4 py-3">{{ $r->tables->pluck('name')->implode(', ') ?: '–' }}</td>
                    <td class="px-4 py-3"><x-status-badge :status="$r->status" /></td>
                    <td class="px-4 py-3 text-stone-500">{{ __('reservations.source.' . $r->source) }}</td>
                    <td class="px-4 py-3">
                        <div class="flex gap-1.5">
                            @if($r->status->value === 'requested')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="confirmed">
                                    <button class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">Bestätigen</button>
                                </form>
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="rejected">
                                    <button class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">Ablehnen</button>
                                </form>
                            @elseif($r->status->value === 'confirmed')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="seated">
                                    <button class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white">Angekommen</button>
                                </form>
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="no_show">
                                    <button class="rounded-lg bg-red-100 px-2.5 py-1 text-xs font-semibold text-red-700">No-Show</button>
                                </form>
                            @elseif($r->status->value === 'seated')
                                <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">@csrf
                                    <input type="hidden" name="status" value="completed">
                                    <button class="rounded-lg bg-stone-200 px-2.5 py-1 text-xs font-semibold">Gegangen</button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-stone-500">Keine Reservierungen gefunden.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $reservations->links() }}</div>
@endsection
