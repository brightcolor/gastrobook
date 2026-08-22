@extends('layouts.public', ['tenant' => $reservation?->location?->tenant ?? null])
@section('title', 'E-Mail bestätigt')
@section('content')
@php
    $du = $reservation?->location?->effectiveSettings()->du() ?? false;
    $status = $reservation?->status;
    // Eine Buchung, die nicht mehr aktiv ist, darf hier nicht als „wird
    // bearbeitet" durchgehen. Der haeufigste Fall: Der Gast klickt den
    // Bestaetigungslink erst nach Ablauf, der Tisch ist laengst wieder frei.
    $nochAktiv = $status?->isActive() ?? false;
    $wartetAufZahlung = $status === \App\Enums\ReservationStatus::PaymentPending;
@endphp
<div class="mx-auto max-w-md rounded-2xl bg-white p-6 text-center shadow-sm">
    <div class="text-5xl">{{ $reservation && ! $nochAktiv ? '⌛' : '✅' }}</div>

    <h1 class="mt-3 text-2xl font-bold">
        {{ $reservation && ! $nochAktiv ? 'Reservierung nicht mehr gültig' : 'E-Mail bestätigt' }}
    </h1>

    @if($reservation && ! $nochAktiv)
        <p class="mt-2 text-stone-600">
            {{ $du ? 'Deine' : 'Ihre' }} E-Mail-Adresse ist bestätigt – die Buchung
            <strong>{{ $reservation->code }}</strong> ist allerdings nicht mehr aktiv.
            Die Bestätigung kam nach Ablauf der Frist, deshalb wurde der Platz
            wieder freigegeben.
        </p>
        <p class="mt-3 text-sm text-stone-500">
            {{ $du ? 'Buch gern neu' : 'Buchen Sie gern neu' }} – wir freuen uns
            auf {{ $du ? 'dich' : 'Sie' }}.
        </p>
        @if($reservation->location)
            <a href="{{ route('booking.show', [$reservation->location->tenant->slug, $reservation->location->slug]) }}"
               class="btn-brand mt-5 inline-block rounded-xl px-5 py-2.5 text-sm font-bold text-white">
                Neu buchen
            </a>
        @endif
    @elseif($reservation)
        <p class="mt-2 text-stone-600">
            Vielen Dank! {{ $du ? 'Deine' : 'Ihre' }} Buchung <strong>{{ $reservation->code }}</strong>
            @if($status?->value === 'confirmed')
                ist jetzt bestätigt.
            @elseif($wartetAufZahlung)
                ist vorgemerkt. Wir haben {{ $du ? 'dir' : 'Ihnen' }} gerade
                geschrieben, wie {{ $du ? 'du die' : 'Sie die' }} Anzahlung
                leisten {{ $du ? 'kannst' : 'können' }} – erst danach ist der
                Tisch verbindlich reserviert.
            @else
                wird bearbeitet.
            @endif
        </p>
        <a href="{{ route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="mt-5 inline-block text-sm text-brand underline">Buchung ansehen</a>
    @else
        <p class="mt-2 text-stone-600">{{ $du ? 'Deine' : 'Ihre' }} E-Mail-Adresse wurde bestätigt.</p>
    @endif
</div>
@endsection
