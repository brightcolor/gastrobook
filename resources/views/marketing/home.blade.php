@extends('layouts.marketing')

@section('content')
{{-- ===================== HERO ===================== --}}
<section class="relative overflow-hidden bg-stone-950 text-white">
    <div class="pointer-events-none absolute inset-0 opacity-60"
         style="background:radial-gradient(60rem 30rem at 75% -10%, rgba(13,148,136,.35), transparent), radial-gradient(40rem 25rem at 0% 110%, rgba(13,148,136,.18), transparent)"></div>

    <div class="relative mx-auto grid max-w-6xl items-center gap-12 px-4 py-20 md:py-28 lg:grid-cols-2">
        <div>
            <p class="mb-5 inline-flex items-center gap-2 rounded-full border border-teal-500/30 bg-teal-500/10 px-4 py-1.5 text-sm font-semibold text-teal-300">
                <span class="h-1.5 w-1.5 rounded-full bg-teal-400"></span>
                Für Restaurants <span class="text-teal-500/60">·</span> Friseure &amp; Dienstleister
            </p>
            <h1 class="max-w-xl text-4xl font-extrabold leading-[1.1] tracking-tight md:text-5xl">
                Reservierungen &amp; Termine, die sich von selbst verwalten
            </h1>
            <p class="mt-5 max-w-xl text-lg text-stone-300">
                Online-Buchung, Live-Board in Echtzeit, Tischplan bzw. Mitarbeiter-Dienstplan,
                Zahlungen und No-Show-Schutz – alles in einer Plattform. DSGVO-konform, in der EU gehostet,
                <span class="font-semibold text-white">ohne Provision pro Buchung</span>.
            </p>
            <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                <a href="{{ route('register') }}" class="rounded-xl bg-teal-500 px-8 py-4 text-center text-lg font-bold text-stone-950 shadow-lg shadow-teal-500/20 transition hover:bg-teal-400">
                    30 Tage kostenlos testen
                </a>
                <a href="#hauptfunktionen" class="rounded-xl border border-stone-700 px-8 py-4 text-center text-lg font-semibold text-stone-200 transition hover:border-stone-500 hover:bg-stone-900">
                    Funktionen ansehen
                </a>
            </div>
            <p class="mt-4 text-sm text-stone-400">Keine Kreditkarte nötig · in 10 Minuten startklar · jederzeit kündbar</p>
        </div>

        {{-- Product preview mock --}}
        <div class="relative">
            <div class="mx-auto max-w-sm rounded-2xl border border-stone-700/60 bg-stone-900/80 p-5 shadow-2xl backdrop-blur">
                <div class="flex items-center justify-between">
                    <span class="text-sm font-bold text-white">Termin buchen</span>
                    <span class="rounded-full bg-teal-500/15 px-2.5 py-0.5 text-xs font-semibold text-teal-300">Live</span>
                </div>
                <div class="mt-4 grid grid-cols-4 gap-2">
                    @foreach(['1','2','3','4'] as $i)
                        <div class="rounded-lg border-2 py-2 text-center text-sm font-bold {{ $i==='2' ? 'border-teal-400 bg-teal-400/10 text-teal-300' : 'border-stone-700 text-stone-400' }}">{{ $i }}</div>
                    @endforeach
                </div>
                <div class="mt-3 grid grid-cols-3 gap-2">
                    @foreach(['18:00'=>false,'18:30'=>true,'19:00'=>false,'19:30'=>false,'20:00'=>true,'20:30'=>false] as $t=>$on)
                        <div class="rounded-lg border-2 py-1.5 text-center text-xs font-semibold {{ $on ? 'border-teal-400 bg-teal-400/10 text-teal-300' : 'border-stone-700 text-stone-500' }}">{{ $t }}</div>
                    @endforeach
                </div>
                <div class="mt-4 rounded-xl bg-teal-500 py-2.5 text-center text-sm font-bold text-stone-950">Jetzt buchen</div>
            </div>
            <div class="absolute -bottom-6 -left-2 hidden w-56 rotate-[-4deg] rounded-xl border border-stone-700/60 bg-stone-900/95 p-3 shadow-xl sm:block">
                <p class="text-[11px] font-bold uppercase tracking-wide text-stone-400">Live-Board</p>
                <div class="mt-2 space-y-1.5 text-xs">
                    <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-emerald-400"></span><span class="text-stone-300">19:00 · Tisch 4 · 2 P</span></div>
                    <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-amber-400"></span><span class="text-stone-300">19:30 · Anfrage · 4 P</span></div>
                    <div class="flex items-center gap-2"><span class="h-2 w-2 rounded-full bg-blue-400"></span><span class="text-stone-300">20:00 · bestätigt · 3 P</span></div>
                </div>
            </div>
        </div>
    </div>
