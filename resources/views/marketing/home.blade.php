@extends('layouts.marketing')

@section('content')
{{-- Hero --}}
<section class="bg-stone-900 text-white">
    <div class="mx-auto max-w-6xl px-4 py-20 text-center md:py-28">
        <p class="mb-4 inline-block rounded-full bg-teal-700/30 px-4 py-1 text-sm font-semibold text-teal-300">30 Tage kostenlos testen – keine Kreditkarte nötig</p>
        <h1 class="mx-auto max-w-3xl text-4xl font-extrabold leading-tight md:text-5xl">Tischreservierungen, die sich von selbst verwalten</h1>
        <p class="mx-auto mt-5 max-w-2xl text-lg text-stone-300">Online-Buchungswidget, digitales Reservierungsbuch, grafischer Tischplan, Gäste-CRM und No-Show-Schutz – alles in einer Plattform, DSGVO-konform und in der EU gehostet.</p>
        <div class="mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <a href="{{ route('register') }}" class="rounded-xl bg-teal-600 px-8 py-4 text-lg font-bold text-white hover:bg-teal-500">Jetzt kostenlos starten</a>
            <a href="#funktionen" class="rounded-xl border border-stone-600 px-8 py-4 text-lg font-semibold text-stone-200 hover:bg-stone-800">Funktionen ansehen</a>
        </div>
    </div>
</section>

{{-- Features --}}
<section id="funktionen" class="mx-auto max-w-6xl px-4 py-20">
    <h2 class="text-center text-3xl font-extrabold">Alles, was Ihr Restaurant braucht</h2>
    <p class="mx-auto mt-3 max-w-2xl text-center text-stone-500">Vom ersten Klick des Gastes bis zur Auswertung am Monatsende.</p>

    <div class="mt-12 grid gap-8 sm:grid-cols-2 lg:grid-cols-3">
        @foreach([
            ['📱', 'Online-Reservierungswidget', 'Mobile-first Buchungsseite mit Live-Verfügbarkeit – als Link teilen oder per Snippet in die eigene Website einbetten.'],
            ['📖', 'Digitales Reservierungsbuch', 'Tagesansicht mit Filtern, Schnellaktionen und Statushistorie. Walk-ins mit einem Klick erfassen.'],
            ['🗺️', 'Grafischer Tischplan', 'Tische per Drag & Drop anordnen, Statusfarben live, automatische Tischzuweisung inklusive Kombinationen.'],
            ['👥', 'Gäste-CRM', 'Stammgäste erkennen, Besuchshistorie, Tags, Allergien und Notizen – mit DSGVO-Export und Anonymisierung.'],
            ['🛡️', 'No-Show-Schutz', 'Anzahlungen per Stripe, Erinnerungs-Mails und transparente No-Show-Quote pro Gast.'],
            ['⏳', 'Warteliste', 'Ausgebucht? Gäste tragen sich ein und erhalten automatisch ein Angebot, sobald ein Tisch frei wird.'],
            ['🎟️', 'Events & Tickets', 'Weinproben, Brunch, Silvester: eigene Eventseiten mit Kapazität, Fristen, Vorauszahlung und Check-in.'],
            ['⭐', 'Feedback-Booster', 'Nach dem Besuch automatisch um Feedback bitten – zufriedene Gäste werden zu Google & Co. weitergeleitet.'],
            ['📊', 'Berichte & API', 'Auslastung, No-Show-Rate, Quellen und CSV-Exporte. REST-API und Webhooks für eigene Integrationen.'],
        ] as [$icon, $title, $text])
            <div class="rounded-2xl border border-stone-200 p-6">
                <div class="text-3xl">{{ $icon }}</div>
                <h3 class="mt-3 text-lg font-bold">{{ $title }}</h3>
                <p class="mt-2 text-sm text-stone-500">{{ $text }}</p>
            </div>
        @endforeach
    </div>
</section>

{{-- How it works --}}
<section class="bg-stone-50">
    <div class="mx-auto max-w-6xl px-4 py-20">
        <h2 class="text-center text-3xl font-extrabold">In 10 Minuten startklar</h2>
        <div class="mt-12 grid gap-8 md:grid-cols-3">
            @foreach([
                ['1', 'Konto erstellen', 'Restaurant registrieren – der Testzeitraum startet sofort, ohne Zahlungsdaten.'],
                ['2', 'Öffnungszeiten & Tische anlegen', 'Räume, Tische und Buchungsregeln einrichten. Der Tischplan baut sich per Drag & Drop.'],
                ['3', 'Buchungslink teilen', 'Link auf Website, Instagram-Bio oder Google Business Profile – fertig. Reservierungen laufen ab sofort digital.'],
            ] as [$step, $title, $text])
                <div class="text-center">
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-teal-700 text-xl font-extrabold text-white">{{ $step }}</div>
                    <h3 class="mt-4 text-lg font-bold">{{ $title }}</h3>
                    <p class="mt-2 text-sm text-stone-500">{{ $text }}</p>
                </div>
            @endforeach
        </div>
    </div>
</section>

