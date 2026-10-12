@extends('layouts.admin')
@section('title', 'Räume & Tische')

@section('content')
@php
    $tableCount = fn (int $count): string => trans_choice('{0} Keine Tische|{1} :count Tisch|[2,*] :count Tische', $count, ['count' => $count]);
    // Die Spalte liefert Maße mit zwei Nachkommastellen („8.00“); das Feld zeigt die Zahl („8“).
    $meters = fn ($value) => $value === null ? null : (float) $value;
@endphp
<x-page-header title="Räume & Tische" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               description="Räume, Tische und Kombinationen dieses Standorts. Wo die Tische stehen, legst du im Tischplan fest.">
    @if($canRooms)
        <x-slot:actions>
            <button type="button" data-drawer-open="raum-neu" class="btn btn-primary">Raum hinzufügen</button>
        </x-slot:actions>
    @endif
</x-page-header>

<div class="mb-4 flex flex-wrap items-center justify-between gap-3 text-sm text-stone-600">
    <p>
        @if($tableLimit === null)
            {{ $tableCount($tables->count()) }} an diesem Standort
        @else
            {{ trans_choice('{0} Dein Tarif umfasst keine Tische (alle Standorte)|{1} :used von :count Tisch deines Tarifs genutzt (alle Standorte)|[2,*] :used von :count Tischen deines Tarifs genutzt (alle Standorte)', $tableLimit, ['used' => $tablesUsed, 'count' => $tableLimit]) }}
        @endif
        · Ein Raum lässt sich löschen, sobald keine Tische mehr darin stehen.
    </p>
    <a href="{{ route('admin.floorplan.index') }}" class="font-semibold underline underline-offset-2">Im Tischplan anordnen</a>
</div>

@if($rooms->isEmpty())
    <x-empty-state title="Noch keine Räume">
        Ein Raum fasst Tische zusammen, etwa Gastraum, Terrasse oder Wintergarten.
        @if($canRooms)
            <x-slot:action>
                <button type="button" data-drawer-open="raum-neu" class="btn btn-primary">Raum hinzufügen</button>
            </x-slot:action>
        @endif
    </x-empty-state>
@else
    <div class="space-y-6">
        @foreach($rooms as $room)
            <section aria-labelledby="abschnitt-raum-{{ $room->id }}" class="rounded-xl border border-stone-200 bg-white">
                <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-b border-stone-200 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <h2 id="abschnitt-raum-{{ $room->id }}" class="font-semibold [overflow-wrap:anywhere]">
                            @if($canRooms)
                                <button type="button" data-drawer-open="raum-{{ $room->id }}" class="text-left underline-offset-2 hover:underline">{{ $room->name }}</button>
                            @else
                                {{ $room->name }}
                            @endif
                        </h2>
                        <p class="text-[0.8125rem] text-stone-600">
                            {{ $room->is_outdoor ? 'Draußen' : 'Drinnen' }} · {{ $tableCount($room->tables->count()) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-1">
                        <button type="button" data-drawer-open="tisch-neu-{{ $room->id }}" class="btn btn-quiet">Tisch hinzufügen<span class="sr-only"> in „{{ $room->name }}“</span></button>
                        @if($canRooms && $room->tables->isEmpty())
                            <x-inline-confirm :id="'raum-loeschen-'.$room->id" :action="route('admin.settings.rooms.delete', $room)" method="DELETE"
                                              label="Löschen" confirm="Raum löschen" :subject="$room->name" :reload="true"
                                              :question="'Raum „'.$room->name.'“ löschen?'" />
                        @endif
                    </div>
                </div>
                @if($room->tables->isEmpty())
                    <p class="px-4 py-3 text-sm text-stone-600">Noch keine Tische in diesem Raum.</p>
                @else
                    <ul class="divide-y divide-stone-200">
                        @foreach($room->tables as $table)
                            @php
                                $flags = array_filter([
                                    $table->joinable ? 'kombinierbar' : null,
                                    $table->accessible ? 'barrierefrei' : null,
                                    $table->high_chair_possible ? 'Kinderstuhl' : null,
                                    $table->outdoor ? 'draußen' : null,
                                ]);
                            @endphp
                            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                                <x-status-icon :state="$table->online_bookable ? 'ok' : 'off'" :label="$table->online_bookable ? 'Online buchbar' : 'Nur intern'" />
                                <div class="min-w-0 flex-1">
                                    <button type="button" data-drawer-open="tisch-{{ $table->id }}"
                                            class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $table->name }}</button>
                                    <p class="text-[0.8125rem] text-stone-600">{{ $table->min_capacity }}–{{ $table->max_capacity }} Personen{{ $flags ? ' · '.implode(' · ', $flags) : '' }}</p>
                                </div>
                                <div class="flex flex-wrap items-center gap-1">
                                    @if($canBlock)
                                        <a href="{{ route('admin.settings.exceptions.show', ['tisch' => $table->id]) }}#ausnahme-neu" class="btn btn-quiet">Sperren…<span class="sr-only"> Tisch {{ $table->name }}</span></a>
                                    @endif
                                    <x-inline-confirm :id="'tisch-loeschen-'.$table->id" :action="route('admin.settings.tables.delete', $table)" method="DELETE"
                                                      label="Löschen" confirm="Tisch löschen" :subject="$table->name" :reload="true"
                                                      :question="'Tisch „'.$table->name.'“ löschen? Er verschwindet aus dem Tischplan; vergangene Reservierungen behalten ihn.'" />
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endforeach
    </div>
