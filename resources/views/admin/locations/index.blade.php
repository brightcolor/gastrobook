@extends('layouts.admin')
@section('title', 'Standorte')

@section('content')
@php($description = $tenant->isSalon()
    ? 'Adresse, Kontakt und Begrüßung jedes Standorts. Zeiten, Leistungen und Mitarbeiter richtest du je Standort unter „Platz & Zeiten“ ein.'
    : 'Adresse, Kontakt und Begrüßung jedes Standorts. Zeiten, Räume und Tische richtest du je Standort unter „Platz & Zeiten“ ein.')
<x-page-header title="Standorte" :crumb="['Einstellungen', 'Betrieb']" scope="tenant" :description="$description">
    @if($canAdd)
        <x-slot:actions>
            <button type="button" data-drawer-open="standort-neu" class="btn btn-primary">Standort hinzufügen</button>
        </x-slot:actions>
    @endif
</x-page-header>

<p class="mb-4 text-sm text-stone-600">
    @if($limit === null)
        {{ trans_choice(':count Standort|:count Standorte', $used, ['count' => $used]) }}
    @elseif($used > $limit)
        {{ trans_choice(':count Standort angelegt|:count Standorte angelegt', $used, ['count' => $used]) }}, {{ trans_choice('{0} dein Tarif umfasst keinen.|{1} dein Tarif umfasst einen.|[2,*] dein Tarif umfasst :count.', (int) $limit, ['count' => $limit]) }}
    @else
        {{ $used }} von {{ $limit }} Standorten deines Tarifs genutzt
    @endif
</p>

@unless($canAdd)
    <p class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        {{ trans_choice('Dein Tarif umfasst einen Standort, und der ist schon angelegt.|Dein Tarif umfasst :count Standorte, und alle sind schon angelegt.', (int) $limit, ['count' => $limit]) }}
        @if($canSeeBilling)
            <a href="{{ route('admin.billing.show') }}" class="font-semibold underline underline-offset-2">Tarif unter „Abo & Rechnungen“ wechseln</a>
        @else
            Für einen weiteren Standort bitte eine Person mit Zugang zu „Abo & Rechnungen“ fragen.
        @endif
    </p>
@endunless

@if($locations->isEmpty())
    <x-empty-state title="Noch kein Standort">
        Ein Standort ist der Ort, an dem Gäste buchen: mit Adresse, Zeiten und Plätzen.
        @if($canAdd)
            <x-slot:action>
                <button type="button" data-drawer-open="standort-neu" class="btn btn-primary">Standort hinzufügen</button>
            </x-slot:action>
        @endif
    </x-empty-state>
@else
    <ul class="divide-y divide-stone-200 rounded-xl border border-stone-200 bg-white">
        @foreach($locations as $loc)
            @php($address = implode(', ', array_filter([$loc->address_line1, trim($loc->postal_code.' '.$loc->city)])))
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                <x-status-icon :state="$loc->is_active ? 'ok' : 'off'" :label="$loc->is_active ? 'Aktiv' : 'Inaktiv'" />
                <div class="min-w-0 flex-1">
                    <button type="button" data-drawer-open="standort-{{ $loc->id }}"
                            class="text-left font-semibold underline-offset-2 hover:underline">{{ $loc->name }}</button>
                    <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">
                        {{ $address !== '' ? $address : 'Adresse fehlt noch' }}
                        @unless($tenant->isSalon())
                            · {{ trans_choice(':count Raum|:count Räume', $loc->rooms_count, ['count' => $loc->rooms_count]) }}
                            · {{ trans_choice(':count Tisch|:count Tische', $loc->tables_count, ['count' => $loc->tables_count]) }}
                        @endunless
                    </p>
                </div>
                <div class="flex items-center gap-1">
                    @if($loc->is_active)
                        <x-inline-confirm id="deaktivieren-{{ $loc->id }}" :action="route('admin.locations.toggle-active', $loc)"
                                          label="Deaktivieren" confirm="Deaktivieren" :subject="$loc->name"
                                          :question="'„'.$loc->name.'“ deaktivieren? Gäste können dort dann nicht mehr online buchen; bestehende Buchungen bleiben.'" />
                    @else
                        <form method="POST" action="{{ route('admin.locations.toggle-active', $loc) }}" data-ajax>
                            @csrf
                            <button type="submit" class="btn btn-quiet">Aktivieren<span class="sr-only"> „{{ $loc->name }}“</span></button>
                        </form>
                    @endif
                </div>
            </li>
        @endforeach
    </ul>
@endif

@push('drawers')
    @if($canAdd)
        <x-drawer id="standort-neu" title="Neuer Standort" description="Name und Zeitzone genügen für den Anfang; den Rest ergänzt du jederzeit.">
            <form id="form-standort-neu" method="POST" action="{{ route('admin.locations.store') }}" data-ajax class="space-y-5">
                @csrf
                @include('admin.locations.partials.fields', ['loc' => null, 'prefix' => 'neu'])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-standort-neu" class="btn btn-primary">Standort anlegen</button>
            </x-slot:footer>
        </x-drawer>
    @endif
    @foreach($locations as $loc)
        <x-drawer id="standort-{{ $loc->id }}" :title="$loc->name" description="Gilt für Buchungsseite, Bestätigungen und Erinnerungen dieses Standorts.">
            <form id="form-standort-{{ $loc->id }}" method="POST" action="{{ route('admin.locations.update', $loc) }}" data-ajax class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.locations.partials.fields', ['loc' => $loc, 'prefix' => (string) $loc->id])
            </form>
            @if($canEditLogo)
                @include('admin.locations.partials.logo', ['loc' => $loc])
            @endif
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-standort-{{ $loc->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
