{{-- Ein Zeitfenster eines Tages (Zeilen: resources/js/admin/rows.js, Hinweise und Vorschau: resources/js/admin/hours.js).
     $key ist der Schlüssel der Zeile, in der Vorlage __INDEX__; $weekday und $dayName gehören zum Tag, $window trägt die Werte,
     $limits kommt aus der Seite. Der Hinweis unter den Feldern ([data-hint]) erscheint, sobald ein Fenster über Mitternacht geht
     oder kürzer ist als ein Besuch. --}}
@php($p = 'hours-'.$key)
<div data-row role="group" aria-label="Zeitfenster am {{ $dayName }}" class="space-y-1.5 rounded-lg border border-stone-200 p-3">
    <input type="hidden" name="hours[{{ $key }}][weekday]" value="{{ $weekday }}">
    <div class="flex flex-wrap items-end gap-x-3 gap-y-2">
        <x-field :name="'hours['.$key.'][opens_at]'" :id="$p.'-opens'" label="Öffnet" type="time" step="300" required class="w-32"
                 :value="$window['opens_at'] ?? ''" />
        <x-field :name="'hours['.$key.'][closes_at]'" :id="$p.'-closes'" label="Schließt" type="time" step="300" required class="w-32"
                 :value="$window['closes_at'] ?? ''" />
        <x-field :name="'hours['.$key.'][service_name]'" :id="$p.'-name'" label="Bezeichnung" class="min-w-44 flex-1"
                 :value="$window['service_name'] ?? ''" placeholder="Name, etwa Mittagstisch" autocomplete="off" :maxlength="$limits['name_max']" />
        <div class="pb-0.5">
            <button type="button" data-rows-remove aria-label="Zeitfenster entfernen" class="btn btn-quiet-danger">Entfernen</button>
        </div>
    </div>
    <p data-hint aria-live="polite" class="text-[0.8125rem] text-amber-900"></p>
</div>