</section>

{{-- ===================== TRUST STRIP ===================== --}}
<section class="border-b border-stone-200 bg-white">
    <div class="mx-auto grid max-w-6xl grid-cols-2 gap-px overflow-hidden px-4 py-8 text-center md:grid-cols-4">
        @foreach([
            ['🇪🇺', 'EU-Hosting & DSGVO'],
            ['🚫', 'Keine Provision pro Buchung'],
            ['🍽️ ✂️', 'Restaurant & Salon'],
            ['🔓', 'Self-host möglich, kein Lock-in'],
        ] as [$icon, $label])
            <div class="px-3">
                <div class="text-2xl">{{ $icon }}</div>
                <p class="mt-2 text-sm font-semibold text-stone-700">{{ $label }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- ===================== BRANCHEN ===================== --}}
<section id="branchen" class="mx-auto max-w-6xl px-4 py-20">
    <p class="text-center text-sm font-bold uppercase tracking-wide text-teal-700">Ein System, zwei Welten</p>
    <h2 class="mt-2 text-center text-3xl font-extrabold tracking-tight">Für Gastronomie und Dienstleister</h2>
    <div class="mt-12 grid gap-6 md:grid-cols-2">
        <div class="rounded-2xl border border-stone-200 bg-gradient-to-br from-white to-stone-50 p-8">
            <div class="text-4xl">🍽️</div>
            <h3 class="mt-4 text-xl font-bold">Restaurants, Cafés &amp; Bars</h3>
            <p class="mt-2 text-stone-600">Tischbasierte Reservierung mit Tischplan, Kombinationen, Walk-ins und Personen-Kapazität.</p>
            <ul class="mt-4 space-y-2 text-sm text-stone-600">
                <li class="flex gap-2"><span class="text-teal-600">✓</span> Grafischer Tischplan &amp; automatische Tischzuweisung</li>
                <li class="flex gap-2"><span class="text-teal-600">✓</span> Öffentlicher Tischplan zur Tischwahl durch den Gast</li>
                <li class="flex gap-2"><span class="text-teal-600">✓</span> Events &amp; Ticketverkauf (Brunch, Weinprobe, Silvester)</li>
            </ul>
        </div>
        <div class="rounded-2xl border border-stone-200 bg-gradient-to-br from-white to-stone-50 p-8">
            <div class="text-4xl">✂️</div>
            <h3 class="mt-4 text-xl font-bold">Friseure &amp; Dienstleister</h3>
            <p class="mt-2 text-stone-600">Terminbuchung pro Mitarbeiter und Leistung – mit Dienstplan, Abwesenheiten und Puffern.</p>
            <ul class="mt-4 space-y-2 text-sm text-stone-600">
                <li class="flex gap-2"><span class="text-teal-600">✓</span> Leistungen mit Dauer &amp; Preis, frei kombinierbar</li>
                <li class="flex gap-2"><span class="text-teal-600">✓</span> Mitarbeiter-Arbeitszeiten, Urlaub &amp; Lückenoptimierer</li>
                <li class="flex gap-2"><span class="text-teal-600">✓</span> Buchung bei „beliebig" oder bestimmtem Mitarbeiter</li>
            </ul>
        </div>
    </div>
</section>

