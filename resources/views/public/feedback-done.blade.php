@extends('layouts.public', ['tenant' => $location?->tenant])
@section('title', 'Danke')
@section('content')
<div class="rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">🙏</div>
    <h1 class="mt-3 text-2xl font-bold">Vielen Dank!</h1>
    <p class="mt-2 text-stone-600">Ihr Feedback hilft uns, noch besser zu werden.</p>
    @if($location)<p class="mt-4 font-semibold">{{ $location->name }}</p>@endif
</div>
@endsection
