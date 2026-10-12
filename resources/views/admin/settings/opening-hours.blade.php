@extends('layouts.admin')
@section('title', 'Öffnungszeiten')

@section('content')
@php
    $guests = $salon ? 'Kunden' : 'Gäste';
    $anyOpen = collect($days)->contains(fn (array $day) => $day['windows'] !== []);
@endphp
<x-save-bar one="1 Tag geändert" many=":count Tage geändert" />
<x-page-header title="Öffnungszeiten" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location">
    <x-slot:description>
        Wann {{ $guests }} online buchen können. Abweichende Tage, etwa Feiertage, trägst du unter
        @if($canExceptions)
            <a href="{{ route('admin.settings.exceptions.show') }}" class="font-semibold underline underline-offset-2">Ausnahmen</a>
        @else
            Ausnahmen
        @endif
        ein.
    </x-slot:description>
</x-page-header>

<form id="formHours" method="POST" action="{{ route('admin.settings.opening-hours') }}" data-ajax data-savebar
      data-preview-url="{{ route('admin.settings.opening-hours.preview') }}" data-preview-delay="{{ $previewDelay }}"
      data-duration="{{ $duration }}" data-windows-max="{{ $limits['windows_per_day_max'] }}" data-time-step="{{ $limits['time_step_minutes'] }}" @if($salon) data-salon @endif>
    @csrf
    @method('PUT')
    {{-- Sind alle Tage geschlossen, sendet das Formular keine Zeile; dieses Feld macht daraus eine leere Woche. --}}
    <input type="hidden" name="hours_sent" value="1">

    <x-settings.section id="woche" title="Reguläre Woche">
        <x-slot:description>
            <p>Die Zeitfenster, in denen {{ $guests }} online buchen können. Mit mehreren Fenstern an einem Tag, etwa Mittag und Abend, entsteht eine Pause dazwischen.</p>
            <p>Die letzte Uhrzeit eines Fensters liegt so weit vor dem Schließen, wie ein {{ $salon ? 'Termin' : 'Besuch' }} dauert. Die Vorschau zeigt, was die Buchungsseite anbietet.</p>
        </x-slot:description>

        <div role="status">
            <p data-week-empty @if($anyOpen) hidden @endif class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                <x-status-icon state="warn" label="Hinweis" :announce="false" class="mt-0.5" />
                <span>Alle Tage sind geschlossen. {{ $guests }} finden online keine Uhrzeit.</span>
            </p>
        </div>

        <div class="divide-y divide-stone-200">
            @foreach($days as $day)
                @php
                    $open = $day['windows'] !== [];
                    $weekday = $day['weekday'];
                @endphp
                <fieldset data-day="{{ $weekday }}" data-day-name="{{ $day['name'] }}" data-open="{{ $open ? 1 : 0 }}" data-change-item="day-{{ $weekday }}" data-change-state="{{ $open ? 1 : 0 }}"
                          class="space-y-3 py-4 first:pt-0 last:pb-0">
                    <legend class="flex items-center gap-2 text-sm font-semibold text-stone-900">{{ $day['name'] }} <x-change-dot /></legend>
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
                        <div class="flex items-center gap-2">
                            <input type="checkbox" id="day-open-{{ $weekday }}" data-day-open @checked($open) class="h-6 w-6 shrink-0">
                            <label for="day-open-{{ $weekday }}" class="text-sm font-semibold text-stone-800"><span class="sr-only">{{ $day['name'] }} </span>Geöffnet</label>
                        </div>
                        <p data-closed-note @if($open) hidden @endif class="text-sm text-stone-600">Geschlossen</p>
                    </div>
                    <div data-rows data-rows-max="{{ $limits['windows_per_day_max'] }}" class="space-y-3">
                        <div data-rows-list @unless($open) hidden @endunless class="space-y-3">
                            @foreach($day['windows'] as $window)
                                @include('admin.settings.partials.opening-hour-window', ['key' => $window['key'], 'weekday' => $weekday, 'dayName' => $day['name'], 'window' => $window])
                            @endforeach
                        </div>
                        <template data-rows-template>
                            @include('admin.settings.partials.opening-hour-window', ['key' => '__INDEX__', 'weekday' => $weekday, 'dayName' => $day['name'], 'window' => []])
                        </template>
                        <div data-day-actions @unless($open) hidden @endunless class="flex flex-wrap gap-2">
                            <button type="button" data-rows-add class="btn btn-secondary">Zeitfenster hinzufügen<span class="sr-only"> am {{ $day['name'] }}</span></button>
                            <button type="button" data-drawer-open="tage-uebertragen" data-copy-open="{{ $weekday }}" class="btn btn-quiet">Auf andere Tage übertragen…<span class="sr-only"> von {{ $day['name'] }}</span></button>
                        </div>
                    </div>
                </fieldset>
            @endforeach
        </div>

        <x-slot:aside>
            <section data-preview aria-live="polite" aria-labelledby="vorschau-titel"
                     data-text-note="Vorschau für :day; gilt für die Zeiten im Formular, auch ungespeichert."
                     data-text-last="Letzte Uhrzeit :time"
                     data-text-none="Keine Uhrzeit"
                     data-text-unavailable="Die Vorschau ist gerade nicht verfügbar. Deine Eingaben bleiben erhalten."
                     class="space-y-3 rounded-xl border border-stone-200 bg-white p-4 shadow-[0_1px_2px_rgba(28,25,23,0.04)]">
                <h3 id="vorschau-titel" class="text-sm font-semibold text-stone-900">So sehen {{ $guests }} es</h3>
                <p data-preview-note class="text-[0.8125rem] text-stone-600">Die Vorschau erscheint gleich.</p>
                <ul data-preview-times hidden class="flex flex-wrap gap-1.5"></ul>
                <template data-preview-time-template>
                    <li class="rounded-md border border-stone-200 bg-stone-50 px-2 py-0.5 text-sm tabular-nums text-stone-900"></li>
                </template>
                <p data-preview-reason class="text-sm text-stone-800"></p>
                <ul data-preview-groups hidden class="space-y-1 border-t border-stone-200 pt-3 text-[0.8125rem] text-stone-700"></ul>
                <template data-preview-group-template>
                    <li><span data-group-label class="font-semibold"></span>: <span data-group-last></span></li>
                </template>
            </section>
        </x-slot:aside>
    </x-settings.section>
