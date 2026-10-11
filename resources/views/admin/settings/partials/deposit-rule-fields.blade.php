{{-- Felder einer Anzahlungsregel in der Seitenleiste. $prefix macht die IDs eindeutig (neu oder ID der Regel).
     Die Wochentage sind von Hand gebaute Kästchen: Montag ist der Wert 0, und ein verstecktes Feld mit einer 0 wie bei x-checkbox
     würde ihn immer mitsenden. Die leere Markierung davor meldet "Tage gewählt": Ohne Haken gilt die Regel an allen Tagen. --}}
@php($p = 'regel-'.$prefix)
@php($amountMax = $limits['amount_max'])
@php($chosenDays = array_map('intval', array_filter((array) old('weekdays', $rule?->weekdays ?? []), fn ($day) => $day !== null && $day !== '')))
@php($fromTime = $rule?->from_time ? substr((string) $rule->from_time, 0, 5) : null)
@php($untilTime = $rule?->until_time ? substr((string) $rule->until_time, 0, 5) : null)
<x-field name="name" label="Name" :id="$p.'-name'" :value="$rule?->name" required :maxlength="$limits['name_max']" autocomplete="off"
         :placeholder="$salon ? 'etwa Anzahlung für Färbungen' : 'etwa Gruppen ab 8'"
         help="Ein interner Name, an dem du die Regel wiedererkennst." />
@if($salon && $salonServices->isNotEmpty())
    <x-field name="service_id" label="Nur für Leistung" :id="$p.'-service'"
             help="Die Regel gilt, sobald der Termin diese Leistung enthält, etwa Balayage. Ohne Auswahl gilt sie für alle Leistungen.">
        <select id="{{ $p }}-service" name="service_id" aria-describedby="{{ $p }}-service-help{{ $errors->has('service_id') ? ' '.$p.'-service-error' : '' }}"
                @if($errors->has('service_id')) aria-invalid="true" @endif class="w-full">
            <option value="">Alle Leistungen</option>
            @foreach($salonServices as $service)
                <option value="{{ $service->id }}" @selected((int) old('service_id', $rule?->service_id) === $service->id)>{{ $service->name }}</option>
            @endforeach
        </select>
    </x-field>
@endif
@if(! $salon && $rooms->isNotEmpty())
    <x-field name="room_id" label="Nur für Raum" :id="$p.'-room'"
             help="Die Regel gilt, wenn der zugeteilte Tisch in diesem Raum steht, etwa im Wintergarten. Ohne Auswahl gilt sie für alle Räume.">
        <select id="{{ $p }}-room" name="room_id" aria-describedby="{{ $p }}-room-help{{ $errors->has('room_id') ? ' '.$p.'-room-error' : '' }}"
                @if($errors->has('room_id')) aria-invalid="true" @endif class="w-full">
            <option value="">Alle Räume</option>
            @foreach($rooms as $room)
                <option value="{{ $room->id }}" @selected((int) old('room_id', $rule?->room_id) === $room->id)>{{ $room->name }}</option>
            @endforeach
        </select>
    </x-field>
@endif
@unless($salon)
    <x-field name="min_party_size" label="Ab Personenzahl" type="number" unit="Pers." :id="$p.'-party'" :value="$rule?->min_party_size"
             min="1" :max="$limits['party_size_max']" class="max-w-48" inputmode="numeric" placeholder="z. B. 8"
             help="Die Regel gilt für Gruppen ab dieser Größe. Leer: für jede Gruppengröße." />
