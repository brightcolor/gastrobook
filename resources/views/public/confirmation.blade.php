@extends('layouts.public', ['tenant' => $location->tenant])
@php
$isSalon = $location->tenant?->isSalon();
// Der Titel im Browser-Tab nennt denselben Stand wie die Überschrift.
$pageTitle = match ($reservation->status->value) {
    'pending_confirmation' => 'E-Mail-Adresse bestätigen',
    'requested' => 'Anfrage erhalten',
    'payment_pending' => 'Zahlung ausstehend',
    default => $isSalon ? 'Termin bestätigt' : 'Reservierung bestätigt',
};
@endphp
@section('title', $pageTitle)
@section('content')

@php
$isAwaitingClick = $reservation->status->value === 'pending_confirmation';
$isRequested = $reservation->status->value === 'requested';
$isPending   = $reservation->status->value === 'payment_pending';
$isConfirmed = ! $isAwaitingClick && ! $isRequested && ! $isPending;

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
$dateStr  = \App\Support\Weekdays::of($start) . ', ' . $start->day . '. ' . $start->locale('de')->translatedFormat('F');
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
        // Der Status trägt die Aussage auch nach dem Neuladen der Seite; der
        // Hinweis aus der Sitzung gilt nur für den ersten Aufruf.
        $wartetAufMail = $isAwaitingClick || (bool) session('email_confirmation_sent');
        $vorgang = $isSalon ? 'Buchung' : 'Reservierung';
    @endphp

    @php
        // Zustellstatus der Mail an den Gast (Postal). Solange sie unterwegs
        // ist, bleibt die Erfolgsmeldung verborgen, und die Seite bittet, sie
        // nicht zu verlassen. Ohne JavaScript gilt die Erfolgsmeldung wie bisher.
        $deliveryState = $delivery['state'] ?? null;
        $deliveryWaiting = in_array($deliveryState, ['pending', 'delayed'], true);
        $deliveryFailed = $deliveryState === 'failed';
        $phone = trim((string) $location->phone);
        $deliveryTimeout = ($du
                ? 'Die Zustellung ist noch nicht bestätigt. Sieh in ein paar Minuten in dein Postfach, auch in den Spam-Ordner.'
                : 'Die Zustellung ist noch nicht bestätigt. Sehen Sie in ein paar Minuten in Ihr Postfach, auch in den Spam-Ordner.')
            .' '.($phone !== ''
                ? ($du ? "Kommt keine E-Mail an, ruf uns an: {$phone}." : "Kommt keine E-Mail an, rufen Sie uns an: {$phone}.")
                : ($du ? 'Kommt keine E-Mail an, melde dich direkt bei uns.' : 'Kommt keine E-Mail an, melden Sie sich direkt bei uns.'));
    @endphp

    @if($delivery !== null)
    <div id="mail-delivery"
         data-status-url="{{ route('booking.mail-status', ['code' => $reservation->code, 'token' => $reservation->manage_token]) }}"
         data-poll-ms="{{ (int) config('swayy.mail_delivery.poll_seconds', 3) * 1000 }}"
         data-wait-ms="{{ (int) config('swayy.mail_delivery.wait_seconds', 90) * 1000 }}"
         data-state="{{ $deliveryState }}">
        <div data-delivery-pending role="status" aria-live="polite"
             class="rounded-2xl bg-amber-50 p-5 text-left text-sm text-amber-900 ring-1 ring-amber-200"
             @if(! $deliveryWaiting) hidden @endif>
            <div class="flex items-center gap-3">
                <span class="inline-block h-5 w-5 shrink-0 animate-spin rounded-full border-2 border-amber-300 border-t-amber-700 motion-reduce:animate-none" aria-hidden="true"></span>
                <p class="font-bold">{{ $du ? 'Einen Moment, bitte bleib auf dieser Seite.' : 'Einen Moment, bitte bleiben Sie auf dieser Seite.' }}</p>
            </div>
            <p data-delivery-message class="mt-2 leading-relaxed">{{ $deliveryWaiting ? $delivery['message'] : '' }}</p>
        </div>
        <div data-delivery-failed role="alert"
             class="rounded-2xl bg-red-50 p-5 text-left text-sm text-red-900 ring-1 ring-red-200"
             @if(! $deliveryFailed) hidden @endif>
            <p class="font-bold">{{ $du ? 'Unsere E-Mail an dich ist nicht angekommen.' : 'Unsere E-Mail an Sie ist nicht angekommen.' }}</p>
            <p data-delivery-message class="mt-2 leading-relaxed">{{ $deliveryFailed ? $delivery['message'] : '' }}</p>
            <p class="mt-2 text-xs text-red-700">Kennung für Rückfragen: <span data-delivery-reference>{{ $delivery['reference'] }}</span></p>
        </div>
        <p data-delivery-timeout class="mb-4 rounded-xl bg-stone-50 p-3 text-left text-sm text-stone-700" hidden>{{ $deliveryTimeout }}</p>
    </div>
    @endif

    <div data-delivery-success @if($deliveryWaiting || $deliveryFailed) hidden @endif>
    @if($deliveryWaiting)
        <noscript><style>[data-delivery-success]{display:block!important}[data-delivery-pending]{display:none!important}</style></noscript>
    @endif

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
            @if($settings->freigabeVonHand())
                {{ $du ? 'Klicke' : 'Klicken Sie' }} auf den Link darin, dann landet
                {{ $du ? 'deine' : 'Ihre' }} Anfrage bei uns.
            </p>
            <p class="mt-2 text-sm leading-relaxed text-stone-600">
                Wir schauen uns jede Anfrage persönlich an und
                {{ $du ? 'schreiben dir' : 'schreiben Ihnen' }}, sobald
                {{ $du ? 'dein' : 'Ihr' }} {{ $isSalon ? 'Termin' : 'Tisch' }} feststeht.
            @else
                {{ $du ? 'Klicke' : 'Klicken Sie' }} auf den Link darin –
                <strong>erst dann {{ $du ? 'ist dein Tisch' : 'ist Ihr Tisch' }} reserviert.</strong>
            @endif
        </p>

        <div class="mt-4 rounded-xl bg-amber-50 p-4 text-left text-sm text-amber-900">
            <p class="font-bold">📬 Keine E-Mail bekommen?</p>
            <ul class="mt-1.5 space-y-1 text-amber-800">
                <li>Die Zustellung dauert meist nur Sekunden, manchmal ein paar Minuten.</li>
                <li><strong>{{ \App\Support\MailNotice::spamHint($du) }}</strong></li>
                <li>Der Link gilt {{ \App\Support\Duration::minutes((int) config('swayy.guest_auth.verify_minutes', 1440)) }}. Danach {{ $du ? 'buche einfach neu' : 'buchen Sie einfach neu' }}.</li>
            </ul>
        </div>
    @elseif($isConfirmed)
        <p class="mt-2 text-sm leading-relaxed text-stone-600">
            {{ $welcomeMsg }}
        </p>
    @else
        <p class="mt-2 text-sm text-stone-500">
            @if($isRequested)
                Schön, dass {{ $du ? 'du' : 'Sie' }} zu uns {{ $du ? 'möchtest' : 'möchten' }}!
                Wir schauen uns {{ $du ? 'deine' : 'Ihre' }} Anfrage persönlich an und
                {{ $du ? 'schreiben dir' : 'schreiben Ihnen' }}, sobald alles feststeht.
                <x-spam-hint :du="$du" class="text-stone-500" />
            @elseif($isPending)
                {{ $du ? 'Deine' : 'Ihre' }} {{ $vorgang }} wird nach Zahlungseingang bestätigt.
            @endif
        </p>
    @endif

    </div>{{-- /data-delivery-success --}}

    <div class="mt-4 empty:hidden">
        @include('public.partials.newsletter-hint', ['tenant' => $location->tenant])
    </div>

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
// Wartet die Seite noch auf die Zustellung der Mail, startet das Konfetti erst
// mit der Erfolgsmeldung (siehe Skript zum Zustellstatus unten).
window.swayyConfetti = function () {
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
            window.removeEventListener('resize', resize);
            canvas.remove();
        }
    }

    setTimeout(function () { burst(0.5, 0.3, 80); requestAnimationFrame(frame); }, 120);
    setTimeout(function () { burst(0.25, 0.45, 50); burst(0.75, 0.45, 50); }, 320);
};
if (!document.querySelector('[data-delivery-success][hidden]')) window.swayyConfetti();
</script>
@endif

