@props(['scope'])
{{-- Geltungsbereich einer Einstellungsseite; bei mehreren Standorten mit Wechsel. --}}
@php
    $ctx = app(\App\Support\TenantContext::class);
    $tenant = $ctx->tenant();
    $location = $ctx->location();
@endphp
@if($scope === 'tenant')
    <span class="text-[0.8125rem] text-stone-600">Gilt für alle Standorte</span>
@elseif($tenant && $tenant->locations->count() > 1)
    <form method="POST" action="{{ route('admin.switch-location') }}" class="flex items-center gap-2 text-[0.8125rem] text-stone-600">
        @csrf
        <label for="scopeLocation">Gilt für Standort</label>
        <select id="scopeLocation" name="location_id" data-autosubmit class="h-9 py-0 text-sm font-semibold text-stone-900">
            @foreach($tenant->locations as $loc)
                <option value="{{ $loc->id }}" @selected($location && $loc->id === $location->id)>{{ $loc->name }}</option>
            @endforeach
        </select>
    </form>
@elseif($location)
    <span class="text-[0.8125rem] text-stone-600">Gilt für Standort <strong class="font-semibold text-stone-900">{{ $location->name }}</strong></span>
@endif
