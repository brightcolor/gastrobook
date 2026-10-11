@extends('layouts.admin')
@section('title', 'Zahlungsanbieter')

@section('content')
@php
    $guest = $salon ? 'Kunde' : 'Gast';
    $guests = $salon ? 'Kunden' : 'Gäste';
    $linkClass = 'font-semibold underline underline-offset-2';
    // Eine neue Anbindung startet eingeschaltet; nur ein ausdrücklich ausgeschalteter Anbieter bleibt es.
    $stripeOn = $stripe === null || $stripe->status !== 'disconnected';
    $paypalOn = $paypal === null || $paypal->status !== 'disconnected';
    $modeError = $errors->first('mode');
@endphp
<x-page-header title="Zahlungsanbieter" :crumb="['Einstellungen', 'Zahlungen']" scope="tenant"
               :description="'Wie '.$guests.' Anzahlungen online bezahlen.'" />

<x-settings.section id="stripe" title="Stripe">
    <x-slot:description>
        <p>Stripe nimmt Kartenzahlungen für Anzahlungen und Event-Vorauszahlungen entgegen. Swayy speichert nur Verweise auf dein Stripe-Konto, verschlüsselt. Kartendaten berühren Swayy nie.</p>
        <p>Den Secret Key findest du im Stripe-Dashboard unter Developers → API keys. Er beginnt mit „sk_live_“ oder „sk_test_“.</p>
        <p>Das Webhook-Signing-Secret bekommst du, sobald du den Webhook-Endpunkt mit der Adresse unten anlegst. Mit ihm verarbeitet Swayy nur echte Ereignisse von Stripe.</p>
    </x-slot:description>

    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span class="text-stone-600">Status</span>
        <x-status-icon :state="$stripeStatus['state']" :label="$stripeStatus['label']" :announce="false" />
        <span class="font-semibold">{{ $stripeStatus['label'] }}</span>
    </p>

    <div class="space-y-2 rounded-lg bg-stone-50 p-3 text-sm">
        <p class="text-stone-700">Webhook-Adresse für Stripe</p>
        <p class="flex flex-wrap items-center gap-2">
            <code class="min-w-0 break-all text-[0.8125rem] text-stone-800">{{ $webhookUrl }}</code>
            <button type="button" data-copy="{{ $webhookUrl }}" data-copy-message="Webhook-Adresse kopiert." class="btn btn-secondary">Kopieren<span class="sr-only"> (Webhook-Adresse)</span></button>
        </p>
        <p class="text-[0.8125rem] text-stone-600">Ereignisse: <code>checkout.session.completed</code>, <code>checkout.session.expired</code></p>
    </div>

    @if($stripeStatus['state'] === 'ok')
        <p class="text-sm" aria-live="polite">
            @if($stripeWebhookSeen)
                <span class="text-stone-700">Letzte Meldung von Stripe: <strong class="font-semibold text-stone-900">{{ $stripeWebhookSeen->translatedFormat('D, d.m.Y H:i') }} Uhr</strong></span>
            @else
                <span class="text-stone-700">Stripe hat sich noch nicht gemeldet. Prüfe die Webhook-Adresse im Stripe-Dashboard.</span>
            @endif
        </p>
    @endif

    <form id="formStripe" method="POST" action="{{ route('admin.settings.stripe') }}" data-ajax class="space-y-5">
        @csrf
        @method('PUT')
        @include('admin.settings.partials.secret-field', ['name' => 'secret_key', 'label' => 'Secret Key', 'has' => $stripeHasSecret, 'hint' => 'Zu finden im Stripe-Dashboard unter Developers → API keys.'])
        @include('admin.settings.partials.secret-field', ['name' => 'webhook_secret', 'label' => 'Webhook-Signing-Secret', 'has' => $stripeHasWebhookSecret, 'hint' => 'Zu finden im Stripe-Dashboard beim Webhook-Endpunkt.'])
        <x-checkbox name="enabled" id="f-stripe-enabled" label="Online-Zahlung mit Stripe" :checked="$stripeOn"
                    help="Ausgeschaltet bleiben die Schlüssel gespeichert, und Swayy verlangt keine Zahlung." />
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</x-settings.section>

<x-settings.section id="paypal" title="PayPal">
    <x-slot:description>
        <p>PayPal gibt es zusätzlich zu Stripe. Sind beide eingeschaltet, wählt der {{ $guest }} bei der Zahlung. Das Geld geht auf dein PayPal-Konto.</p>
        <p>Client-ID und Secret findest du im PayPal Developer Dashboard unter Apps &amp; Credentials. Swayy speichert sie verschlüsselt und gibt sie nie an {{ $guests }} weiter.</p>
        <p>PayPal braucht keinen Webhook. Swayy erfasst die Zahlung, sobald der {{ $guest }} von PayPal zurückkommt.</p>
    </x-slot:description>

    <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm">
        <span class="text-stone-600">Status</span>
        <x-status-icon :state="$paypalStatus['state']" :label="$paypalStatus['label']" :announce="false" />
        <span class="font-semibold">{{ $paypalStatus['label'] }}</span>
    </p>

    <form id="formPaypal" method="POST" action="{{ route('admin.settings.paypal') }}" data-ajax class="space-y-5">
        @csrf
        @method('PUT')
        @include('admin.settings.partials.secret-field', ['name' => 'client_id', 'label' => 'Client-ID', 'has' => $paypalHasClient, 'hint' => 'Zu finden im PayPal Developer Dashboard unter Apps & Credentials.'])
        @include('admin.settings.partials.secret-field', ['name' => 'secret', 'label' => 'Secret', 'has' => $paypalHasSecret, 'hint' => 'Zu finden im PayPal Developer Dashboard unter Apps & Credentials.'])
        <fieldset id="f-mode" class="grid gap-3 sm:grid-cols-2" @if($modeError) aria-describedby="f-mode-error" @endif>
            <legend class="mb-2 text-sm font-semibold text-stone-800">Modus</legend>
            @foreach([
                'sandbox' => ['Test (Sandbox)', 'Zum Ausprobieren mit Testgeld.'],
                'live' => ['Live', 'Für echte Zahlungen von '.($salon ? 'Kunden' : 'Gästen').'. Stelle vor dem Start auf Live um.'],
            ] as $mode => [$modeLabel, $modeSummary])
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                    <input type="radio" name="mode" value="{{ $mode }}" @checked(old('mode', $paypalMode) === $mode)
                           @if($modeError) aria-invalid="true" @endif class="mt-1">
                    <span class="min-w-0 [overflow-wrap:anywhere]">
                        <span class="block font-semibold">{{ $modeLabel }}</span>
                        <span class="block text-[0.8125rem] text-stone-500">{{ $modeSummary }}</span>
                    </span>
                </label>
            @endforeach
            @if($modeError)
                <p id="f-mode-error" class="text-[0.8125rem] text-red-700 sm:col-span-2">{{ $modeError }}</p>
            @endif
        </fieldset>
        <x-checkbox name="enabled" id="f-paypal-enabled" label="Online-Zahlung mit PayPal" :checked="$paypalOn"
                    help="Ausgeschaltet bleiben die Zugangsdaten gespeichert, und Swayy bietet PayPal nicht an." />
        <button type="submit" class="btn btn-primary">Speichern</button>
    </form>
</x-settings.section>
@endsection
