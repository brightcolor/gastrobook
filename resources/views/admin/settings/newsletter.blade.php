@extends('layouts.admin')
@section('title', 'Newsletter')

@section('content')
@php
    $keyError = $errors->first('api_key');
    $keyHelp = $hasKey ? 'Gespeichert. Leer lassen, um ihn zu behalten.' : 'Zu finden in MailWizz unter Account → API.';
@endphp
<x-page-header title="Newsletter" :crumb="['Einstellungen', 'Verbindungen']" scope="tenant"
               description="Gäste mit Newsletter-Einwilligung gehen an deine Liste in MailWizz." />

<x-settings.section id="mailwizz" title="MailWizz">
    <x-slot:description>
        <p>Stimmt ein Gast beim Buchen dem Newsletter zu, trägt Swayy ihn in deine Liste in MailWizz ein. Ob MailWizz zuerst eine Bestätigungsmail schickt (Double-Opt-in), stellst du in den Einstellungen der Liste ein.</p>
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
        <x-checkbox name="enabled" label="Gäste mit Einwilligung übertragen" :checked="$enabled"
                    help="Ausgeschaltet bleiben die Zugangsdaten gespeichert, es geht aber kein Gast an MailWizz." />
        <button type="submit" class="btn btn-primary">Speichern und Verbindung testen</button>
    </form>
</x-settings.section>
@endsection
