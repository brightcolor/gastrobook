@extends('layouts.admin')
@section('title', 'Walk-ins')
@section('content')
<h1 class="mb-5 text-2xl font-bold">Walk-ins</h1>

<div class="grid gap-6 lg:grid-cols-2">
    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Gast platzieren</h2>
        <form method="GET" class="mt-3 flex items-end gap-2">
            <div>
                <label class="mb-1 block text-xs font-semibold text-stone-500">Personen</label>
                <input type="number" name="party_size" min="1" max="50" value="{{ $partySize }}" class="w-24 rounded-lg border-stone-200">
            </div>
            <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Freie Tische zeigen</button>
        </form>

        <div class="mt-4 space-y-2">
            @forelse($freeTables as $table)
                <form method="POST" action="{{ route('admin.walkins.store') }}" class="flex items-center justify-between gap-2 rounded-xl border border-stone-100 p-3">
                    @csrf
                    <input type="hidden" name="party_size" value="{{ $partySize }}">
                    <input type="hidden" name="table_id" value="{{ $table->id }}">
                    <div>
                        <strong>{{ $table->name }}</strong>
                        <span class="text-sm text-stone-500">{{ $table->room?->name }} · {{ $table->min_capacity }}–{{ $table->max_capacity }} P.</span>
                        @if($table->free_until)
                            <span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">frei bis {{ $table->free_until }}</span>
                        @else
                            <span class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 text-xs text-emerald-800">heute frei</span>
                        @endif
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="text" name="name" placeholder="Name (optional)" class="w-36 rounded-lg border-stone-200 text-sm">
                        <button class="rounded-lg bg-emerald-600 px-4 py-2 text-sm font-bold text-white"
                                @if($table->max_capacity < $partySize) disabled title="Zu klein" style="opacity:.4" @endif>Platzieren</button>
                    </div>
                </form>
            @empty
                <p class="text-sm text-stone-500">Aktuell sind keine Tische frei. Tipp: Gast auf die <a href="{{ route('admin.waitlist.index') }}" class="underline">Warteliste</a> setzen.</p>
            @endforelse
        </div>
    </div>

    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="font-bold">Heutige Walk-ins</h2>
        <div class="mt-3 divide-y divide-stone-100">
            @forelse($walkIns as $w)
                <div class="flex items-center justify-between py-2.5 text-sm">
                    <div>
                        <strong>{{ $w->guest_name_snapshot }}</strong> · {{ $w->party_size }} P. · {{ $w->tables->pluck('name')->implode(', ') }}
                        <span class="text-stone-400">seit {{ $w->seated_at?->setTimezone($location->timezone)->format('H:i') }}</span>
                    </div>
                    @if($w->status->value === 'seated')
                        <form method="POST" action="{{ route('admin.reservations.transition', $w) }}">
                            @csrf
                            <input type="hidden" name="status" value="completed">
                            <button class="rounded-lg bg-stone-200 px-3 py-1.5 text-xs font-semibold">Gegangen</button>
                        </form>
                    @else
                        <x-status-badge :status="$w->status" />
                    @endif
                </div>
            @empty
                <p class="py-3 text-sm text-stone-500">Noch keine Walk-ins heute.</p>
            @endforelse
        </div>
    </div>
</div>
@endsection
