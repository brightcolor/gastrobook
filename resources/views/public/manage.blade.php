@extends('layouts.public', ['tenant' => $location->tenant])
@section('title', 'Reservierung verwalten')
@section('content')

@php
$isSalon = $location->tenant?->isSalon();
$sv = $reservation->status->value;
$statusBadge = match($sv) {
    'confirmed'       => ['bg-emerald-50 text-emerald-800', '✓'],
    'requested'       => ['bg-amber-50 text-amber-800', '⏳'],
    'payment_pending' => ['bg-blue-50 text-blue-800', '💳'],
    'cancelled'       => ['bg-stone-100 text-stone-600', '✕'],
    'no_show'         => ['bg-red-50 text-red-800', '✕'],
    'completed'       => ['bg-stone-50 text-stone-500', '✓'],
    default           => ['bg-stone-100 text-stone-600', '·'],
};
@endphp

<div class="overflow-hidden rounded-3xl bg-white shadow-xl shadow-stone-400/15 ring-1 ring-black/5">
    <div class="h-1.5 bg-brand"></div>
    <div class="p-6 sm:p-8">

    <h1 class="text-2xl font-extrabold tracking-tight">{{ $isSalon ? 'Ihr Termin' : 'Ihre Reservierung' }}</h1>
    <div class="mt-1 mb-5 h-0.5 w-8 rounded-full bg-brand/60"></div>

    {{-- Status-Badge --}}
    <div class="mb-5">
        <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-sm font-semibold {{ $statusBadge[0] }}">
            <span>{{ $statusBadge[1] }}</span>
            {{ __('reservations.status.' . $sv) }}
        </span>
    </div>

    {{-- Details --}}
    <div class="rounded-2xl bg-stone-50 text-sm">
        <div class="divide-y divide-stone-100">
            <div class="flex items-center justify-between px-4 py-2.5">
                <span class="text-stone-500">{{ $isSalon ? 'Salon' : 'Restaurant' }}</span>
                <strong>{{ $location->name }}</strong>
            </div>
            <div class="flex items-center justify-between px-4 py-2.5">
                <span class="text-stone-500">Datum</span>
                <strong>{{ $reservation->localStart()->format('d.m.Y') }}</strong>
            </div>
            <div class="flex items-center justify-between px-4 py-2.5">
                <span class="text-stone-500">Uhrzeit</span>
                <strong>{{ $reservation->localStart()->format('H:i') }} Uhr</strong>
            </div>
            @if(!$isSalon)
            <div class="flex items-center justify-between px-4 py-2.5">
                <span class="text-stone-500">Personen</span>
                <strong>{{ $reservation->party_size }}</strong>
            </div>
            @endif
            @if($isSalon && $reservation->services?->isNotEmpty())
            <div class="flex items-start justify-between px-4 py-2.5">
                <span class="text-stone-500">Leistungen</span>
                <strong class="text-right">{{ $reservation->services->pluck('name')->join(', ') }}</strong>
            </div>
            @endif
            <div class="flex items-center justify-between px-4 py-2.5">
                <span class="text-stone-500">Nr.</span>
                <strong class="font-mono tracking-wide">{{ $reservation->code }}</strong>
            </div>
        </div>
    </div>

    {{-- Zahlung --}}
    @if(request()->boolean('paid') || $reservation->payment_status === 'paid')
        <div class="mt-4 flex items-center gap-2 rounded-xl bg-emerald-50 p-3.5 text-sm font-semibold text-emerald-900">
            <span class="text-base">✅</span> Anzahlung erhalten – vielen Dank!
        </div>
    @elseif($payEnabled ?? false)
        <a href="{{ route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="btn-brand mt-5 flex items-center justify-center gap-2 rounded-xl py-3.5 text-base font-bold text-white transition-all active:scale-[0.99]">
            <span>💳</span>
            Jetzt Anzahlung bezahlen · {{ number_format($reservation->payment_amount_minor / 100, 2, ',', '.') }} {{ $reservation->currency }}
        </a>
        <p class="mt-2 rounded-xl bg-stone-50 p-3 text-xs text-stone-600">
            💶 Die Anzahlung wird bei Ihrem Besuch <strong>vollständig mit der Rechnung verrechnet</strong>.
            Bei Nichterscheinen (No-Show) erfolgt <strong>keine Rückerstattung</strong>.
        </p>
        @if($reservation->payment_due_at)
            <p class="mt-1.5 text-xs text-stone-500">
                Bitte zahlen Sie bis <strong>{{ $reservation->payment_due_at->setTimezone($location->timezone)->format('d.m.Y H:i') }} Uhr</strong>, sonst verfällt die Reservierung.
            </p>
        @endif
    @endif

    {{-- Aktionen --}}
    <div class="mt-5 space-y-3">
        @if($reservation->status->isActive())
            <a href="{{ route('booking.reschedule', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
               class="flex items-center justify-center gap-2 rounded-xl border-2 border-stone-200 py-3 text-center font-semibold transition-all hover:border-brand hover:bg-brand/5">
                <span class="text-base">↻</span> Termin umbuchen
            </a>
        @endif

        @if($cancellable)
            <form method="POST" action="{{ route('booking.cancel', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
                  onsubmit="return confirm('{{ $isSalon ? 'Termin' : 'Reservierung' }} wirklich stornieren?')">
                @csrf
                <label for="reason" class="mb-1.5 block text-sm font-semibold text-stone-700">Stornierungsgrund <span class="font-normal text-stone-400">(optional)</span></label>
                <input type="text" name="reason" id="reason" placeholder="z. B. Terminkonflikt"
                       class="public-input mb-3 w-full rounded-xl border-2 border-stone-200 px-4 py-2.5 text-sm">
                <button type="submit" class="w-full rounded-xl bg-red-600 py-3 font-bold text-white transition-all hover:bg-red-700 active:scale-[0.99]">
                    {{ $isSalon ? 'Termin' : 'Reservierung' }} stornieren
                </button>
            </form>
            <p class="text-xs text-stone-500">
                Kostenfreie Stornierung bis <strong>{{ $deadline->setTimezone($location->timezone)->format('d.m.Y H:i') }} Uhr</strong>.
            </p>
        @elseif($reservation->status->isActive())
            <div class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                Die Online-Stornierungsfrist ist abgelaufen. Bitte kontaktieren Sie uns telefonisch:
                @if($location->phone)
                    <a href="tel:{{ preg_replace('/\s+/', '', $location->phone) }}" class="ml-1 font-bold underline">{{ $location->phone }}</a>
                @endif
            </div>
        @else
            <div class="rounded-xl bg-stone-50 p-4 text-sm text-stone-600">
                Diese {{ $isSalon ? 'Buchung' : 'Reservierung' }} ist nicht mehr aktiv.
            </div>
        @endif
    </div>

    </div>
</div>
@endsection
