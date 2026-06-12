@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Storniert')
@section('content')
<div class="rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">👋</div>
    <h1 class="mt-3 text-2xl font-bold">Reservierung storniert</h1>
    <p class="mt-2 text-stone-600">Schade, dass es nicht klappt. Wir hoffen, Sie bald bei uns begrüßen zu dürfen!</p>
    <p class="mt-4 font-semibold">{{ $location->name }}</p>
</div>
@endsection
