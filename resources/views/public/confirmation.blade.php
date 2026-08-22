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

    @php
        // Wartet die Buchung auf die Bestaetigung der E-Mail-Adresse, ist das
        // die wichtigste Aussage der Seite - nicht "Anfrage erhalten". Wer den
        // Link nicht anklickt, hat keinen Tisch.
        $wartetAufMail = (bool) session('email_confirmation_sent');
        $vorgang = $isSalon ? 'Buchung' : 'Reservierung';
    @endphp

    {{-- Status-Icon --}}
    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-full {{ $wartetAufMail ? 'bg-amber-50' : ($isRequested ? 'bg-amber-50' : ($isPending ? 'bg-blue-50' : 'bg-brand/10')) }} text-4xl">
        {{ $wartetAufMail ? '📧' : ($isRequested ? '⏳' : ($isPending ? '💳' : '✅')) }}
    </div>

    <h1 class="mt-4 text-2xl font-extrabold tracking-tight">
        @if($wartetAufMail) Fast geschafft!
        @elseif($isRequested) Anfrage erhalten
        @elseif($isPending) Zahlung ausstehend
        @else {{ $isSalon ? 'Termin bestätigt!' : 'Reservierung bestätigt!' }}
        @endif
    </h1>

    @if($wartetAufMail)
        <p class="mt-2 text-sm leading-relaxed text-stone-600">
            Wir haben {{ $du ? 'dir' : 'Ihnen' }} eine E-Mail an
            <strong class="whitespace-nowrap">{{ $reservation->guest_email_snapshot }}</strong> geschickt.
            {{ $du ? 'Klicke' : 'Klicken Sie' }} auf den Link darin –
            <strong>erst dann {{ $du ? 'ist dein Tisch' : 'ist Ihr Tisch' }} reserviert.</strong>
        </p>

        <div class="mt-4 rounded-xl bg-amber-50 p-4 text-left text-sm text-amber-900">
            <p class="font-bold">📬 Keine E-Mail bekommen?</p>
            <ul class="mt-1.5 space-y-1 text-amber-800">
                <li>Die Zustellung dauert meist nur Sekunden, manchmal ein paar Minuten.</li>
                <li><strong>{{ $du ? 'Sieh' : 'Sehen Sie' }} im Spam- oder Werbeordner nach</strong> – dort landet die
                    Bestätigung erfahrungsgemäß am häufigsten.</li>
                <li>Der Link gilt 24 Stunden. Danach {{ $du ? 'buche einfach neu' : 'buchen Sie einfach neu' }}.</li>
            </ul>
        </div>
    @elseif($isConfirmed)
        <p class="mt-2 text-sm leading-relaxed text-stone-600">
            {{ $welcomeMsg }}
        </p>
    @else
        <p class="mt-2 text-sm text-stone-500">
            @if($isRequested)
                Wir prüfen {{ $du ? 'deine' : 'Ihre' }} Anfrage und melden uns schnellstmöglich.
            @elseif($isPending)
                {{ $du ? 'Deine' : 'Ihre' }} {{ $vorgang }} wird nach Zahlungseingang bestätigt.
            @endif
        </p>
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
                @if($reservation->tables->isNotEmpty())
                    <div class="flex items-center justify-between px-4 py-2.5">
                        <span class="text-stone-500">{{ $reservation->table_chosen_by_guest ? ($du ? 'Dein Wunschtisch' : 'Ihr Wunschtisch') : 'Tisch' }}</span>
                        <strong>{{ $reservation->tables->pluck('name')->join(', ') }}</strong>
                    </div>
                @endif
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
            💶 Die Anzahlung wird bei {{ $du ? 'deinem' : 'Ihrem' }} Besuch <strong>vollständig mit der Rechnung verrechnet</strong>.
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
{{-- Selbst gezeichnet statt von einem fremden CDN geladen. Die alte Fassung
     baute beim Laden dieser Seite eine Verbindung zu einem Drittanbieter auf –
     mit der IP jedes Gastes, der gerade gebucht hat, ohne Einwilligung und ohne
     Integritätsprüfung, auf einer Seite, die Buchungscode und Verwaltungstoken
     im Link trägt. --}}
<script>
(function () {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

    var brand = getComputedStyle(document.documentElement).getPropertyValue('--brand').trim() || '#0f766e';
    var colors = [brand, '#ffffff', '#fcd34d', '#f9a8d4'];

    var canvas = document.createElement('canvas');
    canvas.setAttribute('aria-hidden', 'true');
    Object.assign(canvas.style, {
        position: 'fixed', inset: '0', width: '100%', height: '100%',
        pointerEvents: 'none', zIndex: '60',
    });
    document.body.appendChild(canvas);

    var ctx = canvas.getContext('2d');
    var dpr = window.devicePixelRatio || 1;
    function resize() {
        canvas.width = window.innerWidth * dpr;
        canvas.height = window.innerHeight * dpr;
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    }
    resize();
    window.addEventListener('resize', resize);

    var parts = [];
    function burst(x, y, count) {
        for (var i = 0; i < count; i++) {
            var winkel = (-Math.PI / 2) + (Math.random() - 0.5) * 1.3;
            var tempo = 5 + Math.random() * 7;
            parts.push({
                x: x * window.innerWidth,
                y: y * window.innerHeight,
                vx: Math.cos(winkel) * tempo,
                vy: Math.sin(winkel) * tempo,
                drehung: Math.random() * Math.PI,
                dreh: (Math.random() - 0.5) * 0.3,
                groesse: 5 + Math.random() * 5,
                farbe: colors[i % colors.length],
                leben: 1,
            });
        }
    }

    var laeuft = true;
    function frame() {
        ctx.clearRect(0, 0, window.innerWidth, window.innerHeight);
        for (var i = parts.length - 1; i >= 0; i--) {
            var p = parts[i];
            p.vy += 0.22;          // Schwerkraft
            p.vx *= 0.99;          // Luftwiderstand
            p.x += p.vx;
            p.y += p.vy;
            p.drehung += p.dreh;
            p.leben -= 0.006;
            if (p.leben <= 0 || p.y > window.innerHeight + 40) {
                parts.splice(i, 1);
                continue;
            }
            ctx.save();
            ctx.globalAlpha = Math.max(0, p.leben);
            ctx.translate(p.x, p.y);
            ctx.rotate(p.drehung);
            ctx.fillStyle = p.farbe;
            ctx.fillRect(-p.groesse / 2, -p.groesse / 4, p.groesse, p.groesse / 2);
            ctx.restore();
        }
        if (parts.length > 0 && laeuft) {
            requestAnimationFrame(frame);
        } else {
            laeuft = false;
            canvas.remove();
        }
    }

    setTimeout(function () { burst(0.5, 0.3, 80); requestAnimationFrame(frame); }, 120);
    setTimeout(function () { burst(0.25, 0.45, 50); burst(0.75, 0.45, 50); }, 320);
})();
</script>
@endif
@endsection
