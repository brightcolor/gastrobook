@props(['title', 'description' => null, 'crumb' => [], 'scope' => null])
{{-- Seitenkopf: Pfad, Titel, ein Satz, Geltungsbereich, eine Hauptaktion (Slot "actions"). --}}
<div data-page-header class="mb-6 border-b border-stone-200 pb-5">
    @if($crumb)
        <p class="mb-1 text-[0.8125rem] text-stone-600">{{ implode(' › ', $crumb) }}</p>
    @endif
    <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight">{{ $title }}</h1>
            @if($description)
                <p class="mt-1.5 max-w-2xl text-sm text-stone-600">{{ $description }}</p>
            @endif
        </div>
        @if($scope || isset($actions))
            <div class="flex flex-wrap items-center gap-2.5">
                @if($scope)
                    <x-scope-switch :scope="$scope" />
                @endif
                {{ $actions ?? '' }}
            </div>
        @endif
    </div>
</div>
