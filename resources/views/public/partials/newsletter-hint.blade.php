{{-- Nach einer Buchung mit Newsletter-Häkchen: Das Newsletter-System schickt eine eigene Bestätigungsmail. Die Seite
     zeigt den Hinweis nur, wenn der Controller newsletter_signup mitgibt (Häkchen gesetzt, Anbindung verbunden,
     Double-Opt-in). Wer schon angemeldet ist, bekommt keine Mail, daher „Bist du neu dabei“. Erwartet $tenant und $du. --}}
@if(session('newsletter_signup') && $tenant)
    @php($button = trim((string) $tenant->newsletter_confirm_button))
    <div role="note" data-newsletter-hint class="rounded-xl bg-amber-50 p-4 text-left text-sm leading-relaxed text-amber-900">
        <p>
            <strong>Newsletter:</strong>
            {{ $du ? 'Bist du neu dabei, bekommst du dafür eine eigene E-Mail.' : 'Sind Sie neu dabei, bekommen Sie dafür eine eigene E-Mail.' }}
            @if($button !== '')
                {{ $du ? 'Klick darin auf' : 'Klicken Sie darin auf' }} <strong>„{{ $button }}“</strong>, {{ $du ? 'um deine Anmeldung zu bestätigen.' : 'um Ihre Anmeldung zu bestätigen.' }}
            @else
                {{ $du ? 'Klick darin auf den Bestätigungslink.' : 'Klicken Sie darin auf den Bestätigungslink.' }}
            @endif
        </p>
        <x-spam-hint :du="$du" class="mt-1 text-amber-800" />
    </div>
@endif
