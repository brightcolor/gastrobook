{{-- Felder eines Standorts in der Seitenleiste. $prefix macht die IDs je Seitenleiste eindeutig (neu oder ID des Standorts).
     Zeitzone und Begrüßung sind von Hand gebaut: Fehler und Hinweis hängen über aria-describedby am Feld.
     $introMax kommt aus den Einstellungen (swayy.locations.intro_max). --}}
@php($p = 'loc-'.$prefix)
@php($timezoneError = $errors->first('timezone'))
@php($introError = $errors->first('public_intro'))
<x-field name="name" label="Name" :id="$p.'-name'" :value="$loc?->name" required maxlength="120"
         help="Erscheint auf der Buchungsseite und in Mails an Gäste." />
<x-field name="address_line1" label="Straße und Hausnummer" :id="$p.'-address_line1'" :value="$loc?->address_line1" maxlength="200" />
<div class="grid gap-4 sm:grid-cols-3">
    <x-field name="postal_code" label="PLZ" :id="$p.'-postal_code'" :value="$loc?->postal_code" maxlength="20" />
    <x-field name="city" label="Ort" :id="$p.'-city'" :value="$loc?->city" maxlength="100" class="sm:col-span-2" />
</div>
<x-field name="phone" label="Telefon" type="tel" :id="$p.'-phone'" :value="$loc?->phone" maxlength="40" />
<x-field name="email" label="E-Mail" type="email" :id="$p.'-email'" :value="$loc?->email" maxlength="200"
         help="Für Rückfragen von Gästen; steht in Bestätigungen." />
<x-field name="timezone" label="Zeitzone" :id="$p.'-timezone'" required help="Gilt für Öffnungszeiten, Erinnerungen und alle Uhrzeiten dieses Standorts.">
    <select id="{{ $p }}-timezone" name="timezone" required
            aria-describedby="{{ $p }}-timezone-help{{ $timezoneError ? ' '.$p.'-timezone-error' : '' }}" @if($timezoneError) aria-invalid="true" @endif
            class="w-full">
        @foreach($timezones as $tz)
            <option value="{{ $tz }}" @selected(old('timezone', $loc?->timezone ?? $defaultTimezone) === $tz)>{{ $tz }}</option>
        @endforeach
    </select>
</x-field>
<x-field name="public_intro" label="Begrüßung auf der Buchungsseite" :id="$p.'-public_intro'"
         :help="'Ein, zwei Sätze über dem Buchungsformular, bis '.$introMax.' Zeichen.'">
    <textarea id="{{ $p }}-public_intro" name="public_intro" rows="3" maxlength="{{ $introMax }}"
              aria-describedby="{{ $p }}-public_intro-help{{ $introError ? ' '.$p.'-public_intro-error' : '' }}" @if($introError) aria-invalid="true" @endif
              class="w-full">{{ old('public_intro', $loc?->public_intro) }}</textarea>
</x-field>
