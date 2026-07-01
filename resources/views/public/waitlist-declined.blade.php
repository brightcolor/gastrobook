@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Abgelehnt')
@section('content')
@php($du = $location?->effectiveSettings()->du() ?? false)
<div class="rounded-2xl bg-white p-6 text-center shadow-sm">
    <h1 class="text-xl font-bold">Angebot abgelehnt</h1>
    <p class="mt-2 text-stone-600">{{ $du ? 'Du bleibst' : 'Sie bleiben' }} auf der Warteliste. Wir melden uns, falls ein anderer Tisch frei wird.</p>
</div>
@endsection