{{-- ===================== HAUPTFUNKTIONEN ===================== --}}
<section id="hauptfunktionen" class="bg-stone-50">
    <div class="mx-auto max-w-6xl px-4 py-20">
        <p class="text-center text-sm font-bold uppercase tracking-wide text-teal-700">Hauptfunktionen</p>
        <h2 class="mt-2 text-center text-3xl font-extrabold tracking-tight">Alles für volle Auslastung – ohne Telefonchaos</h2>
        <p class="mx-auto mt-3 max-w-2xl text-center text-stone-500">Vom ersten Klick des Gastes bis zur Auswertung am Monatsende.</p>

        <div class="mt-12 grid gap-6 md:grid-cols-3">
            @foreach([
                ['📱', 'Online-Buchung &amp; Widget', 'Mobile-first Buchungsseite mit Live-Verfügbarkeit – als Link teilen oder per Snippet einbetten. Optionale Tisch-/Mitarbeiterwahl.'],
                ['🟢', 'Live-Board in Echtzeit', 'Neue &amp; anstehende Buchungen auf einen Blick, Inline-Aktionen, Dark Mode und Vollbild – ideal für den Tresen. Updates per SSE.'],
                ['💳', 'Zahlungen &amp; No-Show-Schutz', 'Anzahlungen über Stripe &amp; PayPal, automatische oder manuelle Rückerstattung, Erinnerungen – No-Shows runter, Umsatz hoch.'],
            ] as [$icon, $title, $text])
                <div class="rounded-2xl border border-stone-200 bg-white p-7 shadow-sm">
                    <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-teal-50 text-2xl">{{ $icon }}</div>
                    <h3 class="mt-4 text-lg font-bold">{!! $title !!}</h3>
                    <p class="mt-2 text-sm leading-relaxed text-stone-600">{!! $text !!}</p>
                </div>
            @endforeach
        </div>

        {{-- Secondary feature grid --}}
        <div class="mt-6 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach([
                ['🗺️', 'Tischplan &amp; Dienstplan', 'Grafischer Tischplan per Drag &amp; Drop bzw. Mitarbeiter-Dienstplan mit Arbeitszeiten und Abwesenheiten.'],
                ['👤', 'Kundenkonto (Magic-Link)', 'Passwortloses Login per E-Mail: Gäste sehen, buchen um und stornieren selbst – das entlastet Ihr Team.'],
                ['🔁', 'Online-Umbuchung', 'Gäste verschieben ihren Termin selbstständig innerhalb der Frist – mit erneuter Verfügbarkeitsprüfung.'],
                ['✉️', 'Erinnerungen per Mail &amp; SMS', 'Automatische Bestätigungen und Erinnerungen (SMS über den deutschen Anbieter seven.io) gegen No-Shows.'],
                ['👥', 'Gäste-CRM', 'Stammgäste erkennen, Besuchshistorie, Tags, Allergien – inkl. DSGVO-Export &amp; Anonymisierung.'],
                ['📊', 'Berichte, API &amp; Webhooks', 'Auslastung, No-Show-Rate, Quellen, CSV-Export. REST-API und Webhooks für eigene Integrationen.'],
            ] as [$icon, $title, $text])
                <div class="rounded-2xl border border-stone-200 bg-white p-6">
                    <div class="text-2xl">{{ $icon }}</div>
                    <h3 class="mt-3 font-bold">{!! $title !!}</h3>
                    <p class="mt-1.5 text-sm text-stone-500">{!! $text !!}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ===================== ALLE FUNKTIONEN (Liste) ===================== --}}
<section class="mx-auto max-w-6xl px-4 py-20">
    <p class="text-center text-sm font-bold uppercase tracking-wide text-teal-700">Und vieles mehr</p>
    <h2 class="mt-2 text-center text-3xl font-extrabold tracking-tight">Jedes Detail mitgedacht</h2>
    <div class="mt-10 grid gap-x-10 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            'Warteliste mit automatischem Angebot',
            'Kombi-Leistungen per Klick (Salon)',
            'Lückenoptimierer für enge Auslastung',
            'Öffentlicher Tischplan zur Tischwahl',
            'Anzahlungsregeln &amp; Card-Garantie',
            'Variable Rückerstattung (auto/manuell)',
            'E-Mail-Bestätigung aktivierbar',
            'Feedback-Booster → Google-Bewertungen',
            'Newsletter-Sync (MailWizz)',
            'Mehrere Standorte unter einem Konto',
            'Rollen &amp; Rechte fürs Team',
            'Audit-Log (IP-anonymisiert)',
            'Eigenes Branding (Logo, Farben)',
            'Einbettbares JS-Widget (iFrame)',
            'Rechtstexte als Markdown, ohne Neustart',
            'Reverse-Proxy- &amp; eigene-Domain-tauglich',
            'Automatische Datenlöschung (Retention)',
            'Walk-ins in einem Klick',
        ] as $item)
            <div class="flex items-start gap-2 text-sm text-stone-700">
                <span class="mt-0.5 text-teal-600">✓</span><span>{!! $item !!}</span>
            </div>
        @endforeach
    </div>
