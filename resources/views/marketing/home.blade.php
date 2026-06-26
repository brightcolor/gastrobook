@extends('layouts.marketing')

@push('styles')
<style>
:root{
    --ink:#1c1917; --ink2:#44403c; --mu:#78716c; --mu2:#a8a29e;
    --paper:#fbfaf8; --paper2:#f4f1ea; --line:#e9e5dd; --line2:#efece5;
    --ac:#0f766e; --ac2:#0d9488; --acl:#f0fdfa;
}

/* ── Type ──────────────────────────────────────────────────────── */
.serif{ font-family:var(--font-display, 'Fraunces Variable', Georgia, serif); font-optical-sizing:auto; }
.serif-i{ font-family:var(--font-display, 'Fraunces Variable', Georgia, serif); font-style:italic; }
.display{
    font-family:var(--font-display, 'Fraunces Variable', Georgia, serif);
    font-weight:400; letter-spacing:-.02em; line-height:1.02;
    font-optical-sizing:auto;
    color:var(--ink);
}
.eyebrow{
    font-size:.72rem; font-weight:600; letter-spacing:.18em; text-transform:uppercase; color:var(--ac);
}

/* ── Grain (tactile, premium) ──────────────────────────────────── */
/* ── Parallax orbs ─────────────────────────────────────────────── */
.orb{ position:absolute; border-radius:50%; filter:blur(70px); pointer-events:none; will-change:transform; }
.orb-a{ width:46rem;height:46rem; top:-16rem; right:-14rem; background:radial-gradient(circle,#5eead4 0,transparent 68%); opacity:.45; }
.orb-b{ width:40rem;height:40rem; top:14rem; left:-18rem; background:radial-gradient(circle,#c7d2fe 0,transparent 68%); opacity:.40; }
.orb-c{ width:32rem;height:32rem; background:radial-gradient(circle,#fde68a 0,transparent 70%); opacity:.30; }

[data-par]{ will-change:transform; }

/* ── Reveal ────────────────────────────────────────────────────── */
.rv{ opacity:0; transform:translateY(26px); transition:opacity .9s cubic-bezier(.16,1,.3,1), transform .9s cubic-bezier(.16,1,.3,1); }
.rv.on{ opacity:1; transform:none; }
.d1{transition-delay:.07s}.d2{transition-delay:.14s}.d3{transition-delay:.21s}
.d4{transition-delay:.28s}.d5{transition-delay:.35s}.d6{transition-delay:.42s}
@media (prefers-reduced-motion:reduce){
    .rv{opacity:1;transform:none;transition:none}
    [data-par]{transform:none!important}
    .floaty,.ldot{animation:none!important}
}

/* ── Buttons ───────────────────────────────────────────────────── */
.btn-ink{
    background:var(--ink); color:#fff; border-radius:999px;
    box-shadow:0 1px 1px rgba(0,0,0,.04),0 10px 30px -12px rgba(28,25,23,.55);
    transition:transform .25s cubic-bezier(.16,1,.3,1), box-shadow .25s, background .2s;
}
.btn-ink:hover{ transform:translateY(-2px); background:#000; box-shadow:0 1px 1px rgba(0,0,0,.04),0 18px 44px -14px rgba(28,25,23,.6); }
.btn-ghost{
    border:1px solid var(--line); background:rgba(255,255,255,.6); color:var(--ink2); border-radius:999px;
    backdrop-filter:blur(6px); transition:border-color .2s,color .2s,background .2s,transform .25s;
}
.btn-ghost:hover{ border-color:#d6d1c7; color:var(--ink); transform:translateY(-2px); }

/* ── Cards ─────────────────────────────────────────────────────── */
.surf{ background:#fff; border:1px solid var(--line); border-radius:22px; }
.lift{ transition:box-shadow .35s cubic-bezier(.16,1,.3,1), transform .35s cubic-bezier(.16,1,.3,1), border-color .35s; }
.lift:hover{ transform:translateY(-4px); border-color:#ded8cd; box-shadow:0 30px 60px -24px rgba(28,25,23,.18); }

/* hairline gradient ring used on premium cards */
.ring-soft{ box-shadow:0 1px 0 rgba(255,255,255,.8) inset, 0 24px 60px -30px rgba(28,25,23,.25); }

/* ── Live dot ──────────────────────────────────────────────────── */
@keyframes ldot{0%,100%{box-shadow:0 0 0 0 rgba(16,185,129,.45)}60%{box-shadow:0 0 0 6px rgba(16,185,129,0)}}
.ldot{ display:inline-block;width:7px;height:7px;border-radius:50%;background:#10b981;animation:ldot 2.4s ease-in-out infinite; }

/* ── Float ─────────────────────────────────────────────────────── */
@keyframes floaty{0%,100%{transform:translateY(0)}50%{transform:translateY(-10px)}}
.floaty{ animation:floaty 7s ease-in-out infinite; }
.floaty.slow{ animation-duration:9s; }

/* ── Slot / date pills ─────────────────────────────────────────── */
.slot{ border:1.5px solid var(--line); border-radius:11px; padding:7px 0; text-align:center; font-size:12px; font-weight:600; color:var(--mu); background:#fff; }
.slot.on{ border-color:var(--ac); background:var(--acl); color:var(--ac); }

/* ── FAQ ───────────────────────────────────────────────────────── */
details.faq summary{ list-style:none; cursor:pointer; }
details.faq summary::-webkit-details-marker{ display:none; }
details.faq .fb{ display:grid; grid-template-rows:0fr; transition:grid-template-rows .4s cubic-bezier(.16,1,.3,1); }
details.faq .fb>div{ overflow:hidden; }
details.faq[open] .fb{ grid-template-rows:1fr; }
details.faq .fi{ transition:transform .35s cubic-bezier(.16,1,.3,1); }
details.faq[open] .fi{ transform:rotate(45deg); }

/* ── Marquee (value words, no vendors) ─────────────────────────── */
.marq{ display:flex; gap:3.5rem; width:max-content; animation:marq 38s linear infinite; }
@keyframes marq{ to{ transform:translateX(-50%); } }
.marq:hover{ animation-play-state:paused; }
.marq span{ font-family:var(--font-display,serif); font-style:italic; font-size:1.35rem; color:var(--mu2); white-space:nowrap; }
.marq b{ color:var(--ac); font-style:normal; font-weight:600; font-family:var(--font-sans); font-size:.8rem; letter-spacing:.04em; }

/* ── Section divider ───────────────────────────────────────────── */
.rule{ height:1px; background:linear-gradient(90deg,transparent,var(--line),transparent); }

/* ── CTA ───────────────────────────────────────────────────────── */
.cta-wrap{ background:linear-gradient(160deg,#0f766e,#115e59 55%,#134e4a); border-radius:36px; position:relative; overflow:hidden; }
.cta-grid{ background-image:radial-gradient(rgba(255,255,255,.16) 1px,transparent 1px); background-size:26px 26px; }
</style>
@endpush

@section('content')

{{-- ═══════════════════════════ HERO ═══════════════════════════ --}}
<section class="relative overflow-hidden" style="background:var(--paper)">
    <div class="orb orb-a" data-par="-0.18"></div>
    <div class="orb orb-b" data-par="0.12"></div>

    <div class="relative z-10 mx-auto grid max-w-6xl items-center gap-14 px-5 pb-24 pt-16 lg:grid-cols-[1.05fr_.95fr] lg:gap-10 lg:pb-32 lg:pt-24">

        {{-- Copy --}}
        <div data-par="0.04">
            <span class="rv inline-flex items-center gap-2.5 rounded-full border px-4 py-1.5 text-sm font-medium"
                  style="border-color:var(--line); background:rgba(255,255,255,.7); color:var(--ink2); backdrop-filter:blur(6px)">
                <span class="ldot"></span> Für Restaurants, Cafés &amp; Salons
            </span>

            <h1 class="display rv d1 mt-6" style="font-size:clamp(2.7rem,5.6vw,4.7rem)">
                Reservierungen<br>
                &amp; Termine, die sich<br>
                <span class="serif-i" style="color:var(--ac)">von selbst</span> füllen.
            </h1>

            <p class="rv d2 mt-7 leading-relaxed" style="font-size:1.15rem; color:var(--mu); max-width:30rem">
                Online-Buchung, ein Live-Board in Echtzeit, Tischplan, Zahlungen und
                No-Show-Schutz — in einer ruhigen, durchdachten Plattform.
                <span style="color:var(--ink2)">DSGVO-konform, in der EU gehostet, ohne Provision.</span>
            </p>

            <div class="rv d3 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                <a href="{{ route('register') }}" class="btn-ink group inline-flex items-center justify-center gap-2 px-7 py-3.5 text-[15px] font-semibold">
                    30 Tage kostenlos
                    <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="#funktionen" class="btn-ghost inline-flex items-center justify-center px-7 py-3.5 text-[15px] font-semibold">
                    Funktionen ansehen
                </a>
            </div>

            <div class="rv d4 mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" style="color:var(--mu2)">
                <span class="inline-flex items-center gap-2"><svg class="h-4 w-4" style="color:var(--ac)" viewBox="0 0 16 16" fill="none"><path d="M3 8.5l3.2 3L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Keine Kreditkarte</span>
                <span class="inline-flex items-center gap-2"><svg class="h-4 w-4" style="color:var(--ac)" viewBox="0 0 16 16" fill="none"><path d="M3 8.5l3.2 3L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>In 10 Minuten startklar</span>
                <span class="inline-flex items-center gap-2"><svg class="h-4 w-4" style="color:var(--ac)" viewBox="0 0 16 16" fill="none"><path d="M3 8.5l3.2 3L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Jederzeit kündbar</span>
            </div>
        </div>

        {{-- Mockup --}}
        <div class="relative" data-par="-0.06" style="min-height:30rem">
            {{-- soft platform glow --}}
            <div class="pointer-events-none absolute left-1/2 top-1/2 -z-0 h-[34rem] w-[34rem] -translate-x-1/2 -translate-y-1/2 rounded-full" style="background:radial-gradient(circle,rgba(94,234,212,.28),transparent 60%)"></div>

            {{-- Main booking widget --}}
            <div class="floaty relative z-10 mx-auto max-w-[22rem]">
                <div class="surf ring-soft overflow-hidden">
                    <div class="flex items-center gap-2 border-b px-4 py-3" style="border-color:var(--line2); background:#fcfbf9">
                        <span class="flex gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full" style="background:var(--line)"></span>
                            <span class="h-2.5 w-2.5 rounded-full" style="background:var(--line)"></span>
                            <span class="h-2.5 w-2.5 rounded-full" style="background:var(--line)"></span>
                        </span>
                        <span class="flex-1 text-center text-[11px] font-medium" style="color:var(--mu2)">swayy.app · reservieren</span>
                    </div>
                    <div class="p-5">
                        <p class="serif mb-1 text-lg" style="color:var(--ink)">Tisch reservieren</p>
                        <p class="mb-4 text-xs" style="color:var(--mu2)">Wählen Sie Datum &amp; Uhrzeit</p>
                        <div class="mb-3 grid grid-cols-4 gap-1.5">
                            @foreach(['Mo 21'=>false,'Di 22'=>true,'Mi 23'=>false,'Do 24'=>false] as $d=>$on)
                                <div class="rounded-xl py-2 text-center text-[11px] font-semibold" style="border:1.5px solid {{ $on?'var(--ac)':'var(--line)' }}; color:{{ $on?'var(--ac)':'var(--mu2)' }}; background:{{ $on?'var(--acl)':'#fff' }}">{{ $d }}</div>
                            @endforeach
                        </div>
                        <div class="mb-3 flex items-center justify-between rounded-xl px-4 py-2.5" style="border:1px solid var(--line)">
                            <span class="text-xs" style="color:var(--mu)">Personen</span>
                            <div class="flex items-center gap-3 text-xs">
                                <span class="flex h-5 w-5 items-center justify-center rounded-full" style="border:1px solid var(--line); color:var(--mu)">−</span>
                                <strong style="color:var(--ink)">2</strong>
                                <span class="flex h-5 w-5 items-center justify-center rounded-full" style="border:1px solid var(--line); color:var(--mu)">+</span>
                            </div>
                        </div>
                        <div class="mb-4 grid grid-cols-3 gap-1.5">
                            @foreach(['18:00'=>false,'18:30'=>true,'19:00'=>false,'19:30'=>false,'20:00'=>true,'20:30'=>false] as $t=>$on)
                                <div class="slot {{ $on?'on':'' }}">{{ $t }}</div>
                            @endforeach
                        </div>
                        <button class="w-full rounded-xl py-2.5 text-[13px] font-semibold text-white" style="background:var(--ink)">Reservierung bestätigen</button>
                    </div>
                </div>
            </div>

            {{-- floating notif: new booking --}}
            <div class="floaty slow absolute -right-2 top-2 z-20 sm:-right-6" data-par="0.10">
                <div class="surf ring-soft px-4 py-3" style="min-width:11.5rem">
                    <div class="mb-1.5 flex items-center gap-2">
                        <span class="ldot"></span>
                        <span class="text-[10px] font-bold uppercase tracking-wider" style="color:var(--mu2)">Neue Buchung</span>
                    </div>
                    <p class="text-[12.5px] font-semibold" style="color:var(--ink)">Tisch 4 · 19:30 · 4 P.</p>
                    <p class="mt-0.5 text-[11px]" style="color:var(--mu2)">gerade eben</p>
                </div>
            </div>

            {{-- floating notif: payment --}}
            <div class="floaty absolute -left-3 bottom-6 z-20 sm:-left-8" data-par="0.16" style="animation-delay:-3s">
                <div class="surf ring-soft flex items-center gap-3 px-4 py-3" style="min-width:11rem">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full text-sm" style="background:var(--acl); color:var(--ac)">✓</span>
                    <div>
                        <p class="text-[12.5px] font-semibold" style="color:var(--ink)">Anzahlung erhalten</p>
                        <p class="text-[11px]" style="color:var(--mu2)">25,00 € gesichert</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- value marquee --}}
    <div class="relative z-10 overflow-hidden border-y py-5" style="border-color:var(--line2); background:rgba(255,255,255,.5)">
        <div class="marq">
            @php($words = ['Weniger Telefonate','Volle Tische','Kein No-Show','Mehr Zeit fürs Wesentliche','Gäste, die wiederkommen','Ruhiger Service'])
            @foreach(array_merge($words,$words) as $w)
                <span>{{ $w }} <b>·</b></span>
            @endforeach
        </div>
    </div>
</section>


{{-- ═══════════════════════ BRANCHEN ═══════════════════════ --}}
<section id="branchen" class="relative overflow-hidden" style="background:var(--paper)">
    <div class="orb orb-c" data-par="0.10" style="top:8rem; right:-10rem"></div>
    <div class="relative z-10 mx-auto max-w-6xl px-5 py-28">
        <div class="mx-auto max-w-2xl text-center">
            <p class="eyebrow rv">Ein System, zwei Welten</p>
            <h2 class="display rv d1 mt-4" style="font-size:clamp(2rem,4vw,3rem)">Gemacht für Gastgeber<br>und <span class="serif-i" style="color:var(--ac)">Dienstleister</span></h2>
            <p class="rv d2 mx-auto mt-5 leading-relaxed" style="color:var(--mu); max-width:34rem">Dieselbe Plattform — je nach Betrieb als Tischreservierung oder als Terminbuchung pro Mitarbeiter und Leistung.</p>
        </div>

        <div class="mt-16 grid gap-6 md:grid-cols-2">
            <div class="surf lift rv d1 p-9">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl text-2xl" style="background:linear-gradient(140deg,#f0fdfa,#ccfbf1)">🍽️</div>
                <h3 class="serif mt-6 text-2xl" style="color:var(--ink)">Restaurants, Cafés &amp; Bars</h3>
                <p class="mt-3 leading-relaxed" style="color:var(--mu)">Tischbasierte Reservierung mit grafischem Grundriss, Kombinationen und automatischer Zuweisung.</p>
                <ul class="mt-6 space-y-3 text-[15px]" style="color:var(--ink2)">
                    @foreach(['Grafischer Tischplan mit Flächenzonen','Öffentlicher Grundriss zur Tischwahl','Events &amp; Ticketverkauf'] as $li)
                        <li class="flex items-start gap-3"><span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full" style="background:var(--ac)"></span>{!! $li !!}</li>
                    @endforeach
                </ul>
            </div>
            <div class="surf lift rv d2 p-9">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl text-2xl" style="background:linear-gradient(140deg,#eef2ff,#e0e7ff)">✂️</div>
                <h3 class="serif mt-6 text-2xl" style="color:var(--ink)">Friseure &amp; Dienstleister</h3>
                <p class="mt-3 leading-relaxed" style="color:var(--mu)">Terminbuchung pro Mitarbeiter und Leistung — mit Dienstplan, Abwesenheiten und Lückenoptimierer.</p>
                <ul class="mt-6 space-y-3 text-[15px]" style="color:var(--ink2)">
                    @foreach(['Leistungen mit Dauer &amp; Preis, kombinierbar','Mitarbeiter-Dienstplan &amp; Urlaubsverwaltung','Buchung bei beliebigem oder bestimmtem Mitarbeiter'] as $li)
                        <li class="flex items-start gap-3"><span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full" style="background:var(--ac)"></span>{!! $li !!}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
</section>


{{-- ═══════════════════════ FUNKTIONEN (alternating) ═══════════════════════ --}}
<section id="funktionen" style="background:var(--paper2)">
<div class="mx-auto max-w-6xl px-5 py-28">
    <div class="mx-auto max-w-2xl text-center">
        <p class="eyebrow rv">Hauptfunktionen</p>
        <h2 class="display rv d1 mt-4" style="font-size:clamp(2rem,4vw,3rem)">Alles für volle Auslastung</h2>
    </div>

    <div class="mt-24 space-y-28">

        {{-- F1 Online-Buchung --}}
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div class="rv">
                <p class="eyebrow">Online-Buchung</p>
                <h3 class="serif mt-3" style="font-size:clamp(1.6rem,3vw,2.2rem); color:var(--ink); line-height:1.12">Ein Buchungserlebnis,<br>das Gäste lieben</h3>
                <p class="mt-4 leading-relaxed" style="color:var(--mu)">Mobile-first Buchungsseite mit Live-Verfügbarkeit — als Link teilen oder mit zwei Zeilen Code einbetten. Optionale Tisch- und Mitarbeiterwahl.</p>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 text-[15px]" style="color:var(--ink2)">
                    @foreach(['Direktlink für Social &amp; Maps','Einbettbares Widget','E-Mail- &amp; SMS-Erinnerungen','Konto per Magic-Link'] as $li)
                        <li class="flex items-start gap-2.5"><span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full" style="background:var(--ac)"></span>{!! $li !!}</li>
                    @endforeach
                </ul>
            </div>
            <div class="rv d1 surf ring-soft flex items-center justify-center p-10" style="background:linear-gradient(160deg,#fff,#f8f6f1); min-height:22rem">
                <div class="floaty w-full max-w-[18rem]">
                    <div class="surf overflow-hidden" style="box-shadow:0 20px 50px -24px rgba(28,25,23,.25)">
                        <div class="p-5">
                            <p class="serif mb-3 text-base" style="color:var(--ink)">Termin wählen</p>
                            <div class="mb-3 grid grid-cols-5 gap-1.5">
                                @foreach([22=>true,23=>false,24=>false,25=>false,26=>false] as $d=>$on)
                                    <div class="rounded-lg py-2 text-center text-[10px] font-bold" style="border:1.5px solid {{ $on?'var(--ac)':'var(--line)' }}; color:{{ $on?'var(--ac)':'var(--mu2)' }}; background:{{ $on?'var(--acl)':'transparent' }}">{{ $d }}</div>
                                @endforeach
                            </div>
                            <div class="mb-4 grid grid-cols-3 gap-1.5">
                                @foreach(['18:00'=>false,'18:30'=>true,'19:00'=>false,'19:30'=>false,'20:00'=>false,'20:30'=>true] as $t=>$on)
                                    <div class="slot {{ $on?'on':'' }}" style="font-size:11px">{{ $t }}</div>
                                @endforeach
                            </div>
                            <button class="w-full rounded-lg py-2.5 text-xs font-semibold text-white" style="background:var(--ac)">Reservieren</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- F2 Live-Board --}}
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div class="rv order-last lg:order-first surf ring-soft flex items-center justify-center p-10" style="background:linear-gradient(160deg,#fff,#f8f6f1); min-height:22rem">
                <div class="floaty slow surf w-full max-w-[20rem] overflow-hidden" style="box-shadow:0 20px 50px -24px rgba(28,25,23,.25)">
                    <div class="flex items-center justify-between border-b px-4 py-3" style="border-color:var(--line2)">
                        <span class="serif text-sm" style="color:var(--ink)">Heute · Live</span>
                        <span class="ldot"></span>
                    </div>
                    <div class="p-2">
                        @foreach([
                            ['#10b981','18:30','Müller · 2 P.','bestätigt'],
                            ['#f59e0b','19:00','Weber · 4 P.','Anfrage'],
                            ['#3b82f6','19:30','Schmidt · 6 P.','bestätigt'],
                            ['#a8a29e','20:00','Becker · 2 P.','da'],
                        ] as [$c,$time,$g,$st])
                            <div class="flex items-center gap-3 rounded-xl px-3 py-2.5" style="transition:background .15s" onmouseover="this.style.background='#faf8f4'" onmouseout="this.style.background='transparent'">
                                <span class="h-2 w-2 flex-none rounded-full" style="background:{{ $c }}"></span>
                                <span class="w-12 text-[12px] font-semibold" style="color:var(--ink2)">{{ $time }}</span>
                                <span class="flex-1 text-[12px]" style="color:var(--mu)">{{ $g }}</span>
                                <span class="rounded-full px-2.5 py-0.5 text-[10px] font-semibold" style="background:{{ $c }}1a; color:{{ $c }}">{{ $st }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
            <div class="rv d1">
                <p class="eyebrow">Live-Board</p>
                <h3 class="serif mt-3" style="font-size:clamp(1.6rem,3vw,2.2rem); color:var(--ink); line-height:1.12">Alle Buchungen,<br>in Echtzeit</h3>
                <p class="mt-4 leading-relaxed" style="color:var(--mu)">Neue und anstehende Reservierungen erscheinen sofort — ohne Reload. Ideal für Tresen und Tablet, mit Dark Mode und Vollbild.</p>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 text-[15px]" style="color:var(--ink2)">
                    @foreach(['Live-Updates ohne Reload','Inline bestätigen &amp; umbuchen','Dark Mode &amp; Vollbild','Ansicht nach Tisch oder Zeit'] as $li)
                        <li class="flex items-start gap-2.5"><span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full" style="background:var(--ac)"></span>{!! $li !!}</li>
                    @endforeach
                </ul>
            </div>
        </div>

        {{-- F3 Zahlungen --}}
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div class="rv">
                <p class="eyebrow">Zahlungen &amp; No-Show-Schutz</p>
                <h3 class="serif mt-3" style="font-size:clamp(1.6rem,3vw,2.2rem); color:var(--ink); line-height:1.12">Anzahlungen &amp; Erstattungen,<br><span class="serif-i" style="color:var(--ac)">automatisch</span></h3>
                <p class="mt-4 leading-relaxed" style="color:var(--mu)">Verlangen Sie eine Anzahlung bei der Buchung. Bei Nichterscheinen greift der Schutz automatisch, Rückerstattungen laufen mit einem Klick.</p>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 text-[15px]" style="color:var(--ink2)">
                    @foreach(['Flexible Anzahlungsregeln','Automatische Erstattung','Sichere Abwicklung','Erinnerung vor dem Termin'] as $li)
                        <li class="flex items-start gap-2.5"><span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full" style="background:var(--ac)"></span>{!! $li !!}</li>
                    @endforeach
                </ul>
            </div>
            <div class="rv d1 surf ring-soft flex items-center justify-center p-10" style="background:linear-gradient(160deg,#fff,#f8f6f1); min-height:22rem">
                <div class="w-full max-w-[18rem] space-y-3">
                    @foreach([
                        ['floaty','✓','Buchung bestätigt','Tisch 5 · 20:00 · 4 P.','var(--acl)','var(--ac)','0s'],
                        ['floaty slow','💳','Anzahlung erhalten','25,00 € · gerade eben','#eef2ff','#6366f1','-2s'],
                        ['floaty','🔔','Erinnerung gesendet','1 Tag vor dem Termin','#fffbeb','#f59e0b','-4s'],
                    ] as [$cls,$ic,$t,$s,$bg,$col,$delay])
                        <div class="{{ $cls }} surf flex items-center gap-3 px-4 py-3" style="box-shadow:0 12px 30px -18px rgba(28,25,23,.25); animation-delay:{{ $delay }}">
                            <span class="flex h-9 w-9 flex-none items-center justify-center rounded-full text-sm" style="background:{{ $bg }}; color:{{ $col }}">{{ $ic }}</span>
                            <div>
                                <p class="text-[13px] font-semibold" style="color:var(--ink)">{{ $t }}</p>
                                <p class="text-[11px]" style="color:var(--mu2)">{{ $s }}</p>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>

    </div>
</div>
</section>


{{-- ═══════════════════════ FEATURE-GRID ═══════════════════════ --}}
<section class="relative overflow-hidden" style="background:var(--paper)">
    <div class="relative z-10 mx-auto max-w-6xl px-5 py-28">
        <div class="mx-auto max-w-2xl text-center">
            <p class="eyebrow rv">Und vieles mehr</p>
            <h2 class="display rv d1 mt-4" style="font-size:clamp(2rem,4vw,3rem)">Jedes Detail mitgedacht</h2>
        </div>
        <div class="mt-14 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            @foreach([
                ['🗺️','Grafischer Tischplan &amp; Zonen'],
                ['👤','Kundenkonto per Magic-Link'],
                ['🔁','Online-Umbuchung durch den Gast'],
                ['✉️','E-Mail- &amp; SMS-Erinnerungen'],
                ['⏱','Lückenoptimierer'],
                ['🎟️','Events &amp; Ticketverkauf'],
                ['🔔','Warteliste mit Auto-Angebot'],
                ['👥','Gäste-CRM mit Historie'],
                ['📊','Berichte, Export, API &amp; Webhooks'],
                ['🏪','Mehrere Standorte, ein Konto'],
                ['🎨','Eigenes Branding'],
                ['🔐','Audit-Log &amp; Rollenrechte'],
                ['♻️','Automatische DSGVO-Löschung'],
                ['💬','Feedback-Booster'],
                ['📄','Rechtstexte direkt pflegbar'],
                ['🔌','Einbettbares Widget'],
                ['🔗','Tischkombinationen'],
                ['🚶','Walk-ins in einem Klick'],
            ] as $i => [$icon,$text])
                <div class="rv surf flex items-center gap-3 px-4 py-3.5" style="border-radius:14px; transition:border-color .25s,box-shadow .25s,transform .25s; transition-delay:{{ ($i%6)*.05 }}s"
                     onmouseover="this.style.transform='translateY(-2px)';this.style.boxShadow='0 12px 26px -18px rgba(28,25,23,.22)';this.style.borderColor='#ded8cd'"
                     onmouseout="this.style.transform='';this.style.boxShadow='';this.style.borderColor='var(--line)'">
                    <span class="text-base flex-none">{{ $icon }}</span>
                    <span class="text-[14px]" style="color:var(--ink2)">{!! $text !!}</span>
                </div>
            @endforeach
        </div>
    </div>
</section>


{{-- ═══════════════════════ SO EINFACH ═══════════════════════ --}}
<section style="background:var(--paper2)">
    <div class="mx-auto max-w-5xl px-5 py-28">
        <div class="mx-auto max-w-2xl text-center">
            <p class="eyebrow rv">So einfach</p>
            <h2 class="display rv d1 mt-4" style="font-size:clamp(2rem,4vw,3rem)">In 10 Minuten startklar</h2>
        </div>
        <div class="relative mt-20 grid gap-10 md:grid-cols-3">
            <div class="absolute left-[16%] right-[16%] top-7 hidden h-px md:block" style="background:linear-gradient(90deg,transparent,var(--line),var(--line),transparent)"></div>
            @foreach([
                ['01','Konto erstellen','Betrieb registrieren — der Testzeitraum startet sofort, ganz ohne Zahlungsdaten.'],
                ['02','Einrichten','Geführter Assistent führt durch Betriebstyp, Öffnungszeiten und erste Tische oder Mitarbeiter — fertig in Minuten.'],
                ['03','Link teilen','Buchungslink auf Website, Social oder Maps — ab jetzt läuft alles digital.'],
            ] as $i => [$n,$t,$d])
                <div class="rv d{{ $i+1 }} relative text-center">
                    <div class="serif mx-auto mb-6 flex h-14 w-14 items-center justify-center rounded-full bg-white text-xl" style="border:1px solid var(--line); color:var(--ac); box-shadow:0 8px 20px -12px rgba(28,25,23,.2)">{{ $n }}</div>
                    <h3 class="serif text-xl" style="color:var(--ink)">{{ $t }}</h3>
                    <p class="mx-auto mt-2.5 text-[15px] leading-relaxed" style="color:var(--mu); max-width:18rem">{{ $d }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>


{{-- ═══════════════════════ PREISE ═══════════════════════ --}}
<section id="preise" class="relative overflow-hidden" style="background:var(--paper)">
    <div class="orb orb-c" data-par="-0.08" style="bottom:0; left:-12rem; opacity:.22"></div>
    <div class="relative z-10 mx-auto max-w-6xl px-5 py-28">
        <div class="mx-auto max-w-2xl text-center">
            <p class="eyebrow rv">Preise</p>
            <h2 class="display rv d1 mt-4" style="font-size:clamp(2rem,4vw,3rem)">Fair &amp; monatlich kündbar</h2>
            <p class="rv d2 mx-auto mt-5 leading-relaxed" style="color:var(--mu); max-width:34rem"><span style="color:var(--ink2)">Voller Funktionsumfang in jedem Tarif</span> — unbegrenzte Benutzer, API, Zahlungen und Berichte inklusive. Ohne Provision.</p>
        </div>

        <div class="mt-16 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
            @forelse($plans as $plan)
                @php($popular = $plan->key === 'professional')
                <div class="rv d{{ $loop->index+1 }} surf lift flex flex-col p-7" @style(['border-color:var(--ac); box-shadow:0 0 0 4px var(--acl), 0 30px 60px -30px rgba(15,118,110,.45)' => $popular])>
                    @if($popular)
                        <span class="serif-i mb-3 -mt-9 self-center rounded-full px-4 py-1 text-sm text-white" style="background:var(--ac)">beliebt</span>
                    @endif
                    <h3 class="serif text-xl" style="color:var(--ink)">{{ $plan->name }}</h3>
                    <p class="mt-3 flex items-end gap-1">
                        @if($plan->key === 'enterprise')
                            <span class="serif text-3xl" style="color:var(--ink)">Auf Anfrage</span>
                        @else
                            <span class="serif text-5xl leading-none" style="color:var(--ink)">{{ number_format($plan->price_monthly_minor/100,0,',','.') }}</span>
                            <span class="mb-1 text-sm" style="color:var(--mu2)">€ / Monat</span>
                        @endif
                    </p>
                    <div class="my-5 grid grid-cols-2 gap-3 border-y py-4" style="border-color:var(--line)">
                        <div>
                            <p class="serif text-2xl" style="color:var(--ink)">{{ isset($plan->limits['max_locations']) ? $plan->limits['max_locations'] : '∞' }}</p>
                            <p class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--mu2)">{{ (isset($plan->limits['max_locations']) && $plan->limits['max_locations']==1) ? 'Standort' : 'Standorte' }}</p>
                        </div>
                        <div>
                            <p class="serif text-2xl" style="color:var(--ink)">{{ isset($plan->limits['max_tables']) ? 'bis '.$plan->limits['max_tables'] : '∞' }}</p>
                            <p class="text-[10px] font-semibold uppercase tracking-wider" style="color:var(--mu2)">Tische / Ress.</p>
                        </div>
                    </div>
                    <p class="flex-1 text-[13px] leading-relaxed" style="color:var(--mu)"><span style="color:var(--ink2)">Alle Funktionen inklusive</span> — unbegr. Benutzer · API · Zahlungen · Warteliste · Branding</p>
                    @if($plan->key === 'enterprise')
                        <a href="{{ route('contact') }}" class="btn-ghost mt-6 py-3 text-center text-sm font-semibold">Kontakt</a>
                    @else
                        <a href="{{ route('register') }}" class="mt-6 rounded-full py-3 text-center text-sm font-semibold {{ $popular?'btn-ink text-white':'btn-ghost' }}">Kostenlos testen</a>
                    @endif
                </div>
            @empty
                <p class="col-span-full text-center" style="color:var(--mu)">Preise auf Anfrage — <a href="{{ route('contact') }}" class="font-semibold" style="color:var(--ac)">kontaktieren Sie uns</a>.</p>
            @endforelse
        </div>
        <p class="mx-auto mt-8 max-w-xl text-center text-sm" style="color:var(--mu2)">Mehr Tische oder Standorte nötig? Jederzeit upgraden — Sie zahlen nur, wenn Ihr Betrieb wächst.</p>
    </div>
</section>


{{-- ═══════════════════════ FAQ ═══════════════════════ --}}
<section id="faq" style="background:var(--paper2)">
    <div class="mx-auto max-w-3xl px-5 py-28">
        <div class="mx-auto max-w-2xl text-center">
            <p class="eyebrow rv">FAQ</p>
            <h2 class="display rv d1 mt-4" style="font-size:clamp(2rem,4vw,3rem)">Häufige Fragen</h2>
        </div>
        <div class="mt-12 space-y-2.5">
            @foreach([
                ['Für wen ist Swayy geeignet?','Für Restaurants, Cafés und Bars (tischbasiert) ebenso wie für Friseure und Dienstleister (terminbasiert pro Mitarbeiter und Leistung). Der Betriebstyp lässt sich pro Konto umstellen.'],
                ['Brauche ich eine eigene Website?','Nein. Sie erhalten einen Buchungslink für Social und Maps. Wer eine Website hat, bettet das Widget mit zwei Zeilen Code ein.'],
                ['Welche Zahlungsmöglichkeiten gibt es?','Gängige Zahlungsarten sind direkt integriert. Kreditkartendaten werden nie bei uns gespeichert — die Abwicklung erfolgt sicher beim Zahlungsdienstleister.'],
                ['Was passiert nach dem Testzeitraum?','Sie wählen einen Tarif — oder nicht. Es gibt keine automatische Abbuchung, da im Test keine Zahlungsdaten erhoben werden.'],
                ['Ist Swayy DSGVO-konform?','Ja. EU-Hosting, Einwilligungsverwaltung, Datenexport und Anonymisierung pro Gast sind eingebaut, IP-Adressen werden minimiert. Selbst die Schriften sind lokal eingebunden, ganz ohne externe CDN.'],
                ['Kann ich mehrere Standorte verwalten?','Ja, ab dem Multi-Location-Tarif beliebig viele Standorte unter einem Konto — mit getrennten Plänen, Berichten und Teams.'],
                ['Kann ich das Erscheinungsbild anpassen?','Vollständig. Eigenes Logo, Farben, Schriften und Rechtstexte lassen sich direkt im Admin-Bereich hinterlegen und sind sofort aktiv. Das Widget passt sich Ihrem Corporate Design an.'],
            ] as [$q,$a])
                <details class="faq rv surf">
                    <summary class="flex items-center justify-between gap-4 px-6 py-5">
                        <span class="serif text-[1.05rem]" style="color:var(--ink)">{{ $q }}</span>
                        <span class="fi flex-none text-2xl font-light leading-none" style="color:var(--ac)">+</span>
                    </summary>
                    <div class="fb"><div><p class="px-6 pb-5 leading-relaxed" style="color:var(--mu)">{{ $a }}</p></div></div>
                </details>
            @endforeach
        </div>
    </div>
</section>


{{-- ═══════════════════════ CTA ═══════════════════════ --}}
<section style="background:var(--paper)">
    <div class="mx-auto max-w-6xl px-5 py-20">
        <div class="cta-wrap rv px-6 py-24 text-center">
            <div class="cta-grid pointer-events-none absolute inset-0 opacity-60"></div>
            <div class="pointer-events-none absolute inset-x-0 -top-32 mx-auto h-64 w-[40rem] rounded-full" style="background:radial-gradient(circle,rgba(94,234,212,.35),transparent 60%)"></div>
            <div class="relative z-10 mx-auto max-w-2xl text-white">
                <p class="eyebrow" style="color:rgba(204,251,241,.75)">Jetzt starten</p>
                <h2 class="display mt-4 text-white" style="font-size:clamp(2.1rem,4.5vw,3.4rem)">Bereit für volle Auslastung,<br><span class="serif-i">ohne Telefonchaos?</span></h2>
                <p class="mx-auto mt-5 text-lg leading-relaxed" style="color:rgba(240,253,250,.82); max-width:30rem">In wenigen Minuten eingerichtet. 30 Tage kostenlos — ohne Risiko, ohne Kreditkarte.</p>
                <a href="{{ route('register') }}" class="group mt-9 inline-flex items-center gap-2.5 rounded-full bg-white px-9 py-4 text-base font-semibold transition hover:-translate-y-1" style="color:var(--ac); box-shadow:0 20px 50px -16px rgba(0,0,0,.4)">
                    Jetzt kostenlos starten
                    <svg class="h-5 w-5 transition-transform group-hover:translate-x-1" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <p class="mt-5 text-sm" style="color:rgba(204,251,241,.6)">Keine Kreditkarte · jederzeit kündbar · DSGVO-konform · EU-Hosting</p>
            </div>
        </div>
    </div>
</section>


<script>
(function(){
    'use strict';
    const rm = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    /* Reveal */
    const io = new IntersectionObserver((es)=>{
        es.forEach(e=>{ if(e.isIntersecting){ e.target.classList.add('on'); io.unobserve(e.target); } });
    },{ threshold:.12, rootMargin:'0px 0px -8% 0px' });
    document.querySelectorAll('.rv').forEach(el=>io.observe(el));

    /* Parallax — always-on rAF engine, relative to viewport centre.
       Visible on every browser, buttery via translate3d. */
    if(rm) return;
    const layers = [...document.querySelectorAll('[data-par]')].map(el=>({
        el, speed: parseFloat(el.dataset.par) || 0
    }));
    let ticking = false;
    function frame(){
        const vh = window.innerHeight;
        for(const {el,speed} of layers){
            const r = el.getBoundingClientRect();
            const centre = r.top + r.height/2;
            const delta = centre - vh/2;
            el.style.transform = `translate3d(0, ${(-delta*speed).toFixed(2)}px, 0)`;
        }
        ticking = false;
    }
    function onScroll(){ if(!ticking){ ticking = true; requestAnimationFrame(frame); } }
    window.addEventListener('scroll', onScroll, { passive:true });
    window.addEventListener('resize', onScroll, { passive:true });
    frame();
})();
</script>
@endsection
