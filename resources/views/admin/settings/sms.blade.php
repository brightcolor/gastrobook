@extends('layouts.admin')
@section('title', 'SMS-Versand')

@section('content')
@php
    $guest = $salon ? 'Kunde' : 'Gast';
    $guests = $salon ? 'Kunden' : 'Gäste';
    $linkClass = 'font-semibold underline underline-offset-2';
    $keyError = $errors->first('api_key');
    $keyHelp = $hasKey ? 'Gespeichert. Leer lassen, um ihn zu behalten.' : 'Zu finden in app.seven.io unter Einstellungen → API.';
    // Eine neue Anbindung startet eingeschaltet; nur ein ausdrücklich ausgeschalteter Versand bleibt es.
    $enabled = $connection === null || $connection->status !== 'disconnected';
@endphp
<x-page-header title="SMS-Versand" :crumb="['Einstellungen', $salon ? 'Kundenkommunikation' : 'Gästekommunikation']" scope="tenant"
               description="Erinnerungen per SMS über seven.io." />

<x-settings.section id="seven" title="seven.io">
    <x-slot:description>
        <p>Den API-Key findest du nach der Anmeldung bei <a href="https://app.seven.io" target="_blank" rel="noopener" class="{{ $linkClass }}">app.seven.io<span class="sr-only"> (öffnet in einem neuen Tab)</span></a> unter Einstellungen → API. Swayy speichert ihn verschlüsselt.</p>
        <p>Welche Erinnerungen als SMS an {{ $guests }} gehen, schaltest du unter <a href="{{ route('admin.settings.notifications.show') }}#erinnerung" class="{{ $linkClass }}">Benachrichtigungen</a> ein.</p>
    </x-slot:description>

    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span class="text-stone-600">Status</span>
        <x-status-icon :state="$statusState" :label="$statusLabel" :announce="false" />
        <span class="font-semibold">{{ $statusLabel }}</span>
        @if($statusState === 'error')
            <span class="basis-full text-[0.8125rem] text-stone-600">Bitte den API-Key unter app.seven.io prüfen und neu eintragen.</span>
        @endif
    </p>

    <form id="formSms" method="POST" action="{{ route('admin.settings.sms') }}" data-ajax class="space-y-5">
        @csrf
        @method('PUT')
        <x-field name="api_key" label="API-Key" :required="! $hasKey" :help="$keyHelp">
            <input id="f-api_key" type="password" name="api_key" autocomplete="new-password" autocapitalize="off" spellcheck="false"
                   maxlength="{{ $limits['api_key_max'] }}" @unless($hasKey) required aria-required="true" @endunless
                   aria-describedby="f-api_key-help{{ $keyError ? ' f-api_key-error' : '' }}" @if($keyError) aria-invalid="true" @endif
                   class="w-full">
        </x-field>
        <x-field name="sender_id" label="Absenderkennung" :value="$senderId" :maxlength="$limits['sender_id_max']" autocomplete="off"
                 placeholder="z. B. Sonnenhof"
                 :help="'Das sieht der Empfänger als Absender: bis zu '.$limits['sender_id_text_max'].' Buchstaben oder eine Rufnummer mit bis zu '.$limits['sender_id_max'].' Ziffern. Leer: seven.io verwendet seine Standardkennung.'" />
        <x-checkbox name="enabled" label="SMS-Versand einschalten" :checked="$enabled"
                    help="Ausgeschaltet bleiben die Zugangsdaten gespeichert, es gehen aber keine SMS hinaus." />
        <button type="submit" class="btn btn-primary">Speichern und Verbindung testen</button>
    </form>
</x-settings.section>
@endsection
