{{-- Felder einer Tischkombination in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID der Kombination).
     Die Tische sind von Hand gebaute Kästchen: Fehler und Hinweis hängen über aria-describedby an der Gruppe.
     Mitglieder, die nicht mehr kombinierbar sind, bleiben in der Liste, angehakt und mit Vermerk; erst Abwählen nimmt sie heraus. --}}
@php($p = 'kombi-'.$prefix)
@php($tablesError = $errors->first('table_ids'))
@php($selected = array_map('intval', (array) old('table_ids', $combo?->tables->pluck('id')->all() ?? [])))
@php($members = $combo ? $combo->tables->reject(fn ($member) => $joinable->contains('id', $member->id))->values() : collect())
<x-field name="name" label="Name" :id="$p.'-name'" :value="$combo?->name" required :maxlength="$comboNameMax" placeholder="etwa Große Tafel" autocomplete="off" />
<fieldset id="{{ $p }}-tische" class="space-y-1" aria-describedby="{{ $p }}-tische-hinweis{{ $tablesError ? ' '.$p.'-tische-error' : '' }}">
    <legend class="mb-1 text-sm font-semibold text-stone-800">Tische<span class="text-red-700" aria-hidden="true"> *</span></legend>
    <p id="{{ $p }}-tische-hinweis" class="mb-2 text-[0.8125rem] text-stone-600">Mindestens zwei Tische mit „Kombinierbar“.@if($members->isNotEmpty()) Tische mit dem Vermerk „nicht kombinierbar“ gehören bereits zur Kombination; du kannst sie abwählen.@endif</p>
    @foreach($joinable->concat($members) as $table)
        <label class="flex min-h-6 items-center gap-3 py-0.5">
            <input type="checkbox" name="table_ids[]" value="{{ $table->id }}" @checked(in_array($table->id, $selected, true)) @if($tablesError) aria-invalid="true" @endif class="h-6 w-6 shrink-0">
            <span class="[overflow-wrap:anywhere]">{{ $table->name }} <span class="text-stone-500">({{ $table->room?->name }}, {{ $table->min_capacity }}–{{ $table->max_capacity }} Personen{{ $table->joinable ? '' : ', nicht kombinierbar' }})</span></span>
        </label>
    @endforeach
    @if($tablesError)
        <p id="{{ $p }}-tische-error" class="text-[0.8125rem] text-red-700">{{ $tablesError }}</p>
    @endif
</fieldset>
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="min_capacity" label="Ab Personen" type="number" :id="$p.'-min'" :value="$combo?->min_capacity ?? 2" required min="1" :max="$comboMax" />
    <x-field name="max_capacity" label="Bis Personen" type="number" :id="$p.'-max'" :value="$combo?->max_capacity ?? 8" required min="1" :max="$comboMax" />
</div>
