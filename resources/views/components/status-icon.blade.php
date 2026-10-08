@props(['state', 'label'])
{{-- Zustand als Symbol mit eigener Form und Farbe; das Wort steht im Tooltip und für Screenreader.
     ok = gefüllter Kreis mit Haken, off = leerer Kreis, warn = Dreieck, error = Quadrat mit Kreuz. --}}
@php
    $shapes = [
        'ok' => ['text-emerald-700', '<circle cx="8" cy="8" r="7" fill="currentColor"/><path d="M4.8 8.2l2.1 2.1 4.3-4.6" fill="none" stroke="var(--color-white)" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>'],
        'off' => ['text-stone-500', '<circle cx="8" cy="8" r="6.2" fill="none" stroke="currentColor" stroke-width="1.6"/>'],
        'warn' => ['text-amber-700', '<path d="M8 1.5l7 12.5H1z" fill="currentColor"/><path d="M8 6v3.6" stroke="var(--color-white)" stroke-width="1.6" stroke-linecap="round"/><circle cx="8" cy="11.6" r=".9" fill="var(--color-white)"/>'],
        'error' => ['text-red-700', '<rect x="1.5" y="1.5" width="13" height="13" rx="2.5" fill="currentColor"/><path d="M5.5 5.5l5 5m0-5l-5 5" stroke="var(--color-white)" stroke-width="1.6" stroke-linecap="round"/>'],
    ];
    [$color, $shape] = $shapes[$state] ?? $shapes['off'];
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex shrink-0 items-center '.$color]) }} title="{{ $label }}">
    <svg viewBox="0 0 16 16" class="h-4 w-4" aria-hidden="true" focusable="false">{!! $shape !!}</svg>
    <span class="sr-only">{{ $label }}</span>
</span>