</form>

<x-settings.section id="saison" title="Buchbare Saison">
    <x-slot:description>
        <p>Lege fest, wann {{ $guests }} online buchen können – gilt jedes Jahr. Ohne Eintrag ist das ganze Jahr buchbar.</p>
        <p>Außerhalb der Saison nimmt die Buchungsseite keine {{ $salon ? 'Termine' : 'Reservierungen' }} an und zeigt, ab wann es wieder losgeht.</p>
    </x-slot:description>
    @if($seasons->isEmpty())
        <x-empty-state title="Keine Saison gesetzt">
            Das ganze Jahr ist buchbar.
            @if($canSeasons)
                <x-slot:action>
                    <button type="button" data-drawer-open="saison-neu" class="btn btn-primary">Saison hinzufügen</button>
                </x-slot:action>
            @endif
        </x-empty-state>
    @else
        <ul class="-my-2 divide-y divide-stone-200">
            @foreach($seasons as $season)
                @php
                    $seasonName = $season->label ?: $season->rangeLabel();
                @endphp
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold [overflow-wrap:anywhere]">{{ $seasonName }}</p>
                        @if($season->label)
                            <p class="text-[0.8125rem] text-stone-600">{{ $season->rangeLabel() }}</p>
                        @endif
                    </div>
                    @if($canSeasons)
                        <x-inline-confirm :id="'saison-loeschen-'.$season->id" :action="route('admin.settings.seasons.delete', $season)" method="DELETE"
                                          label="Löschen" confirm="Saison löschen" :subject="$seasonName" :reload="true"
                                          :question="'Saison „'.$seasonName.'“ löschen?'.($seasons->count() === 1 ? ' Danach ist das ganze Jahr buchbar.' : '')" />
                    @endif
                </li>
            @endforeach
        </ul>
        @if($canSeasons)
            <div>
                <button type="button" data-drawer-open="saison-neu" class="btn btn-secondary">Saison hinzufügen</button>
            </div>
        @endif
    @endif
