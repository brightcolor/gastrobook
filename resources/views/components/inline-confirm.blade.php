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
    'subject' => null,
    'reload' => false,
])
{{-- Rückfrage in der Zeile (resources/js/admin/inline-confirm.js): Der Knopf weicht der Frage mit Objekt und Folge.
     Der Standard-Slot nimmt weitere Felder auf (etwa eine Eingabe zur Bestätigung). :ajax="false" sendet klassisch,
     :open="true" zeigt die Frage gleich (nach einer Ablehnung, die mit Weiterleitung zurückkam), :reload="true" lädt
     nach dem Bestätigen neu (Listen, deren Antwort kein reload sendet).
     subject="Kim" hängt das Objekt für Screenreader an den Knopf (gehört: "Entfernen „Kim“"); in Listen mit mehreren
     gleichlautenden Knöpfen immer setzen. Die Frage benennt den Inhalt des Formulars als Gruppe und beschreibt den
     Bestätigen-Knopf. --}}
@php
    $verb = strtoupper($method);
    $opener = $tone === 'danger' ? 'btn-quiet-danger' : 'btn-quiet';
    $confirmClass = $tone === 'danger' ? 'btn btn-danger' : 'btn btn-primary';
@endphp
<div data-inline-confirm {{ $attributes->merge(['class' => 'min-w-0']) }}>
    <button type="button" data-inline-confirm-open @if($open) hidden @endif class="btn {{ $opener }}">{{ $label }}@if($subject)<span class="sr-only"> „{{ $subject }}“</span>@endif</button>
    <form id="{{ $id }}" data-inline-confirm-panel @unless($open) hidden @endunless method="POST" action="{{ $action }}" @if($ajax) data-ajax @endif @if($reload) data-reload-after-save @endif
          class="rounded-lg border border-stone-300 bg-stone-50 p-3 text-left">
        @csrf
        @if($verb !== 'POST')
            @method($verb)
        @endif
        <div data-inline-confirm-group role="group" aria-labelledby="{{ $id }}-question" class="space-y-3">
            <p id="{{ $id }}-question" class="text-sm font-semibold text-stone-900">{{ $question }}</p>
            {{ $slot }}
            <div class="flex flex-wrap gap-2">
                <button type="submit" aria-describedby="{{ $id }}-question" class="{{ $confirmClass }}">{{ $confirm }}</button>
                <button type="button" data-inline-confirm-cancel class="btn btn-secondary">Abbrechen</button>
            </div>
        </div>
    </form>
</div>
