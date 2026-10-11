@extends('layouts.admin')
@section('title', 'Absender & Antworten')

@section('content')
@php
    $replyTo = old('mail_reply_to', $tenant->mail_reply_to);
    $relayHelp = 'Antworten erscheinen im Buchungsverlauf und gehen zusätzlich an deine Adresse.'
        .($relayReady ? '' : ' Diese Funktion richtet der Betreiber von Swayy ein. Bitte frag dort nach.');
@endphp
<x-save-bar />
<x-page-header title="Absender & Antworten" :crumb="['Einstellungen', 'Gästekommunikation']" scope="tenant"
               description="Unter welchem Namen Mails an Gäste ankommen und wohin die Antworten der Gäste gehen." />

<form id="formSender" method="POST" action="{{ route('admin.settings.guest-mail') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')

    <x-settings.section id="absender" title="Absender">
        <x-slot:description>
            <p>Diesen Namen sehen Gäste im Postfach bei Bestätigung, Erinnerung und allen anderen Mails.</p>
        </x-slot:description>
        <x-field name="mail_from_name" label="Absendername" :value="$tenant->mail_from_name" :maxlength="$limits['from_name_max']"
                 :placeholder="$fallbackName" :help="'Leer: „'.$fallbackName.'“.'" />
    </x-settings.section>

    <x-settings.section id="antworten" title="Antworten von Gästen">
        <x-slot:description>
            <p>Antwortet ein Gast auf eine Mail, geht die Antwort an diese Adresse.</p>
            @if($relayReady)
                <p>Mit der Antwortadresse über Swayy bekommt jede Mail eine eigene Adresse, etwa <code class="break-all text-[0.8125rem]">{{ $relayExample }}</code>.</p>
            @endif
        </x-slot:description>
        <x-field name="mail_reply_to" label="Antwortadresse" type="email" :value="$tenant->mail_reply_to" :maxlength="$limits['reply_to_max']"
                 :placeholder="$fallbackReply ?? 'info@dein-betrieb.de'"
                 :help="$fallbackReply ? 'Leer: '.$fallbackReply.' (Kontaktadresse des Standorts oder Adresse für die Info an den Betrieb).' : null" />
        @unless($fallbackReply)
            <div aria-live="polite">
                <p data-show-if="mail_reply_to:" @if(filled($replyTo)) hidden @endif role="note" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                    Bitte eintragen. Solange hier und am Standort keine Adresse steht, erreichen Antworten von Gästen niemanden.
                </p>
            </div>
        @endunless
        <x-checkbox name="mail_relay_enabled" label="Antworten über Swayy sammeln" :checked="$relayReady && (bool) $tenant->mail_relay_enabled"
                    :help="$relayHelp" :disabled="! $relayReady" />
    </x-settings.section>
</form>
@endsection
