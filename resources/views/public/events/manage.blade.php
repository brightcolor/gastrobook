@extends('layouts.public', ['tenant' => $location?->tenant])
@section('title', 'Ihre Eventbuchung')
@section('content')
<div class="rounded-2xl bg-white p-6 shadow-sm">
    @if(session('just_booked'))
        <div class="text-center text-5xl">🎉</div>
        <h1 class="mt-3 text-center text-2xl font-bold">Buchung bestätigt!</h1>
    @else
        <h1 class="text-xl font-bold">Ihre Eventbuchung</h1>
    @endif

    @if(session('success'))
        <div class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm text-emerald-900">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div class="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-900">{{ $errors->first() }}</div>
    @endif

    @php($startLocal = $event?->starts_at->copy()->setTimezone($location?->timezone ?? 'Europe/Berlin'))
    <div class="mt-4 space-y-2 rounded-xl bg-stone-50 p-4 text-sm">
        <div class="flex justify-between"><span class="text-stone-500">Event</span><strong>{{ $event?->title }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Ort</span><strong>{{ $location?->name }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Datum</span><strong>{{ $startLocal?->format('d.m.Y') }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Uhrzeit</span><strong>{{ $startLocal?->format('H:i') }} Uhr</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Tickets</span><strong>{{ $booking->ticket_count }}</strong></div>
        @if($booking->amount_minor)
            <div class="flex justify-between"><span class="text-stone-500">Betrag</span><strong>{{ number_format($booking->amount_minor / 100, 2, ',', '.') }} {{ $event?->currency }}</strong></div>
        @endif
        <div class="flex justify-between"><span class="text-stone-500">Buchungsnr.</span><strong>{{ $booking->code }}</strong></div>
        <div class="flex justify-between"><span class="text-stone-500">Status</span>
            <strong>{{ ['confirmed' => 'Bestätigt', 'checked_in' => 'Eingecheckt', 'cancelled' => 'Storniert'][$booking->status] ?? $booking->status }}</strong>
        </div>
    </div>

    @if(request()->boolean('paid') || $booking->payment_status === 'paid')
        <div class="mt-4 rounded-xl bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">✅ Zahlung erhalten – vielen Dank!</div>
    @elseif($payEnabled ?? false)
        <a href="{{ route('pay.event', ['code' => $booking->code, 'token' => $booking->manage_token]) }}"
           class="mt-6 block rounded-xl bg-emerald-600 py-3.5 text-center text-lg font-bold text-white hover:bg-emerald-700">
            Jetzt bezahlen · {{ number_format($booking->amount_minor / 100, 2, ',', '.') }} {{ $event?->currency }}
        </a>
        <p class="mt-2 rounded-xl bg-stone-50 p-3 text-xs text-stone-600">
            💶 Die Vorauszahlung wird bei Ihrem Besuch <strong>vollständig mit der Rechnung verrechnet</strong>.
            Bei Nichterscheinen (No-Show) erfolgt <strong>keine Rückerstattung</strong>.
        </p>
    @endif

    @if($cancellable)
        <form method="POST" action="{{ route('events.cancel', ['code' => $booking->code, 'token' => $booking->manage_token]) }}"
              onsubmit="return confirm('Buchung wirklich stornieren?')" class="mt-6">
            @csrf
            <button class="w-full rounded-xl bg-red-600 py-3 font-bold text-white hover:bg-red-700">Buchung stornieren</button>
        </form>
        @if($event?->cancellation_deadline_at)
            <p class="mt-2 text-xs text-stone-500">Stornierung möglich bis {{ $event->cancellation_deadline_at->setTimezone($location->timezone)->format('d.m.Y H:i') }} Uhr.</p>
        @endif
    @elseif($booking->status === 'confirmed')
        <p class="mt-6 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">Die Stornierungsfrist ist abgelaufen. Bitte kontaktieren Sie uns: {{ $location?->phone }}</p>
    @endif
</div>
@endsection
