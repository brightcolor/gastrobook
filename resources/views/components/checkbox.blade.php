@props(['name', 'label', 'checked' => false, 'help' => null, 'id' => null, 'value' => '1'])
{{-- Schalter als Kästchen mit Beschriftung und Hilfesatz. Das versteckte Feld davor sendet 0, solange der Haken fehlt:
     So kommt jeder Schalter beim Server an, und ein fehlender Schalter ist ein Fehler. Weitere Attribute gehen an das Kästchen. --}}
@php
    $id = $id ?? 'f-'.trim((string) preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $isChecked = (bool) old(str_replace(['[', ']'], ['.', ''], $name), $checked);
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'flex items-start gap-3']) }}>
    <input type="hidden" name="{{ $name }}" value="0">
    <input id="{{ $id }}" type="checkbox" name="{{ $name }}" value="{{ $value }}" @checked($isChecked)
           @if($help) aria-describedby="{{ $id }}-help" @endif
           {{ $attributes->except(['class'])->merge(['class' => '-mt-0.5 h-6 w-6 shrink-0']) }}>
    <div class="min-w-0">
        <label for="{{ $id }}" class="block text-sm font-semibold text-stone-800">{{ $label }}</label>
        @if($help)
            <p id="{{ $id }}-help" class="text-[0.8125rem] text-stone-600">{{ $help }}</p>
        @endif
    </div>
</div>
