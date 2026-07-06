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

/* ── Interactive demo widget — faithful mini replica of the real
      booking page (accordion with numbered steps) ─────────────────── */
.demo-hero{
    background:linear-gradient(155deg, var(--ac) 0%, #115e59 100%);
}
.dstep[data-state="locked"]{ opacity:.38; pointer-events:none; user-select:none; }
.dstep[data-state="locked"] .dsp-body,
.dstep[data-state="done"]   .dsp-body{ display:none; }
.dstep:not([data-state="done"]) .dsp-summary{ display:none; }
.dstep:not([data-state="done"]) .dsp-edit{ display:none; }
.dsp-num{
    display:inline-flex; align-items:center; justify-content:center;
    width:1.4rem; height:1.4rem; border-radius:9999px;
    font-size:.6rem; font-weight:700; flex-shrink:0;
    transition:background .18s ease, color .18s ease;
}
.dstep[data-state="active"] .dsp-num{ background:var(--ac); color:#fff; }
.dstep[data-state="done"]   .dsp-num{ background:var(--acl); color:var(--ac); }
.dstep[data-state="locked"] .dsp-num{ background:#e7e5e4; color:#a8a29e; }
.dstep[data-state="done"]   .dsp-head{ cursor:pointer; }
.dparty{
    border:2px solid #e7e5e4; border-radius:12px; background:#fff;
    padding:8px 0; text-align:center; font-size:15px; font-weight:900; color:var(--ink);
    cursor:pointer; transition:border-color .15s, background .15s, transform .1s;
}
.dparty:hover{ border-color:var(--ac); background:var(--acl); }
.dparty:active{ transform:scale(.95); }
.dslot{
    border:2px solid #e7e5e4; border-radius:11px; background:#fff;
    padding:8px 0; text-align:center; font-size:12px; font-weight:700; letter-spacing:.02em;
    color:var(--ink); cursor:pointer; transition:border-color .15s, background .15s, color .15s;
}
.dslot:hover:not(:disabled){ border-color:var(--ac); background:var(--acl); }
.dslot.on{ border-color:var(--ac); background:var(--ac); color:#fff; }
.dslot:disabled{ opacity:.4; cursor:not-allowed; text-decoration:line-through; }
.dtable{
    position:absolute; display:flex; align-items:center; justify-content:center;
    font-size:10px; font-weight:800; cursor:pointer; user-select:none;
    border:2px solid #34d399; background:#d1fae5; color:#065f46;
    transition:background .15s, border-color .15s, color .15s, transform .1s;
}
.dtable:hover{ transform:scale(1.06); }
.dtable.busy{
    border-color:#d6d3d1; background:#e7e5e4; color:#a8a29e;
    cursor:not-allowed; transform:none;
}
.dtable.on{ border-color:var(--ac); background:var(--ac); color:#fff; }
.demo-sticker{
    font-family:var(--font-display,'Fraunces Variable',serif); font-style:italic;
    transform:rotate(-2deg);
}
#demoCard.flash{ animation:demoflash 1s ease; }
@keyframes demoflash{ 0%,100%{box-shadow:0 1px 0 rgba(255,255,255,.8) inset,0 24px 60px -30px rgba(28,25,23,.25)} 40%{box-shadow:0 0 0 4px var(--acl),0 0 0 6px var(--ac),0 24px 60px -30px rgba(28,25,23,.25)} }
@keyframes checkpop{ 0%{transform:scale(.4);opacity:0} 70%{transform:scale(1.12)} 100%{transform:scale(1);opacity:1} }
.checkpop{ animation:checkpop .45s cubic-bezier(.16,1,.3,1) both; }

/* ── Confetti burst on demo confirmation ────────────────────────── */
.confetti-piece{
    position:absolute; top:-12px; left:50%; width:8px; height:8px;
    opacity:0; animation:confetti-fall linear forwards;
}
@keyframes confetti-fall{
    0%{ opacity:1; transform:translate(var(--cx,0),0) rotate(0deg); }
    100%{ opacity:0; transform:translate(var(--cx,0),210px) rotate(var(--cr,540deg)); }
}
@media (prefers-reduced-motion:reduce){
    .confetti-piece{ display:none; }
}

/* ── Slot / date pills (static mocks) ──────────────────────────── */
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
                Deine Gäste<br>
                buchen. Du bist<br>
                einfach <span class="serif-i" style="color:var(--ac)">Gastgeber</span>.
            </h1>

            <p class="rv d2 mt-7 leading-relaxed" style="font-size:1.15rem; color:var(--mu); max-width:30rem">
                Freitagabend, volles Haus — und das Telefon? Bleibt still.
                Jede Buchung landet von allein bei dir: Live-Board, Tischplan,
                Erinnerungen und No-Show-Schutz, alles an einem Ort.
                <span style="color:var(--ink2)">DSGVO-konform, EU-Hosting, ohne Provision.</span>
            </p>

            <div class="rv d3 mt-9 flex flex-col gap-3 sm:flex-row sm:items-center">
                <a href="{{ route('register') }}" class="btn-ink group inline-flex items-center justify-center gap-2 px-7 py-3.5 text-[15px] font-semibold">
                    30 Tage kostenlos
                    <svg class="h-4 w-4 transition-transform group-hover:translate-x-1" viewBox="0 0 16 16" fill="none"><path d="M3 8h10M9 4l4 4-4 4" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </a>
                <a href="#demo" id="demoJump" class="btn-ghost inline-flex items-center justify-center px-7 py-3.5 text-[15px] font-semibold">
                    Erst mal ausprobieren
                </a>
            </div>

            <div class="rv d4 mt-8 flex flex-wrap items-center gap-x-6 gap-y-2 text-sm" style="color:var(--mu2)">
                <span class="inline-flex items-center gap-2"><svg class="h-4 w-4" style="color:var(--ac)" viewBox="0 0 16 16" fill="none"><path d="M3 8.5l3.2 3L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Keine Kreditkarte</span>
                <span class="inline-flex items-center gap-2"><svg class="h-4 w-4" style="color:var(--ac)" viewBox="0 0 16 16" fill="none"><path d="M3 8.5l3.2 3L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>In 10 Minuten startklar</span>
                <span class="inline-flex items-center gap-2"><svg class="h-4 w-4" style="color:var(--ac)" viewBox="0 0 16 16" fill="none"><path d="M3 8.5l3.2 3L13 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Jederzeit kündbar</span>
            </div>
        </div>

        {{-- Interactive demo (mirrors the real booking flow, stores nothing) --}}
        <div id="demo" class="relative" data-par="-0.06" style="min-height:32rem">
            {{-- soft platform glow --}}
            <div class="pointer-events-none absolute left-1/2 top-1/2 -z-0 h-[34rem] w-[34rem] -translate-x-1/2 -translate-y-1/2 rounded-full" style="background:radial-gradient(circle,rgba(94,234,212,.28),transparent 60%)"></div>

            @php($demoSlots = ['17:30' => true, '18:00' => true, '18:30' => true, '19:00' => false, '19:30' => true, '20:00' => true])

            <div class="relative z-10 mx-auto max-w-[22rem]">
                <p class="demo-sticker mb-3 text-center text-[15px]" style="color:var(--ac)">Klick dich durch — es passiert nichts, versprochen ✌️</p>
                <div id="demoCard" class="surf ring-soft overflow-hidden" style="border-radius:24px; position:relative">
                    <div id="confettiLayer" class="pointer-events-none absolute inset-0 z-20 overflow-hidden" style="border-radius:24px"></div>
                    <div class="flex items-center gap-2 border-b px-4 py-2.5" style="border-color:var(--line2); background:#fcfbf9">
                        <span class="flex gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full" style="background:var(--line)"></span>
                            <span class="h-2.5 w-2.5 rounded-full" style="background:var(--line)"></span>
                            <span class="h-2.5 w-2.5 rounded-full" style="background:var(--line)"></span>
                        </span>
                        <span class="flex-1 text-center text-[11px] font-medium" style="color:var(--mu2)">swayy.app/book/trattoria-sonnenhof</span>
                        <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase tracking-wider" style="background:var(--acl); color:var(--ac)">Demo</span>
                    </div>

                    {{-- Booking flow (mirrors the real page: hero + numbered accordion) --}}
                    <div id="dFlow">
                        <div class="demo-hero px-5 pb-5 pt-6 text-center">
                            <div class="mx-auto mb-2.5 flex h-10 w-10 items-center justify-center rounded-xl text-lg" style="background:rgba(255,255,255,.2); box-shadow:0 0 0 2px rgba(255,255,255,.25)">🍽️</div>
                            <p class="text-lg font-black tracking-tight text-white">Trattoria Sonnenhof</p>
                            <p class="mt-0.5 text-[11px]" style="color:rgba(255,255,255,.7)">Schön, dass du da bist — wann dürfen wir dich erwarten?</p>
                        </div>

                        <div class="divide-y" style="border-color:var(--line2)">
                            {{-- Step 1: Personen --}}
                            <div id="dStep1" class="dstep" data-state="active">
                                <div class="dsp-head flex items-center gap-2.5 px-4 py-3">
                                    <span class="dsp-num">1</span>
                                    <span class="flex-1 text-[12.5px] font-semibold" style="color:var(--ink)">Wie viele Personen?</span>
                                    <span class="dsp-summary text-[11px]" style="color:var(--mu2)" id="dSum1"></span>
                                    <button type="button" class="dsp-edit text-[10px] font-semibold" style="color:var(--ac)" data-demo-edit="1">Ändern</button>
                                </div>
                                <div class="dsp-body px-4 pb-4">
                                    <div class="grid grid-cols-4 gap-1.5">
                                        @foreach(range(1, 8) as $n)
                                            <button type="button" class="dparty" data-demo-party="{{ $n }}">{{ $n }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            {{-- Step 2: Wann? --}}
                            <div id="dStep2" class="dstep" data-state="locked">
                                <div class="dsp-head flex items-center gap-2.5 px-4 py-3">
                                    <span class="dsp-num">2</span>
                                    <span class="flex-1 text-[12.5px] font-semibold" style="color:var(--ink)">Wann?</span>
                                    <span class="dsp-summary text-[11px]" style="color:var(--mu2)" id="dSum2"></span>
                                    <button type="button" class="dsp-edit text-[10px] font-semibold" style="color:var(--ac)" data-demo-edit="2">Ändern</button>
                                </div>
                                <div class="dsp-body space-y-2.5 px-4 pb-4">
                                    <input type="date" id="dDate"
                                           min="{{ now('Europe/Berlin')->toDateString() }}"
                                           value="{{ now('Europe/Berlin')->addDay()->toDateString() }}"
                                           class="w-full rounded-xl px-3 py-2 text-sm" style="border:2px solid #e7e5e4">
                                    <div class="grid grid-cols-3 gap-1.5">
                                        @foreach($demoSlots as $time => $free)
                                            <button type="button" class="dslot" data-demo-time="{{ $time }}" @disabled(! $free) @if(! $free) title="Ausgebucht" @endif>{{ $time }}</button>
                                        @endforeach
                                    </div>
                                </div>
                            </div>

                            {{-- Tischplan (eigener Block wie auf der echten Seite, optional) --}}
                            <div id="dPlanWrap" class="hidden px-4 pb-4 pt-3">
                                <div class="mb-2 flex items-center justify-between">
                                    <span class="text-[11.5px] font-semibold" style="color:var(--ink)">Tisch wählen <span class="font-normal" style="color:var(--mu2)">(optional)</span></span>
                                    <span class="flex gap-2.5 text-[9.5px]" style="color:var(--mu2)">
                                        <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm" style="background:#34d399"></span>frei</span>
                                        <span class="flex items-center gap-1"><span class="inline-block h-2 w-2 rounded-sm" style="background:#d6d3d1"></span>belegt</span>
                                    </span>
                                </div>
                                <div id="dPlan" class="relative w-full overflow-hidden rounded-xl" style="height:130px; border:2px solid #f5f5f4; background:#fafaf9"></div>
                                <p id="dPlanHint" class="mt-1.5 text-[10px]" style="color:var(--mu2)">Tippe auf einen freien Tisch — oder leer lassen für automatische Zuteilung.</p>
                            </div>

                            {{-- Step 3: Deine Angaben --}}
                            <div id="dStep3" class="dstep" data-state="locked">
                                <div class="dsp-head flex items-center gap-2.5 px-4 py-3">
                                    <span class="dsp-num">3</span>
                                    <span class="flex-1 text-[12.5px] font-semibold" style="color:var(--ink)">Deine Angaben</span>
                                </div>
                                <div class="dsp-body space-y-3 px-4 pb-4">
                                    <div>
                                        <label for="dName" class="mb-1 block text-[11px] font-semibold" style="color:var(--ink2)">Name *</label>
                                        <input type="text" id="dName" maxlength="40" placeholder="z. B. Alex" autocomplete="off"
                                               class="w-full rounded-xl px-3 py-2 text-sm" style="border:2px solid #e7e5e4">
                                    </div>
                                    <label class="flex items-start gap-2 text-[10.5px]" style="color:var(--mu)">
                                        <input type="checkbox" id="dPrivacy" class="mt-0.5 h-3.5 w-3.5 rounded" style="accent-color:var(--ac)">
                                        <span>Ich akzeptiere die Datenschutzhinweise. <span style="color:var(--mu2)">(In der Demo natürlich folgenlos.)</span></span>
                                    </label>
                                    <button type="button" id="dConfirm" class="flex w-full items-center justify-center gap-2 rounded-xl py-2.5 text-[13px] font-bold text-white transition-all active:scale-[0.99]" style="background:var(--ac)">
                                        Jetzt reservieren
                                        <svg class="h-4 w-4 opacity-80" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 8l4 4m0 0l-4 4m4-4H3"/></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Confirmation (mirrors the real confirmation page) --}}
                    <div id="dDone" class="hidden p-5 text-center">
                        <div class="checkpop mx-auto mb-3 flex h-14 w-14 items-center justify-center rounded-full text-2xl" style="background:var(--acl); color:var(--ac)">✓</div>
                        <p class="serif text-lg" style="color:var(--ink)">Reservierung bestätigt</p>
                        <p class="mt-0.5 text-xs" style="color:var(--mu)">Danke, <span id="dDoneName" class="font-semibold" style="color:var(--ink)">Alex</span> — wir freuen uns auf dich!</p>
                        <div class="mx-auto mt-3 rounded-xl px-4 py-2.5 text-xs" style="background:#faf8f4; color:var(--ink2)">
                            <span id="dDoneSummary"></span><br>
                            <span class="font-semibold tracking-wide" style="color:var(--ac)">Code <span id="dDoneCode">DEMO-0000</span></span>
                        </div>
                        <p class="mx-auto mt-3 max-w-[16rem] text-[11px] leading-relaxed" style="color:var(--mu2)">Genau so fühlt es sich für deine Gäste an. Und nein — hier wurde nichts gespeichert. 😉</p>
                        <a href="{{ route('register') }}" class="mt-4 inline-block w-full rounded-xl py-2.5 text-[13px] font-semibold text-white" style="background:var(--ink)">Das will ich für meinen Betrieb</a>
                        <button type="button" id="dReset" class="mt-2 w-full py-1.5 text-[12px] font-medium" style="color:var(--mu2)">↻ Nochmal ausprobieren</button>
                    </div>
                </div>
            </div>

            {{-- floating notif: new booking (updates live when the demo is played) --}}
            <div class="floaty slow absolute -right-2 top-8 z-20 sm:-right-6" data-par="0.10">
                <div class="surf ring-soft px-4 py-3" style="min-width:11.5rem">
                    <div class="mb-1.5 flex items-center gap-2">
                        <span class="ldot"></span>
                        <span class="text-[10px] font-bold uppercase tracking-wider" style="color:var(--mu2)">Neue Buchung</span>
                    </div>
                    <p class="text-[12.5px] font-semibold" style="color:var(--ink)"><span id="dNotifLine">Tisch 4 · 19:30 · 4 P.</span></p>
                    <p class="mt-0.5 text-[11px]" style="color:var(--mu2)">gerade eben</p>
                </div>
            </div>

        </div>
    </div>

    {{-- value marquee --}}
    <div class="relative z-10 overflow-hidden border-y py-5" style="border-color:var(--line2); background:rgba(255,255,255,.5)">
        <div class="marq">
            @php($words = ['Das Telefon bleibt still','Der Freitagabend läuft einfach','Gäste, die wiederkommen','Kein leerer Sechser-Tisch','Feierabend ohne Zettelchaos','Ruhiger Service'])
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
            <p class="rv d2 mx-auto mt-5 leading-relaxed" style="color:var(--mu); max-width:34rem">Dieselbe Plattform — je nach Betrieb als Tischreservierung oder als Terminbuchung pro Mitarbeiter und Leistung. Du stellst es einfach um.</p>
        </div>

        <div class="mt-16 grid gap-6 md:grid-cols-2">
            <div class="surf lift rv d1 p-9">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl text-2xl" style="background:linear-gradient(140deg,#f0fdfa,#ccfbf1)">🍽️</div>
                <h3 class="serif mt-6 text-2xl" style="color:var(--ink)">Restaurants, Cafés &amp; Bars</h3>
                <p class="mt-3 leading-relaxed" style="color:var(--mu)">Tischbasierte Reservierung mit grafischem Grundriss, Kombinationen und automatischer Zuweisung — damit der Samstagabend sich von selbst sortiert.</p>
                <ul class="mt-6 space-y-3 text-[15px]" style="color:var(--ink2)">
                    @foreach(['Grafischer Tischplan mit Flächenzonen','Öffentlicher Grundriss zur Tischwahl','Events &amp; Ticketverkauf'] as $li)
                        <li class="flex items-start gap-3"><span class="mt-1.5 h-1.5 w-1.5 flex-none rounded-full" style="background:var(--ac)"></span>{!! $li !!}</li>
                    @endforeach
                </ul>
            </div>
            <div class="surf lift rv d2 p-9">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl text-2xl" style="background:linear-gradient(140deg,#eef2ff,#e0e7ff)">✂️</div>
                <h3 class="serif mt-6 text-2xl" style="color:var(--ink)">Friseure &amp; Dienstleister</h3>
                <p class="mt-3 leading-relaxed" style="color:var(--mu)">Terminbuchung pro Mitarbeiter und Leistung — mit Dienstplan, Abwesenheiten und einem Optimierer, der Lücken im Kalender gar nicht erst entstehen lässt.</p>
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
        <h2 class="display rv d1 mt-4" style="font-size:clamp(2rem,4vw,3rem)">Alles, was dir den<br><span class="serif-i" style="color:var(--ac)">Service leichter</span> macht</h2>
    </div>

    <div class="mt-24 space-y-28">

        {{-- F1 Online-Buchung --}}
        <div class="grid items-center gap-12 lg:grid-cols-2">
            <div class="rv">
                <p class="eyebrow">Online-Buchung</p>
                <h3 class="serif mt-3" style="font-size:clamp(1.6rem,3vw,2.2rem); color:var(--ink); line-height:1.12">Ein Buchungserlebnis,<br>das Gäste lieben</h3>
                <p class="mt-4 leading-relaxed" style="color:var(--mu)">Deine Gäste buchen mobil in unter einer Minute — mit Live-Verfügbarkeit, optionaler Tisch- oder Mitarbeiterwahl und Bestätigung aufs Handy. Als Link geteilt oder mit zwei Zeilen Code in deine Website eingebettet.</p>
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
                            ['#a8a29e','20:00','Becker · 2 P.','sitzt seit 20:04'],
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
                <p class="mt-4 leading-relaxed" style="color:var(--mu)">Es ist 19:40, die Küche ruft, vorne warten drei Gruppen — ein Blick aufs Board und du weißt, was los ist. Neue Reservierungen erscheinen sofort, Check-in und Checkout sind einen Fingertipp entfernt. Ideal für Tresen und Tablet.</p>
                <ul class="mt-6 grid gap-3 sm:grid-cols-2 text-[15px]" style="color:var(--ink2)">
                    @foreach(['Live-Updates ohne Reload','Check-in mit einem Tipp','Verweildauer in Echtzeit','Ansicht nach Tisch oder Zeit'] as $li)
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
                <p class="mt-4 leading-relaxed" style="color:var(--mu)">Der Sechser-Tisch, der am Samstag einfach nicht auftaucht? Mit einer kleinen Anzahlung passiert das nicht mehr. Bei Nichterscheinen greift der Schutz von allein, Erstattungen laufen mit einem Klick.</p>
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
                ['🗣️','Du oder Sie — deine Gäste, dein Ton'],
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
                ['01','Konto erstellen','Betrieb registrieren — dein Test startet sofort, ganz ohne Zahlungsdaten.'],
                ['02','Einrichten','Der Assistent führt dich durch Betriebstyp, Öffnungszeiten und deine ersten Tische oder Mitarbeiter — fertig in Minuten.'],
                ['03','Link teilen','Deinen Buchungslink auf Website, Social oder Maps teilen — ab jetzt läuft alles von allein.'],
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
            <p class="rv d2 mx-auto mt-5 leading-relaxed" style="color:var(--mu); max-width:34rem"><span style="color:var(--ink2)">Voller Funktionsumfang in jedem Tarif</span> — unbegrenzte Benutzer, API, Zahlungen und Berichte inklusive. Ohne Provision, ohne Kleingedrucktes.</p>
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
                <p class="col-span-full text-center" style="color:var(--mu)">Preise auf Anfrage — <a href="{{ route('contact') }}" class="font-semibold" style="color:var(--ac)">schreib uns</a>.</p>
            @endforelse
        </div>
        <p class="mx-auto mt-8 max-w-xl text-center text-sm" style="color:var(--mu2)">Mehr Tische oder Standorte nötig? Upgrade jederzeit — du zahlst nur, wenn dein Betrieb wächst.</p>
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
                ['Für wen ist Swayy geeignet?','Für Restaurants, Cafés und Bars (tischbasiert) genauso wie für Friseure und Dienstleister (terminbasiert pro Mitarbeiter und Leistung). Den Betriebstyp stellst du pro Konto einfach um.'],
                ['Brauche ich eine eigene Website?','Nein. Du bekommst einen Buchungslink für Social und Google Maps. Wenn du eine Website hast, bettest du das Widget mit zwei Zeilen Code ein.'],
                ['Welche Zahlungsmöglichkeiten gibt es?','Gängige Zahlungsarten sind direkt integriert. Kreditkartendaten landen nie bei uns — die Abwicklung läuft sicher über den Zahlungsdienstleister.'],
                ['Was passiert nach dem Testzeitraum?','Du entscheidest dich für einen Tarif — oder eben nicht. Abgebucht wird nichts, denn im Test fragen wir gar keine Zahlungsdaten ab.'],
                ['Ist Swayy DSGVO-konform?','Ja. EU-Hosting, Einwilligungsverwaltung, Datenexport und Anonymisierung pro Gast sind eingebaut, IP-Adressen werden minimiert. Sogar die Schriften laden lokal — ganz ohne externes CDN.'],
                ['Kann ich mehrere Standorte verwalten?','Ja, ab dem Multi-Location-Tarif verwaltest du beliebig viele Standorte unter einem Konto — mit getrennten Plänen, Berichten und Teams.'],
                ['Kann ich das Erscheinungsbild anpassen?','Komplett. Logo, Farben und Rechtstexte hinterlegst du direkt im Admin-Bereich — sofort aktiv. Das Widget passt sich deinem Look an, und sogar die Anrede (du oder Sie) wählst du passend zu deinem Betrieb.'],
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
                <h2 class="display mt-4 text-white" style="font-size:clamp(2.1rem,4.5vw,3.4rem)">Bereit für volle Tische —<br><span class="serif-i">ohne Telefonchaos?</span></h2>
                <p class="mx-auto mt-5 text-lg leading-relaxed" style="color:rgba(240,253,250,.82); max-width:30rem">In ein paar Minuten eingerichtet. 30 Tage kostenlos — ohne Risiko, ohne Kreditkarte. Dein nächster Freitagabend kann kommen.</p>
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

    /* ── Interactive booking demo — mirrors the real accordion flow,
          front-end only, nothing is stored ─────────────────────────── */
    (function(){
        const card = document.getElementById('demoCard');
        if(!card) return;
        const steps = { 1: document.getElementById('dStep1'), 2: document.getElementById('dStep2'), 3: document.getElementById('dStep3') };
        const state = { pax: null, time: null, table: null };
        const dateInput = document.getElementById('dDate');
        const slots = [...card.querySelectorAll('.dslot')];

        function setState(n, s){ steps[n].dataset.state = s; }

        /* Mini floor plan — like the real public floor plan. 1–2 tables are
           always occupied for the chosen slot (rotates per time), tables too
           small for the party are unavailable too. */
        const tables = [
            { id: 'T1', cap: 2, shape: 'round', x: 6,  y: 10, w: 34, h: 34 },
            { id: 'T2', cap: 4, shape: 'rect',  x: 32, y: 8,  w: 54, h: 36 },
            { id: 'T3', cap: 4, shape: 'rect',  x: 65, y: 8,  w: 54, h: 36 },
            { id: 'T4', cap: 2, shape: 'round', x: 7,  y: 58, w: 34, h: 34 },
            { id: 'T5', cap: 6, shape: 'rect',  x: 30, y: 55, w: 62, h: 40 },
            { id: 'T6', cap: 8, shape: 'rect',  x: 60, y: 53, w: 76, h: 44 },
        ];
        const busyBySlot = {
            '17:30': ['T2', 'T4'],
            '18:00': ['T1'],
            '18:30': ['T3', 'T1'],
            '19:30': ['T2'],
            '20:00': ['T4', 'T3'],
        };
        const planWrap = document.getElementById('dPlanWrap');
        const plan = document.getElementById('dPlan');
        const planHint = document.getElementById('dPlanHint');

        function renderPlan(){
            plan.innerHTML = '';
            state.table = null;
            planHint.textContent = 'Tippe auf einen freien Tisch — oder leer lassen für automatische Zuteilung.';
            const busy = busyBySlot[state.time] || ['T2'];
            tables.forEach(t => {
                const el = document.createElement('button');
                el.type = 'button';
                el.className = 'dtable' + (t.shape === 'round' ? '' : '');
                el.style.cssText = `left:${t.x}%;top:${t.y}%;width:${t.w}px;height:${t.h}px;border-radius:${t.shape === 'round' ? '9999px' : '8px'}`;
                el.textContent = t.id;
                const isBusy = busy.includes(t.id) || t.cap < (state.pax || 1);
                if (isBusy) {
                    el.classList.add('busy');
                    el.title = busy.includes(t.id) ? 'Belegt' : 'Zu klein für ' + state.pax + ' Personen';
                } else {
                    el.title = t.id + ' · bis ' + t.cap + ' Personen';
                    el.addEventListener('click', () => {
                        const wasOn = el.classList.contains('on');
                        plan.querySelectorAll('.dtable').forEach(x => x.classList.remove('on'));
                        if (wasOn) {
                            state.table = null;
                            planHint.textContent = 'Tippe auf einen freien Tisch — oder leer lassen für automatische Zuteilung.';
                        } else {
                            el.classList.add('on');
                            state.table = t.id;
                            planHint.textContent = 'Tisch ' + t.id + ' ausgewählt — gute Wahl! Nochmal tippen zum Abwählen.';
                        }
                    });
                }
                plan.appendChild(el);
            });
        }

        function fmtDate(){
            const [y, m, d] = (dateInput.value || '').split('-').map(Number);
            if (!y) return '';
            return new Date(y, m - 1, d).toLocaleDateString('de-DE', { weekday: 'short', day: '2-digit', month: '2-digit' }).replace(',', '');
        }

        // Step 1: party buttons (like the real page's big number grid)
        card.querySelectorAll('.dparty').forEach(btn => btn.addEventListener('click', () => {
            state.pax = parseInt(btn.dataset.demoParty, 10);
            document.getElementById('dSum1').textContent = state.pax + (state.pax === 1 ? ' Person' : ' Personen');
            setState(1, 'done');
            setState(2, 'active');
        }));

        // Step 2: date change clears a chosen slot (real page reloads slots)
        dateInput.addEventListener('change', () => {
            state.time = null; state.table = null;
            slots.forEach(x => x.classList.remove('on'));
            planWrap.classList.add('hidden');
            if (steps[2].dataset.state === 'done') { setState(2, 'active'); setState(3, 'locked'); }
        });

        slots.forEach(s => s.addEventListener('click', () => {
            if (s.disabled) return;
            slots.forEach(x => x.classList.remove('on'));
            s.classList.add('on');
            state.time = s.dataset.demoTime;
            document.getElementById('dSum2').textContent = fmtDate() + ' · ' + state.time + ' Uhr';
            setState(2, 'done');
            // Floor plan appears once the slot is known (occupancy depends on it)
            planWrap.classList.remove('hidden');
            renderPlan();
            setState(3, 'active');
            document.getElementById('dName').focus();
        }));

        // "Ändern" reopens a step; later steps lock again
        card.querySelectorAll('[data-demo-edit]').forEach(btn => btn.addEventListener('click', () => {
            const n = parseInt(btn.dataset.demoEdit, 10);
            setState(n, 'active');
            for (let i = n + 1; i <= 3; i++) setState(i, 'locked');
            state.time = null; state.table = null;
            slots.forEach(x => x.classList.remove('on'));
            planWrap.classList.add('hidden');
        }));

        function confirmDemo(){
            const privacy = document.getElementById('dPrivacy');
            if (!privacy.checked) {
                privacy.parentElement.style.color = '#dc2626';
                setTimeout(() => privacy.parentElement.style.color = '', 1200);
                return;
            }
            const name = document.getElementById('dName').value.trim() || 'Alex';
            const first = name.split(' ')[0];
            const tableTxt = state.table ? ' · Tisch ' + state.table : '';
            const summary = `${fmtDate()} · ${state.time} Uhr · ${state.pax} ${state.pax === 1 ? 'Person' : 'Personen'}${tableTxt}`;
            document.getElementById('dDoneName').textContent = first;
            document.getElementById('dDoneSummary').textContent = summary;
            document.getElementById('dDoneCode').textContent =
                'DEMO-' + Math.random().toString(36).slice(2, 6).toUpperCase();
            // The floating admin notification reacts — "so sieht das bei dir aus"
            document.getElementById('dNotifLine').textContent =
                `${first} · ${state.time} · ${state.pax} P.${state.table ? ' · ' + state.table : ''}`;
            document.getElementById('dFlow').classList.add('hidden');
            const done = document.getElementById('dDone');
            done.classList.remove('hidden');
            const check = done.querySelector('.checkpop');
            check.classList.remove('checkpop'); void check.offsetWidth; check.classList.add('checkpop');
            fireConfetti();
        }

        // Small, dependency-free confetti burst inside the demo card.
        function fireConfetti(){
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
            const layer = document.getElementById('confettiLayer');
            const colors = ['#0f766e', '#5eead4', '#fde68a', '#f59e0b', '#a7f3d0', '#134e4a'];
            for (let i = 0; i < 34; i++) {
                const p = document.createElement('span');
                p.className = 'confetti-piece';
                const startX = 45 + Math.random() * 10; // near the checkmark, centre-ish
                const drift = (Math.random() - 0.5) * 220;
                const size = 5 + Math.random() * 5;
                p.style.left = startX + '%';
                p.style.width = size + 'px';
                p.style.height = size * (Math.random() < 0.5 ? 1 : 2.2) + 'px';
                p.style.background = colors[i % colors.length];
                p.style.borderRadius = Math.random() < 0.5 ? '50%' : '2px';
                p.style.setProperty('--cx', drift + 'px');
                p.style.setProperty('--cr', (Math.random() < 0.5 ? -1 : 1) * (360 + Math.random() * 360) + 'deg');
                p.style.animationDuration = (0.9 + Math.random() * 0.7) + 's';
                p.style.animationDelay = (Math.random() * 0.15) + 's';
                layer.appendChild(p);
                setTimeout(() => p.remove(), 2000);
            }
        }
        document.getElementById('dConfirm').addEventListener('click', confirmDemo);
        document.getElementById('dName').addEventListener('keydown', e => { if (e.key === 'Enter') confirmDemo(); });

        document.getElementById('dReset').addEventListener('click', () => {
            state.pax = null; state.time = null; state.table = null;
            slots.forEach(x => x.classList.remove('on'));
            planWrap.classList.add('hidden');
            document.getElementById('dName').value = '';
            document.getElementById('dPrivacy').checked = false;
            document.getElementById('confettiLayer').innerHTML = '';
            setState(1, 'active'); setState(2, 'locked'); setState(3, 'locked');
            document.getElementById('dDone').classList.add('hidden');
            document.getElementById('dFlow').classList.remove('hidden');
        });

        // "Erst mal ausprobieren" flashes the card so the eye lands on it
        const jump = document.getElementById('demoJump');
        if (jump) jump.addEventListener('click', () => {
            card.classList.remove('flash'); void card.offsetWidth; card.classList.add('flash');
        });
    })();

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
