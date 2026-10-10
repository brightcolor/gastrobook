{{-- Felder eines Raums in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID des Raums). --}}
@php($p = 'raum-'.$prefix)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$room?->name" required :maxlength="$roomNameMax" placeholder="etwa Terrasse" autocomplete="off" />
<x-checkbox name="is_outdoor" :id="$p.'-outdoor'" label="Draußen" :checked="(bool) $room?->is_outdoor"
            help="Für Terrasse, Biergarten und andere Plätze im Freien." />
