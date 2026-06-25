@extends('layouts.marketing')

@push('styles')
<style>
:root {
    --ac:  #0d9488;
    --ac2: #0f766e;
    --acl: #f0fdfa;
    --br:  #e6e3dc;
    --sa:  #f5f3ef;
    --tx:  #1c1917;
    --mu:  #78716c;
}

/* ── Parallax (translate property keeps other transforms intact) ─── */
[data-p] { will-change: translate; }

@supports (animation-timeline: scroll()) {
    .px-ring  { animation: px1 linear both; animation-timeline: scroll(root); animation-range: 0px 900px; }
    .px-blob  { animation: px2 linear both; animation-timeline: scroll(root); animation-range: 0px 900px; }
    .px-mock  { animation: px3 linear both; animation-timeline: scroll(root); animation-range: 0px 900px; }
    .px-na    { animation: px4 linear both; animation-timeline: scroll(root); animation-range: 0px 900px; }
    .px-nb    { animation: px5 linear both; animation-timeline: scroll(root); animation-range: 0px 900px; }
    @keyframes px1 { to { translate: 0  80px; } }
    @keyframes px2 { to { translate: 0 130px; } }
    @keyframes px3 { to { translate: 0 190px; } }
    @keyframes px4 { to { translate: 0 240px; } }
    @keyframes px5 { to { translate: 0 210px; } }
}

