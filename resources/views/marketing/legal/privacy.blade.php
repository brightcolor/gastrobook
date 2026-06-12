@extends('layouts.marketing')

@section('title', 'Datenschutzerklärung – GastroBook')

@section('content')
<section class="legal mx-auto max-w-3xl px-4 py-16">
    <h1>Datenschutzerklärung</h1>
    <p class="rounded-xl bg-amber-50 p-4 text-sm text-amber-900"><strong>Platzhalter:</strong> Vor dem Produktivgang juristisch prüfen und mit den Angaben des Betreibers befüllen.</p>

    <h2>1. Verantwortlicher</h2>
    <p>[Firmenname, Anschrift, E-Mail – siehe Impressum]</p>

    <h2>2. Welche Daten wir verarbeiten</h2>
    <ul>
        <li><strong>Kontodaten</strong> (Restaurantbetreiber): Name, E-Mail, Passwort-Hash – zur Vertragserfüllung (Art. 6 Abs. 1 lit. b DSGVO).</li>
        <li><strong>Reservierungsdaten</strong> (Gäste der Restaurants): Name, Kontaktdaten, Reservierungsdetails – im Auftrag des jeweiligen Restaurants (Auftragsverarbeitung, Art. 28 DSGVO).</li>
        <li><strong>Zahlungsdaten</strong>: werden <strong>nicht</strong> bei uns gespeichert. Zahlungen laufen über Stripe; wir speichern nur Status und Beleg-Referenzen.</li>
        <li><strong>Server-Logs</strong>: IP-Adressen werden minimiert und nur kurzzeitig zur Betriebssicherheit gespeichert.</li>
    </ul>

    <h2>3. Hosting</h2>
    <p>Die Plattform wird ausschließlich in Rechenzentren innerhalb der Europäischen Union betrieben.</p>

    <h2>4. Empfänger</h2>
    <p>Stripe (Zahlungsabwicklung, nur bei aktivierten Anzahlungen), E-Mail-Versanddienstleister, ggf. der Newsletter-Dienst des jeweiligen Restaurants (nur bei ausdrücklicher Einwilligung des Gastes).</p>

    <h2>5. Ihre Rechte</h2>
    <p>Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit, Widerspruch und Beschwerde bei einer Aufsichtsbehörde (Art. 15–21, 77 DSGVO). Gäste wenden sich für Reservierungsdaten an das jeweilige Restaurant als Verantwortlichen; die Plattform stellt dafür Export- und Anonymisierungswerkzeuge bereit.</p>

    <h2>6. Speicherdauer</h2>
    <p>Reservierungs- und Gastdaten werden gemäß den Aufbewahrungsregeln des jeweiligen Restaurants automatisch anonymisiert. Kontodaten werden mit Vertragsende gelöscht, soweit keine gesetzlichen Aufbewahrungspflichten bestehen.</p>
</section>
@endsection