@if($deliveryWaiting)
<script>
// Zustellstatus der Mail an den Gast: Die Seite fragt nach, bis Postal die
// Zustellung meldet, und warnt bis dahin vor dem Verlassen. Erst dann
// erscheint die Erfolgsmeldung; scheitert die Zustellung, steht der Grund da.
(function () {
    var box = document.getElementById('mail-delivery');
    if (!box) return;

    var url = box.dataset.statusUrl;
    var pollMs = parseInt(box.dataset.pollMs, 10) || 3000;
    var waitMs = parseInt(box.dataset.waitMs, 10) || 90000;
    var startedAt = Date.now();
    var pending = box.querySelector('[data-delivery-pending]');
    var failed = box.querySelector('[data-delivery-failed]');
    var timeout = box.querySelector('[data-delivery-timeout]');
    var success = document.querySelector('[data-delivery-success]');

    function guard(e) { e.preventDefault(); e.returnValue = ''; }
    window.addEventListener('beforeunload', guard);

    function showSuccess() {
        window.removeEventListener('beforeunload', guard);
        pending.hidden = true;
        success.hidden = false;
        if (typeof window.swayyConfetti === 'function') window.swayyConfetti();
    }

    function showFailure(data) {
        window.removeEventListener('beforeunload', guard);
        pending.hidden = true;
        failed.querySelector('[data-delivery-message]').textContent = data.message || '';
        failed.querySelector('[data-delivery-reference]').textContent = data.reference || '';
        failed.hidden = false;
        failed.setAttribute('tabindex', '-1');
        failed.focus();
    }

    function showTimeout() {
        timeout.hidden = false;
        showSuccess();
    }

    function poll() {
        fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) {
                if (data.state === 'delivered' || data.state === 'none') return showSuccess();
                if (data.state === 'failed') return showFailure(data);
                pending.querySelector('[data-delivery-message]').textContent = data.message || '';
                if (Date.now() - startedAt >= waitMs) return showTimeout();
                setTimeout(poll, pollMs);
            })
            .catch(function () {
                // Netzfehler oder Drosselung: weiter versuchen, aber nicht länger
                // als die eingestellte Wartezeit.
                if (Date.now() - startedAt >= waitMs) return showTimeout();
                setTimeout(poll, pollMs);
            });
    }

    setTimeout(poll, Math.min(pollMs, 1000));
})();
</script>
@endif
@endsection
