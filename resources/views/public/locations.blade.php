@extends('layouts.public')
@section('title', 'Standort wählen – ' . $tenant->name)
@section('content')
<div class="overflow-hidden rounded-3xl bg-white shadow-xl shadow-stone-400/15 ring-1 ring-black/5">
    <div class="h-1.5 bg-brand"></div>
    <div class="p-6 sm:p-8">
    @if($tenant->brand_logo_path)
        <img src="{{ route('brand.tenant.logo', $tenant->slug) }}" alt="{{ $tenant->name }}" class="mx-auto mb-5 h-16 object-contain">
    @endif
    <h1 class="text-center text-3xl font-extrabold tracking-tight">{{ $tenant->name }}</h1>
    <div class="mx-auto mt-3 h-1 w-12 rounded-full bg-brand/70"></div>
    <p class="mt-3 text-center text-sm text-stone-600">Bitte wählen Sie einen Standort.</p>

    <div class="mt-6 space-y-3">
        @foreach($locations as $location)
            <a href="{{ route('booking.show', [$tenant->slug, $location->slug]) }}"
               class="flex items-center justify-between gap-3 rounded-xl border-2 border-stone-200 p-4 transition hover:border-brand hover:bg-stone-50">
                <span>
                    <span class="block font-bold">{{ $location->name }}</span>
                    @if($location->address_line1 || $location->city)
                        <span class="block text-sm text-stone-500">{{ trim(($location->address_line1 ? $location->address_line1.', ' : '').$location->city) }}</span>
                    @endif
                </span>
                <span class="text-brand text-xl font-bold">→</span>
            </a>
        @endforeach
    </div>
    </div>
</div>
@endsection