</section>

{{-- ===================== HOW IT WORKS ===================== --}}
<section class="bg-stone-950 text-white">
    <div class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-center text-3xl font-extrabold tracking-tight">In 10 Minuten startklar</h2>
        <div class="mt-12 grid gap-8 md:grid-cols-3">
            @foreach([
                ['1', 'Konto erstellen', 'Betrieb registrieren – der Testzeitraum startet sofort, ohne Zahlungsdaten.'],
                ['2', 'Einrichten', 'Öffnungszeiten und Tische bzw. Leistungen &amp; Mitarbeiter anlegen – in wenigen Minuten.'],
                ['3', 'Buchungslink teilen', 'Link auf Website, Instagram oder Google – Buchungen laufen ab sofort digital.'],
            ] as [$step, $title, $text])
                <div class="rounded-2xl border border-stone-800 bg-stone-900/50 p-7 text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-teal-500 text-xl font-extrabold text-stone-950">{{ $step }}</div>
                    <h3 class="mt-4 text-lg font-bold">{!! $title !!}</h3>
                    <p class="mt-2 text-sm text-stone-400">{!! $text !!}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ===================== PRICING ===================== --}}
<section id="preise" class="mx-auto max-w-6xl px-4 py-20">
    <p class="text-center text-sm font-bold uppercase tracking-wide text-teal-700">Preise</p>
    <h2 class="mt-2 text-center text-3xl font-extrabold tracking-tight">Fair &amp; monatlich kündbar</h2>
    <p class="mx-auto mt-3 max-w-2xl text-center text-stone-500">
        <strong class="text-stone-700">Voller Funktionsumfang in jedem Tarif</strong> – unbegrenzte Benutzer, API, Zahlungen, alles inklusive.
        Sie zahlen nur nach Größe: Standorte und Tische. 30 Tage kostenlos, keine Provision.
    </p>

    <div class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        @forelse($plans as $plan)
            @php($highlight = $plan->key === 'professional')
            <div class="flex flex-col rounded-2xl border bg-white p-6 {{ $highlight ? 'border-teal-600 shadow-xl ring-2 ring-teal-600' : 'border-stone-200 shadow-sm' }}">
                @if($highlight)
                    <p class="mb-2 -mt-9 self-center rounded-full bg-teal-600 px-3 py-1 text-xs font-bold text-white">Beliebt</p>
                @endif
                <h3 class="text-lg font-bold">{{ $plan->name }}</h3>
                <p class="mt-2">
                    @if($plan->key === 'enterprise')
                        <span class="text-3xl font-extrabold">Auf Anfrage</span>
                    @else
                        <span class="text-4xl font-extrabold">{{ number_format($plan->price_monthly_minor / 100, 0, ',', '.') }} €</span>
                        <span class="text-sm text-stone-500">/ Monat</span>
                    @endif
                </p>

                {{-- Differentiators: locations & tables --}}
                <div class="mt-5 space-y-3 border-y border-stone-100 py-4">
                    <div>
                        <p class="text-2xl font-extrabold">{{ isset($plan->limits['max_locations']) ? ($plan->limits['max_locations'] == 1 ? '1' : $plan->limits['max_locations']) : '∞' }}</p>
                        <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">{{ (isset($plan->limits['max_locations']) && $plan->limits['max_locations'] == 1) ? 'Standort' : 'Standorte' }}</p>
                    </div>
                    <div>
                        <p class="text-2xl font-extrabold">{{ isset($plan->limits['max_tables']) ? 'bis '.$plan->limits['max_tables'] : '∞' }}</p>
                        <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Tische / Ressourcen</p>
                    </div>
                </div>

                <p class="mt-4 flex-1 text-sm text-stone-600">
                    <span class="font-semibold text-stone-800">Alle Funktionen inklusive</span><br>
                    Unbegrenzte Benutzer · API &amp; Webhooks · Zahlungen &amp; No-Show-Schutz · Warteliste · Berichte · eigenes Branding
                </p>

                @if($plan->key === 'enterprise')
                    <a href="{{ route('contact') }}" class="mt-6 rounded-xl border border-stone-300 py-3 text-center font-bold hover:bg-stone-50">Kontakt aufnehmen</a>
                @else
                    <a href="{{ route('register') }}" class="mt-6 rounded-xl py-3 text-center font-bold {{ $highlight ? 'bg-teal-600 text-white hover:bg-teal-700' : 'border border-stone-300 hover:bg-stone-50' }}">Kostenlos testen</a>
                @endif
            </div>
        @empty
            <p class="col-span-full text-center text-stone-500">Preise auf Anfrage – <a href="{{ route('contact') }}" class="font-semibold text-teal-700">kontaktieren Sie uns</a>.</p>
        @endforelse
    </div>

    <p class="mx-auto mt-8 max-w-2xl text-center text-sm text-stone-500">
        Mehr Tische oder Standorte nötig? Jederzeit upgraden – Sie zahlen nur, wenn Ihr Betrieb wächst.
    </p>
