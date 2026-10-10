{{-- Felder eines Tags in der Seitenleiste. $prefix macht die IDs je Seitenleiste eindeutig (neu oder ID des Tags).
     Das Farbfeld des Browsers kennt nur #rrggbb; eine andere gespeicherte Schreibweise ersetzt die Vorgabe aus den Einstellungen. --}}
@php($p = 'tag-'.$prefix)
@php($colorError = $errors->first('color'))
@php($storedColor = preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $tag?->color) === 1 ? $tag->color : $defaultColor)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$tag?->name" required :maxlength="$nameMax" placeholder="etwa VIP" autocomplete="off" />
<x-field name="color" label="Farbe" :id="$p.'-color'" help="Am besten eine kräftige Farbe, die auf Weiß gut zu sehen ist.">
    <input id="{{ $p }}-color" type="color" name="color" value="{{ old('color', $storedColor) }}"
           aria-describedby="{{ $p }}-color-help{{ $colorError ? ' '.$p.'-color-error' : '' }}" @if($colorError) aria-invalid="true" @endif
           class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
</x-field>
