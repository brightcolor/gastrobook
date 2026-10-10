{{-- Felder eines Tisches in der Seitenleiste. $prefix macht die IDs eindeutig; $outdoorDefault gilt für neue Tische (Lage des Raums). --}}
@php($p = 'tisch-'.$prefix)
<x-field name="name" label="Name oder Nummer" :id="$p.'-name'" :value="$table?->name" required :maxlength="$tableNameMax" placeholder="etwa T12" autocomplete="off" />
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="min_capacity" label="Ab Personen" type="number" :id="$p.'-min'" :value="$table?->min_capacity ?? 1" required min="1" :max="$capacityMax" />
    <x-field name="max_capacity" label="Bis Personen" type="number" :id="$p.'-max'" :value="$table?->max_capacity ?? 4" required min="1" :max="$capacityMax" />
</div>
<fieldset class="space-y-3">
    <legend class="mb-2 text-sm font-semibold text-stone-800">Eigenschaften</legend>
    <x-checkbox name="online_bookable" :id="$p.'-online'" label="Online buchbar" :checked="$table?->online_bookable ?? true"
                help="Mit Haken buchen Gäste den Tisch online. Ist der Haken entfernt, vergibt dein Team den Tisch." />
    <x-checkbox name="joinable" :id="$p.'-joinable'" label="Kombinierbar" :checked="$table?->joinable ?? true"
                help="Lässt sich mit anderen Tischen zu einer Kombination zusammenstellen." />
    <x-checkbox name="accessible" :id="$p.'-accessible'" label="Barrierefrei" :checked="(bool) $table?->accessible" />
    <x-checkbox name="high_chair_possible" :id="$p.'-high-chair'" label="Kinderstuhl möglich" :checked="(bool) $table?->high_chair_possible" />
    <x-checkbox name="outdoor" :id="$p.'-outdoor'" label="Draußen" :checked="$table ? (bool) $table->outdoor : $outdoorDefault" />
</fieldset>
