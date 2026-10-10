{{-- Ein Zeitfenster der Arbeitszeit (Zeilen: resources/js/admin/rows.js). $key = Schlüssel der Zeile, in der Vorlage __INDEX__.
     Der Wochentag ist von Hand gebaut: Fehler und Hinweis hängen über aria-describedby am Feld. --}}
@php($p = 'wh-'.$member->id.'-'.$key)
@php($dayError = $errors->first('hours.'.$key.'.weekday'))
<div data-row data-change-item="{{ $p }}" role="group" aria-label="Zeitfenster" class="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-lg border border-stone-200 p-3">
    <x-field :name="'hours['.$key.'][weekday]'" :id="$p.'-day'" label="Tag" class="w-36">
        <select id="{{ $p }}-day" name="hours[{{ $key }}][weekday]" @if($dayError) aria-describedby="{{ $p }}-day-error" aria-invalid="true" @endif class="w-full">
            @foreach($weekdays as $day => $dayName)
                <option value="{{ $day }}" @selected((int) old('hours.'.$key.'.weekday', $hour['weekday'] ?? 0) === $day)>{{ $dayName }}</option>
            @endforeach
        </select>
    </x-field>
    <x-field :name="'hours['.$key.'][starts_at]'" :id="$p.'-start'" label="Von" type="time" :value="$hour['starts_at'] ?? ''" required class="w-32" />
    <x-field :name="'hours['.$key.'][ends_at]'" :id="$p.'-end'" label="Bis" type="time" :value="$hour['ends_at'] ?? ''" required class="w-32" />
    <div class="flex items-center gap-2 pb-1">
        <x-change-dot />
        <button type="button" data-rows-remove class="btn btn-quiet-danger">Entfernen<span class="sr-only"> dieses Zeitfenster</span></button>
    </div>
</div>
