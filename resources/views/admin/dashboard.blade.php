@extends('layouts.admin')
@section('title', 'Dashboard')
@section('content')
<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">{{ $location->name }} – Heute</h1>
    <a href="{{ route('admin.reservations.create') }}"
       class="rounded-xl bg-stone-900 px-5 py-2.5 font-semibold text-white hover:bg-stone-700">+ Neue Reservierung</a>
</div>

{{-- Live notification toast --}}
<div id="dbToast"
     class="pointer-events-none fixed bottom-6 right-6 z-50 hidden max-w-sm translate-y-2 rounded-xl px-5 py-3 text-sm font-semibold shadow-xl transition-all duration-300 opacity-0"
     role="alert" aria-live="polite"></div>

@php
    $today = now($location->timezone)->toDateString();
    $tomorrow = now($location->timezone)->addDay()->toDateString();
@endphp

<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
    @foreach([
        ['Reservierungen heute', $stats['today_count'], '📖', 'bg-teal-50', route('admin.reservations.index', ['date' => $today]), 'today_count'],
        ['Gäste heute', $stats['today_covers'], '👥', 'bg-sky-50', route('admin.reservations.index', ['date' => $today]), 'today_covers'],
        ['Aktuell am Tisch', $stats['seated_now'], '🪑', 'bg-emerald-50', route('admin.floorplan.index'), 'seated_now'],
        ['Offene Anfragen', $stats['open_requests'], '🕐', 'bg-amber-50', route('admin.reservations.index', ['status' => 'requested']), 'open_requests'],
        ['Morgen', $stats['tomorrow_count'], '📅', 'bg-stone-100', route('admin.reservations.index', ['date' => $tomorrow]), 'tomorrow_count'],
        ['Warteliste', $stats['waitlist_waiting'], '⏳', 'bg-stone-100', route('admin.waitlist.index'), 'waitlist_waiting'],
        ['Gäste diese Woche', $stats['week_covers'], '📈', 'bg-stone-100', route('admin.reservations.index'), 'week_covers'],
        ['No-Shows (7 Tage)', $stats['no_shows_week'], '🚫', 'bg-red-50', route('admin.reservations.index', ['status' => 'no_show']), 'no_shows_week'],
    ] as [$label, $value, $icon, $chip, $href, $key])
        <a href="{{ $href }}"
           class="rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100 transition hover:-translate-y-0.5 hover:shadow-md hover:ring-stone-200 block">
            <div class="flex items-center gap-2">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg {{ $chip }} text-base">{{ $icon }}</span>
                <span class="text-xs font-medium uppercase tracking-wide text-stone-500">{{ $label }}</span>
            </div>
            <div class="mt-2 text-3xl font-extrabold tabular-nums" data-stat="{{ $key }}">{{ $value }}</div>
        </a>
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="flex items-center gap-2 font-bold"><span>⏭️</span> Nächste Ankünfte</h2>
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

    <div class="rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="flex items-center gap-2 font-bold"><span>⏰</span> Überfällige Gäste</h2>
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

<div class="mt-6 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
    <h2 class="flex items-center gap-2 font-bold"><span>📊</span> Reservierungsquellen (30 Tage)</h2>
    <div class="mt-3 flex flex-wrap gap-4 text-sm">
        @forelse($sources as $source => $count)
            <span class="rounded-full bg-stone-100 px-4 py-1.5">{{ __('reservations.source.' . $source) }}: <strong>{{ $count }}</strong></span>
        @empty
            <span class="text-stone-500">Noch keine Daten.</span>
        @endforelse
    </div>
</div>

<script>
(function () {
    const statsUrl = @json(route('admin.dashboard.stats'));
    const toast = document.getElementById('dbToast');

    // Track last known values for change detection
    let prev = {
        today_count: @json($stats['today_count']),
        open_requests: @json($stats['open_requests']),
        last_created_at: null,
    };

    function showToast(msg, isErr) {
        toast.textContent = msg;
        toast.className = toast.className
            .replace(/bg-\S+|text-\S+/g, '')
            .replace(/\s+/g, ' ').trim();
        toast.classList.add(
            ...(isErr ? ['bg-red-900', 'text-white'] : ['bg-stone-900', 'text-white'])
        );
        toast.classList.remove('hidden', 'opacity-0', 'translate-y-2');
        toast.classList.add('opacity-100', 'translate-y-0');
        clearTimeout(toast._t);
        toast._t = setTimeout(() => {
            toast.classList.remove('opacity-100', 'translate-y-0');
            toast.classList.add('opacity-0', 'translate-y-2');
            setTimeout(() => toast.classList.add('hidden'), 350);
        }, 4500);
    }

    async function pollStats() {
        try {
            const res = await fetch(statsUrl, { headers: { Accept: 'application/json' } });
            if (!res.ok) return;
            const data = await res.json();

            // Update KPI numbers
            document.querySelectorAll('[data-stat]').forEach(el => {
                const key = el.dataset.stat;
                if (data[key] !== undefined) el.textContent = data[key];
            });

            // Detect new booking
            const newBooking = data.today_count > prev.today_count;
            const newRequest = data.open_requests > prev.open_requests;

            if (newBooking || newRequest) {
                const msgs = [];
                if (newBooking) msgs.push(`${data.today_count - prev.today_count} neue Buchung${data.today_count - prev.today_count > 1 ? 'en' : ''} heute`);
                if (newRequest) msgs.push(`${data.open_requests - prev.open_requests} neue Anfrage${data.open_requests - prev.open_requests > 1 ? 'n' : ''}`);
                showToast('🔔 ' + msgs.join(' · '));
                // brief pulse on affected cards
                document.querySelectorAll('[data-stat="today_count"],[data-stat="open_requests"]').forEach(el => {
                    el.closest('a')?.animate([
                        { boxShadow: '0 0 0 4px rgba(20,184,166,.4)' },
                        { boxShadow: '0 0 0 0px rgba(20,184,166,0)' },
                    ], { duration: 1200, easing: 'ease-out' });
                });
            }

            prev = { today_count: data.today_count, open_requests: data.open_requests, last_created_at: data.last_created_at };
        } catch (_) {}
    }

    setInterval(pollStats, 30_000);
})();
</script>
@endsection
