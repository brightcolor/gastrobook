@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Warteliste')
@section('content')
@php($du = $location?->effectiveSettings()->du() ?? false)
<div class="overflow-hidden rounded-3xl bg-white text-center shadow-xl shadow-stone-400/15 ring-1 ring-black/5">
    <div class="h-1.5 bg-brand"></div>
    <div class="p-6 sm:p-8">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-brand/10 text-4xl">🔔</div>
        <h1 class="mt-4 text-2xl font-extrabold tracking-tight">{{ $du ? 'Du stehst' : 'Sie stehen' }} auf der Warteliste!</h1>
        <p class="mt-2 text-sm leading-relaxed text-stone-600">
            @if($du)
                Sobald ein Tisch für dich frei wird, erhältst du sofort eine E-Mail mit einem
                Bestätigungslink – einfach klicken und der Tisch gehört dir.
            @else
                Sobald ein Tisch für Sie frei wird, erhalten Sie sofort eine E-Mail mit einem
                Bestätigungslink – einfach klicken und der Tisch gehört Ihnen.
            @endif
        </p>
        <p class="mt-6 font-semibold text-stone-700">{{ $location->name }}</p>
    </div>
</div>
@endsection