@endif

<section id="kombinationen" aria-labelledby="kombinationen-titel" class="mt-8">
    <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h2 id="kombinationen-titel" class="text-base font-semibold">Tischkombinationen</h2>
            <p class="text-sm text-stone-600">Zusammengestellte Tische für größere Gruppen. Swayy nimmt sie, wenn ein einzelner Tisch zu klein ist.</p>
        </div>
        @if($joinable->count() >= 2 && $combinations->isNotEmpty())
            <button type="button" data-drawer-open="kombi-neu" class="btn btn-secondary">Kombination hinzufügen</button>
        @endif
    </div>
    @if($combinations->isEmpty())
        <x-empty-state title="Noch keine Kombinationen">
            @if($joinable->count() >= 2)
                Für Gruppen, die an keinen einzelnen Tisch passen, stellst du hier Tische zusammen.
                <x-slot:action>
                    <button type="button" data-drawer-open="kombi-neu" class="btn btn-primary">Kombination hinzufügen</button>
                </x-slot:action>
            @else
                Kombinationen brauchen mindestens zwei Tische mit „Kombinierbar“.
            @endif
        </x-empty-state>
    @else
        <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
            @foreach($combinations as $combo)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <div class="min-w-0 flex-1">
                        <button type="button" data-drawer-open="kombi-{{ $combo->id }}"
                                class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $combo->name }}</button>
                        <p class="text-[0.8125rem] text-stone-600">{{ $combo->tables->pluck('name')->implode(' + ') }} · {{ $combo->min_capacity }}–{{ $combo->max_capacity }} Personen</p>
                    </div>
                    <x-inline-confirm :id="'kombi-loeschen-'.$combo->id" :action="route('admin.settings.combinations.delete', $combo)" method="DELETE"
                                      label="Löschen" confirm="Kombination löschen" :subject="$combo->name" :reload="true"
                                      :question="'Kombination „'.$combo->name.'“ löschen? Die Tische bleiben.'" />
                </li>
            @endforeach
        </ul>
    @endif
</section>

