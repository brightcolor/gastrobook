@extends('layouts.admin')
@section('title', 'Berichte')
@section('content')
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Berichte – {{ $location->name }}</h1>
    <form method="GET" class="flex items-end gap-2">
        <input type="date" name="from" value="{{ $from }}" class="rounded-lg border-stone-200 text-sm">
        <input type="date" name="until" value="{{ $until }}" class="rounded-lg border-stone-200 text-sm">
        <button class="rounded-lg bg-stone-900 px-4 py-2 text-sm font-semibold text-white">Anzeigen</button>
    </form>
</div>

<div class="grid grid-cols-2 gap-3 md:grid-cols-5">
    @foreach([
        ['Reservierungen', $stats['total']],
        ['Gäste (Covers)', $stats['covers']],
        ['No-Show-Rate', $stats['no_show_rate'] . ' %'],
        ['Stornoquote', $stats['cancellation_rate'] . ' %'],
        ['Online-Anteil', $stats['online_share'] . ' %'],
        ['Walk-in-Anteil', $stats['walk_in_share'] . ' %'],
        ['Ø Gruppengröße', $stats['avg_party']],
        ['Abgeschlossen', $stats['completed']],
        ['Feedback-Antworten', $stats['feedback_count']],
        ['Ø Bewertung', $stats['feedback_avg'] !== null ? $stats['feedback_avg'] . ' / 5' : '–'],
    ] as [$label, $value])
        <div class="rounded-2xl bg-white p-4 shadow-sm">
            <div class="text-xs text-stone-500">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold">{{ $value }}</div>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 lg:grid-cols-2">
    <div class="rounded-2xl bg-white p-5 shadow-sm">
        <h2 class="mb-3 font-bold">Reservierungen pro Tag</h2>
        @php($max = max(1, $byDay->max('cnt')))
        <div class="space-y-1.5 text-xs">
            @foreach($byDay as $day)
                <div class="flex items-center gap-2">
                    <span class="w-16 shrink-0 text-stone-500">{{ \Carbon\Carbon::parse($day->reservation_date)->format('d.m.') }}</span>
                    <div class="h-4 rounded bg-teal-600" style="width: {{ round(100 * $day->cnt / $max) }}%"></div>
                    <span>{{ $day->cnt }} ({{ $day->covers }} G.)</span>
                </div>
            @endforeach
        </div>
    </div>

    <div class="space-y-6">
        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-bold">Auslastung nach Uhrzeit</h2>
            @php($maxH = max(1, $byHour->max()))
            <div class="space-y-1.5 text-xs">
                @foreach($byHour as $hour => $count)
                    <div class="flex items-center gap-2">
                        <span class="w-12 shrink-0 text-stone-500">{{ $hour }}</span>
                        <div class="h-4 rounded bg-amber-500" style="width: {{ round(100 * $count / $maxH) }}%"></div>
                        <span>{{ $count }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        <div class="rounded-2xl bg-white p-5 shadow-sm">
            <h2 class="mb-3 font-bold">Nach Quelle</h2>
            <div class="flex flex-wrap gap-3 text-sm">
                @foreach($bySource as $source => $count)
                    <span class="rounded-full bg-stone-100 px-4 py-1.5">{{ __('reservations.source.' . $source) }}: <strong>{{ $count }}</strong></span>
                @endforeach
            </div>
        </div>
    </div>
</div>
@endsection