</x-settings.section>

@push('drawers')
    <x-drawer id="tage-uebertragen" title="Auf andere Tage übertragen" description="Die Zeitfenster des gewählten Tages ersetzen die Zeitfenster der angehakten Tage.">
        <p data-copy-source class="text-sm text-stone-700" data-text-source="Von: :day"></p>
        <fieldset class="space-y-3">
            <legend class="mb-1 text-sm font-semibold text-stone-800">Übertragen auf</legend>
            <div class="flex flex-wrap gap-2">
                <button type="button" data-copy-preset="0,1,2,3,4" class="btn btn-secondary">Mo–Fr</button>
                <button type="button" data-copy-preset="0,1,2,3,4,5,6" class="btn btn-secondary">Alle Tage</button>
            </div>
            @foreach($days as $day)
                <div data-copy-choice="{{ $day['weekday'] }}" class="flex items-center gap-3">
                    <input type="checkbox" id="copy-day-{{ $day['weekday'] }}" data-copy-day="{{ $day['weekday'] }}" class="h-6 w-6 shrink-0">
                    <label for="copy-day-{{ $day['weekday'] }}" class="text-sm font-semibold text-stone-800">{{ $day['name'] }}</label>
                </div>
            @endforeach
            <p data-copy-error hidden role="alert" class="text-[0.8125rem] text-red-700">Bitte mindestens einen Tag wählen.</p>
        </fieldset>
        <p class="text-[0.8125rem] text-stone-600">Gespeichert wird erst mit „Speichern“ in der Leiste oben.</p>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="button" data-copy-apply class="btn btn-primary">Übertragen</button>
        </x-slot:footer>
    </x-drawer>

    @if($canSeasons)
        <x-drawer id="saison-neu" title="Neue Saison" description="Der Zeitraum gilt jedes Jahr.">
            <form id="form-saison-neu" method="POST" action="{{ route('admin.settings.seasons.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
                @csrf
                <x-field name="label" id="saison-neu-label" label="Bezeichnung" placeholder="z. B. Sommersaison" autocomplete="off"
                         :maxlength="$limits['season_label_max']" help="Freiwillig. Die Liste zeigt sonst den Zeitraum." />
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-stone-800">Beginn</legend>
                    <div class="flex flex-wrap items-start gap-3">
                        <x-field name="start_day" id="saison-neu-start-day" label="Tag" type="number" min="1" max="31" inputmode="numeric" required class="w-24" />
                        <x-field name="start_month" id="saison-neu-start-month" label="Monat" required class="min-w-40 flex-1">
                            <select id="saison-neu-start-month" name="start_month" required class="w-full">
                                @foreach($months as $number => $month)
                                    <option value="{{ $number }}" @selected((int) old('start_month', 1) === $number)>{{ $month }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    </div>
                </fieldset>
                <fieldset class="space-y-3">
                    <legend class="text-sm font-semibold text-stone-800">Ende</legend>
                    <div class="flex flex-wrap items-start gap-3">
                        <x-field name="end_day" id="saison-neu-end-day" label="Tag" type="number" min="1" max="31" inputmode="numeric" required class="w-24" />
                        <x-field name="end_month" id="saison-neu-end-month" label="Monat" required class="min-w-40 flex-1">
                            <select id="saison-neu-end-month" name="end_month" required class="w-full">
                                @foreach($months as $number => $month)
                                    <option value="{{ $number }}" @selected((int) old('end_month', 12) === $number)>{{ $month }}</option>
                                @endforeach
                            </select>
                        </x-field>
                    </div>
                </fieldset>
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-saison-neu" class="btn btn-primary">Saison hinzufügen</button>
            </x-slot:footer>
        </x-drawer>
    @endif
@endpush
@endsection
