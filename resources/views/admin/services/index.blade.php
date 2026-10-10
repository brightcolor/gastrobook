@extends('layouts.admin')
@section('title', 'Leistungen')

@section('content')
@php
    $question = fn ($service): string => __('Leistung „:name“ löschen? Gäste können sie dann nicht mehr buchen. Vergangene Termine zeigen sie dann nicht mehr.', ['name' => $service->name]);
    // Dauer, Preis, wer die Leistung anbietet; eine pausierte Leistung nennt das dazu.
    $details = fn ($service): string => collect([
        $service->durationFormatted(),
        $service->priceFormatted(),
        $service->staff->isNotEmpty() ? $service->staff->pluck('name')->join(', ') : __('Noch niemandem zugeordnet'),
        $service->is_active ? null : __('Pausiert'),
    ])->filter()->implode(' · ');
    // Die Spalte fasst auch Werte, die das Farbfeld nicht kennt; nur #rrggbb gelangt in den Stil.
    $dot = fn ($service): ?string => preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $service->color) === 1 ? $service->color : null;
@endphp
<x-page-header title="Leistungen" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               description="Was Gäste buchen können, mit Dauer, Preis und den Personen, die es anbieten.">
    <x-slot:actions>
        <button type="button" data-drawer-open="leistung-neu" class="btn btn-primary">Leistung hinzufügen</button>
    </x-slot:actions>
</x-page-header>

@if($staff->isEmpty())
    <p role="note" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        Buchbar wird eine Leistung, sobald eine Person sie anbietet. Lege die Personen unter
        <a href="{{ route('admin.staff.index') }}" class="font-semibold underline underline-offset-2">Mitarbeiter</a> an.
    </p>
@endif

@if($services->isEmpty())
    <x-empty-state title="Noch keine Leistungen">
        Leistungen sind das, was Gäste buchen: etwa Haarschnitt, Färben oder Beratung.
        <x-slot:action>
            <button type="button" data-drawer-open="leistung-neu" class="btn btn-primary">Leistung hinzufügen</button>
        </x-slot:action>
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($services as $service)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                {{-- Das Wort „Pausiert“ steht sichtbar in der Zeile und dient als Vorlesetext; das Symbol trägt dann keinen eigenen. --}}
                <x-status-icon :state="$service->is_active ? 'ok' : 'off'" :label="$service->is_active ? 'Online buchbar' : 'Pausiert'" :announce="$service->is_active" />
                <div class="min-w-0 flex-1">
                    <button type="button" data-drawer-open="leistung-{{ $service->id }}"
                            class="flex items-center gap-2 text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">
                        @if($dot($service))
                            <span class="h-3 w-3 shrink-0 rounded-full ring-1 ring-stone-300" style="background-color: {{ $dot($service) }}" aria-hidden="true"></span>
                        @endif
                        {{ $service->name }}
                    </button>
                    <p class="text-[0.8125rem] text-stone-600">{{ $details($service) }}</p>
                </div>
                <x-inline-confirm :id="'leistung-loeschen-'.$service->id" :action="route('admin.services.destroy', $service)" method="DELETE"
                                  label="Löschen" confirm="Leistung löschen" :subject="$service->name" :reload="true"
                                  :question="$question($service)" />
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    <x-drawer id="leistung-neu" title="Neue Leistung" description="Name, Dauer und Preis, wie Gäste sie auf der Buchungsseite sehen.">
        <form id="form-leistung-neu" method="POST" action="{{ route('admin.services.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            @include('admin.services.partials.fields', ['service' => null, 'prefix' => 'neu'])
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-leistung-neu" class="btn btn-primary">Leistung anlegen</button>
        </x-slot:footer>
    </x-drawer>
    @foreach($services as $service)
        <x-drawer :id="'leistung-'.$service->id" :title="$service->name" description="Änderungen gelten für neue Termine.">
            <form id="form-leistung-{{ $service->id }}" method="POST" action="{{ route('admin.services.update', $service) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.services.partials.fields', ['service' => $service, 'prefix' => (string) $service->id])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-leistung-{{ $service->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