@push('drawers')
    @if($canRooms)
        <x-drawer id="raum-neu" title="Neuer Raum" description="Danach legst du im Raum die Tische an.">
            <form id="form-raum-neu" method="POST" action="{{ route('admin.settings.rooms.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
                @csrf
                @include('admin.settings.partials.room-fields', ['room' => null, 'prefix' => 'neu'])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-raum-neu" class="btn btn-primary">Raum anlegen</button>
            </x-slot:footer>
        </x-drawer>
    @endif
    @foreach($rooms as $room)
        @if($canRooms)
            <x-drawer :id="'raum-'.$room->id" :title="$room->name" description="Name und Lage des Raums.">
                <form id="form-raum-{{ $room->id }}" method="POST" action="{{ route('admin.settings.rooms.update', $room) }}" data-ajax data-reload-after-save class="space-y-5">
                    @csrf
                    @method('PUT')
                    @include('admin.settings.partials.room-fields', ['room' => $room, 'prefix' => (string) $room->id])
                </form>
                @if($canFloorplan)
                    <section aria-labelledby="raum-{{ $room->id }}-masse-titel" class="space-y-4 border-t border-stone-200 pt-5">
                        <h3 id="raum-{{ $room->id }}-masse-titel" class="text-sm font-semibold">Maße für den Tischplan</h3>
                        <p class="text-[0.8125rem] text-stone-600">Mit Breite und Tiefe zeichnet der Tischplan den Raum maßstabsgetreu.</p>
                        <form method="POST" action="{{ route('admin.floorplan.rooms.size', $room) }}" data-ajax class="space-y-3">
                            @csrf
                            @method('PATCH')
                            <div class="grid gap-4 sm:grid-cols-2">
                                <x-field name="plan_width_m" label="Breite" type="number" unit="m" :id="'raum-'.$room->id.'-breite'"
                                         :value="$meters($room->plan_width_m)" min="1" :max="$roomSizeMax" step="0.5" />
                                <x-field name="plan_height_m" label="Tiefe" type="number" unit="m" :id="'raum-'.$room->id.'-tiefe'"
                                         :value="$meters($room->plan_height_m)" min="1" :max="$roomSizeMax" step="0.5" />
                            </div>
                            <button type="submit" class="btn btn-secondary">Maße speichern</button>
                        </form>
                    </section>
                @endif
                <x-slot:footer>
                    <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                    <button type="submit" form="form-raum-{{ $room->id }}" class="btn btn-primary">Speichern</button>
                </x-slot:footer>
            </x-drawer>
        @endif
        <x-drawer :id="'tisch-neu-'.$room->id" :title="'Neuer Tisch in „'.$room->name.'“'" description="Neue Tische stehen im Tischplan oben links; dort ziehst du sie an ihren Platz.">
            <form id="form-tisch-neu-{{ $room->id }}" method="POST" action="{{ route('admin.settings.tables.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
                @csrf
                <input type="hidden" name="room_id" value="{{ $room->id }}">
                @include('admin.settings.partials.table-fields', ['table' => null, 'prefix' => 'neu-'.$room->id, 'outdoorDefault' => (bool) $room->is_outdoor])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-tisch-neu-{{ $room->id }}" class="btn btn-primary">Tisch anlegen</button>
            </x-slot:footer>
        </x-drawer>
        @foreach($room->tables as $table)
            <x-drawer :id="'tisch-'.$table->id" :title="$table->name" :description="'Raum „'.$room->name.'“'">
                <form id="form-tisch-{{ $table->id }}" method="POST" action="{{ route('admin.settings.tables.update', $table) }}" data-ajax data-reload-after-save class="space-y-5">
                    @csrf
                    @method('PUT')
                    @include('admin.settings.partials.table-fields', ['table' => $table, 'prefix' => (string) $table->id, 'outdoorDefault' => false])
                </form>
                <x-slot:footer>
                    <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                    <button type="submit" form="form-tisch-{{ $table->id }}" class="btn btn-primary">Speichern</button>
                </x-slot:footer>
            </x-drawer>
        @endforeach
    @endforeach
    @if($joinable->count() >= 2)
        <x-drawer id="kombi-neu" title="Neue Kombination" description="Tische, die für eine größere Gruppe zusammengestellt werden.">
            <form id="form-kombi-neu" method="POST" action="{{ route('admin.settings.combinations.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
                @csrf
                @include('admin.settings.partials.combination-fields', ['combo' => null, 'prefix' => 'neu'])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-kombi-neu" class="btn btn-primary">Kombination anlegen</button>
            </x-slot:footer>
        </x-drawer>
    @endif
    @foreach($combinations as $combo)
        <x-drawer :id="'kombi-'.$combo->id" :title="$combo->name" description="Tische und Gruppengröße der Kombination.">
            <form id="form-kombi-{{ $combo->id }}" method="POST" action="{{ route('admin.settings.combinations.update', $combo) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.settings.partials.combination-fields', ['combo' => $combo, 'prefix' => (string) $combo->id])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-kombi-{{ $combo->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
