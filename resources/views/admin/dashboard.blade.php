@extends('layouts.admin')
@section('title', 'Dashboard')
@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">{{ $location->name }} – Heute</h1>
    <a href="{{ route('admin.reservations.create') }}"
       class="rounded-xl bg-stone-900 px-5 py-2.5 font-semibold text-white hover:bg-stone-700">+ Neue Reservierung</a>
</div>

<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
    @foreach([
        ['Reservierungen heute', $stats['today_count'], '📖'],
        ['Gäste heute', $stats['today_covers'], '👥'],
        ['Aktuell am Tisch', $stats['seated_now'], '🪑'],
        ['Offene Anfragen', $stats['open_requests'], '🕐'],
        ['Morgen', $stats['tomorrow_count'], '📅'],
        ['Warteliste', $stats['waitlist_waiting'], '⏳'],
        ['Gäste diese Woche', $stats['week_covers'], '📈'],
        ['No-Shows (7 Tage)', $stats['no_shows_week'], '🚫'],
    ] as [$label, $value, $icon])
        <div class="rounded-2xl bg-white p-4 shadow-sm">
            <div class="text-sm text-stone-500">{{ $icon }} {{ $label }}</div>
            <div class="mt-1 text-3xl font-bold">{{ $value }}</div>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Nächste Ankünfte</h2>
        <div class="mt-3 divide-y divide-stone-100">
            @forelse($upcoming as $r)
                <a href="{{ route('admin.reservations.show', $r) }}" class="flex items-center justify-between py-2.5 hover:bg-stone-50">
                    <div>
                        <span class="font-semibold">{{ $r->localStart()->format('H:i') }}</span>
                        <span class="ml-2">{{ $r->guest_name_snapshot }}</span>
                        <span class="ml-2 text-sm text-stone-500">{{ $r->party_size }} P.</span>
                    </div>
                    <span class="text-sm text-stone-500">{{ $r->tables->pluck('name')->implode(', ') ?: '–' }}</span>
                </a>
            @empty
                <p class="py-3 text-sm text-stone-500">Keine weiteren Ankünfte heute.</p>
            @endforelse
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Überfällige Gäste</h2>
        <div class="mt-3 divide-y divide-stone-100">
            @forelse($overdue as $r)
                <div class="flex items-center justify-between py-2.5">
                    <div>
                        <span class="font-semibold text-amber-700">{{ $r->localStart()->format('H:i') }}</span>
                        <span class="ml-2">{{ $r->guest_name_snapshot }}</span>
                        <span class="ml-2 text-sm text-stone-500">{{ $r->party_size }} P.</span>
                    </div>
                    <div class="flex gap-2">
                        <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">
                            @csrf
                            <input type="hidden" name="status" value="seated">
                            <button class="rounded-lg bg-emerald-600 px-3 py-1.5 text-sm font-semibold text-white">Da!</button>
                        </form>
                        <form method="POST" action="{{ route('admin.reservations.transition', $r) }}">
                            @csrf
                            <input type="hidden" name="status" value="no_show">
                            <button class="rounded-lg bg-red-100 px-3 py-1.5 text-sm font-semibold text-red-700">No-Show</button>
                        </form>
                    </div>
                </div>
            @empty
                <p class="py-3 text-sm text-stone-500">Keine überfälligen Gäste. 👍</p>
            @endforelse
        </div>
    </div>
</div>

<div class="mt-6 rounded-2xl bg-white p-5 shadow-sm">
    <h2 class="font-bold">Reservierungsquellen (30 Tage)</h2>
    <div class="mt-3 flex flex-wrap gap-4 text-sm">
        @forelse($sources as $source => $count)
            <span class="rounded-full bg-stone-100 px-4 py-1.5">{{ __('reservations.source.' . $source) }}: <strong>{{ $count }}</strong></span>
        @empty
            <span class="text-stone-500">Noch keine Daten.</span>
        @endforelse
    </div>
</div>
@endsection
