@extends('layouts.public', ['tenant' => $location->tenant])
@php
$isSalon = $location->tenant?->isSalon();
@endphp
@section('title', $isSalon ? 'Termin bestätigt' : 'Reservierung bestätigt')
@section('content')

@php
$isRequested = $reservation->status->value === 'requested';
$isPending   = $reservation->status->value === 'payment_pending';
$isConfirmed = ! $isRequested && ! $isPending;

$settings    = $location->effectiveSettings();
$confetti    = $isConfirmed && $settings->confetti_on_booking;
$du          = $settings->guest_address === 'du';

// Warm welcome – build the companion clause
$party   = $reservation->party_size;
if ($party >= 3) {
    $companions = ($du ? 'deine ' : 'Ihre ') . ($party - 1) . ' Begleitungen';
} elseif ($party === 2) {
    $companions = $du ? 'deine Begleitung' : 'Ihre Begleitung';
} else {
    $companions = null;
}

// German date + time
$start    = $reservation->localStart();
$weekdays = ['Sonntag', 'Montag', 'Dienstag', 'Mittwoch', 'Donnerstag', 'Freitag', 'Samstag'];
$months   = ['', 'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
             'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
$dateStr  = $weekdays[$start->dayOfWeek] . ', ' . $start->day . '. ' . $months[$start->month];
$timeStr  = $start->format('H:i');

// Build the full welcome sentence
$subject = $du ? 'dich' : 'Sie';
if ($companions) {
    $welcomeMsg = 'Wir freuen uns, ' . $subject . ' und ' . $companions . ' am ' . $dateStr . ' um ' . $timeStr . ' Uhr bei uns begrüßen zu dürfen.';
} else {
    $welcomeMsg = 'Wir freuen uns, ' . $subject . ' am ' . $dateStr . ' um ' . $timeStr . ' Uhr bei uns begrüßen zu dürfen.';
}
@endphp

<div class="overflow-hidden rounded-3xl bg-white text-center shadow-xl shadow-stone-400/15 ring-1 ring-black/5">
    <div class="h-1.5 bg-brand"></div>
    <div class="p-6 sm:p-8">

    {{-- Status-Icon --}}
    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full {{ $isRequested ? 'bg-amber-50' : ($isPending ? 'bg-blue-50' : 'bg-brand/10') }} text-4xl">
        {{ $isRequested ? '⏳' : ($isPending ? '💳' : '✅') }}
    </div>

    <h1 class="mt-4 text-2xl font-extrabold tracking-tight">
        @if($isRequested) Anfrage erhalten
        @elseif($isPending) Zahlung ausstehend
        @else {{ $isSalon ? 'Termin bestätigt!' : 'Reservierung bestätigt!' }}
        @endif
    </h1>

    @if($isConfirmed)
        <p class="mt-2 text-sm leading-relaxed text-stone-600">
            {{ $welcomeMsg }}
        </p>
    @else
        <p class="mt-2 text-sm text-stone-500">
            @if($isRequested)
                Wir prüfen Ihre Anfrage und melden uns schnellstmöglich.
            @elseif($isPending)
                Ihre {{ $isSalon ? 'Buchung' : 'Reservierung' }} wird nach Zahlungseingang bestätigt.
            @endif
        </p>
    @endif

    @if(session('email_confirmation_sent'))
        <div class="mt-4 rounded-xl bg-amber-50 p-3.5 text-sm text-amber-900">
            📧 Bitte bestätigen Sie Ihre E-Mail-Adresse über den Link, den wir Ihnen gerade geschickt haben –
            erst danach ist Ihre {{ $isSalon ? 'Buchung' : 'Reservierung' }} verbindlich.
        </div>
    @endif

    {{-- Details --}}
    <div class="mt-6 rounded-2xl bg-stone-50 text-left text-sm">
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
            @if($isSalon)
                @if($reservation->services->isNotEmpty())
                    <div class="flex items-start justify-between px-4 py-2.5">
                        <span class="text-stone-500">Leistungen</span>
                        <strong class="text-right">{{ $reservation->services->pluck('name')->join(', ') }}</strong>
                    </div>
                @endif
                @if($reservation->staffMember)
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-stone-500">Mitarbeiter:in</span>
                        <strong>{{ $reservation->staffMember->name }}</strong>
                    </div>
                @endif
                <div class="flex items-center justify-between px-4 py-2.5">
                    <span class="text-stone-500">Terminnr.</span>
                    <strong class="font-mono tracking-wide">{{ $reservation->code }}</strong>
                </div>
            @else
                <div class="flex items-center justify-between px-4 py-2.5">
                    <span class="text-stone-500">Personen</span>
                    <strong>{{ $reservation->party_size }}</strong>
                </div>
                <div class="flex items-center justify-between px-4 py-2.5">
                    <span class="text-stone-500">Reservierungsnr.</span>
                    <strong class="font-mono tracking-wide">{{ $reservation->code }}</strong>
                </div>
            @endif
        </div>
    </div>

    @if($isPending && $reservation->payment_amount_minor > 0)
        <a href="{{ route('pay.reservation', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
           class="btn-brand mt-6 flex items-center justify-center gap-2 rounded-xl py-3.5 text-base font-bold text-white transition-all active:scale-[0.99]">
            <span>💳</span>
            Jetzt Anzahlung bezahlen · {{ number_format($reservation->payment_amount_minor / 100, 2, ',', '.') }} {{ $reservation->currency }}
        </a>
        <p class="mt-2 rounded-xl bg-stone-50 p-3 text-left text-xs text-stone-600">
            💶 Die Anzahlung wird bei Ihrem Besuch <strong>vollständig mit der Rechnung verrechnet</strong>.
            Bei Nichterscheinen (No-Show) erfolgt <strong>keine Rückerstattung</strong>.
        </p>
    @endif

    <a href="{{ route('booking.manage', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
       class="mt-6 inline-block text-sm text-brand underline">
        {{ $isSalon ? 'Termin' : 'Reservierung' }} verwalten oder stornieren
    </a>

    </div>
</div>

@if($confetti)
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
<script>
(function () {
    var brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#0f766e';
    var colors = [brand, '#ffffff', '#fcd34d', '#f9a8d4'];

    function burst(origin) {
        confetti({
            particleCount: 80,
            spread: 70,
            origin: origin,
            colors: colors,
            scalar: 1.1,
            gravity: 0.9,
            ticks: 220,
        });
    }

    // Main burst from center-top, then two side bursts
    setTimeout(function () { burst({ x: 0.5, y: 0.3 }); }, 120);
    setTimeout(function () {
        burst({ x: 0.25, y: 0.45 });
        burst({ x: 0.75, y: 0.45 });
    }, 320);
})();
</script>
@endif
@endsection