{{-- Pricing --}}
<section id="preise" class="mx-auto max-w-6xl px-4 py-20">
    <h2 class="text-center text-3xl font-extrabold">Faire Preise, monatlich kündbar</h2>
    <p class="mx-auto mt-3 max-w-2xl text-center text-stone-500">Jeder Tarif beginnt mit 30 Tagen kostenlosem Test. Keine Einrichtungsgebühr, keine Provision pro Gast.</p>

    <div class="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
        @forelse($plans as $plan)
            @php($highlight = $plan->key === 'professional')
            <div class="flex flex-col rounded-2xl border p-6 {{ $highlight ? 'border-teal-700 ring-2 ring-teal-700' : 'border-stone-200' }}">
                @if($highlight)
                    <p class="mb-2 -mt-9 self-center rounded-full bg-teal-700 px-3 py-1 text-xs font-bold text-white">Beliebt</p>
                @endif
                <h3 class="text-lg font-bold">{{ $plan->name }}</h3>
                <p class="mt-2">
                    @if($plan->key === 'enterprise')
                        <span class="text-3xl font-extrabold">Auf Anfrage</span>
                    @else
                        <span class="text-3xl font-extrabold">{{ number_format($plan->price_monthly_minor / 100, 0, ',', '.') }} €</span>
                        <span class="text-sm text-stone-500">/ Monat</span>
                    @endif
                </p>
                <ul class="mt-4 flex-1 space-y-2 text-sm text-stone-600">
                    @if(isset($plan->limits['max_locations']))
                        <li>✓ {{ $plan->limits['max_locations'] == 1 ? '1 Standort' : 'Bis zu '.$plan->limits['max_locations'].' Standorte' }}</li>
                    @else
                        <li>✓ Unbegrenzte Standorte</li>
                    @endif
                    @if(isset($plan->limits['max_users']))
                        <li>✓ {{ $plan->limits['max_users'] }} Benutzer</li>
                    @else
                        <li>✓ Unbegrenzte Benutzer</li>
                    @endif
                    @if(isset($plan->limits['max_tables']))
                        <li>✓ Bis zu {{ $plan->limits['max_tables'] }} Tische</li>
                    @else
                        <li>✓ Unbegrenzte Tische</li>
                    @endif
                    <li>{{ ($plan->features['waitlist_enabled'] ?? false) ? '✓' : '–' }} Warteliste</li>
                    <li>{{ ($plan->features['feedback_enabled'] ?? false) ? '✓' : '–' }} Feedback-Booster</li>
                    <li>{{ ($plan->features['deposits_enabled'] ?? false) ? '✓' : '–' }} No-Show-Schutz (Anzahlungen)</li>
                    <li>{{ ($plan->features['api_enabled'] ?? false) ? '✓' : '–' }} REST-API & Webhooks</li>
                    @if($plan->features['remove_branding'] ?? false)
                        <li>✓ Ohne GastroBook-Branding</li>
                    @endif
                </ul>
                @if($plan->key === 'enterprise')
                    <a href="{{ route('contact') }}" class="mt-6 rounded-xl border border-stone-300 py-3 text-center font-bold hover:bg-stone-50">Kontakt aufnehmen</a>
                @else
                    <a href="{{ route('register') }}" class="mt-6 rounded-xl py-3 text-center font-bold {{ $highlight ? 'bg-teal-700 text-white hover:bg-teal-800' : 'border border-stone-300 hover:bg-stone-50' }}">Kostenlos testen</a>
                @endif
            </div>
        @empty
            <p class="col-span-full text-center text-stone-500">Preise auf Anfrage – <a href="{{ route('contact') }}" class="font-semibold text-teal-700">kontaktieren Sie uns</a>.</p>
        @endforelse
    </div>
</section>

{{-- FAQ --}}
<section id="faq" class="bg-stone-50">
    <div class="mx-auto max-w-3xl px-4 py-20">
        <h2 class="text-center text-3xl font-extrabold">Häufige Fragen</h2>
        <div class="mt-10 space-y-4">
            @foreach([
                ['Brauche ich eine eigene Website?', 'Nein. Sie erhalten einen Buchungslink, den Sie überall teilen können – auf Google, Instagram oder Facebook. Wer eine Website hat, bettet das Widget mit zwei Zeilen Code ein.'],
                ['Was passiert nach dem Testzeitraum?', 'Sie wählen einen Tarif – oder nicht. Es gibt keine automatische Abbuchung, da im Test keine Zahlungsdaten erhoben werden.'],
                ['Werden Zahlungsdaten gespeichert?', 'Nein. Zahlungen für Anzahlungen laufen vollständig über Stripe; GastroBook speichert keine Kreditkartendaten, nur Status und Beleg-Referenzen.'],
                ['Ist GastroBook DSGVO-konform?', 'Ja. EU-Hosting, Einwilligungsverwaltung, Datenexport und Anonymisierung pro Gast sind eingebaut. IP-Adressen werden minimiert gespeichert.'],
                ['Kann ich mehrere Restaurants verwalten?', 'Ja, ab dem Multi-Location-Tarif verwalten Sie beliebig viele Standorte unter einem Konto mit getrennten Tischplänen und Berichten.'],
                ['Kann ich kündigen?', 'Jederzeit zum Monatsende. Ihre Daten können Sie vorher als CSV exportieren.'],
            ] as [$q, $a])
                <details class="rounded-xl border border-stone-200 bg-white p-5">
                    <summary class="cursor-pointer font-bold">{{ $q }}</summary>
                    <p class="mt-3 text-sm text-stone-600">{{ $a }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>

{{-- CTA --}}
<section class="bg-stone-900 text-white">
    <div class="mx-auto max-w-6xl px-4 py-16 text-center">
        <h2 class="text-3xl font-extrabold">Bereit für volle Tische ohne Telefonchaos?</h2>
        <p class="mt-3 text-stone-300">In wenigen Minuten eingerichtet. 30 Tage kostenlos, ohne Risiko.</p>
        <a href="{{ route('register') }}" class="mt-6 inline-block rounded-xl bg-teal-600 px-8 py-4 text-lg font-bold hover:bg-teal-500">Jetzt kostenlos starten</a>
    </div>
</section>
@endsection
