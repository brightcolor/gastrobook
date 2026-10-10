{{-- Eine Tischzeit-Regel auf „Kapazität & Dauer“ (Zeilen: resources/js/admin/rows.js).
     $key ist der Schlüssel der Zeile, in der Vorlage __INDEX__; $limits kommt aus der Seite. --}}
@php($p = 'rule-'.$key)
<div data-row data-change-item="{{ $p }}" role="group" aria-label="Tischzeit" class="flex flex-wrap items-end gap-x-3 gap-y-2 rounded-lg border border-stone-200 p-3">
    <x-field :name="'duration_rules['.$key.'][min_party]'" :id="$p.'-min'" label="Ab Personen" type="number" class="w-28"
             :value="$rule['min_party'] ?? ''" min="1" :max="$limits['rule_party_max']" />
    <x-field :name="'duration_rules['.$key.'][max_party]'" :id="$p.'-max'" label="Bis Personen" type="number" class="w-28"
             :value="$rule['max_party'] ?? ''" min="1" :max="$limits['rule_party_max']" />
    <x-field :name="'duration_rules['.$key.'][duration]'" :id="$p.'-duration'" label="Tischzeit" type="number" unit="Min." class="w-36"
             :value="$rule['duration'] ?? ''" :min="$limits['rule_duration_min']" :max="$limits['rule_duration_max']" />
    <div class="flex items-center gap-2 pb-1">
        <x-change-dot />
        <button type="button" data-rows-remove class="btn btn-quiet-danger">Entfernen</button>
    </div>
</div>
