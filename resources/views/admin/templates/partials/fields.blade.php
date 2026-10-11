{{-- Felder einer Vorlage in der Seitenleiste. $prefix macht die IDs je Seitenleiste eindeutig (vorlage-{key}),
     $t ist die Vorlage mit Betreff und Text, $limits kommen aus config/swayy.php (templates). --}}
@php($p = $prefix)
@php($bodyError = $errors->first('body'))
<x-field name="subject" label="Betreff" :id="$p.'-subject'" :value="$t['subject']" required :maxlength="$limits['subject_max']" autocomplete="off"
         help="Kurz und konkret, so findet der Gast die Mail im Postfach wieder." />
<x-field name="body" label="Text" :id="$p.'-body'" required help="Platzhalter in geschweiften Klammern ersetzt Swayy beim Versand.">
    <textarea id="{{ $p }}-body" name="body" rows="12" required aria-required="true" maxlength="{{ $limits['body_max'] }}"
              @if($bodyError) aria-invalid="true" aria-describedby="{{ $p }}-body-help {{ $p }}-body-error" @else aria-describedby="{{ $p }}-body-help" @endif
              class="w-full font-mono text-[0.8125rem] leading-relaxed">{{ old('body', $t['body']) }}</textarea>
</x-field>
