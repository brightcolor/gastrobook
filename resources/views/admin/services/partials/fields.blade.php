{{-- Felder einer Leistung in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID der Leistung).
     Beschreibung, Farbe und Personen sind von Hand gebaut: Fehler und Hinweis hängen über aria-describedby am Feld bzw. an der Gruppe.
     Das Farbfeld des Browsers kennt nur #rrggbb; eine andere gespeicherte Schreibweise ersetzt die Vorgabe aus den Einstellungen.
     Der Preis steht in Euro mit Punkt (die Spalte speichert Cent), exakt aus den Cent zurückgerechnet. --}}
@php($p = 'svc-'.$prefix)
@php($descriptionError = $errors->first('description'))
@php($colorError = $errors->first('color'))
@php($staffError = $errors->first('staff_ids'))
@php($storedColor = preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $service?->color) === 1 ? $service->color : $defaultColor)
@php($priceMinor = (int) ($service?->price_minor ?? 0))
@php($selected = array_map('intval', (array) old('staff_ids', $service?->staff->pluck('id')->all() ?? [])))
<x-field name="name" label="Name" :id="$p.'-name'" :value="$service?->name" required :maxlength="$limits['service_name_max']" placeholder="etwa Haarschnitt" autocomplete="off" />
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="duration_minutes" label="Dauer" type="number" unit="Min." :minutes="true" :id="$p.'-duration'" required
             :value="$service?->duration_minutes ?? 30" :min="$limits['service_duration_min']" :max="$limits['service_duration_max']" />
    <x-field name="price" label="Preis" type="number" unit="€" :id="$p.'-price'" required step="0.01" min="0" :max="$limits['service_price_max']"
             :value="sprintf('%d.%02d', intdiv($priceMinor, 100), $priceMinor % 100)"
             help="Bei 0 steht auf der Buchungsseite „auf Anfrage“." />
</div>
<x-field name="description" label="Beschreibung" :id="$p.'-description'" help="Ein Satz für die Buchungsseite.">
    <textarea id="{{ $p }}-description" name="description" rows="2" maxlength="{{ $limits['service_description_max'] }}"
              aria-describedby="{{ $p }}-description-help{{ $descriptionError ? ' '.$p.'-description-error' : '' }}" @if($descriptionError) aria-invalid="true" @endif
              class="w-full">{{ old('description', $service?->description) }}</textarea>
</x-field>
<x-field name="color" label="Farbe" :id="$p.'-color'" help="Die Farbe steht als Punkt vor dem Namen der Leistung in der Liste.">
    <input id="{{ $p }}-color" type="color" name="color" value="{{ old('color', $storedColor) }}"
           aria-describedby="{{ $p }}-color-help{{ $colorError ? ' '.$p.'-color-error' : '' }}" @if($colorError) aria-invalid="true" @endif
           class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
</x-field>
<fieldset id="{{ $p }}-personen" class="space-y-1" aria-describedby="{{ $p }}-personen-hinweis{{ $staffError ? ' '.$p.'-personen-error' : '' }}">
    <legend class="mb-1 text-sm font-semibold text-stone-800">Wer bietet die Leistung an?</legend>
    @if($staff->isEmpty())
        <p id="{{ $p }}-personen-hinweis" class="text-sm text-stone-600">Personen legst du unter <a href="{{ route('admin.staff.index') }}" class="font-semibold underline underline-offset-2">Mitarbeiter</a> an.</p>
    @else
        <p id="{{ $p }}-personen-hinweis" class="mb-2 text-[0.8125rem] text-stone-600">Gäste buchen die Leistung bei den angehakten Personen.</p>
        @foreach($staff as $member)
            <label class="flex min-h-6 items-center gap-3 py-0.5">
                <input type="checkbox" name="staff_ids[]" value="{{ $member->id }}" @checked(in_array($member->id, $selected, true)) @if($staffError) aria-invalid="true" @endif class="h-6 w-6 shrink-0">
                <span class="[overflow-wrap:anywhere]">{{ $member->name }}</span>
            </label>
        @endforeach
    @endif
    @if($staffError)
        <p id="{{ $p }}-personen-error" class="text-[0.8125rem] text-red-700">{{ $staffError }}</p>
    @endif
</fieldset>
<x-checkbox name="is_active" :id="$p.'-active'" label="Online buchbar" :checked="$service?->is_active ?? true"
            help="Mit Haken buchen Gäste die Leistung online. Ist der Haken entfernt, ist sie pausiert: Sie bleibt erhalten, und bestehende Termine behalten sie." />
