@extends('layouts.admin')
@section('title', 'Terminplan')
@section('content')

<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-bold">Terminplan</h1>
        <p class="text-sm text-stone-500">{{ $date->translatedFormat('l, d.m.Y') }} · {{ $location->name }}</p>
    </div>
    <form method="GET" class="flex flex-wrap items-center gap-2 text-sm">
        <a href="{{ route('admin.staff.calendar', ['date' => $prev]) }}"
           class="rounded-lg bg-stone-100 px-3 py-2 font-semibold hover:bg-stone-200">←</a>
        <a href="{{ route('admin.staff.calendar', ['date' => $today]) }}"
           class="rounded-lg bg-stone-100 px-3 py-2 font-semibold hover:bg-stone-200">Heute</a>
        <a href="{{ route('admin.staff.calendar', ['date' => $next]) }}"
           class="rounded-lg bg-stone-100 px-3 py-2 font-semibold hover:bg-stone-200">→</a>
        <input type="date" name="date" value="{{ $date->toDateString() }}"
               onchange="this.form.submit()" class="rounded-lg border-stone-200 text-sm">
    </form>
</div>

@if(count($columns) === 0)
    <div class="rounded-2xl bg-amber-50 p-5 text-sm text-amber-900">
        Noch keine aktiven Mitarbeiter – lege sie unter
        <a href="{{ route('admin.staff.index') }}" class="font-semibold underline">Mitarbeiter</a> an.
    </div>
@else
<div class="overflow-x-auto rounded-2xl bg-white p-4 shadow-sm ring-1 ring-stone-100">
    <div class="flex min-w-max gap-2">
        {{-- Time axis --}}
        <div class="relative w-14 shrink-0" style="height: {{ $height }}px; margin-top: 34px;">
            @foreach($hours as $mark)
                <div class="absolute right-1 -translate-y-1/2 text-xs text-stone-400" style="top: {{ $mark['top'] }}px;">
                    {{ $mark['label'] }}
                </div>
            @endforeach
        </div>

        @foreach($columns as $column)
            <div class="w-52 shrink-0">
                <div class="mb-1.5 flex h-7 items-center gap-1.5 truncate text-sm font-bold">
                    <span class="inline-block h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $column['color'] }}"></span>
                    <span class="truncate">{{ $column['name'] }}</span>
                </div>
                <div class="relative rounded-xl bg-stone-100" style="height: {{ $height }}px;">
                    {{-- Working windows sit on top of the grey background --}}
                    @foreach($column['windows'] as $window)
                        <div class="absolute inset-x-0 rounded-lg bg-white ring-1 ring-stone-200"
                             style="top: {{ $window['top'] }}px; height: {{ $window['height'] }}px;"></div>
                    @endforeach

                    {{-- Hour lines --}}
                    @foreach($hours as $mark)
                        <div class="absolute inset-x-0 border-t border-stone-200/70" style="top: {{ $mark['top'] }}px;"></div>
                    @endforeach

                    {{-- Absences --}}
                    @foreach($column['absences'] as $absence)
                        <div class="absolute inset-x-0 rounded-lg bg-stone-300/70 px-2 py-1 text-[11px] font-semibold text-stone-700"
                             style="top: {{ $absence['top'] }}px; height: {{ $absence['height'] }}px;"
                             title="{{ $absence['reason'] ?: 'Abwesend' }}">
                            {{ $absence['reason'] ?: 'Abwesend' }}
                        </div>
                    @endforeach

                    {{-- Appointments --}}
                    @foreach($column['appointments'] as $appointment)
                        <a href="{{ route('admin.reservations.show', $appointment['id']) }}"
                           class="absolute inset-x-1 overflow-hidden rounded-lg px-2 py-1 text-[11px] leading-tight text-white shadow-sm hover:opacity-90
                                  {{ $appointment['status'] === 'requested' ? 'bg-amber-500' : ($appointment['status'] === 'seated' ? 'bg-emerald-600' : 'bg-teal-700') }}"
                           style="top: {{ $appointment['top'] }}px; height: {{ $appointment['height'] }}px;">
                            <span class="font-bold">{{ $appointment['time'] }}</span>
                            @if($appointment['is_regular'])⭐@endif
                            <span class="block truncate">{{ $appointment['name'] }}</span>
                            @if($appointment['services'])
                                <span class="block truncate opacity-80">{{ $appointment['services'] }}</span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>

<div class="mt-3 flex flex-wrap gap-4 text-xs text-stone-500">
    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-teal-700"></span>bestätigt</span>
    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-amber-500"></span>angefragt</span>
    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-emerald-600"></span>im Haus</span>
    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-stone-300"></span>abwesend</span>
    <span><span class="mr-1 inline-block h-2.5 w-2.5 rounded-sm bg-stone-100 ring-1 ring-stone-300"></span>außerhalb der Arbeitszeit</span>
</div>
@endif

@if(count($unassigned) > 0)
    <div class="mt-5 rounded-2xl bg-white p-5 shadow-sm ring-1 ring-stone-100">
        <h2 class="mb-1 font-bold">Ohne Mitarbeiter<span class="tip" tabindex="0" data-tip="Diese Termine hängen an keinem Mitarbeiter – etwa weil sie vor der Umstellung auf den Salon-Betrieb angelegt wurden. Öffne den Termin und weise jemanden zu.">?</span></h2>
        <p class="mb-3 text-xs text-stone-500">Diese Termine tauchen in keiner Spalte auf.</p>
        <div class="space-y-2 text-sm">
            @foreach($unassigned as $appointment)
                <a href="{{ route('admin.reservations.show', $appointment['id']) }}"
                   class="flex items-center justify-between rounded-lg bg-stone-50 px-3 py-2 hover:bg-stone-100">
                    <span><strong>{{ $appointment['time'] }}</strong> · {{ $appointment['name'] }}</span>
                    <span class="text-xs text-stone-500">{{ $appointment['services'] ?: $appointment['party_size'].' P.' }}</span>
                </a>
            @endforeach
        </div>
    </div>
@endif
@endsection
