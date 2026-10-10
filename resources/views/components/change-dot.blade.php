{{-- Punkt an einer geänderten Zeile; resources/js/admin/savebar.js zeigt ihn, solange die Zeile ungespeichert ist.
     Gefüllter Kreis (Kontrast ≥ 3:1 auf Weiß), dazu das Wort für Screenreader und im Tooltip. --}}
<span data-change-dot hidden title="Geändert" {{ $attributes->merge(['class' => 'inline-block h-2.5 w-2.5 shrink-0 rounded-full bg-amber-600']) }}>
    <span class="sr-only">Geändert</span>
</span>
