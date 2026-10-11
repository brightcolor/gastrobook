{{-- Eingabefeld für einen Schlüssel (Stripe, PayPal). Es trägt nie ein value-Attribut, auch kein old(): Weder ein gespeicherter noch ein eben
     getippter Wert steht in der Seite. Ein leeres Feld behält den gespeicherten Wert. $has sagt, ob einer gespeichert ist; $hint hilft, solange keiner da ist. --}}
@php($id = 'f-'.$name)
@php($error = $errors->first($name))
<x-field :name="$name" :label="$label" :required="! $has" :help="$has ? 'Gespeichert. Leer lassen, um ihn zu behalten.' : $hint">
    <input id="{{ $id }}" type="password" name="{{ $name }}" autocomplete="new-password" autocapitalize="off" spellcheck="false"
           maxlength="{{ $limits['secret_max'] }}" @unless($has) required aria-required="true" @endunless
           aria-describedby="{{ $id }}-help{{ $error ? ' '.$id.'-error' : '' }}" @if($error) aria-invalid="true" @endif
           class="w-full">
</x-field>
