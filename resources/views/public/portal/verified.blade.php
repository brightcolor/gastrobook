@extends('layouts.public', ['tenant' => $reservation?->location?->tenant ?? null])
@section('title', 'E-Mail bestätigt')
@section('content')
@php($du = $reservation?->location?->effectiveSettings()->du() ?? false)
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">✅</div>
    <h1 class="mt-3 text-2xl font-bold">E-Mail bestätigt</h1>
    @if($reservation)
        <p class="mt-2 text-stone-600">
            Vielen Dank! {{ $du ? 'Deine' : 'Ihre' }} Buchung <strong>{{ $reservation->code }}</strong>
            @if($reservation->status->value === 'confirmed') ist jetzt bestätigt. @else wird bearbeitet. @endif
        </p>
        <a href="{{ route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="mt-5 inline-block text-sm text-brand underline">Buchung ansehen</a>
    @else
        <p class="mt-2 text-stone-600">{{ $du ? 'Deine' : 'Ihre' }} E-Mail-Adresse wurde bestätigt.</p>
    @endif
</div>
@endsection
