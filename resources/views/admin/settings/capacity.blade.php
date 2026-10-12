@extends('layouts.admin')
@use('App\Support\CapacityModes')
@section('title', 'Kapazität & Dauer')

@section('content')
<x-save-bar />
<x-page-header title="Kapazität & Dauer" :crumb="['Einstellungen', 'Platz & Zeiten']" scope="location"
               :description="$salon
                   ? 'In welchem Takt Gäste Termine wählen und wie viel Zeit nach jedem Termin frei bleibt.'
                   : 'In welchem Takt Gäste Uhrzeiten wählen, wie lange ein Besuch dauert und wie Swayy die Plätze zählt.'" />

<form id="formCapacity" method="POST" action="{{ route('admin.settings.capacity') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')

    <x-settings.section id="takt" :title="$salon ? 'Takt und Puffer' : 'Takt und Dauer'">
        <x-slot:description>
            <p>Der Takt bestimmt, welche Uhrzeiten die Buchungsseite anbietet: bei 30 Minuten etwa 18:00, 18:30 und 19:00.</p>
            @unless($salon)
                <p>Die Standarddauer gilt für jede Buchung, zu der unten keine Tischzeit passt.</p>
            @endunless
            @if($salon)
                <p>Der Puffer hält nach jeder Buchung Zeit frei, etwa zum Aufräumen und Vorbereiten.</p>
            @else
                <p>Der Puffer hält nach jeder Buchung Zeit frei, etwa zum Abräumen und Eindecken.</p>
            @endif
        </x-slot:description>
        <x-field name="slot_interval_minutes" label="Takt der Uhrzeiten" required class="max-w-48">
            <div class="flex items-stretch">
                <select id="f-slot_interval_minutes" name="slot_interval_minutes" required
                        @error('slot_interval_minutes') aria-invalid="true" aria-describedby="f-slot_interval_minutes-unit f-slot_interval_minutes-error" @else aria-describedby="f-slot_interval_minutes-unit" @enderror
                        class="w-full min-w-0 rounded-r-none">
                    @foreach($intervals as $interval)
                        <option value="{{ $interval }}" @selected((int) old('slot_interval_minutes', $settings->slot_interval_minutes) === $interval)>{{ $interval }}</option>
                    @endforeach
                </select>
                <span id="f-slot_interval_minutes-unit" class="inline-flex items-center rounded-r border border-l-0 border-stone-300 bg-stone-50 px-3 text-sm text-stone-600">Min.</span>
            </div>
        </x-field>
        @unless($salon)
            <x-field name="default_duration_minutes" label="Standarddauer" type="number" unit="Min." :minutes="true" required class="max-w-48"
                     :value="$settings->default_duration_minutes" :min="$limits['duration_min']" :max="$limits['duration_max']"
                     help="So lange ist ein Tisch je Buchung belegt." />
        @endunless
        <x-field name="buffer_minutes" label="Puffer nach jeder Buchung" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->buffer_minutes" min="0" :max="$limits['buffer_max']" />
        @if($salon)
            <x-checkbox name="gap_optimization_enabled" label="Lückenoptimierung" :checked="(bool) $settings->gap_optimization_enabled"
                        help="Lässt ein Gast die Wahl der Person offen, wählt Swayy die Person, deren Kalender dadurch am dichtesten wird." />
        @endif
    </x-settings.section>

    @unless($salon)
        <x-settings.section id="tischzeiten" title="Tischzeiten je Gruppengröße">
            <x-slot:description>
                <p>Große Runden bleiben länger als Paare. Eine Regel legt fest, wie lange der Tisch für eine Gruppengröße belegt ist; für alle übrigen gilt die Standarddauer.</p>
                <p>Beispiel: 1–2 Personen 75 Minuten, 3–5 Personen 105 Minuten, 6–20 Personen 150 Minuten.</p>
            </x-slot:description>
            <div data-rows data-rows-max="{{ $limits['duration_rules_max'] }}" class="space-y-3">
                <div data-rows-list class="space-y-3">
                    @foreach($durationRules as $key => $rule)
                        @include('admin.settings.partials.duration-rule', ['key' => $key, 'rule' => (array) $rule])
                    @endforeach
                </div>
                <template data-rows-template>
                    @include('admin.settings.partials.duration-rule', ['key' => '__INDEX__', 'rule' => []])
                </template>
                <button type="button" data-rows-add class="btn btn-secondary">Tischzeit hinzufügen</button>
                <p class="text-[0.8125rem] text-stone-600">Jede Gruppengröße gehört zu höchstens einer Regel, bis zu {{ $limits['duration_rules_max'] }} Regeln.</p>
            </div>
        </x-settings.section>

        <x-settings.section id="kapazitaet" title="Wie Swayy die Plätze zählt">
            <x-slot:description>
                <p>Nach Tischen: Eine Buchung braucht einen freien Tisch oder eine Kombination, die zur Gruppe passt.</p>
                <p>Nach Personen: Swayy zählt die Gäste, die gleichzeitig da sind, bis zur Höchstzahl.</p>
                <p>Beides: Es braucht einen passenden Tisch, und die Höchstzahl gilt zusätzlich.</p>
            </x-slot:description>
            <fieldset class="grid gap-3 sm:grid-cols-3">
                <legend class="mb-2 text-sm font-semibold text-stone-800">Zählweise</legend>
                @foreach([
                    'table' => ['Für Betriebe mit festen Tischen.'],
                    'person' => ['Für Plätze ohne feste Tische, etwa an einer Bar.'],
                    'hybrid' => ['Tische und eine Höchstzahl für den ganzen Standort.'],
                ] as $mode => [$modeSummary])
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                        <input type="radio" name="capacity_mode" value="{{ $mode }}" @checked(old('capacity_mode', $settings->capacity_mode) === $mode) class="mt-1">
                        <span class="min-w-0 [overflow-wrap:anywhere]">
                            <span class="block font-semibold">{{ CapacityModes::label($mode) }}</span>
                            <span class="block text-[0.8125rem] text-stone-500">{{ $modeSummary }}</span>
                        </span>
                    </label>
                @endforeach
            </fieldset>
            <div data-show-if="capacity_mode:person,hybrid" @unless(in_array(old('capacity_mode', $settings->capacity_mode), ['person', 'hybrid'], true)) hidden @endunless>
                <x-field name="max_covers_per_slot" label="Höchstens gleichzeitig" type="number" unit="Gäste" class="max-w-56"
                         :value="$settings->max_covers_per_slot" min="1" :max="$limits['covers_max']"
                         help="Ohne Eintrag gilt keine Höchstzahl." />
            </div>
        </x-settings.section>
    @endunless
</form>
@endsection
