@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Reservierung verwalten')
@section('content')
<div class="rounded-2xl bg-white p-6 shadow-sm">
    <h1 class="text-xl font-bold">Ihre Reservierung</h1>
    <div class="mt-4 space-y-2 rounded-xl bg-stone-50 p-4 text-sm">
        <div class="flex justify-between"><span class="text-stone-500">Restaurant</span><strong>{{ $location->name }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Datum</span><strong>{{ $reservation->localStart()->format('d.m.Y') }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Uhrzeit</span><strong>{{ $reservation->localStart()->format('H:i') }} Uhr</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Personen</span><strong>{{ $reservation->party_size }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Status</span><strong>{{ __('reservations.status.' . $reservation->status->value) }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Nr.</span><strong>{{ $reservation->code }}</strong></div>
    </div>

    @if(request()->boolean('paid') || $reservation->payment_status === 'paid')
        <div class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">✅ Anzahlung erhalten – vielen Dank!</div>
    @elseif($payEnabled ?? false)
        <a href="{{ route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="mt-6 block rounded-xl bg-emerald-600 py-3.5 text-center text-lg font-bold text-white hover:bg-emerald-700">
            Anzahlung bezahlen · {{ number_format($reservation->payment_amount_minor / 100, 2, ',', '.') }} {{ $reservation->currency }}
        </a>
        <p class="mt-2 rounded-xl bg-stone-50 p-3 text-xs text-stone-600">
            💶 Die Anzahlung wird bei Ihrem Besuch <strong>vollständig mit der Rechnung verrechnet</strong>.
            Bei Nichterscheinen (No-Show) erfolgt <strong>keine Rückerstattung</strong>.
        </p>
        @if($reservation->payment_due_at)
            <p class="mt-1 text-xs text-stone-500">Bitte zahlen Sie bis {{ $reservation->payment_due_at->setTimezone($location->timezone)->format('d.m.Y H:i') }} Uhr, sonst verfällt die Reservierung.</p>
        @endif
    @endif

    @if($reservation->status->isActive())
        <a href="{{ route('booking.reschedule', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="mt-6 block rounded-xl border-2 border-stone-200 py-3 text-center font-bold hover:border-brand">
            Termin umbuchen
        </a>
    @endif

    @if($cancellable)
        <form method="POST" action="{{ route('booking.cancel', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
              onsubmit="return confirm('Reservierung wirklich stornieren?')" class="mt-3">
            @csrf
            <label for="reason" class="mb-1 block text-sm font-semibold">Grund (optional)</label>
            <input type="text" name="reason" id="reason" class="mb-3 w-full rounded-xl border-2 border-stone-200 px-4 py-3">
            <button class="w-full rounded-xl bg-red-600 py-3 font-bold text-white hover:bg-red-700">Reservierung stornieren</button>
        </form>
        <p class="mt-3 text-xs text-stone-500">Kostenfreie Stornierung bis {{ $deadline->setTimezone($location->timezone)->format('d.m.Y H:i') }} Uhr.</p>
    @elseif($reservation->status->isActive())
        <p class="mt-6 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
            Die Online-Stornierungsfrist ist abgelaufen. Bitte kontaktieren Sie uns telefonisch: <strong>{{ $location->phone }}</strong>
        </p>
    @else
        <p class="mt-6 rounded-xl bg-stone-50 p-4 text-sm text-stone-600">Diese Reservierung ist nicht mehr aktiv.</p>
    @endif
</div>
@endsection
