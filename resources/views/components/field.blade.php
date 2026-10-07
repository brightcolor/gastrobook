@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'help' => null,
    'unit' => null,
    'required' => false,
    'id' => null,
])
{{-- Feld mit Beschriftung darüber, Hilfesatz und Fehler am Feld. Der Standard-Slot ersetzt das Eingabefeld (Auswahl, Textfeld). --}}
@php
    $id = $id ?? 'f-'.trim((string) preg_replace('/[^A-Za-z0-9_]+/', '-', $name), '-');
    $dotted = str_replace(['[', ']'], ['.', ''], $name);
    // $errors teilt die Middleware ShareErrorsFromSession; außerhalb einer
    // Anfrage (Komponententest, Mail) fehlt die Variable.
    $bag = isset($errors) ? $errors : new \Illuminate\Support\ViewErrorBag();
    $error = $bag->first($dotted);
    $describedBy = trim(($help ? $id.'-help ' : '').($error ? $id.'-error' : ''));
@endphp
<div {{ $attributes->only('class')->merge(['class' => 'space-y-1.5']) }}>
    <label for="{{ $id }}" class="block text-sm font-semibold text-stone-800">
        {{ $label }}@if($required)<span class="text-red-700" aria-hidden="true"> *</span>@endif
    </label>
    @if($slot->isNotEmpty())
        {{ $slot }}
    @else
        <div class="flex items-stretch">
            <input id="{{ $id }}" name="{{ $name }}" type="{{ $type }}" value="{{ old($dotted, $value) }}"
                   @if($required) required aria-required="true" @endif
                   @if($describedBy) aria-describedby="{{ $describedBy }}" @endif
                   @if($error) aria-invalid="true" @endif
                   {{ $attributes->except(['class'])->merge(['class' => 'w-full min-w-0'.($unit ? ' rounded-r-none' : '')]) }}>
            @if($unit)
                <span class="inline-flex items-center rounded-r border border-l-0 border-stone-300 bg-stone-50 px-3 text-sm text-stone-600">{{ $unit }}</span>
            @endif
        </div>
    @endif
    @if($help)
        <p id="{{ $id }}-help" class="text-[13px] text-stone-500">{{ $help }}</p>
    @endif
    @if($error)
        <p id="{{ $id }}-error" class="text-[13px] text-red-700">{{ $error }}</p>
    @endif
</div>
