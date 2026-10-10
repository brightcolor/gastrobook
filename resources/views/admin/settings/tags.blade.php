@extends('layouts.admin')
@section('title', 'Tags')

@section('content')
@php
    $description = $salon
        ? 'Farbige Markierungen für Termine, etwa VIP, Geburtstag oder Allergie. Sie erscheinen in den Details eines Termins.'
        : 'Farbige Markierungen für Reservierungen, etwa VIP, Geburtstag oder Allergie. Sie erscheinen in den Reservierungsdetails und im Tischplan.';
    $uses = fn (int $count): string => $salon
        ? trans_choice('An :count Termin|An :count Terminen', $count, ['count' => $count])
        : trans_choice('An :count Reservierung|An :count Reservierungen', $count, ['count' => $count]);
    $question = fn ($tag): string => match (true) {
        $tag->reservations_count === 0 => __('Tag „:name“ löschen?', ['name' => $tag->name]),
        $salon => trans_choice('Tag „:name“ löschen? Er verschwindet von :count Termin.|Tag „:name“ löschen? Er verschwindet von :count Terminen.', $tag->reservations_count, ['name' => $tag->name, 'count' => $tag->reservations_count]),
        default => trans_choice('Tag „:name“ löschen? Er verschwindet von :count Reservierung.|Tag „:name“ löschen? Er verschwindet von :count Reservierungen.', $tag->reservations_count, ['name' => $tag->name, 'count' => $tag->reservations_count]),
    };
@endphp
<x-page-header title="Tags" :crumb="['Einstellungen', 'Buchungen']" scope="tenant" :description="$description">
    <x-slot:actions>
        <button type="button" data-drawer-open="tag-neu" class="btn btn-primary">Tag hinzufügen</button>
    </x-slot:actions>
</x-page-header>

@if($tags->isEmpty())
    <x-empty-state title="Noch keine Tags">
        Mit Tags erkennt dein Team besondere {{ $salon ? 'Termine' : 'Reservierungen' }} auf einen Blick.
        <x-slot:action>
            <button type="button" data-drawer-open="tag-neu" class="btn btn-primary">Tag hinzufügen</button>
        </x-slot:action>
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($tags as $tag)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <span class="h-4 w-4 shrink-0 rounded-full ring-1 ring-stone-300" style="background-color: {{ $tag->color }}" aria-hidden="true"></span>
                <div class="min-w-0 flex-1">
                    @if($tag->is_system)
                        <p class="font-semibold [overflow-wrap:anywhere]">{{ $tag->name }}</p>
                    @else
                        <button type="button" data-drawer-open="tag-{{ $tag->id }}"
                                class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $tag->name }}</button>
                    @endif
                    <p class="text-[0.8125rem] text-stone-600">
                        {{ $uses($tag->reservations_count) }}
                        @if($tag->is_system)
                            · fest eingerichtet
                        @endif
                    </p>
                </div>
                @unless($tag->is_system)
                    <x-inline-confirm :id="'tag-loeschen-'.$tag->id" :action="route('admin.tags.destroy', $tag)" method="DELETE"
                                      label="Löschen" confirm="Tag löschen" :subject="$tag->name" :reload="true"
                                      :question="$question($tag)" />
                @endunless
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    <x-drawer id="tag-neu" title="Neuer Tag" :description="$salon ? 'Name und Farbe, wie sie an Terminen erscheinen.' : 'Name und Farbe, wie sie an Reservierungen erscheinen.'">
        <form id="form-tag-neu" method="POST" action="{{ route('admin.tags.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            @include('admin.settings.partials.tag-fields', ['tag' => null, 'prefix' => 'neu'])
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-tag-neu" class="btn btn-primary">Tag anlegen</button>
        </x-slot:footer>
    </x-drawer>
    @foreach($tags->reject(fn ($tag) => $tag->is_system) as $tag)
        <x-drawer :id="'tag-'.$tag->id" :title="$tag->name" :description="$salon ? 'Änderungen gelten an allen Terminen mit diesem Tag.' : 'Änderungen gelten an allen Reservierungen mit diesem Tag.'">
            <form id="form-tag-{{ $tag->id }}" method="POST" action="{{ route('admin.tags.update', $tag) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.settings.partials.tag-fields', ['tag' => $tag, 'prefix' => (string) $tag->id])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-tag-{{ $tag->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
