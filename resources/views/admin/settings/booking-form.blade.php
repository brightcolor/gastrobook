@extends('layouts.admin')
@section('title', 'Buchungsformular')

@section('content')
@php
    $texts = [
        'email' => ['E-Mail', 'Für Bestätigung, Erinnerung und den Link, mit dem Gäste ihre Buchung verwalten.'],
        'phone' => ['Telefon', 'Für Rückfragen und SMS-Erinnerungen.'],
        'occasion' => ['Anlass', 'Geburtstag, Jubiläum, Geschäftsessen: So kannst du dich vorbereiten.'],
        'allergies' => ['Allergien und Unverträglichkeiten', 'Hinweise für die Küche, bevor der Gast kommt.'],
        'note' => ['Anmerkung', $salon ? 'Platz für Wünsche und Hinweise zum Termin.' : 'Platz für Wünsche, etwa einen Tisch am Fenster.'],
    ];
    $bothHidden = ($rules['email'] ?? null) === 'hidden' && ($rules['phone'] ?? null) === 'hidden';
@endphp
<x-save-bar one="1 Feld geändert" many=":count Felder geändert" />
<x-page-header title="Buchungsformular" :crumb="['Einstellungen', 'Buchungen']" scope="location"
               :description="$salon
                   ? 'Welche Angaben Gäste beim Buchen eines Termins machen.'
                   : 'Welche Angaben Gäste beim Buchen machen und welche davon Pflicht sind.'" />

<form id="formFields" method="POST" action="{{ route('admin.settings.field-rules') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')
    <x-settings.section id="felder" title="Felder">
        <x-slot:description>
            @if($salon)
                <p>Name und E-Mail-Adresse fragt das Formular für Termine immer ab, das Telefon als freiwillige Angabe. Für die Anmerkung wählst du, ob sie erscheint.</p>
            @else
                <p>Den Namen fragt das Formular immer ab. Für jedes weitere Feld wählst du, ob es erscheint und ob Gäste es ausfüllen müssen.</p>
            @endif
        </x-slot:description>
        <div>
            <div class="divide-y divide-stone-200">
                @foreach($fields as $field)
                    @php
                        $id = 'f-fields-'.$field;
                        $hasError = $errors->has('fields.'.$field);
                    @endphp
                    <div data-change-item="feld-{{ $field }}" role="group" aria-label="{{ $texts[$field][0] }}"
                         class="grid gap-2 py-3 first:pt-0 last:pb-0 sm:grid-cols-[minmax(0,1fr)_12rem] sm:items-center">
                        <div class="min-w-0">
                            <label for="{{ $id }}" class="inline-block text-sm font-semibold text-stone-800">{{ $texts[$field][0] }}</label>
                            <x-change-dot class="ml-2 align-middle" />
                            <p id="{{ $id }}-help" class="mt-0.5 text-[0.8125rem] text-stone-600">{{ $texts[$field][1] }}</p>
                            @if($hasError)
                                <p id="{{ $id }}-error" class="mt-0.5 text-[0.8125rem] text-red-700">{{ $errors->first('fields.'.$field) }}</p>
                            @endif
                        </div>
                        <select id="{{ $id }}" name="fields[{{ $field }}]"
                                @if($hasError) aria-invalid="true" aria-describedby="{{ $id }}-help {{ $id }}-error" @else aria-describedby="{{ $id }}-help" @endif
                                class="w-full">
                            @foreach($choices as $value => $choiceLabel)
                                <option value="{{ $value }}" @selected($rules[$field] === $value)>{{ $choiceLabel }}</option>
                            @endforeach
                        </select>
                    </div>
                @endforeach
            </div>
            @unless($salon)
                <div aria-live="polite">
                    <p data-show-if="fields[email]:hidden;fields[phone]:hidden" role="note" @unless($bothHidden) hidden @endunless class="mt-5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Mit E-Mail und Telefon ausgeblendet bekommt der Gast keine Bestätigung, und Swayy ordnet die Buchung keinem Gästeprofil zu.
                    </p>
                </div>
            @endunless
        </div>
    </x-settings.section>
</form>
@endsection
