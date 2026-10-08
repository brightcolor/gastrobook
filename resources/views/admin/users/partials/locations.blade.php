{{-- Standortfreigabe: „Alle Standorte“ oder einzelne Standorte. Das versteckte Feld sendet 0, wenn der Haken fehlt. --}}
<fieldset class="space-y-3">
    <legend class="text-sm font-semibold text-stone-800">Standorte</legend>
    <input type="hidden" name="all_locations" value="0">
    <label class="flex items-start gap-3">
        <input type="checkbox" name="all_locations" value="1" @checked($allLocations) class="mt-1">
        <span>
            <span class="block font-semibold">Alle Standorte</span>
            <span class="block text-[0.8125rem] text-stone-600">Gilt auch für Standorte, die später dazukommen.</span>
        </span>
    </label>
    <div class="space-y-2 pl-7">
        <p class="text-[0.8125rem] text-stone-600">Ohne Haken bei „Alle Standorte“ gelten nur diese:</p>
        @foreach($locations as $loc)
            <label class="flex items-center gap-3">
                <input type="checkbox" name="location_ids[]" value="{{ $loc->id }}" @checked(in_array($loc->id, $selected, true))>
                <span class="[overflow-wrap:anywhere]">{{ $loc->name }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