/* ── Gradient text ─────────────────────────────────────────────── */
.gt {
    background: linear-gradient(120deg, #0d9488 0%, #6366f1 70%);
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
}

/* ── Hero ring (slow rotate) ───────────────────────────────────── */
@keyframes slow-rot { to { transform: rotate(360deg); } }
.hero-ring { animation: slow-rot 90s linear infinite; transform-origin: center; }

/* ── Pulsing live dot ──────────────────────────────────────────── */
@keyframes ldot { 0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.4)} 60%{box-shadow:0 0 0 5px rgba(16,185,129,0)} }
.ldot { display:inline-block; width:7px; height:7px; border-radius:50%; background:#10b981; animation: ldot 2.2s ease-in-out infinite; }

/* ── Scroll reveal ─────────────────────────────────────────────── */
.rv {
    opacity:0; transform: translateY(22px);
    transition: opacity .7s cubic-bezier(.16,1,.3,1), transform .7s cubic-bezier(.16,1,.3,1);
}
.rv.on { opacity:1; transform:none; }
.d1{transition-delay:.05s} .d2{transition-delay:.11s} .d3{transition-delay:.17s}
.d4{transition-delay:.23s} .d5{transition-delay:.29s} .d6{transition-delay:.35s}

/* ── Cards ─────────────────────────────────────────────────────── */
.card {
    background:#fff; border:1px solid var(--br); border-radius:20px;
    transition: box-shadow .25s ease, transform .25s ease;
}
.card:hover { box-shadow:0 12px 48px -12px rgba(0,0,0,.10); transform:translateY(-2px); }

/* ── FAQ ───────────────────────────────────────────────────────── */
details.faq summary { list-style:none; cursor:pointer; }
details.faq summary::-webkit-details-marker { display:none; }
details.faq .fb { display:grid; grid-template-rows:0fr; transition:grid-template-rows .35s ease; }
details.faq .fb > div { overflow:hidden; }
details.faq[open] .fb { grid-template-rows:1fr; }
details.faq .fi { transition:transform .32s ease; }
details.faq[open] .fi { transform:rotate(45deg); }

/* ── Mockup tilt ───────────────────────────────────────────────── */
.tilt { transform: perspective(1100px) rotateY(-5deg) rotateX(2deg); }

/* ── Notif float-in ────────────────────────────────────────────── */
@keyframes finr { from{opacity:0;translate:16px 0} to{opacity:1;translate:0 0} }
@keyframes finl { from{opacity:0;translate:-16px 0} to{opacity:1;translate:0 0} }
.finr { animation: finr .6s .5s cubic-bezier(.16,1,.3,1) both; }
.finl { animation: finl .6s .9s cubic-bezier(.16,1,.3,1) both; }

/* ── Feature alternating ───────────────────────────────────────── */
.feat-visual {
    background: var(--sa); border:1px solid var(--br); border-radius:24px;
    padding:2rem; overflow:hidden; min-height:320px;
    display:flex; align-items:center; justify-content:center;
}

/* ── Board rows ────────────────────────────────────────────────── */
.brow { transition:background .15s; border-radius:8px; }
.brow:hover { background:var(--sa); }

/* ── Slot pill ─────────────────────────────────────────────────── */
.slot { border:1.5px solid var(--br); border-radius:10px; padding:6px 0; text-align:center; font-size:12px; font-weight:600; color:var(--mu); }
.slot.on { border-color:var(--ac); background:color-mix(in oklch,var(--ac) 10%,white); color:var(--ac); }

/* ── Pricing popular ───────────────────────────────────────────── */
.pp { border:2px solid var(--ac); box-shadow:0 0 0 5px color-mix(in oklch,var(--ac) 12%,transparent), 0 16px 48px -8px color-mix(in oklch,var(--ac) 18%,transparent); }

/* ── CTA ───────────────────────────────────────────────────────── */
.cta-bg { background: linear-gradient(140deg,#0d9488 0%,#0f766e 40%,#134e4a 100%); }
.cta-dots {
    background-image: radial-gradient(rgba(255,255,255,.18) 1px, transparent 1px);
    background-size: 28px 28px;
}
</style>
@endpush

@section('content')

{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  HERO                                                        ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section class="relative overflow-hidden" style="background:#FAFAF8; min-height:calc(100vh - 62px)">

    {{-- Decorative ring (parallax slow) --}}
    <div class="px-ring pointer-events-none absolute -top-40 -right-40 opacity-[.07]">
        <svg class="hero-ring" width="700" height="700" viewBox="0 0 700 700" fill="none">
            <circle cx="350" cy="350" r="300" stroke="#0d9488" stroke-width="1"/>
            <circle cx="350" cy="350" r="240" stroke="#0d9488" stroke-width=".5"/>
            <circle cx="350" cy="350" r="180" stroke="#0d9488" stroke-width=".3"/>
            <line x1="50" y1="350" x2="650" y2="350" stroke="#0d9488" stroke-width=".3"/>
            <line x1="350" y1="50" x2="350" y2="650" stroke="#0d9488" stroke-width=".3"/>
            <circle cx="350" cy="50"  r="3" fill="#0d9488"/>
            <circle cx="350" cy="650" r="3" fill="#0d9488"/>
            <circle cx="50"  cy="350" r="3" fill="#0d9488"/>
            <circle cx="650" cy="350" r="3" fill="#0d9488"/>
        </svg>
    </div>

    {{-- Soft gradient blob (parallax medium) --}}
    <div class="px-blob pointer-events-none absolute top-0 right-0 w-[65vw] h-[65vw] max-w-3xl max-h-3xl opacity-[.22]"
         style="background:radial-gradient(circle at 60% 30%, #14b8a6, transparent 65%)"></div>

    <div class="relative z-10 mx-auto grid max-w-6xl items-center gap-16 px-4 py-20 lg:grid-cols-[1fr_460px] lg:py-28 xl:gap-24">

        {{-- Text --}}
        <div>
            <span class="mb-6 inline-flex items-center gap-2.5 rounded-full border border-stone-200 bg-white px-4 py-1.5 text-sm font-semibold text-stone-600 shadow-sm">
                <span class="ldot"></span>
                Für Restaurants · Friseure &amp; Dienstleister
            </span>

            <h1 class="mt-4 font-black tracking-tight leading-[1.04]"
                style="font-size:clamp(2.6rem,6vw,4.2rem); text-wrap:balance">
                <span class="gt">Reservierungen&nbsp;&amp;&nbsp;Termine</span>,<br>
                die sich von selbst verwalten
            </h1>

            <p class="mt-6 leading-relaxed text-stone-500" style="font-size:1.125rem; max-width:44ch; text-wrap:pretty">
                Online-Buchung, Live-Board in Echtzeit, Tischplan, Zahlungen
                und No&#8209;Show&#8209;Schutz — alles in einer Plattform.
                <strong class="font-semibold text-stone-700">DSGVO&#8209;konform, in der EU gehostet,
                ohne Provision.</strong>
            </p>

            <div class="mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                <a href="{{ route('register') }}"
                   class="group inline-flex items-center justify-center gap-2 rounded-2xl px-8 py-4 text-base font-bold text-white shadow-lg transition-all hover:shadow-xl hover:-translate-y-0.5"
                   style="background:linear-gradient(135deg,#0d9488,#0f766e); box-shadow:0 8px 24px -4px rgba(13,148,136,.35)">
                    30 Tage kostenlos testen
                    <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" viewBox="0 0 16 16" fill="none">
                        <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <a href="#hauptfunktionen"
                   class="inline-flex items-center justify-center rounded-2xl border border-stone-200 bg-white px-8 py-4 text-base font-semibold text-stone-600 shadow-sm transition hover:border-stone-300 hover:text-stone-900">
                    Funktionen ansehen
                </a>
            </div>

            <p class="mt-5 text-sm text-stone-400">Keine Kreditkarte · in 10 Minuten startklar · jederzeit kündbar</p>

            <div class="mt-7 flex flex-wrap gap-2">
                @foreach(['🇪🇺 EU-Hosting', '🚫 Keine Provision', '⚡ 10 Min. Setup', '✨ Voll anpassbar'] as $p)
                    <span class="rounded-full border border-stone-200 bg-white px-3.5 py-1 text-xs font-semibold text-stone-500 shadow-sm">{{ $p }}</span>
                @endforeach
            </div>
        </div>

        {{-- Product mockup column --}}
        <div class="relative hidden lg:block">

            {{-- Main booking widget --}}
            <div class="px-mock tilt" style="position:relative; z-index:2">
                <div style="background:#fff; border:1px solid #e6e3dc; border-radius:20px; box-shadow:0 24px 80px -16px rgba(0,0,0,.12), 0 0 0 1px rgba(0,0,0,.03); overflow:hidden">
                    {{-- Window chrome --}}
                    <div style="padding:10px 14px; border-bottom:1px solid #f0ede6; display:flex; align-items:center; gap:8px; background:#fafaf8">
                        <span style="display:flex; gap:5px">
                            <span style="width:10px;height:10px;border-radius:50%;background:#e6e3dc"></span>
                            <span style="width:10px;height:10px;border-radius:50%;background:#e6e3dc"></span>
                            <span style="width:10px;height:10px;border-radius:50%;background:#e6e3dc"></span>
                        </span>
                        <span style="flex:1; text-align:center; font-size:11px; color:#a8a29e; font-weight:500">swayy.app/book/restaurant</span>
                    </div>
                    {{-- Booking UI --}}
                    <div style="padding:20px">
                        <p style="font-size:10px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#a8a29e; margin-bottom:12px">Termin wählen</p>
                        {{-- Date row --}}
                        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:6px; margin-bottom:12px">
                            @foreach(['Mo 21'=>false,'Di 22'=>true,'Mi 23'=>false,'Do 24'=>false] as $d=>$on)
                                <div style="border-radius:10px; border:1.5px solid {{ $on ? '#0d9488' : '#e6e3dc' }}; padding:8px 4px; text-align:center; font-size:11px; font-weight:700; color:{{ $on ? '#0d9488' : '#a8a29e' }}; background:{{ $on ? 'color-mix(in oklch,#0d9488 10%,white)' : '#fff' }}">{{ $d }}</div>
                            @endforeach
                        </div>
                        {{-- Guests --}}
                        <div style="border:1px solid #e6e3dc; border-radius:10px; padding:10px 14px; display:flex; align-items:center; justify-content:space-between; margin-bottom:12px">
                            <span style="font-size:12px; color:#a8a29e">Personen</span>
                            <div style="display:flex; align-items:center; gap:12px; font-size:12px">
                                <span style="width:22px;height:22px;border-radius:50%;border:1px solid #e6e3dc;display:flex;align-items:center;justify-content:center;color:#78716c;cursor:pointer">−</span>
                                <strong style="color:#1c1917">2</strong>
                                <span style="width:22px;height:22px;border-radius:50%;border:1px solid #e6e3dc;display:flex;align-items:center;justify-content:center;color:#78716c;cursor:pointer">+</span>
                            </div>
                        </div>
                        {{-- Slots --}}
                        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:6px; margin-bottom:16px">
                            @foreach(['18:00'=>false,'18:30'=>true,'19:00'=>false,'19:30'=>false,'20:00'=>true,'20:30'=>false] as $t=>$on)
                                <div class="slot {{ $on ? 'on' : '' }}">{{ $t }}</div>
                            @endforeach
                        </div>
                        <button style="width:100%; border-radius:12px; padding:11px; background:linear-gradient(135deg,#0d9488,#0f766e); color:#fff; font-size:13px; font-weight:700; border:none; cursor:pointer">
                            Tisch reservieren →
                        </button>
                    </div>
                </div>
            </div>

            {{-- Notification A – top right --}}
            <div class="px-na finr" style="position:absolute; top:-8px; right:-52px; z-index:3">
                <div style="background:#fff; border:1px solid #e6e3dc; border-radius:14px; padding:12px 14px; box-shadow:0 8px 32px -8px rgba(0,0,0,.12); min-width:190px">
                    <div style="display:flex; align-items:center; gap:6px; margin-bottom:6px">
                        <span class="ldot"></span>
                        <span style="font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#a8a29e">Neue Buchung</span>
                    </div>
                    <p style="font-size:12px; font-weight:600; color:#1c1917; margin:0">Tisch 4 · 19:30 · 4 Pers.</p>
                    <p style="font-size:11px; color:#a8a29e; margin:2px 0 0">gerade eben</p>
                </div>
            </div>

            {{-- Notification B – bottom left --}}
            <div class="px-nb finl" style="position:absolute; bottom:20px; left:-56px; z-index:3">
                <div style="background:#fff; border:1px solid #e6e3dc; border-radius:14px; padding:12px 14px; box-shadow:0 8px 32px -8px rgba(0,0,0,.12); min-width:176px">
                    <p style="font-size:10px; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#a8a29e; margin:0 0 8px">Live-Board</p>
                    @foreach([['#10b981','18:30 · Müller · 2P'],['#f59e0b','19:00 · Anfrage · 6P'],['#3b82f6','20:00 · bestätigt · 3P']] as [$c,$l])
                        <div style="display:flex; align-items:center; gap:7px; margin-bottom:5px; font-size:11px; color:#44403c">
                            <span style="width:6px;height:6px;border-radius:50%;background:{{ $c }};flex:none"></span>{{ $l }}
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- Fade to next section --}}
    <div class="pointer-events-none absolute inset-x-0 bottom-0 h-32" style="background:linear-gradient(transparent,#fff)"></div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  STATS STRIP                                                  ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section style="background:#fff; border-top:1px solid var(--br); border-bottom:1px solid var(--br)">
    <div class="mx-auto max-w-6xl px-4 py-8">
        <div class="grid grid-cols-2 gap-px md:grid-cols-4">
            @foreach([
                ['Keine Provision', 'pro Buchung'],
                ['EU-Hosting',      'DSGVO-konform'],
                ['30 Tage',         'kostenlos testen'],
                ['10 Minuten',      'bis zur ersten Buchung'],
            ] as [$val, $label])
                <div class="px-6 text-center rv">
                    <p class="text-xl font-black text-stone-900">{{ $val }}</p>
                    <p class="mt-0.5 text-sm text-stone-400">{{ $label }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  BRANCHEN                                                     ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section id="branchen" class="mx-auto max-w-6xl px-4 py-24">
    <div class="text-center rv">
        <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">Ein System, zwei Welten</p>
        <h2 class="mt-3 font-black tracking-tight" style="font-size:clamp(1.8rem,4vw,2.8rem); text-wrap:balance">Für Gastronomie und Dienstleister</h2>
        <p class="mx-auto mt-3 text-stone-500" style="max-width:42ch; line-height:1.7">Dasselbe Buchungssystem – je nach Betriebstyp als Tischreservierung oder Terminbuchung.</p>
    </div>

    <div class="mt-14 grid gap-5 md:grid-cols-2">
        <div class="card rv d1 p-8">
            <div style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#f0fdfa,#ccfbf1);display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:1.25rem">🍽️</div>
            <h3 class="text-xl font-black">Restaurants, Cafés &amp; Bars</h3>
            <p class="mt-2 text-stone-500 leading-relaxed">Tischbasierte Reservierung mit Grundriss, Kombinationen und automatischer Tischzuweisung.</p>
            <ul class="mt-5 space-y-2.5 text-sm text-stone-600">
                @foreach(['Grafischer Tischplan & Drag-and-Drop','Öffentlicher Grundriss zur Tischwahl','Events & Ticketverkauf'] as $li)
                    <li class="flex items-start gap-2.5"><span class="mt-0.5 shrink-0 font-bold" style="color:var(--ac)">✓</span>{{ $li }}</li>
                @endforeach
            </ul>
        </div>
        <div class="card rv d2 p-8">
            <div style="width:48px;height:48px;border-radius:14px;background:linear-gradient(135deg,#eff6ff,#ddd6fe);display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:1.25rem">✂️</div>
            <h3 class="text-xl font-black">Friseure &amp; Dienstleister</h3>
            <p class="mt-2 text-stone-500 leading-relaxed">Terminbuchung pro Mitarbeiter und Leistung – mit Dienstplan, Abwesenheiten und Lückenoptimierer.</p>
            <ul class="mt-5 space-y-2.5 text-sm text-stone-600">
                @foreach(['Leistungen mit Dauer & Preis, kombinierbar','Mitarbeiter-Dienstplan & Urlaubsverwaltung','Buchung bei beliebigem oder bestimmtem Mitarbeiter'] as $li)
                    <li class="flex items-start gap-2.5"><span class="mt-0.5 shrink-0 font-bold" style="color:var(--ac)">✓</span>{{ $li }}</li>
                @endforeach
            </ul>
        </div>
    </div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  HAUPTFUNKTIONEN (alternating)                                ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section id="hauptfunktionen" style="background:var(--sa)">
<div class="mx-auto max-w-6xl px-4 py-24 space-y-32">

    <div class="text-center rv">
        <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">Hauptfunktionen</p>
        <h2 class="mt-3 font-black tracking-tight" style="font-size:clamp(1.8rem,4vw,2.8rem)">Alles für volle Auslastung</h2>
    </div>

    {{-- FEATURE 1: Online-Buchung --}}
    <div class="grid items-center gap-12 md:grid-cols-2">
        <div class="rv d1">
            <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">Online-Buchung</p>
            <h3 class="mt-3 text-2xl font-black tracking-tight leading-tight">Das Buchungs&shy;erlebnis, das Gäste lieben</h3>
            <p class="mt-4 leading-relaxed text-stone-500">Mobile-first Buchungsseite mit Live-Verfügbarkeit — als Link teilen oder direkt auf Ihrer Website einbetten. Optionale Tisch- und Mitarbeiterwahl.</p>
            <ul class="mt-6 space-y-2.5 text-sm text-stone-600">
                @foreach(['Direktlink für Google, Instagram & Co.','Einbettbares Widget mit zwei Zeilen Code','Erinnerungen per E-Mail und SMS','Passwortloses Kundenkonto per Magic-Link'] as $li)
                    <li class="flex items-start gap-2.5"><span class="mt-0.5 shrink-0 font-bold" style="color:var(--ac)">✓</span>{{ $li }}</li>
                @endforeach
            </ul>
        </div>
        <div class="feat-visual rv d2">
            {{-- Mini booking widget --}}
            <div style="background:#fff; border:1px solid var(--br); border-radius:16px; padding:20px; width:100%; max-width:300px; box-shadow:0 4px 24px -4px rgba(0,0,0,.08)">
                <p style="font-size:11px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#a8a29e; margin-bottom:14px">Datum &amp; Uhrzeit</p>
                <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:5px; margin-bottom:14px">
                    @foreach([22=>true,23=>false,24=>false,25=>false,26=>false] as $d=>$on)
                        <div style="border-radius:9px; border:1.5px solid {{ $on ? '#0d9488' : '#e6e3dc' }}; padding:7px 2px; text-align:center; font-size:10px; font-weight:700; color:{{ $on ? '#0d9488' : '#a8a29e' }}; background:{{ $on ? 'color-mix(in oklch,#0d9488 10%,white)' : 'transparent' }}">{{ $d }}</div>
                    @endforeach
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:5px; margin-bottom:14px">
                    @foreach(['18:00'=>false,'18:30'=>true,'19:00'=>false,'19:30'=>false,'20:00'=>false,'20:30'=>true] as $t=>$on)
                        <div class="slot {{ $on ? 'on' : '' }}" style="font-size:11px">{{ $t }}</div>
                    @endforeach
                </div>
                <button style="width:100%;border-radius:10px;padding:10px;background:var(--ac);color:#fff;font-size:12px;font-weight:700;border:none">Reservieren →</button>
            </div>
        </div>
    </div>

    {{-- FEATURE 2: Live-Board --}}
    <div class="grid items-center gap-12 md:grid-cols-2">
        <div class="feat-visual rv d1 order-last md:order-first">
            {{-- Mini live board --}}
            <div style="background:#fff; border:1px solid var(--br); border-radius:16px; overflow:hidden; width:100%; max-width:340px; box-shadow:0 4px 24px -4px rgba(0,0,0,.08)">
                <div style="padding:12px 16px; border-bottom:1px solid var(--br); display:flex; align-items:center; justify-content:space-between">
                    <span style="font-size:12px; font-weight:700; color:#1c1917">Live-Board</span>
                    <span class="ldot"></span>
                </div>
                <div style="padding:8px">
                    @foreach([
                        ['#10b981','18:30','Müller, 2 P.','Tisch 3','bestätigt'],
                        ['#f59e0b','19:00','Weber, 4 P.','—','Anfrage'],
                        ['#3b82f6','19:30','Schmidt, 6 P.','Tisch 7','bestätigt'],
                        ['#a8a29e','20:00','Becker, 2 P.','Tisch 1','eingetroffen'],
                    ] as [$c,$time,$guest,$table,$status])
                        <div class="brow" style="display:grid; grid-template-columns:32px 1fr 1fr 70px; align-items:center; gap:8px; padding:8px">
                            <span style="width:8px;height:8px;border-radius:50%;background:{{ $c }};display:block;margin:auto"></span>
                            <span style="font-size:11px; font-weight:600; color:#44403c">{{ $time }}</span>
                            <span style="font-size:11px; color:#78716c">{{ $guest }}</span>
                            <span style="font-size:10px; font-weight:600; padding:2px 7px; border-radius:20px; background:{{ $c }}18; color:{{ $c }}; text-align:center">{{ $status }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
        <div class="rv d2">
            <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">Live-Board</p>
            <h3 class="mt-3 text-2xl font-black tracking-tight leading-tight">Alle Buchungen auf einen Blick — in Echtzeit</h3>
            <p class="mt-4 leading-relaxed text-stone-500">Neue und anstehende Reservierungen erscheinen sofort, ohne Seiten-Reload. Ideal für Tresen und Tablet. Mit Dark Mode, Vollbild und Inline-Aktionen.</p>
            <ul class="mt-6 space-y-2.5 text-sm text-stone-600">
                @foreach(['Live-Updates per Server-Sent Events','Inline: bestätigen, ablehnen, umbuchen','Dark Mode & Vollbild-Ansicht fürs Tresen-Tablet','Tagesübersicht nach Tisch oder Zeit'] as $li)
                    <li class="flex items-start gap-2.5"><span class="mt-0.5 shrink-0 font-bold" style="color:var(--ac)">✓</span>{{ $li }}</li>
                @endforeach
            </ul>
        </div>
    </div>

    {{-- FEATURE 3: Zahlungen --}}
    <div class="grid items-center gap-12 md:grid-cols-2">
        <div class="rv d1">
            <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">Zahlungen &amp; No-Show-Schutz</p>
            <h3 class="mt-3 text-2xl font-black tracking-tight leading-tight">Anzahlungen, Rückerstattungen — automatisch</h3>
            <p class="mt-4 leading-relaxed text-stone-500">Verlangen Sie eine Anzahlung bei der Buchung. Bei Nichterscheinen greift der Schutz automatisch. Rückerstattungen laufen mit einem Klick.</p>
            <ul class="mt-6 space-y-2.5 text-sm text-stone-600">
                @foreach(['Flexible Anzahlungsregeln pro Buchungsgröße','Automatische Rückerstattung nach Frist','Erinnerungen per E-Mail und SMS vor dem Termin','Nahtlose Zahlungsabwicklung für Gäste'] as $li)
                    <li class="flex items-start gap-2.5"><span class="mt-0.5 shrink-0 font-bold" style="color:var(--ac)">✓</span>{{ $li }}</li>
                @endforeach
            </ul>
        </div>
        <div class="feat-visual rv d2">
            {{-- Payment flow mini visual --}}
            <div style="width:100%; max-width:300px; display:flex; flex-direction:column; gap:10px">
                @foreach([
                    ['✓','Buchung bestätigt','Tisch 5 · 20:00 · 4 P.','#10b981'],
                    ['💳','Anzahlung erhalten','25,00 € · gerade eben','#6366f1'],
                    ['🔔','Erinnerung gesendet','1 Tag vor dem Termin','#f59e0b'],
                ] as [$ic,$title,$sub,$col])
                    <div style="background:#fff; border:1px solid var(--br); border-radius:12px; padding:12px 14px; display:flex; align-items:center; gap:12px; box-shadow:0 2px 8px rgba(0,0,0,.04)">
                        <span style="font-size:1.1rem">{{ $ic }}</span>
                        <div>
                            <p style="font-size:12px; font-weight:700; color:#1c1917; margin:0">{{ $title }}</p>
                            <p style="font-size:11px; color:#a8a29e; margin:1px 0 0">{{ $sub }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

</div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  FEATURE-LISTE                                                ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section class="mx-auto max-w-6xl px-4 py-24">
    <div class="text-center rv">
        <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">Und vieles mehr</p>
        <h2 class="mt-3 font-black tracking-tight" style="font-size:clamp(1.8rem,4vw,2.8rem)">Jedes Detail mitgedacht</h2>
    </div>

    <div class="mt-12 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            ['🗺️','Grafischer Tischplan & Flächenzonen'],
            ['👤','Kundenkonto per Magic-Link (passwortlos)'],
            ['🔁','Online-Umbuchung durch den Gast'],
            ['✉️','E-Mail- & SMS-Erinnerungen'],
            ['⏱', 'Lückenoptimierer für dichte Auslastung'],
            ['🎟️','Events & Ticketverkauf'],
            ['🔔','Warteliste mit automatischem Angebot'],
            ['👥','Gäste-CRM mit Besuchshistorie & Tags'],
            ['📊','Berichte, CSV-Export, API & Webhooks'],
            ['🏪','Mehrere Standorte, ein Konto'],
            ['🎨','Eigenes Branding (Logo, Farben)'],
            ['🔐','Audit-Log & Rollen-/Rechteverwaltung'],
            ['♻️','Automatische DSGVO-Datenlöschung'],
            ['💬','Feedback-Booster'],
            ['📄','Rechtstexte direkt pflegbar'],
            ['🔌','Einbettbares Widget per iFrame'],
            ['🔗','Tischkombinationen per Klick'],
            ['🚶','Walk-ins in einem Klick'],
        ] as $i => [$icon, $text])
            <div class="rv flex items-center gap-3 rounded-2xl border border-stone-100 bg-white px-4 py-3.5 shadow-sm transition hover:border-stone-200 hover:shadow"
                 style="transition-delay:{{ ($i % 6) * 0.04 }}s">
                <span class="text-base flex-none">{{ $icon }}</span>
                <span class="text-sm text-stone-700">{{ $text }}</span>
            </div>
        @endforeach
    </div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  HOW IT WORKS                                                 ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section style="background:var(--sa); border-top:1px solid var(--br); border-bottom:1px solid var(--br)">
    <div class="mx-auto max-w-5xl px-4 py-24">
        <div class="text-center rv">
            <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">So einfach geht's</p>
            <h2 class="mt-3 font-black tracking-tight" style="font-size:clamp(1.8rem,4vw,2.8rem)">In 10 Minuten startklar</h2>
        </div>

        <div class="relative mt-16 grid gap-8 md:grid-cols-3">
            {{-- Connector line --}}
            <div class="absolute left-[16.6%] right-[16.6%] top-7 hidden h-px md:block" style="background:linear-gradient(90deg,transparent,var(--br),var(--br),transparent)"></div>
            @foreach([
                ['1','Konto erstellen','Betrieb registrieren — Testzeitraum startet sofort, keine Zahlungsdaten nötig.'],
                ['2','Einrichten','Öffnungszeiten, Tische oder Leistungen anlegen — in wenigen Minuten.'],
                ['3','Link teilen','Buchungslink auf Website, Instagram oder Google — Buchungen laufen digital.'],
            ] as $i => [$n,$t,$d])
                <div class="rv d{{ $i+1 }} relative text-center">
                    <div class="mx-auto mb-5 flex h-14 w-14 items-center justify-center rounded-2xl bg-white font-black text-lg shadow-sm" style="border:1px solid var(--br); color:var(--ac)">{{ $n }}</div>
                    <h3 class="font-black text-stone-900">{{ $t }}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-stone-500">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  PRICING                                                      ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section id="preise" class="mx-auto max-w-6xl px-4 py-24">
    <div class="text-center rv">
        <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">Preise</p>
        <h2 class="mt-3 font-black tracking-tight" style="font-size:clamp(1.8rem,4vw,2.8rem)">Fair &amp; monatlich kündbar</h2>
        <p class="mx-auto mt-3 text-stone-500" style="max-width:46ch; line-height:1.7">
            <strong class="text-stone-700">Voller Funktionsumfang in jedem Tarif</strong> —
            unbegrenzte Benutzer, API, Zahlungen und Berichte inklusive. Ohne Provision.
        </p>
    </div>

    <div class="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
        @forelse($plans as $plan)
            @php($popular = $plan->key === 'professional')
            <div class="rv flex flex-col rounded-2xl bg-white p-6 d{{ $loop->index + 1 }} {{ $popular ? 'pp' : 'card' }}">
                @if($popular)
                    <span class="mb-3 -mt-10 self-center rounded-full px-4 py-1 text-xs font-black text-white"
                          style="background:linear-gradient(135deg,var(--ac),var(--ac2))">✦ Beliebt</span>
                @endif
                <h3 class="font-black text-stone-900">{{ $plan->name }}</h3>
                <p class="mt-3 flex items-end gap-1">
                    @if($plan->key === 'enterprise')
                        <span class="text-2xl font-black">Auf Anfrage</span>
                    @else
                        <span class="text-4xl font-black leading-none">{{ number_format($plan->price_monthly_minor / 100, 0, ',', '.') }}</span>
                        <span class="mb-1 text-sm text-stone-400">€ / Monat</span>
                    @endif
                </p>
                <div class="my-5 space-y-3 border-y py-4" style="border-color:var(--br)">
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
                    <strong class="text-stone-700">Alle Funktionen inklusive</strong> —
                    unbegr. Benutzer · API &amp; Webhooks · Zahlungen · Warteliste · Berichte · Branding
                </p>
                @if($plan->key === 'enterprise')
                    <a href="{{ route('contact') }}" class="mt-5 rounded-xl border py-3 text-center text-sm font-bold text-stone-700 transition hover:bg-stone-50" style="border-color:var(--br)">Kontakt aufnehmen</a>
                @else
                    <a href="{{ route('register') }}"
                       class="mt-5 rounded-xl py-3 text-center text-sm font-bold transition
                              {{ $popular ? 'text-white' : 'text-stone-700 hover:bg-stone-50' }}"
                       style="{{ $popular ? 'background:linear-gradient(135deg,#0d9488,#0f766e)' : 'border:1px solid #e6e3dc' }}">
                        Kostenlos testen
                    </a>
                @endif
            </div>
        @empty
            <p class="col-span-full text-center text-stone-500">Preise auf Anfrage –
                <a href="{{ route('contact') }}" class="font-semibold" style="color:var(--ac)">kontaktieren Sie uns</a>.</p>
        @endforelse
    </div>
    <p class="mx-auto mt-8 max-w-xl text-center text-sm text-stone-400">Mehr Tische oder Standorte nötig? Jederzeit upgraden — Sie zahlen nur, wenn Ihr Betrieb wächst.</p>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  FAQ                                                          ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section id="faq" style="background:var(--sa); border-top:1px solid var(--br)">
    <div class="mx-auto max-w-3xl px-4 py-24">
        <div class="text-center rv">
            <p class="text-xs font-bold uppercase tracking-widest" style="color:var(--ac)">FAQ</p>
            <h2 class="mt-3 font-black tracking-tight" style="font-size:clamp(1.8rem,4vw,2.8rem)">Häufige Fragen</h2>
        </div>

        <div class="mt-12 space-y-2.5">
            @foreach([
                ['Für wen ist Swayy geeignet?',
                 'Für Restaurants, Cafés und Bars (tischbasiert) ebenso wie für Friseure und Dienstleister (terminbasiert pro Mitarbeiter und Leistung). Der Betriebstyp lässt sich pro Konto umstellen.'],
                ['Brauche ich eine eigene Website?',
                 'Nein. Sie erhalten einen Buchungslink für Google, Instagram & Co. Wer eine Website hat, bettet das Widget mit zwei Zeilen Code ein.'],
                ['Welche Zahlungsmöglichkeiten gibt es?',
                 'Gängige Zahlungsarten sind direkt integriert. Kreditkartendaten werden nie bei uns gespeichert — die Abwicklung erfolgt sicher beim Zahlungsdienstleister.'],
                ['Was passiert nach dem Testzeitraum?',
                 'Sie wählen einen Tarif — oder nicht. Es gibt keine automatische Abbuchung, da im Test keine Zahlungsdaten erhoben werden.'],
                ['Ist Swayy DSGVO-konform?',
                 'Ja. EU-Hosting, Einwilligungsverwaltung, Datenexport und Anonymisierung pro Gast sind eingebaut, IP-Adressen werden minimiert. Rechtstexte sind als Markdown direkt pflegbar.'],
                ['Kann ich mehrere Standorte verwalten?',
                 'Ja, ab dem Multi-Location-Tarif beliebig viele Standorte unter einem Konto — mit getrennten Plänen, Berichten und Teams.'],
                ['Kann ich das Erscheinungsbild anpassen?',
                 'Vollständig. Eigenes Logo, Farben, Schriften und Rechtstexte lassen sich direkt im Admin-Bereich hinterlegen und sind sofort aktiv. Das Widget passt sich dem Corporate Design Ihres Betriebs an.'],
            ] as [$q, $a])
                <details class="faq rv rounded-2xl bg-white" style="border:1px solid var(--br)">
                    <summary class="flex items-center justify-between gap-4 px-6 py-4 font-bold text-stone-800 select-none">
                        <span>{{ $q }}</span>
                        <span class="fi flex-none font-light text-2xl leading-none" style="color:var(--ac)">+</span>
                    </summary>
                    <div class="fb">
                        <div><p class="px-6 pb-5 text-sm leading-relaxed text-stone-500">{{ $a }}</p></div>
                    </div>
                </details>
            @endforeach
        </div>
    </div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  CTA                                                          ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<section class="cta-bg cta-dots relative overflow-hidden">
    <div class="pointer-events-none absolute inset-0" style="background:radial-gradient(80rem 40rem at 50% 120%,rgba(255,255,255,.07),transparent)"></div>
    <div class="relative mx-auto max-w-4xl px-4 py-28 text-center text-white">
        <p class="text-xs font-bold uppercase tracking-widest text-teal-200/60 rv">Jetzt starten</p>
        <h2 class="mt-4 font-black tracking-tight text-white rv d1" style="font-size:clamp(2rem,5vw,3.2rem); text-wrap:balance">
            Bereit für volle Auslastung<br>ohne Telefonchaos?
        </h2>
        <p class="mx-auto mt-5 text-lg text-teal-50/80 rv d2" style="max-width:42ch">
            In wenigen Minuten eingerichtet. 30 Tage kostenlos, ohne Risiko, ohne Kreditkarte.
        </p>
        <a href="{{ route('register') }}"
           class="rv d3 group mt-9 inline-flex items-center gap-2.5 rounded-2xl bg-white px-10 py-4 text-lg font-black text-teal-800 shadow-2xl shadow-teal-900/30 transition hover:bg-teal-50 hover:-translate-y-0.5">
            Jetzt kostenlos starten
            <svg class="h-5 w-5 transition-transform group-hover:translate-x-0.5" viewBox="0 0 16 16" fill="none">
                <path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </a>
        <p class="rv d4 mt-5 text-sm text-teal-100/50">Keine Kreditkarte · jederzeit kündbar · DSGVO-konform · EU-Hosting</p>
    </div>
</section>


{{-- ╔══════════════════════════════════════════════════════════════╗
     ║  SCRIPTS                                                      ║
     ╚══════════════════════════════════════════════════════════════╝ --}}
<script>
(function () {
    'use strict';

    /* ── Scroll reveal ────────────────────────────────────────── */
    const io = new IntersectionObserver(entries => {
        entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('on'); io.unobserve(e.target); } });
    }, { threshold: 0.1 });
    document.querySelectorAll('.rv').forEach(el => io.observe(el));

    /* ── JS Parallax (Safari + fallback) ──────────────────────── */
    const supportsScrollTimeline = CSS.supports('animation-timeline', 'scroll()');
    if (!supportsScrollTimeline) {
        const map = [
            ['.px-ring',  0.10],
            ['.px-blob',  0.14],
            ['.px-mock',  0.21],
            ['.px-na',    0.27],
            ['.px-nb',    0.24],
        ].map(([sel, rate]) => ({ el: document.querySelector(sel), rate }))
         .filter(x => x.el);

        let raf = null;
        function tick() {
            const y = window.scrollY;
            map.forEach(({ el, rate }) => { el.style.translate = `0 ${y * rate}px`; });
            raf = null;
        }
        window.addEventListener('scroll', () => { if (!raf) raf = requestAnimationFrame(tick); }, { passive: true });
    }
})();
</script>

@endsection