@endunless
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="amount_per_person" :label="$salon ? 'Betrag pro Termin' : 'Betrag pro Person'" type="number" unit="€" :id="$p.'-amount'" required
             :value="$rule ? \App\Support\MoneyInput::fromMinor($rule->amount_per_person_minor) : null"
             min="0" :max="$amountMax" step="0.01" inputmode="decimal" placeholder="0.00"
             :help="$salon ? 'So viel zahlt der Kunde vorab. Der Betrag wird beim Termin mit der Rechnung verrechnet.' : 'So viel zahlt jede Person vorab, bei 10 € und 4 Personen also 40 €. Der Betrag wird beim Besuch mit der Rechnung verrechnet.'" />
    <x-field name="flat_amount" label="Grundbetrag" type="number" unit="€" :id="$p.'-flat'"
             :value="$rule && $rule->flat_amount_minor > 0 ? \App\Support\MoneyInput::fromMinor($rule->flat_amount_minor) : null"
             min="0" :max="$amountMax" step="0.01" inputmode="decimal" placeholder="0.00"
             :help="$salon ? 'Ein fester Betrag, der zusätzlich zum Betrag pro Termin fällig wird.' : 'Ein fester Betrag, der zusätzlich fällig wird. 20 € Grundbetrag und 5 € pro Person ergeben bei 4 Personen 40 €.'" />
</div>
<div class="grid gap-4 sm:grid-cols-2">
    <x-field name="from_time" label="Nur ab Uhrzeit" type="time" :id="$p.'-from'" :value="$fromTime"
             :help="'Die Regel gilt erst ab dieser Uhrzeit, etwa '.($salon ? '17:00 für Termine am Abend' : '18:00 für Abendtische').'. Leer: ganztags.'" />
    <x-field name="until_time" label="Nur bis Uhrzeit" type="time" :id="$p.'-until'" :value="$untilTime"
             help="Die Regel gilt bis zu dieser Uhrzeit. Leer: bis zum Ende des Tages." />
</div>
<fieldset id="{{ $p }}-tage" class="space-y-1" aria-describedby="{{ $p }}-tage-help{{ $errors->has('weekdays') ? ' '.$p.'-tage-error' : '' }}">
    <legend class="mb-1 text-sm font-semibold text-stone-800">Nur an diesen Tagen</legend>
    <p id="{{ $p }}-tage-help" class="mb-2 text-[0.8125rem] text-stone-600">Kein Haken: an allen Tagen.</p>
    <input type="hidden" name="weekdays[]" value="">
    <div class="flex flex-wrap gap-x-5 gap-y-1">
        @foreach($weekdays as $day => $dayName)
            <label class="flex min-h-6 items-center gap-2 py-0.5">
                <input type="checkbox" name="weekdays[]" value="{{ $day }}" id="{{ $p }}-tag-{{ $day }}" @checked(in_array($day, $chosenDays, true)) class="h-6 w-6 shrink-0">
                <span aria-hidden="true">{{ $dayName }}</span><span class="sr-only">{{ $weekdayNames[$day] }}</span>
            </label>
        @endforeach
    </div>
    @if($errors->has('weekdays'))
        <p id="{{ $p }}-tage-error" class="text-[0.8125rem] text-red-700">{{ $errors->first('weekdays') }}</p>
    @endif
</fieldset>
<x-field name="payment_deadline_minutes" label="Zahlungsfrist" type="number" unit="Min." :minutes="true" :id="$p.'-deadline'"
         :value="$rule?->payment_deadline_minutes ?? $limits['deadline_default']" :min="$limits['deadline_min']" :max="$limits['deadline_max']" class="max-w-48"
         :help="$salon ? 'So lange hat der Kunde nach der Buchung Zeit zu zahlen.' : 'So lange hat der Gast nach der Buchung Zeit zu zahlen.'" />
<x-checkbox name="cancel_unpaid_automatically" :id="$p.'-storno'" label="Unbezahlte Buchungen nach Ablauf der Frist stornieren"
            :checked="$rule ? (bool) $rule->cancel_unpaid_automatically : true"
            help="Mit Haken gibt Swayy den Platz nach Ablauf der Zahlungsfrist wieder frei. Ohne Haken bleibt die Buchung offen, bis dein Team entscheidet." />
