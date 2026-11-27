{{-- Große Gruppen: der Weg per E-Mail, gleich beim Buchen und beim Ändern. Erwartet $email, $from (ab so vielen
     Personen), $du, $subject, $backId (Knopf zurück zur Auswahl) und optional $note (ein zusätzlicher Satz). --}}
<div class="rounded-2xl border-2 border-brand/20 bg-brand/5 p-6 text-center">
    <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-brand/15 text-2xl" aria-hidden="true">🥂</div>
    <h3 class="text-lg font-bold text-stone-800">Große Runde? Wie schön!</h3>
    <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-stone-600">
        {{ $du
            ? 'Für Gruppen ab '.$from.' Personen finden wir gemeinsam den passenden Platz. Schreib uns kurz, worum es geht – wir melden uns schnell mit einem persönlichen Vorschlag.'
            : 'Für Gruppen ab '.$from.' Personen finden wir gemeinsam den passenden Platz. Schreiben Sie uns kurz, worum es geht – wir melden uns schnell mit einem persönlichen Vorschlag.' }}
    </p>
    @if(! empty($note))
        <p class="mx-auto mt-2 max-w-sm text-sm leading-relaxed text-stone-600">{{ $note }}</p>
    @endif
    <a href="mailto:{{ $email }}?subject={{ rawurlencode($subject) }}"
       class="btn-brand mt-5 inline-flex items-center justify-center gap-2 rounded-xl px-6 py-3 text-base font-bold text-white transition-all active:scale-[0.99]">
        ✉️ E-Mail schreiben
    </a>
    <p class="mt-3 text-xs text-stone-400">{{ $email }}</p>
    <button type="button" id="{{ $backId }}" class="mt-4 text-xs font-semibold text-stone-500 transition-colors hover:text-brand hover:underline">
        ← Zurück zur Auswahl
    </button>
</div>
