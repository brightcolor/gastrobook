@props(['reset', 'filters' => []])
@php
    // Keep only filters that actually carry a value.
    $active = array_filter($filters, fn ($v) => $v !== null && $v !== '' && $v !== []);
@endphp
@if(count($active) > 0)
    <div class="mb-4 flex flex-wrap items-center gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm">
        <span class="font-semibold text-amber-800">Filter aktiv:</span>
        @foreach($active as $label => $value)
            <span class="inline-flex items-center gap-1 rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-amber-900 ring-1 ring-amber-200">
                <span class="text-amber-500">{{ $label }}:</span> {{ $value }}
            </span>
        @endforeach
        <a href="{{ $reset }}"
           class="ml-auto inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-bold text-white hover:bg-amber-700">
            ✕ Filter löschen
        </a>
    </div>
@endif
