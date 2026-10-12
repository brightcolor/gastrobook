{{-- Ein Zeitfenster der anderen Öffnungszeiten einer Ausnahme (Zeilen: resources/js/admin/rows.js).
     $prefix kennzeichnet das Formular, $key die Zeile (in der Vorlage __INDEX__), $window trägt die Werte. --}}
@php
    $p = $prefix.'-windows-'.$key;
@endphp
<div data-row role="group" aria-label="Zeitfenster" class="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-lg border border-stone-200 p-3">
    <x-field :name="'windows['.$key.'][opens_at]'" :id="$p.'-opens'" label="Öffnet" type="time" class="w-32"
             :value="$window['opens_at'] ?? ''" />
    <x-field :name="'windows['.$key.'][closes_at]'" :id="$p.'-closes'" label="Schließt" type="time" class="w-32"
             :value="$window['closes_at'] ?? ''" />
    <div class="pb-0.5">
        <button type="button" data-rows-remove aria-label="Zeitfenster entfernen" class="btn btn-quiet-danger">Entfernen</button>
    </div>
</div>
