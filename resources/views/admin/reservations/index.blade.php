@extends('layouts.admin')
@section('title', 'Reservierungen')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Reservierungsbuch</h1>
    <div class="flex gap-2">
        <a href="{{ route('admin.reservations.export', ['from' => $date, 'until' => $date]) }}"
           class="rounded-xl bg-stone-200 px-4 py-2.5 text-sm font-semibold hover:bg-stone-300">CSV-Export</a>
        <a href="{{ route('admin.reservations.create', ['date' => $date]) }}"
           class="rounded-xl bg-stone-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-stone-700">+ Neu</a>
    </div>
</div>

<form method="GET" class="mb-4 flex flex-wrap items-end gap-3 rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100">
    <div>
        <label class="mb-1 block text-xs font-semibold text-stone-500">Datum</label>
        <input type="date" name="date" value="{{ $date }}" class="rounded-lg border-stone-200 text-sm" onchange="this.form.submit()">
    </div>
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
</form>

<div class="overflow-x-auto rounded-2xl bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="border-b border-stone-100 text-left text-xs uppercase text-stone-500">
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
        <tbody class="divide-y divide-stone-50">
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
