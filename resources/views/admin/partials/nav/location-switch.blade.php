{{-- Standortwahl: bei mehreren Standorten eine Auswahl, sonst der Name. --}}
@if($tenant && $tenant->locations->count() > 1)
    <form method="POST" action="{{ route('admin.switch-location') }}">
        @csrf
        <label for="{{ $switchId }}" class="sr-only">Standort wählen</label>
        <select id="{{ $switchId }}" name="location_id" data-autosubmit
                class="w-full rounded-md border-0 bg-stone-800 px-2 py-1.5 text-sm text-stone-100">
            @foreach($tenant->locations as $loc)
                <option value="{{ $loc->id }}" @selected($location && $loc->id === $location->id)>{{ $loc->name }}</option>
            @endforeach
        </select>
    </form>
@elseif($location)
    <div class="text-sm text-stone-200">{{ $location->name }}</div>
@endif
