@extends('layouts.admin')
@use('App\Support\ScheduleExceptionPeriod')
@section('title', 'Ausnahmen')

@section('content')
@php
    $guests = $salon ? 'Kunden' : 'Gäste';
    $pastCount = count($past);
    $hasEntries = $upcoming !== [] || $pastCount > 0;
    // Zustandssymbol je Wirkung; das Wort steht immer daneben (Zustände nie über Farbe allein).
    $states = ['closed' => 'error', 'hours' => 'ok', 'limit' => 'warn', 'lock' => 'warn'];
    $stateLabels = ['closed' => 'Geschlossen', 'hours' => 'Andere Öffnungszeiten', 'limit' => 'Weniger '.$guests, 'lock' => 'Gesperrt'];
    $description = $salon
        ? 'Alles, was an bestimmten Tagen anders ist: Feiertage, Betriebsferien oder besondere Öffnungszeiten.'
        : 'Alles, was an bestimmten Tagen anders ist: Feiertage, Betriebsferien, Veranstaltungen, gesperrte Räume oder Tische.';
    $emptyText = $salon
        ? 'Trag Feiertage, Betriebsferien oder Tage mit anderen Öffnungszeiten ein. Kunden sehen die Änderung sofort auf der Buchungsseite.'
        : 'Trag Feiertage, Betriebsferien oder Tage mit weniger Plätzen ein. Gäste sehen die Änderung sofort auf der Buchungsseite.';
@endphp
<x-page-header title="Ausnahmen" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location" :description="$description">
    <x-slot:actions>
        <button type="button" data-drawer-open="ausnahme-neu" class="btn btn-primary">Ausnahme anlegen</button>
    </x-slot:actions>
</x-page-header>

@if(! $hasEntries)
    <x-empty-state title="Noch keine Ausnahmen">
        {{ $emptyText }}
        <x-slot:action>
            <button type="button" data-drawer-open="ausnahme-neu" class="btn btn-primary">Ausnahme anlegen</button>
        </x-slot:action>
    </x-empty-state>
@else
    <section aria-labelledby="kommend-titel" class="space-y-6">
        <h2 id="kommend-titel" class="text-base font-semibold">Kommend</h2>
        @forelse($upcoming as $month => $entries)
            <div class="space-y-2">
                <h3 class="text-sm font-semibold text-stone-700">{{ $month }}</h3>
                <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
                    @foreach($entries as $entry)
                        @include('admin.settings.partials.exception-row', ['entry' => $entry, 'past' => false])
                    @endforeach
                </ul>
            </div>
        @empty
            <p class="text-sm text-stone-600">Keine kommenden Ausnahmen.</p>
        @endforelse
    </section>

    @if($pastCount > 0)
        <details class="mt-8 rounded-xl border border-stone-200 bg-white">
            <summary class="cursor-pointer rounded-xl px-4 py-3 text-sm font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--brand)">Vergangen ({{ $pastCount }})</summary>
            <ul class="divide-y divide-stone-200 border-t border-stone-200">
                @foreach($past as $entry)
                    @include('admin.settings.partials.exception-row', ['entry' => $entry, 'past' => true])
                @endforeach
            </ul>
        </details>
    @endif
@endif

@push('drawers')
    {{-- Nur die Seitenleiste zum Anlegen. Die zum Bearbeiten lädt drawer.js beim Öffnen (data-drawer-src in der Zeile). --}}
    @include('admin.settings.partials.exception-drawer', ['entry' => null])
@endpush
@endsection
