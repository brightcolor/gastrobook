@extends('layouts.public', ['tenant' => $location->tenant])
@php $isSalon = $location->tenant?->isSalon(); @endphp
@section('title', $isSalon ? 'Termin storniert' : 'Reservierung storniert')
@section('content')
<div class="overflow-hidden rounded-3xl bg-white text-center shadow-xl shadow-stone-400/15 ring-1 ring-black/5">
    <div class="h-1.5 bg-brand"></div>
    <div class="p-6 sm:p-8">
        <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full bg-stone-50 text-4xl">👋</div>
        <h1 class="mt-4 text-2xl font-extrabold tracking-tight">Schade, dass es nicht klappt!</h1>
        <p class="mt-2 text-sm leading-relaxed text-stone-600">
            {{ $isSalon ? 'Ihr Termin' : 'Ihre Reservierung' }} wurde storniert.
            Wir würden uns freuen, Sie beim nächsten Mal bei uns begrüßen zu dürfen.
        </p>

        @if(($refund ?? null) !== null)
            <div class="mt-5 rounded-2xl bg-emerald-50 p-4 text-sm text-emerald-900">
                @if($refund->status === 'completed')
                    💶 Ihre Anzahlung von <strong>{{ $refund->amountFormatted() }}</strong> wurde bereits zurückerstattet.
                @elseif(in_array($refund->status, ['approved', 'processing']))
                    💶 Ihre Anzahlung von <strong>{{ $refund->amountFormatted() }}</strong> wird in Kürze auf Ihrem Konto gutgeschrieben.
                @else
                    💶 Ihre Rückerstattung von <strong>{{ $refund->amountFormatted() }}</strong> wird geprüft und schnellstmöglich bearbeitet.
                @endif
            </div>
        @endif

        <p class="mt-6 font-semibold text-stone-700">{{ $location->name }}</p>
        @if($location->phone)
            <p class="mt-1 text-sm text-stone-500">
                Fragen? Wir sind erreichbar unter
                <a href="tel:{{ preg_replace('/\s+/', '', $location->phone) }}" class="font-semibold text-brand underline">{{ $location->phone }}</a>
            </p>
        @endif
    </div>
</div>
@endsection
