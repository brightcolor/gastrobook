{{--
    Hinweis, solange Impressum oder AGB noch die Vorlage enthalten.

    Wird nur auf den Seiten der Plattform selbst eingebunden (Marketing,
    Rechtstexte, Anmeldung/Registrierung) – NICHT auf den Buchungsseiten der
    Betriebe. Dort tritt der jeweilige Betrieb als Anbieter auf; ein
    "noch nicht in Betrieb" wäre dort schlicht falsch.

    Verschwindet von selbst, sobald die Texte gepflegt sind.

    compact: für die Anmeldeseiten, die kein Layout haben und deren <body> ein
    zentrierter Flex-Container ist – dort sitzt der Hinweis in der Karte statt
    als Balken am Seitenkopf.
--}}
@props(['compact' => false])

@php
    $rechtstexte = app(App\Services\LegalDocumentStatus::class);
    $offen = $rechtstexte->pending();
@endphp

@if ($offen !== [])
    @if ($compact)
        <div class="mt-4 rounded-lg border border-amber-300 bg-amber-100 px-4 py-3 text-sm text-amber-950" role="status">
            <p class="font-bold">Noch nicht in Betrieb</p>
            <p class="mt-0.5 text-amber-900">
                Noch nicht ausgefüllt: {{ $rechtstexte->pendingLabel() }}.
                Diese Anmeldung dient bis dahin nur zur Ansicht.
            </p>
        </div>
    @else
        <div id="platform-notice" class="border-b border-amber-300 bg-amber-100 text-amber-950" role="status">
            <div class="mx-auto flex max-w-6xl items-start gap-3 px-4 py-3">
                <span class="mt-0.5 shrink-0 text-lg leading-none" aria-hidden="true">🚧</span>
                <div class="text-sm leading-relaxed">
                    <p class="font-bold">Diese Plattform ist noch nicht in Betrieb.</p>
                    <p class="mt-0.5 text-amber-900">
                        Noch nicht ausgefüllt: {{ $rechtstexte->pendingLabel() }}.
                        Bis dahin ist alles hier nur zur Ansicht: Es kommen keine Verträge
                        zustande, und es sollten keine echten Daten eingegeben werden.
                    </p>
                </div>
            </div>
        </div>
    @endif
@endif
