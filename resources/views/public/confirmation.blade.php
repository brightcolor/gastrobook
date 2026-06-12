@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Reservierung bestätigt')
@section('content')
<div class="rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">{{ $reservation->status->value === 'requested' ? '🕐' : '✅' }}</div>
    <h1 class="mt-3 text-2xl font-bold">
        {{ $reservation->status->value === 'requested' ? 'Anfrage erhalten' : 'Reservierung bestätigt' }}
    </h1>
    <p class="mt-2 text-stone-600">
        @if($reservation->status->value === 'requested')
            Wir prüfen Ihre Anfrage und melden uns schnellstmöglich.
        @elseif($reservation->status->value === 'payment_pending')
            Ihre Reservierung wird nach Zahlungseingang bestätigt.
        @else
            Wir freuen uns auf Ihren Besuch!
        @endif
    </p>

    <div class="mt-6 space-y-2 rounded-xl bg-stone-50 p-4 text-left text-sm">
        <div class="flex justify-between"><span class="text-stone-500">Restaurant</span><strong>{{ $location->name }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Datum</span><strong>{{ $reservation->localStart()->format('d.m.Y') }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Uhrzeit</span><strong>{{ $reservation->localStart()->format('H:i') }} Uhr</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Personen</span><strong>{{ $reservation->party_size }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Reservierungsnr.</span><strong>{{ $reservation->code }}</strong></div>
    </div>

    @if($reservation->status->value === 'payment_pending' && $reservation->payment_amount_minor > 0)
        <a href="{{ route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="mt-6 block rounded-xl bg-emerald-600 py-3.5 text-center text-lg font-bold text-white hover:bg-emerald-700">
            Jetzt Anzahlung bezahlen · {{ number_format($reservation->payment_amount_minor / 100, 2, ',', '.') }} {{ $reservation->currency }}
        </a>
        <p class="mt-2 rounded-xl bg-stone-50 p-3 text-left text-xs text-stone-600">
            💶 Die Anzahlung wird bei Ihrem Besuch <strong>vollständig mit der Rechnung verrechnet</strong>.
            Bei Nichterscheinen (No-Show) erfolgt <strong>keine Rückerstattung</strong>.
        </p>
    @endif

    <a href="{{ route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
       class="mt-6 inline-block text-sm text-brand underline">Reservierung ändern oder stornieren</a>
</div>
@endsection
