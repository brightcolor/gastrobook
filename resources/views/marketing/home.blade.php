@extends('layouts.marketing')

@push('styles')
<style>
/* ── Gradient text ──────────────────────────────────────────────── */
.g-text {
    background: linear-gradient(135deg, #14b8a6 0%, #818cf8 100%);
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* ── Animated hero background ───────────────────────────────────── */
@keyframes mesh-shift {
    0%,100% { background-position: 0% 50%; }
    50%      { background-position: 100% 50%; }
}
.hero-bg {
    background: linear-gradient(-50deg, #05111f, #091a1a, #0d0b20, #060f1c, #091c1c);
    background-size: 400% 400%;
    animation: mesh-shift 16s ease infinite;
}

/* ── Floating orbs ──────────────────────────────────────────────── */
@keyframes float-a { 0%,100%{transform:translateY(0) scale(1)}   50%{transform:translateY(-18px) scale(1.04)} }
@keyframes float-b { 0%,100%{transform:translateY(0) scale(1)}   50%{transform:translateY( 14px) scale(1.03)} }
.orb-a { animation: float-a 10s ease-in-out infinite; }
.orb-b { animation: float-b 13s ease-in-out infinite; animation-delay: -5s; }

/* ── Pulsing live dot ───────────────────────────────────────────── */
@keyframes pulse-ring {
    0%   { transform:scale(1);   opacity:.9; }
    100% { transform:scale(2.4); opacity:0; }
}
.live-dot::after {
    content:''; position:absolute; inset:0; border-radius:9999px;
    background:currentColor; animation: pulse-ring 2s ease-out infinite;
}

/* ── Mockup (3-D tilt) ──────────────────────────────────────────── */
.mockup-card {
    background: rgba(255,255,255,.04);
    border: 1px solid rgba(255,255,255,.1);
    border-radius: 20px;
    backdrop-filter: blur(16px);
    box-shadow: 0 48px 120px -24px rgba(0,0,0,.72), 0 0 0 1px rgba(255,255,255,.05) inset;
    transform: perspective(1100px) rotateY(-6deg) rotateX(3deg);
}

/* ── Notification slide-in ──────────────────────────────────────── */
@keyframes slide-right { from{opacity:0;transform:translateX(24px)} to{opacity:1;transform:translateX(0)} }
@keyframes slide-left  { from{opacity:0;transform:translateX(-24px)} to{opacity:1;transform:translateX(0)} }
.notif-r { animation: slide-right .55s cubic-bezier(.16,1,.3,1) both; }
.notif-l { animation: slide-left  .55s cubic-bezier(.16,1,.3,1) both; }
.notif-r { animation-delay: .6s; }
.notif-l { animation-delay: 1s; }

/* ── Scroll reveal ──────────────────────────────────────────────── */
.reveal {
    opacity: 0;
    transform: translateY(26px);
    transition: opacity .6s cubic-bezier(.16,1,.3,1), transform .6s cubic-bezier(.16,1,.3,1);
}
.reveal.on { opacity:1; transform:translateY(0); }
.d1{transition-delay:.08s} .d2{transition-delay:.16s} .d3{transition-delay:.24s}
.d4{transition-delay:.32s} .d5{transition-delay:.4s}  .d6{transition-delay:.48s}

/* ── Feature cards ──────────────────────────────────────────────── */
.feat-card {
    background: #fff;
    border: 1px solid #e7e5e4;
    border-radius: 18px;
    padding: 1.75rem;
    transition: box-shadow .22s ease, transform .22s ease;
}
.feat-card:hover {
    box-shadow: 0 16px 48px -12px rgba(0,0,0,.12);
    transform: translateY(-2px);
}
.feat-icon {
    width: 2.75rem; height: 2.75rem; border-radius: 12px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.25rem;
}

/* ── Bento grid ─────────────────────────────────────────────────── */
.bento { display:grid; grid-template-columns:repeat(3,1fr); gap:1.25rem; }
.bento .span2 { grid-column: span 2; }
@media(max-width:768px){ .bento{grid-template-columns:1fr 1fr;} .bento .span2{grid-column:span 2;} }
@media(max-width:520px){ .bento{grid-template-columns:1fr;}     .bento .span2{grid-column:span 1;} }

/* ── Pricing highlight ──────────────────────────────────────────── */
.price-popular {
    border: 2px solid #0d9488;
    box-shadow: 0 0 0 4px color-mix(in oklab, #0d9488 15%, transparent), 0 16px 48px -8px rgba(13,148,136,.2);
}

/* ── FAQ accordion ──────────────────────────────────────────────── */
details.faq summary { list-style:none; user-select:none; }
details.faq summary::-webkit-details-marker { display:none; }
details.faq .faq-body { display:grid; grid-template-rows:0fr; transition:grid-template-rows .32s ease; }
details.faq .faq-body > div { overflow:hidden; }
details.faq[open] .faq-body { grid-template-rows:1fr; }
details.faq .faq-icon { transition:transform .3s ease; }
details.faq[open] .faq-icon { transform:rotate(45deg); }

/* ── CTA grid pattern ───────────────────────────────────────────── */
.cta-grid {
    background-image: linear-gradient(rgba(255,255,255,.05) 1px, transparent 1px),
                      linear-gradient(90deg, rgba(255,255,255,.05) 1px, transparent 1px);
    background-size: 40px 40px;
}

/* ── Step connector ─────────────────────────────────────────────── */
.step-line { flex:1; height:1px; background: linear-gradient(90deg, #e7e5e4, transparent); }
</style>
@endpush

@section('content')

{{-- ═══════════════════════════════════════════════════════════════
     HERO
═══════════════════════════════════════════════════════════════ --}}
<section class="hero-bg relative overflow-hidden text-white" style="min-height:calc(100vh - 62px)">

    {{-- Orbs --}}
    <div class="orb-a pointer-events-none absolute -top-40 right-0 h-[700px] w-[700px] opacity-25 rounded-full"
         style="background:radial-gradient(circle at 30% 30%, #14b8a6, transparent 68%)"></div>
    <div class="orb-b pointer-events-none absolute bottom-0 -left-24 h-[560px] w-[560px] opacity-18 rounded-full"
         style="background:radial-gradient(circle at 70% 60%, #818cf8, transparent 68%)"></div>

    <div class="relative z-10 mx-auto grid max-w-6xl items-center gap-12 px-4 py-24 lg:grid-cols-[1fr_480px] lg:py-36">

        {{-- ── Text column ── --}}
        <div>
            <span class="mb-6 inline-flex items-center gap-2.5 rounded-full border border-teal-400/25 bg-teal-400/8 px-4 py-1.5 text-sm font-semibold text-teal-300">
                <span class="live-dot relative h-1.5 w-1.5 rounded-full bg-teal-400 text-teal-400"></span>
                Für Restaurants · Friseure &amp; Dienstleister
            </span>

            <h1 class="mt-4 text-5xl font-black leading-[1.05] tracking-tight md:text-[3.5rem] lg:text-[4rem]">
                <span class="g-text">Reservierungen &amp;&nbsp;Termine</span>,<br>
                die sich von selbst<br>verwalten
            </h1>

            <p class="mt-6 max-w-[44ch] text-lg leading-relaxed text-stone-300">
                Online-Buchung, Live-Board in Echtzeit, Tischplan, Zahlungen und
                No-Show-Schutz — alles in einer Plattform.
                <strong class="font-semibold text-white">DSGVO-konform, EU-Hosting,
                ohne Provision per Buchung.</strong>
            </p>

            <div class="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                <a href="{{ route('register') }}"
                   class="inline-flex items-center justify-center gap-2 rounded-xl bg-teal-500 px-8 py-4 text-base font-bold text-stone-950 shadow-lg shadow-teal-500/20 transition hover:bg-teal-400 hover:shadow-teal-400/30">
                    30 Tage kostenlos testen
                    <svg class="h-4 w-4" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="#hauptfunktionen"
                   class="inline-flex items-center justify-center rounded-xl border border-white/12 px-8 py-4 text-base font-semibold text-stone-200 backdrop-blur-sm transition hover:bg-white/8 hover:border-white/20">
                    Funktionen ansehen
                </a>
            </div>

            <p class="mt-5 text-sm text-stone-500">Keine Kreditkarte · in 10 Minuten startklar · jederzeit kündbar</p>

            {{-- Trust pills --}}
            <div class="mt-8 flex flex-wrap gap-2">
                @foreach(['🇪🇺 EU-Hosting', '🚫 Keine Provision', '🔓 Self-host möglich', '⚡ 10 Min. Setup'] as $pill)
                    <span class="rounded-full border border-white/10 bg-white/6 px-3 py-1 text-xs font-semibold text-stone-400">{{ $pill }}</span>
                @endforeach
            </div>
        </div>

        {{-- ── Product mockup column ── --}}
        <div class="relative hidden lg:block">
            <div class="mockup-card p-5">
                {{-- Browser chrome --}}
                <div class="mb-4 flex items-center gap-2">
                    <div class="flex gap-1.5">
                        <span class="h-3 w-3 rounded-full" style="background:rgba(255,59,48,.55)"></span>
                        <span class="h-3 w-3 rounded-full" style="background:rgba(255,204,0,.55)"></span>
                        <span class="h-3 w-3 rounded-full" style="background:rgba(52,199,89,.55)"></span>
                    </div>
                    <div class="flex-1 rounded-md px-3 py-1 text-center text-xs text-stone-500" style="background:rgba(255,255,255,.06)">
                        swayy.app/book/mein-restaurant
                    </div>
                </div>

                {{-- Booking widget --}}
                <div class="rounded-2xl p-5 text-sm" style="background:rgba(12,16,28,.8)">
                    <div class="mb-1 flex items-center justify-between">
                        <p class="text-xs font-bold uppercase tracking-widest text-stone-500">Datum wählen</p>
                        <span class="rounded-full px-2.5 py-0.5 text-[10px] font-bold text-teal-300" style="background:rgba(20,184,166,.12)">Live</span>
                    </div>
                    <div class="mt-3 grid grid-cols-4 gap-2">
                        @foreach(['Mo 21'=>false, 'Di 22'=>true, 'Mi 23'=>false, 'Do 24'=>false] as $d=>$on)
                            <div class="rounded-xl border-2 py-2.5 text-center text-xs font-bold
                                {{ $on ? 'border-teal-400 text-teal-300' : 'text-stone-500' }}"
                                 style="{{ $on ? 'background:rgba(20,184,166,.1)' : 'border-color:rgba(255,255,255,.09)' }}">
                                {{ $d }}
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-3 flex items-center justify-between rounded-xl border px-4 py-3" style="border-color:rgba(255,255,255,.09)">
                        <span class="text-xs text-stone-500">Personen</span>
                        <div class="flex items-center gap-3 text-xs">
                            <span class="flex h-6 w-6 cursor-pointer items-center justify-center rounded-full border font-bold text-stone-400" style="border-color:rgba(255,255,255,.12)">−</span>
                            <span class="font-extrabold text-white">2</span>
                            <span class="flex h-6 w-6 cursor-pointer items-center justify-center rounded-full border font-bold text-stone-400" style="border-color:rgba(255,255,255,.12)">+</span>
                        </div>
                    </div>

                    <p class="mt-3 mb-2 text-xs font-bold uppercase tracking-widest text-stone-500">Uhrzeit</p>
                    <div class="grid grid-cols-3 gap-2">
                        @foreach(['18:00'=>false,'18:30'=>true,'19:00'=>false,'19:30'=>false,'20:00'=>true,'20:30'=>false] as $t=>$on)
                            <div class="rounded-xl border-2 py-2 text-center text-xs font-semibold
                                {{ $on ? 'border-teal-400 text-teal-300' : 'text-stone-500' }}"
                                 style="{{ $on ? 'background:rgba(20,184,166,.1)' : 'border-color:rgba(255,255,255,.09)' }}">
                                {{ $t }}
                            </div>
                        @endforeach
                    </div>

                    <button class="mt-4 w-full rounded-xl py-3 text-sm font-bold text-stone-950 transition hover:brightness-110"
                            style="background:linear-gradient(135deg,#14b8a6,#0d9488)">
                        Jetzt reservieren →
                    </button>
                </div>
            </div>

            {{-- Floating notification – right --}}
            <div class="notif-r absolute -right-8 top-8 w-56 rounded-2xl border p-3.5 shadow-2xl"
                 style="background:rgba(10,16,28,.9);border-color:rgba(255,255,255,.1);backdrop-filter:blur(16px)">
                <div class="mb-2 flex items-center gap-2">
                    <span class="live-dot relative h-2 w-2 rounded-full bg-emerald-400 text-emerald-400"></span>
                    <p class="text-[10px] font-bold uppercase tracking-widest text-stone-400">Neue Buchung</p>
                </div>
                <p class="text-xs font-semibold text-stone-200">Tisch 4 · 19:30 · 4 Pers.</p>
                <p class="mt-0.5 text-[10px] text-stone-500">vor 12 Sekunden</p>
            </div>

            {{-- Floating Live-Board – left --}}
            <div class="notif-l absolute -left-10 bottom-20 w-52 rounded-2xl border p-3.5 shadow-2xl"
                 style="background:rgba(10,16,28,.9);border-color:rgba(255,255,255,.1);backdrop-filter:blur(16px)">
                <p class="mb-2 text-[10px] font-bold uppercase tracking-widest text-stone-400">Live-Board</p>
                <div class="space-y-1.5 text-xs">
                    <div class="flex items-center gap-2">
                        <span class="h-1.5 w-1.5 flex-none rounded-full bg-emerald-400"></span>
                        <span class="text-stone-300">18:30 · Müller · 2 P.</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-1.5 w-1.5 flex-none rounded-full bg-amber-400"></span>
                        <span class="text-stone-300">19:00 · Anfrage · 6 P.</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="h-1.5 w-1.5 flex-none rounded-full bg-blue-400"></span>
                        <span class="text-stone-300">20:00 · bestätigt · 3 P.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Fade to white --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-28" style="background:linear-gradient(to bottom,transparent,#fff)"></div>
</section>


{{-- ═══════════════════════════════════════════════════════════════
     BRANCHEN
═══════════════════════════════════════════════════════════════ --}}
<section id="branchen" class="mx-auto max-w-6xl px-4 py-24">
    <div class="text-center reveal">
        <p class="text-xs font-bold uppercase tracking-widest text-teal-600">Ein System, zwei Welten</p>
        <h2 class="mt-3 text-3xl font-black tracking-tight md:text-4xl">Für Gastronomie und Dienstleister</h2>
        <p class="mx-auto mt-3 max-w-xl text-stone-500">Dasselbe Buchungssystem – je nach Betriebstyp als Tischreservierung oder Terminbuchung konfiguriert.</p>
    </div>

    <div class="mt-14 grid gap-6 md:grid-cols-2">
        <div class="feat-card reveal d1 group relative overflow-hidden">
            <div class="absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                 style="background:radial-gradient(600px circle at var(--mx,50%) var(--my,50%), rgba(20,184,166,.06), transparent 50%)"></div>
            <div class="feat-icon mb-5" style="background:linear-gradient(135deg,#f0fdfa,#ccfbf1)">🍽️</div>
            <h3 class="text-xl font-black">Restaurants, Cafés &amp; Bars</h3>
            <p class="mt-2 text-stone-500 leading-relaxed">Tischbasierte Reservierung mit Tischplan, Kombinationen, Walk-ins und Personen-Kapazität.</p>
            <ul class="mt-5 space-y-2.5 text-sm text-stone-600">
                <li class="flex items-start gap-2.5"><span class="mt-0.5 flex-none text-teal-500 font-bold">✓</span>Grafischer Tischplan &amp; automatische Tischzuweisung</li>
                <li class="flex items-start gap-2.5"><span class="mt-0.5 flex-none text-teal-500 font-bold">✓</span>Öffentlicher Tischplan zur Tischwahl durch den Gast</li>
                <li class="flex items-start gap-2.5"><span class="mt-0.5 flex-none text-teal-500 font-bold">✓</span>Events &amp; Ticketverkauf (Brunch, Weinprobe, Silvester)</li>
            </ul>
        </div>

        <div class="feat-card reveal d2 group relative overflow-hidden">
            <div class="absolute inset-0 opacity-0 transition-opacity duration-500 group-hover:opacity-100"
                 style="background:radial-gradient(600px circle at var(--mx,50%) var(--my,50%), rgba(129,140,248,.06), transparent 50%)"></div>
            <div class="feat-icon mb-5" style="background:linear-gradient(135deg,#eff6ff,#ddd6fe)">✂️</div>
            <h3 class="text-xl font-black">Friseure &amp; Dienstleister</h3>
            <p class="mt-2 text-stone-500 leading-relaxed">Terminbuchung pro Mitarbeiter und Leistung – mit Dienstplan, Abwesenheiten und Puffern.</p>
            <ul class="mt-5 space-y-2.5 text-sm text-stone-600">
                <li class="flex items-start gap-2.5"><span class="mt-0.5 flex-none text-teal-500 font-bold">✓</span>Leistungen mit Dauer &amp; Preis, frei kombinierbar</li>
                <li class="flex items-start gap-2.5"><span class="mt-0.5 flex-none text-teal-500 font-bold">✓</span>Mitarbeiter-Arbeitszeiten, Urlaub &amp; Lückenoptimierer</li>
                <li class="flex items-start gap-2.5"><span class="mt-0.5 flex-none text-teal-500 font-bold">✓</span>Buchung bei „beliebig" oder bestimmtem Mitarbeiter</li>
            </ul>
        </div>
    </div>
</section>


{{-- ═══════════════════════════════════════════════════════════════
     HAUPTFUNKTIONEN (Bento)
═══════════════════════════════════════════════════════════════ --}}
<section id="hauptfunktionen" style="background:linear-gradient(180deg,#f9f8f7 0%,#f4f3f1 100%)">
    <div class="mx-auto max-w-6xl px-4 py-24">
        <div class="text-center reveal">
            <p class="text-xs font-bold uppercase tracking-widest text-teal-600">Hauptfunktionen</p>
            <h2 class="mt-3 text-3xl font-black tracking-tight md:text-4xl">Alles für volle Auslastung — ohne Chaos</h2>
            <p class="mx-auto mt-3 max-w-xl text-stone-500">Vom ersten Klick des Gastes bis zur Auswertung am Monatsende.</p>
        </div>

        {{-- Bento Grid --}}
        <div class="bento mt-14">

            {{-- Large card: Online-Buchung --}}
            <div class="span2 feat-card reveal d1" style="background:linear-gradient(145deg,#0f172a,#0d2626);color:white;border-color:#1e293b">
                <div class="feat-icon mb-5" style="background:rgba(20,184,166,.15)">📱</div>
                <h3 class="text-xl font-black text-white">Online-Buchung &amp; Widget</h3>
                <p class="mt-2 leading-relaxed" style="color:rgba(255,255,255,.6)">Mobile-first Buchungsseite mit Live-Verfügbarkeit – als Link teilen oder per Snippet einbetten. Optionale Tisch- bzw. Mitarbeiterwahl, Zahlungen, Erinnerungen.</p>
                <div class="mt-6 flex flex-wrap gap-2">
                    @foreach(['Link teilen', 'iFrame-Widget', 'Mobile-first', 'Live-Verfügbarkeit'] as $tag)
                        <span class="rounded-full border px-3 py-1 text-xs font-semibold" style="border-color:rgba(255,255,255,.12);color:rgba(255,255,255,.5)">{{ $tag }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Live-Board --}}
            <div class="feat-card reveal d2">
                <div class="feat-icon mb-5" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7)">🟢</div>
                <h3 class="font-black">Live-Board in Echtzeit</h3>
                <p class="mt-2 text-sm text-stone-500 leading-relaxed">Neue &amp; anstehende Buchungen auf einen Blick, Inline-Aktionen, Dark Mode. Updates per SSE ohne Reload.</p>
            </div>

            {{-- Zahlungen --}}
            <div class="feat-card reveal d3">
                <div class="feat-icon mb-5" style="background:linear-gradient(135deg,#fdf4ff,#f3e8ff)">💳</div>
                <h3 class="font-black">Zahlungen &amp; No-Show-Schutz</h3>
                <p class="mt-2 text-sm text-stone-500 leading-relaxed">Anzahlungen via Stripe &amp; PayPal, automatische Rückerstattung, Erinnerungen – No-Shows runter, Umsatz hoch.</p>
            </div>

            {{-- Tischplan --}}
            <div class="feat-card reveal d4">
                <div class="feat-icon mb-5" style="background:linear-gradient(135deg,#fff7ed,#fed7aa)">🗺️</div>
                <h3 class="font-black">Grafischer Tischplan</h3>
                <p class="mt-2 text-sm text-stone-500 leading-relaxed">Drag &amp; Drop, Zonen, Kombinationen, Hintergrundbilder. Gäste wählen ihren Tisch selbst.</p>
            </div>

            {{-- Kundenkonto --}}
            <div class="feat-card reveal d5">
                <div class="feat-icon mb-5" style="background:linear-gradient(135deg,#f0f9ff,#bae6fd)">👤</div>
                <h3 class="font-black">Kundenkonto (Magic-Link)</h3>
                <p class="mt-2 text-sm text-stone-500 leading-relaxed">Passwortloses Login: Gäste sehen, buchen um &amp; stornieren selbst – entlastet Ihr Team.</p>
            </div>

            {{-- Large card: CRM --}}
            <div class="span2 feat-card reveal d1" style="background:linear-gradient(145deg,#fefce8,#fef9c3);border-color:#fde68a">
                <div class="feat-icon mb-5" style="background:rgba(234,179,8,.12)">👥</div>
                <h3 class="text-xl font-black">Gäste-CRM &amp; Auswertungen</h3>
                <p class="mt-2 text-stone-600 leading-relaxed">Stammgäste erkennen, Besuchshistorie, Tags, Allergien, No-Show-Risiko-Hinweis, DSGVO-Export &amp; Anonymisierung – plus Berichte, API &amp; CSV-Export.</p>
            </div>

            {{-- Online-Umbuchung --}}
            <div class="feat-card reveal d2">
                <div class="feat-icon mb-5" style="background:linear-gradient(135deg,#f0fdf4,#bbf7d0)">🔁</div>
                <h3 class="font-black">Online-Umbuchung</h3>
                <p class="mt-2 text-sm text-stone-500 leading-relaxed">Gäste verschieben ihren Termin selbstständig innerhalb der Frist – mit erneuter Verfügbarkeitsprüfung.</p>
            </div>

        </div>
    </div>
</section>


{{-- ═══════════════════════════════════════════════════════════════
     FEATURE-LISTE
═══════════════════════════════════════════════════════════════ --}}
<section class="mx-auto max-w-6xl px-4 py-24">
    <div class="text-center reveal">
        <p class="text-xs font-bold uppercase tracking-widest text-teal-600">Und vieles mehr</p>
        <h2 class="mt-3 text-3xl font-black tracking-tight md:text-4xl">Jedes Detail mitgedacht</h2>
    </div>
    <div class="mt-12 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            ['✉️', 'Erinnerungen per Mail &amp; SMS (seven.io)'],
            ['⏱',  'Lückenoptimierer für enge Auslastung'],
            ['🔔', 'Warteliste mit automatischem Angebot'],
            ['🎟️', 'Events &amp; Ticketverkauf'],
            ['💰', 'Anzahlungsregeln &amp; variable Rückerstattung'],
            ['🎨', 'Eigenes Branding (Logo, Farben)'],
            ['📊', 'Berichte, REST-API &amp; Webhooks'],
            ['🏪', 'Mehrere Standorte, ein Konto'],
            ['👥', 'Rollen &amp; Rechte fürs Team'],
            ['🔐', 'Audit-Log (IP-anonymisiert)'],
            ['📄', 'Rechtstexte als Markdown pflegbar'],
            ['🐳', 'Docker · Self-host möglich · kein Lock-in'],
            ['♻️', 'Automatische Datenlöschung (DSGVO)'],
            ['🔌', 'Newsletter-Sync (MailWizz)'],
            ['💬', 'Feedback-Booster → Google-Bewertungen'],
            ['🖼️', 'Einbettbares JS-Widget (iFrame)'],
            ['🔗', 'Tischkombinationen per Klick'],
            ['🚶', 'Walk-ins in einem Klick'],
        ] as [$icon, $text])
            <div class="reveal flex items-center gap-3 rounded-xl border border-stone-100 bg-white px-4 py-3 shadow-sm">
                <span class="text-base">{{ $icon }}</span>
                <span class="text-sm text-stone-700">{!! $text !!}</span>
            </div>
        @endforeach
    </div>
</section>


{{-- ═══════════════════════════════════════════════════════════════
     HOW IT WORKS
═══════════════════════════════════════════════════════════════ --}}
<section style="background:#07111e">
    <div class="mx-auto max-w-6xl px-4 py-24">
        <div class="text-center reveal">
            <p class="text-xs font-bold uppercase tracking-widest text-teal-400">So einfach geht's</p>
            <h2 class="mt-3 text-3xl font-black tracking-tight text-white md:text-4xl">In 10 Minuten startklar</h2>
        </div>

        <div class="mt-14 flex flex-col gap-6 md:flex-row md:items-start">
            @foreach([
                ['01', 'Konto erstellen', 'Betrieb registrieren — der Testzeitraum startet sofort, ohne Zahlungsdaten.', '#14b8a6'],
                ['02', 'Einrichten',       'Öffnungszeiten, Tische und Leistungen anlegen — in wenigen Minuten fertig.', '#818cf8'],
                ['03', 'Link teilen',      'Buchungslink auf Website, Instagram oder Google — Buchungen laufen digital.', '#f59e0b'],
            ] as $i => [$num, $title, $text, $color])
                <div class="reveal d{{ $i+1 }} flex-1 rounded-2xl border p-7 text-center" style="background:rgba(255,255,255,.03);border-color:rgba(255,255,255,.08)">
                    <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl text-lg font-black text-white" style="background:{{ $color }}20;border:1px solid {{ $color }}40;color:{{ $color }}">{{ $num }}</div>
                    <h3 class="text-lg font-black text-white">{!! $title !!}</h3>
                    <p class="mt-2 text-sm leading-relaxed" style="color:rgba(255,255,255,.5)">{!! $text !!}</p>
                </div>
                @if(!$loop->last)
                    <div class="hidden items-center self-center md:flex" style="padding-top:2.5rem">
                        <div style="width:40px;height:1px;background:rgba(255,255,255,.1)"></div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</section>


{{-- ═══════════════════════════════════════════════════════════════
     PRICING
═══════════════════════════════════════════════════════════════ --}}
<section id="preise" class="mx-auto max-w-6xl px-4 py-24">
    <div class="text-center reveal">
        <p class="text-xs font-bold uppercase tracking-widest text-teal-600">Preise</p>
        <h2 class="mt-3 text-3xl font-black tracking-tight md:text-4xl">Fair &amp; monatlich kündbar</h2>
        <p class="mx-auto mt-3 max-w-xl text-stone-500">
            <strong class="text-stone-700">Voller Funktionsumfang in jedem Tarif</strong> – unbegrenzte Benutzer, API, Zahlungen, alles inklusive.
            30 Tage kostenlos, keine Provision.
        </p>
    </div>

    <div class="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
        @forelse($plans as $plan)
            @php($popular = $plan->key === 'professional')
            <div class="reveal flex flex-col rounded-2xl border bg-white p-6 d{{ $loop->index + 1 }} {{ $popular ? 'price-popular' : 'border-stone-200 shadow-sm' }}">
                @if($popular)
                    <span class="mb-3 -mt-10 self-center rounded-full px-4 py-1 text-xs font-black text-white shadow"
                          style="background:linear-gradient(135deg,#0d9488,#0f766e)">✦ Beliebt</span>
                @endif
                <h3 class="text-base font-black">{{ $plan->name }}</h3>
                <p class="mt-3 flex items-end gap-1">
                    @if($plan->key === 'enterprise')
                        <span class="text-2xl font-black">Auf Anfrage</span>
                    @else
                        <span class="text-4xl font-black leading-none">{{ number_format($plan->price_monthly_minor / 100, 0, ',', '.') }}</span>
                        <span class="mb-1 text-sm text-stone-500">€ / Monat</span>
                    @endif
                </p>

                <div class="my-5 space-y-3 border-y border-stone-100 py-4">
                    <div>
                        <p class="text-2xl font-black">{{ isset($plan->limits['max_locations']) ? $plan->limits['max_locations'] : '∞' }}</p>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-stone-400">{{ (isset($plan->limits['max_locations']) && $plan->limits['max_locations'] == 1) ? 'Standort' : 'Standorte' }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-black">{{ isset($plan->limits['max_tables']) ? 'bis '.$plan->limits['max_tables'] : '∞' }}</p>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-stone-400">Tische / Ressourcen</p>
                    </div>
                </div>

                <p class="flex-1 text-xs leading-relaxed text-stone-500">
                    <span class="font-semibold text-stone-700">Alle Funktionen inklusive</span> —
                    unbegr. Benutzer · API &amp; Webhooks · Zahlungen · Warteliste · Berichte · Branding
                </p>

                @if($plan->key === 'enterprise')
                    <a href="{{ route('contact') }}" class="mt-5 rounded-xl border border-stone-200 py-3 text-center text-sm font-bold text-stone-700 transition hover:bg-stone-50">Kontakt aufnehmen</a>
                @else
                    <a href="{{ route('register') }}"
                       class="mt-5 rounded-xl py-3 text-center text-sm font-bold transition
                              {{ $popular ? 'bg-teal-600 text-white hover:bg-teal-700' : 'border border-stone-200 text-stone-700 hover:bg-stone-50' }}">
                        Kostenlos testen
                    </a>
                @endif
            </div>
        @empty
            <p class="col-span-full text-center text-stone-500">Preise auf Anfrage –
                <a href="{{ route('contact') }}" class="font-semibold text-teal-700">kontaktieren Sie uns</a>.</p>
        @endforelse
    </div>

    <p class="mx-auto mt-8 max-w-xl text-center text-sm text-stone-400">
        Mehr Tische oder Standorte? Jederzeit upgraden — Sie zahlen nur, wenn Ihr Betrieb wächst.
    </p>
</section>


{{-- ═══════════════════════════════════════════════════════════════
     FAQ
═══════════════════════════════════════════════════════════════ --}}
<section id="faq" style="background:#f9f8f7">
    <div class="mx-auto max-w-3xl px-4 py-24">
        <div class="text-center reveal">
            <p class="text-xs font-bold uppercase tracking-widest text-teal-600">FAQ</p>
            <h2 class="mt-3 text-3xl font-black tracking-tight md:text-4xl">Häufige Fragen</h2>
        </div>

        <div class="mt-12 space-y-3">
            @foreach([
                ['Für wen ist Swayy geeignet?',
                 'Für Restaurants, Cafés und Bars (tischbasiert) ebenso wie für Friseure und Dienstleister (terminbasiert pro Mitarbeiter und Leistung). Der Betriebstyp ist pro Konto umschaltbar.'],
                ['Brauche ich eine eigene Website?',
                 'Nein. Sie erhalten einen Buchungslink für Google, Instagram & Co. Wer eine Website hat, bettet das Widget mit zwei Zeilen Code ein.'],
                ['Welche Zahlungsanbieter werden unterstützt?',
                 'Stripe und PayPal – auch beide gleichzeitig; der Gast wählt dann an der Kasse. Kreditkartendaten werden nie bei uns gespeichert.'],
                ['Was passiert nach dem Testzeitraum?',
                 'Sie wählen einen Tarif – oder nicht. Es gibt keine automatische Abbuchung, da im Test keine Zahlungsdaten erhoben werden.'],
                ['Ist Swayy DSGVO-konform?',
                 'Ja. EU-Hosting, Einwilligungsverwaltung, Datenexport und Anonymisierung pro Gast sind eingebaut, IP-Adressen werden minimiert. Rechtstexte sind als Markdown direkt pflegbar.'],
                ['Kann ich mehrere Standorte verwalten?',
                 'Ja, ab dem Multi-Location-Tarif beliebig viele Standorte unter einem Konto – mit getrennten Plänen und Berichten.'],
                ['Lässt sich Swayy selbst hosten?',
                 'Ja. Per Docker hinter eigener Domain betreibbar – volle Datenhoheit, kein Lock-in.'],
            ] as $i => [$q, $a])
                <details class="faq reveal group rounded-2xl border border-stone-200 bg-white shadow-sm">
                    <summary class="flex cursor-pointer items-center justify-between gap-4 px-6 py-4 font-bold text-stone-800">
                        <span>{{ $q }}</span>
                        <span class="faq-icon flex-none text-xl font-light text-teal-600 leading-none">+</span>
                    </summary>
                    <div class="faq-body">
                        <div><p class="px-6 pb-5 text-sm leading-relaxed text-stone-600">{{ $a }}</p></div>
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</section>


{{-- ═══════════════════════════════════════════════════════════════
     CTA
═══════════════════════════════════════════════════════════════ --}}
<section class="cta-grid relative overflow-hidden" style="background:linear-gradient(135deg,#0d9488,#0f766e,#115e59)">
    <div class="pointer-events-none absolute inset-0" style="background:radial-gradient(80rem 40rem at 50% 120%,rgba(255,255,255,.06),transparent)"></div>
    <div class="relative mx-auto max-w-4xl px-4 py-24 text-center text-white">
        <p class="text-xs font-bold uppercase tracking-widest opacity-60 reveal">Jetzt starten</p>
        <h2 class="mt-4 text-4xl font-black tracking-tight md:text-5xl reveal d1">Bereit für volle Auslastung<br>ohne Telefonchaos?</h2>
        <p class="mx-auto mt-5 max-w-xl text-lg text-teal-100 reveal d2">In wenigen Minuten eingerichtet. 30 Tage kostenlos, ohne Risiko, ohne Kreditkarte.</p>
        <a href="{{ route('register') }}"
           class="reveal d3 mt-8 inline-flex items-center gap-2 rounded-2xl bg-white px-10 py-4 text-lg font-black text-teal-800 shadow-xl shadow-teal-900/30 transition hover:bg-teal-50 hover:scale-[1.02]">
            Jetzt kostenlos starten
            <svg class="h-5 w-5" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </a>
        <p class="mt-5 text-sm text-teal-200/70 reveal d4">Keine Kreditkarte · jederzeit kündbar · DSGVO-konform</p>
    </div>
</section>

{{-- ═══════════════════════════════════════════════════════════════
     SCRIPTS
═══════════════════════════════════════════════════════════════ --}}
<script>
(function () {
    // Scroll reveal via IntersectionObserver
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (e.isIntersecting) { e.target.classList.add('on'); io.unobserve(e.target); }
        });
    }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(el => io.observe(el));

    // Radial spotlight follows mouse on feature cards
    document.querySelectorAll('.feat-card').forEach(card => {
        card.addEventListener('mousemove', e => {
            const r = card.getBoundingClientRect();
            card.style.setProperty('--mx', ((e.clientX - r.left) / r.width * 100) + '%');
            card.style.setProperty('--my', ((e.clientY - r.top)  / r.height * 100) + '%');
        });
    });
})();
</script>

@endsection
