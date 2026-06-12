@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Warteliste')
@section('content')
<div class="rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">⏳</div>
    <h1 class="mt-3 text-2xl font-bold">Sie stehen auf der Warteliste</h1>
    <p class="mt-2 text-stone-600">Sobald ein Tisch frei wird, benachrichtigen wir Sie per E-Mail mit einem Bestätigungslink.</p>
    <p class="mt-4 font-semibold">{{ $location->name }}</p>
</div>
@endsection
