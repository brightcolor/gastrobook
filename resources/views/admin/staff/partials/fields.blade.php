{{-- Profil einer Person in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID der Person).
     Kurzvorstellung, Farbe und Leistungen sind von Hand gebaut: Fehler und Hinweis hängen über aria-describedby am Feld bzw. an der Gruppe.
     Das Farbfeld des Browsers kennt nur #rrggbb; eine andere gespeicherte Schreibweise ersetzt die Vorgabe aus den Einstellungen. --}}
@php($p = 'person-'.$prefix)
@php($bioError = $errors->first('bio'))
@php($colorError = $errors->first('color'))
@php($servicesError = $errors->first('service_ids'))
@php($storedColor = preg_match('/^#[0-9a-fA-F]{6}$/D', (string) $member?->color) === 1 ? $member->color : $defaultColor)
@php($selected = array_map('intval', (array) old('service_ids', $member?->services->pluck('id')->all() ?? [])))
<x-field name="name" label="Name" :id="$p.'-name'" :value="$member?->name" required :maxlength="$limits['staff_name_max']" placeholder="etwa Alex" autocomplete="off" />
<x-field name="bio" label="Kurzvorstellung" :id="$p.'-bio'" help="Eine Notiz für dein Team, etwa Spezialgebiet oder Erfahrung.">
    <textarea id="{{ $p }}-bio" name="bio" rows="2" maxlength="{{ $limits['staff_bio_max'] }}"
              aria-describedby="{{ $p }}-bio-help{{ $bioError ? ' '.$p.'-bio-error' : '' }}" @if($bioError) aria-invalid="true" @endif
              class="w-full">{{ old('bio', $member?->bio) }}</textarea>
</x-field>
<x-field name="color" label="Farbe im Terminplan" :id="$p.'-color'" help="Die Termine der Person erscheinen im Terminplan in dieser Farbe.">
    <input id="{{ $p }}-color" type="color" name="color" value="{{ old('color', $storedColor) }}"
           aria-describedby="{{ $p }}-color-help{{ $colorError ? ' '.$p.'-color-error' : '' }}" @if($colorError) aria-invalid="true" @endif
           class="h-10 w-16 cursor-pointer rounded border border-stone-300 bg-white p-1">
</x-field>
<fieldset id="{{ $p }}-leistungen" class="space-y-1" aria-describedby="{{ $p }}-leistungen-hinweis{{ $servicesError ? ' '.$p.'-leistungen-error' : '' }}">
    <legend class="mb-1 text-sm font-semibold text-stone-800">Welche Leistungen bietet die Person an?</legend>
    @if($services->isEmpty())
        <p id="{{ $p }}-leistungen-hinweis" class="text-sm text-stone-600">Leistungen legst du unter <a href="{{ route('admin.services.index') }}" class="font-semibold underline underline-offset-2">Leistungen</a> an.</p>
    @else
        <p id="{{ $p }}-leistungen-hinweis" class="mb-2 text-[0.8125rem] text-stone-600">Gäste buchen die angehakten Leistungen bei dieser Person.</p>
        @foreach($services as $service)
            <label class="flex min-h-6 items-center gap-3 py-0.5">
                <input type="checkbox" name="service_ids[]" value="{{ $service->id }}" @checked(in_array($service->id, $selected, true)) @if($servicesError) aria-invalid="true" @endif class="h-6 w-6 shrink-0">
                <span class="[overflow-wrap:anywhere]">{{ $service->name }} <span class="text-stone-600">({{ $service->durationFormatted() }})</span></span>
            </label>
        @endforeach
    @endif
    @if($servicesError)
        <p id="{{ $p }}-leistungen-error" class="text-[0.8125rem] text-red-700">{{ $servicesError }}</p>
    @endif
</fieldset>
<x-checkbox name="is_active" :id="$p.'-active'" label="Buchbar" :checked="$member?->is_active ?? true"
            help="Mit Haken wählen Gäste die Person online. Ist der Haken entfernt, ist sie pausiert: Sie bleibt mit ihren Terminen erhalten." />
