@extends('layouts.admin')
@section('title', 'Newsletter')

@section('content')
@php
    $keyError = $errors->first('api_key');
    $keyHelp = $hasKey ? 'Gespeichert. Leer lassen, um ihn zu behalten.' : 'Zu finden in MailWizz unter Account → API.';
    $guest = $salon ? 'Kunde' : 'Gast';
    $guests = $salon ? 'Kunden' : 'Gäste';
@endphp
<x-page-header title="Newsletter" :crumb="['Einstellungen', 'Verbindungen']" scope="tenant"
               :description="$guests.' mit Newsletter-Einwilligung gehen an deine Liste in MailWizz.'" />

<x-settings.section id="anmeldung" title="Anmeldung beim Buchen">
    <x-slot:description>
        <p>Der Text neben dem Häkchen steht auf der Buchungsseite und bei Events. Er sollte nennen, wer schreibt und worum es geht. Swayy speichert bei jeder Zustimmung den Text, den der {{ $guest }} gesehen hat.</p>
        @if($tenant->newsletter_unticked_revokes)
            <p>Unter Betrieb › Allgemein ist „Leerer Haken zählt als Abmeldung“ eingeschaltet: Neben dem Abmeldelink meldet auch eine spätere Buchung mit leerem Häkchen ab.</p>
        @else
            <p>Abgemeldet wird über den Abmeldelink. Bleibt das Häkchen bei einer späteren Buchung leer, bleibt die Anmeldung bestehen.</p>
        @endif
    </x-slot:description>
    <form id="formNewsletterSignup" method="POST" action="{{ route('admin.settings.newsletter-signup') }}" data-ajax class="space-y-5">
        @csrf
        @method('PUT')
        <x-field name="newsletter_consent_text" label="Text neben dem Häkchen" :help="'Leer heißt: „'.$defaultText.'“'">
            <textarea id="f-newsletter_consent_text" name="newsletter_consent_text" rows="3" maxlength="{{ $limits['consent_text_max'] }}"
                      aria-describedby="f-newsletter_consent_text-help" class="w-full">{{ old('newsletter_consent_text', $tenant->newsletter_consent_text) }}</textarea>
        </x-field>
        <x-checkbox name="newsletter_double_opt_in" label="Die Liste verschickt eine Bestätigungsmail (Double-Opt-in)" :checked="$tenant->newsletter_double_opt_in"
                    :help="'Nach einer Buchung mit Häkchen weist die Seite dann auf diese Mail und den Spam-Ordner hin.'" />
        <x-field name="newsletter_confirm_button" label="Knopf in der Bestätigungsmail" :value="$tenant->newsletter_confirm_button" :maxlength="$limits['confirm_button_max']"
                 help="So, wie er in der Mail steht, etwa „Ja, ich will mehr!“. Leer: Der Hinweis spricht vom Bestätigungslink." />
        <x-field name="source_tag" label="Feld für die Herkunft" :value="$fields['source_tag']" maxlength="50" autocapitalize="off" spellcheck="false"
                 help="Feldkürzel in deiner Liste, etwa QUELLE. Leer schaltet das Feld ab." />
        <x-field name="source_value" label="Wert für die Herkunft" :value="$fields['source_value']" :maxlength="$limits['field_value_max']"
                 help="Geht nur bei einer neuen Anmeldung mit; wer schon auf der Liste steht, behält seine erste Herkunft." />
        <x-field name="event_tag" label="Feld für die Veranstaltung" :value="$fields['event_tag']" maxlength="50" autocapitalize="off" spellcheck="false"
                 help="Etwa VERANSTALTUNG. Leer schaltet das Feld ab." />
        <x-field name="event_value" label="Wert für die Veranstaltung" :value="$fields['event_value']" :maxlength="$limits['field_value_max']"
                 :help="'Leer: der Name des Betriebs („'.$tenant->name.'“).'" />
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</x-settings.section>

<x-settings.section id="mailwizz" title="MailWizz">
    <x-slot:description>
        <p>Stimmt ein {{ $guest }} beim Buchen dem Newsletter zu, trägt Swayy ihn in deine Liste in MailWizz ein. Ob MailWizz zuerst eine Bestätigungsmail schickt (Double-Opt-in), stellst du in den Einstellungen der Liste ein.</p>
        <p>Die API-URL steht in deinem MailWizz-Adminbereich unter API-Einstellungen. Swayy ruft sie direkt auf, deshalb beginnt sie mit https:// und ist aus dem Internet erreichbar.</p>
        <p>Den API-Key legst du in MailWizz unter Account → API an. Swayy speichert ihn verschlüsselt und zeigt ihn nie wieder an. Die Listen-UID steht in der Listenübersicht unter „Unique ID“.</p>
    </x-slot:description>

    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span class="text-stone-600">Status</span>
        <x-status-icon :state="$statusState" :label="$statusLabel" :announce="false" />
        <span class="font-semibold">{{ $statusLabel }}</span>
    </p>

    @if($lastError !== '')
        <p role="note" class="flex items-start gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
            <x-status-icon state="warn" label="Letzter Fehler" :announce="false" class="mt-0.5" />
            <span class="min-w-0 [overflow-wrap:anywhere]"><strong class="font-semibold">Der letzte Test ist fehlgeschlagen.</strong> {{ $lastError }}</span>
        </p>
    @endif

    <form id="formMailwizz" method="POST" action="{{ route('admin.settings.mailwizz') }}" data-ajax class="space-y-5">
        @csrf
        @method('PUT')
        <x-field name="api_url" label="API-URL" type="url" :value="$apiUrl" required :maxlength="$limits['api_url_max']" autocomplete="off" inputmode="url"
                 placeholder="https://news.example.com/api"
                 help="Die Adresse deiner MailWizz-API, meist mit /api am Ende." />
        <x-field name="api_key" label="API-Key" :required="! $hasKey" :help="$keyHelp">
            <input id="f-api_key" type="password" name="api_key" autocomplete="new-password" autocapitalize="off" spellcheck="false"
                   maxlength="{{ $limits['api_key_max'] }}" @unless($hasKey) required aria-required="true" @endunless
                   aria-describedby="f-api_key-help{{ $keyError ? ' f-api_key-error' : '' }}" @if($keyError) aria-invalid="true" @endif
                   class="w-full">
        </x-field>
        <x-field name="list_uid" label="Listen-UID" :value="$listUid" required :maxlength="$limits['list_uid_max']" autocomplete="off" autocapitalize="off" spellcheck="false"
                 placeholder="z. B. ab12cd34ef567"
                 help="Die ID deiner Newsletter-Liste. Sie steht in der Listenübersicht von MailWizz unter „Unique ID“." />
        <x-checkbox name="enabled" :label="$guests.' mit Einwilligung übertragen'" :checked="$enabled"
                    :help="'Ausgeschaltet bleiben die Zugangsdaten gespeichert, es geht aber kein '.$guest.' an MailWizz.'" />
        <button type="submit" class="btn btn-primary">Speichern und Verbindung testen</button>
    </form>
</x-settings.section>
@endsection
