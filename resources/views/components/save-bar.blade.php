{{-- Speicherleiste: erscheint bei Änderungen in Formularen mit data-savebar (resources/js/admin/savebar.js).
     Am Smartphone über der unteren Leiste, ab 768 px oben im Inhalt und beim Scrollen stehend. --}}
<div data-save-bar hidden role="region" aria-label="Ungespeicherte Änderungen"
     class="fixed inset-x-0 bottom-14 z-40 flex flex-wrap items-center justify-between gap-3 bg-stone-900 px-4 py-3 text-white shadow-[0_-6px_16px_rgba(28,25,23,0.18)] md:sticky md:top-0 md:bottom-auto md:-mx-6 md:-mt-6 md:mb-6 md:px-6">
    <p class="flex items-center gap-2.5 text-sm font-semibold">
        <span class="h-2 w-2 rounded-full bg-amber-400" aria-hidden="true"></span>
        <span data-save-bar-text>Ungespeicherte Änderungen</span>
    </p>
    <div class="flex w-full gap-2 sm:w-auto">
        <button type="button" data-save-bar-discard
                class="min-h-9 flex-1 rounded-md border border-stone-600 px-3 text-sm font-semibold hover:bg-stone-800 sm:flex-none">Verwerfen</button>
        <button type="button" data-save-bar-save
                class="min-h-9 flex-1 rounded-md bg-white px-3 text-sm font-semibold text-stone-900 hover:bg-stone-100 sm:flex-none">Speichern</button>
    </div>
</div>
