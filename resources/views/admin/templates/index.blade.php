@extends('layouts.admin')
@section('title', 'Vorlagen')

@section('content')
@php
    $guest = $salon ? 'Kunde' : 'Gast';
    $guests = $salon ? 'Kunden' : 'Gäste';
@endphp
<x-page-header title="Vorlagen" :crumb="['Einstellungen', $salon ? 'Kundenkommunikation' : 'Gästekommunikation']" scope="tenant"
               :description="'Betreff und Text der Mails an '.$guests.'. Ohne eigene Fassung schickt Swayy den Standardtext.'" />

<x-settings.section id="platzhalter" title="Platzhalter">
    <x-slot:description>
        <p>Swayy ersetzt sie beim Versand durch die Angaben der Buchung. Ein Klick kopiert den Platzhalter.</p>
        <p>Schreibe einfachen Text. Swayy setzt daraus die Mail zusammen: Zeilen wie „Datum: {reservation_date}“ erscheinen als Tabelle, eine Zeile wie „Jetzt bezahlen: {payment_link}“ als Knopf. Logo, Überschrift und Fußbereich ergänzt Swayy.</p>
    </x-slot:description>
    <ul class="flex flex-wrap gap-2">
        @foreach($placeholders as $placeholder)
            <li>
                <button type="button" data-copy="{{ '{'.$placeholder.'}' }}" data-copy-message="{{ '{'.$placeholder.'}' }} kopiert."
                        class="inline-flex min-h-8 items-center rounded-md border border-stone-300 bg-stone-50 px-2.5 hover:bg-stone-100">
                    <code class="text-[0.8125rem] text-stone-800">{{ '{'.$placeholder.'}' }}</code><span class="sr-only"> kopieren</span>
                </button>
            </li>
        @endforeach
    </ul>
</x-settings.section>

<x-settings.section id="mails" :title="'Mails an '.$guests">
    <x-slot:description>
        <p>Die Vorlage öffnet sich an der Seite. Mit „Speichern“ legst du eine eigene Fassung an, die für alle Standorte gilt.</p>
    </x-slot:description>
    <ul class="-my-2 divide-y divide-stone-200">
        @foreach($templates as $t)
            <li class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                <div class="min-w-0 flex-1">
                    <button type="button" data-drawer-open="vorlage-{{ $t['key'] }}"
                            class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $t['label'] }}</button>
                    <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ $t['subject'] }}</p>
                </div>
                <span class="inline-flex items-center gap-1.5 text-[0.8125rem] text-stone-700">
                    <x-status-icon :state="$t['customized'] ? 'ok' : 'off'" :label="$t['customized'] ? 'Angepasst' : 'Standard'" :announce="false" />
                    {{ $t['customized'] ? 'Angepasst' : 'Standard' }}
                </span>
                @if($t['customized'])
                    <x-inline-confirm :id="'vorlage-zuruecksetzen-'.$t['key']" :action="route('admin.templates.reset', $t['key'])" method="DELETE"
                                      label="Auf Standard zurücksetzen" confirm="Zurücksetzen" :subject="$t['label']" :reload="true"
                                      :question="__('Vorlage „:name“ auf den Standardtext zurücksetzen? Deine Fassung geht dabei verloren.', ['name' => $t['label']])" />
                @endif
            </li>
        @endforeach
    </ul>
</x-settings.section>

@push('drawers')
    @foreach($templates as $t)
        <x-drawer :id="'vorlage-'.$t['key']" :title="$t['label']"
                  :description="$t['customized'] ? 'Deine Fassung. Sie gilt für alle Standorte.' : 'Der Standardtext in der Anrede „'.$address.'“'.($addressLocation !== null ? ' (Standort „'.$addressLocation.'“)' : '').'. Mit „Speichern“ legst du eine eigene Fassung an, die für alle Standorte gilt.'">
            <form id="form-vorlage-{{ $t['key'] }}" method="POST" action="{{ route('admin.templates.update', $t['key']) }}" data-ajax class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.templates.partials.fields', ['t' => $t, 'prefix' => 'vorlage-'.$t['key']])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-vorlage-{{ $t['key'] }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
