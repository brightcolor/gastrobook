@props(['title'])
{{-- Leerzustand: wofür der Bereich da ist, und genau eine Aktion (Slot "action"). --}}
<div {{ $attributes->merge(['class' => 'rounded-xl border border-dashed border-stone-300 bg-white px-6 py-10 text-center']) }}>
    <p class="font-semibold text-stone-900">{{ $title }}</p>
    <p class="mx-auto mt-1 max-w-md text-sm text-stone-600">{{ $slot }}</p>
    @isset($action)
        <div class="mt-4 flex justify-center">
            {{ $action }}
        </div>
    @endisset
</div>
