@props([
    'id',
    'action',
    'label',
    'question',
    'confirm',
    'method' => 'POST',
    'tone' => 'danger',
    'ajax' => true,
    'open' => false,
])
{{-- Rückfrage in der Zeile (resources/js/admin/inline-confirm.js): Der Knopf weicht der Frage mit Objekt und Folge.
     Der Standard-Slot nimmt weitere Felder auf (etwa eine Eingabe zur Bestätigung). :ajax="false" sendet klassisch,
     :open="true" zeigt die Frage gleich (nach einer Ablehnung, die mit Weiterleitung zurückkam). --}}
@php
    $verb = strtoupper($method);
    $opener = $tone === 'danger' ? 'btn-quiet-danger' : 'btn-quiet';
    $confirmClass = $tone === 'danger' ? 'btn btn-danger' : 'btn btn-primary';
@endphp
<div data-inline-confirm {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <button type="button" data-inline-confirm-open @if($open) hidden @endif class="btn {{ $opener }}">{{ $label }}</button>
    <form id="{{ $id }}" data-inline-confirm-panel @unless($open) hidden @endunless method="POST" action="{{ $action }}" @if($ajax) data-ajax @endif
          class="space-y-3 rounded-lg border border-stone-300 bg-stone-50 p-3 text-left">
        @csrf
        @if($verb !== 'POST')
            @method($verb)
        @endif
        <p class="text-sm font-semibold text-stone-900">{{ $question }}</p>
        {{ $slot }}
        <div class="flex flex-wrap gap-2">
            <button type="submit" class="{{ $confirmClass }}">{{ $confirm }}</button>
            <button type="button" data-inline-confirm-cancel class="btn btn-secondary">Abbrechen</button>
        </div>
    </form>
</div>