</section>

{{-- ===================== FAQ ===================== --}}
<section id="faq" class="bg-stone-50">
    <div class="mx-auto max-w-3xl px-4 py-20">
        <h2 class="text-center text-3xl font-extrabold tracking-tight">Häufige Fragen</h2>
        <div class="mt-10 space-y-4">
            @foreach([
                ['Für wen ist Swayy geeignet?', 'Für Restaurants, Cafés und Bars (tischbasiert) ebenso wie für Friseure und Dienstleister (terminbasiert pro Mitarbeiter und Leistung). Der Betriebstyp ist pro Konto umschaltbar.'],
                ['Brauche ich eine eigene Website?', 'Nein. Sie erhalten einen Buchungslink für Google, Instagram & Co. Wer eine Website hat, bettet das Widget mit zwei Zeilen Code ein.'],
                ['Welche Zahlungsanbieter werden unterstützt?', 'Stripe und PayPal – auch beide gleichzeitig; der Gast wählt dann an der Kasse. Kreditkartendaten werden nie bei uns gespeichert.'],
                ['Was passiert nach dem Testzeitraum?', 'Sie wählen einen Tarif – oder nicht. Es gibt keine automatische Abbuchung, da im Test keine Zahlungsdaten erhoben werden.'],
                ['Ist Swayy DSGVO-konform?', 'Ja. EU-Hosting, Einwilligungsverwaltung, Datenexport und Anonymisierung pro Gast sind eingebaut, IP-Adressen werden minimiert. Rechtstexte sind als Markdown direkt pflegbar.'],
                ['Kann ich mehrere Standorte verwalten?', 'Ja, ab dem Multi-Location-Tarif beliebig viele Standorte unter einem Konto – mit getrennten Plänen und Berichten.'],
                ['Lässt sich Swayy selbst hosten?', 'Ja. Per Docker hinter eigener Domain/Reverse Proxy betreibbar – volle Datenhoheit, kein Lock-in.'],
            ] as [$q, $a])
                <details class="group rounded-xl border border-stone-200 bg-white p-5">
                    <summary class="flex cursor-pointer items-center justify-between font-bold">
                        <span>{{ $q }}</span>
                        <span class="text-teal-600 transition group-open:rotate-45">+</span>
                    </summary>
                    <p class="mt-3 text-sm leading-relaxed text-stone-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- ===================== CTA ===================== --}}
<section class="relative overflow-hidden bg-teal-700 text-white">
    <div class="relative mx-auto max-w-4xl px-4 py-16 text-center">
        <h2 class="text-3xl font-extrabold tracking-tight md:text-4xl">Bereit für volle Auslastung ohne Telefonchaos?</h2>
        <p class="mt-3 text-lg text-teal-50">In wenigen Minuten eingerichtet. 30 Tage kostenlos, ohne Risiko.</p>
        <a href="{{ route('register') }}" class="mt-7 inline-block rounded-xl bg-white px-8 py-4 text-lg font-bold text-teal-800 shadow-lg transition hover:bg-teal-50">
            Jetzt kostenlos starten
        </a>
    </div>
</section>
@endsection
