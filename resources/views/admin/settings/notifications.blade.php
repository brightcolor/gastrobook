@extends('layouts.admin')
@section('title', 'Benachrichtigungen')

@section('content')
@php
    $guest = $salon ? 'Kunde' : 'Gast';
    $guests = $salon ? 'Kunden' : 'Gäste';
    $visit = $salon ? 'dem Termin' : 'der Reservierung';
    $reminderOn = (bool) old('reminder_enabled', $settings->reminder_enabled);
    $feedbackOn = (bool) old('feedback_enabled', $settings->feedback_enabled);
    $ownerOn = (bool) old('owner_notification_enabled', $settings->owner_notification_enabled);
    $ownerEmail = old('owner_notification_email', $settings->owner_notification_email);
    $addressError = $errors->first('guest_address');
    $templates = route('admin.templates.index');
    $linkClass = 'font-semibold underline underline-offset-2';
@endphp
<x-save-bar one="1 Einstellung geändert" many=":count Einstellungen geändert" />
<x-page-header title="Benachrichtigungen" :crumb="['Einstellungen', $salon ? 'Kundenkommunikation' : 'Gästekommunikation']" scope="location"
               :description="'Wie du '.$guests.' ansprichst und wann sie von dir hören: bei der Buchung, vor und nach dem Besuch. Dazu die Info an deinen Betrieb.'" />

<form id="formNotifications" method="POST" action="{{ route('admin.settings.notifications') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')

    <x-settings.section id="anrede" title="Anrede">
        <x-slot:description>
            <p>Gilt für Buchungsseite, Mails und SMS an {{ $guests }}.</p>
        </x-slot:description>
        <fieldset id="f-guest_address" class="grid gap-3 sm:grid-cols-2" @if($addressError) aria-describedby="f-guest_address-error" @endif>
            <legend class="mb-2 text-sm font-semibold text-stone-800">{{ $guests }} ansprechen mit</legend>
            @foreach([
                'du' => ['du', $salon ? '„Hallo Anna, dein Termin …“' : '„Hallo Anna, deine Reservierung …“'],
                'Sie' => ['Sie', $salon ? '„Guten Tag Frau Muster, Ihr Termin …“' : '„Guten Tag Frau Muster, Ihre Reservierung …“'],
            ] as $address => [$addressLabel, $addressExample])
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                    <input type="radio" name="guest_address" value="{{ $address }}" @checked(old('guest_address', $settings->guest_address) === $address)
                           @if($addressError) aria-invalid="true" @endif class="mt-1">
                    <span class="min-w-0 [overflow-wrap:anywhere]">
                        <span class="block font-semibold">{{ $addressLabel }}</span>
                        <span class="block text-[0.8125rem] text-stone-500">{{ $addressExample }}</span>
                    </span>
                </label>
            @endforeach
            @if($addressError)
                <p id="f-guest_address-error" class="text-[0.8125rem] text-red-700 sm:col-span-2">{{ $addressError }}</p>
            @endif
        </fieldset>
    </x-settings.section>

    <x-settings.section id="bestaetigung" title="Bestätigung">
        <x-slot:description>
            <p>Ob du Buchungen von Hand freigibst, stellst du unter <a href="{{ route('admin.settings.rules.show') }}#bestaetigung" class="{{ $linkClass }}">Buchungsregeln</a> ein.</p>
        </x-slot:description>
        <p class="text-sm text-stone-700">Nach jeder Buchung bekommt der {{ $guest }} eine Bestätigung, bei Freigabe von Hand zuerst eine Eingangsbestätigung.</p>
        @if($canTemplates)
            <ul class="space-y-1.5 text-sm">
                <li><a href="{{ $templates }}#vorlage-reservation_confirmed" class="{{ $linkClass }}">Text der Bestätigung bearbeiten</a></li>
                <li><a href="{{ $templates }}#vorlage-reservation_requested" class="{{ $linkClass }}">Text der Eingangsbestätigung bearbeiten</a></li>
            </ul>
        @else
            <p class="text-[0.8125rem] text-stone-600">Die Texte der Mails ändert ein Administrator des Betriebs unter Vorlagen.</p>
        @endif
    </x-settings.section>

    <x-settings.section id="erinnerung" title="Erinnerung">
        <x-slot:description>
            <p>Die Erinnerung geht vor dem Besuch an {{ $guests }} mit E-Mail-Adresse, auf Wunsch zusätzlich als SMS.</p>
        </x-slot:description>
        <x-checkbox name="reminder_enabled" label="E-Mail-Erinnerung schicken" :checked="(bool) $settings->reminder_enabled" />
        <div data-show-if="reminder_enabled:1" @unless($reminderOn) hidden @endunless class="space-y-5">
            <x-field name="reminder_hours_before" label="Stunden vorher" type="number" unit="Std." minutes="hours" required class="max-w-48"
                     :value="$settings->reminder_hours_before" :min="$limits['reminder_hours_min']" :max="$limits['reminder_hours_max']"
                     :help="'So viele Stunden vor '.$visit.' kommt die Erinnerung.'" />
            <x-checkbox name="sms_reminder_enabled" label="Erinnerung zusätzlich per SMS" :checked="(bool) $settings->sms_reminder_enabled"
                        :help="'Geht nur an '.$guests.' mit Telefonnummer.'" />
            @unless($smsReady)
                <p class="text-[0.8125rem] text-stone-600">
                    SMS-Versand ist noch nicht eingerichtet.
                    @if($canSms)
                        <a href="{{ route('admin.settings.sms.show') }}" class="{{ $linkClass }}">SMS-Versand einrichten</a>
                    @else
                        Bitte einen Administrator des Betriebs unter SMS-Versand einrichten lassen.
                    @endif
                </p>
            @endunless
            @if($canTemplates)
                <p class="text-sm"><a href="{{ $templates }}#vorlage-reservation_reminder" class="{{ $linkClass }}">Text der Erinnerung bearbeiten</a></p>
            @endif
        </div>
    </x-settings.section>

    <x-settings.section id="feedback" title="Feedback nach dem Besuch">
        <x-slot:description>
            <p>Nach dem Besuch bittet Swayy den {{ $guest }} per Mail um eine Bewertung von 1 bis 5 Sternen. Zufriedene {{ $guests }} leitet Swayy zu deinem Bewertungsportal weiter, etwa bei Google.</p>
        </x-slot:description>
        <x-checkbox name="feedback_enabled" label="Feedback-Anfrage schicken" :checked="(bool) $settings->feedback_enabled"
                    :help="'Die Anfrage geht nach einem abgeschlossenen Besuch an '.$guests.' mit E-Mail-Adresse.'" />
        <div data-show-if="feedback_enabled:1" @unless($feedbackOn) hidden @endunless class="space-y-5">
            <x-field name="feedback_hours_after" label="Stunden nach dem Besuch" type="number" unit="Std." minutes="hours" required class="max-w-48"
                     :value="$settings->feedback_hours_after" :min="$limits['feedback_hours_min']" :max="$limits['feedback_hours_max']"
                     help="So viele Stunden nach dem Besuch kommt die Anfrage." />
            <x-field name="feedback_redirect_min_score" label="Ab Sternen zum Portal" required class="max-w-64"
                     :help="$guests.' mit dieser Bewertung oder einer besseren bekommen den Link zum Bewertungsportal.'">
                <select id="f-feedback_redirect_min_score" name="feedback_redirect_min_score" required aria-required="true"
                        @error('feedback_redirect_min_score') aria-invalid="true" aria-describedby="f-feedback_redirect_min_score-help f-feedback_redirect_min_score-error" @else aria-describedby="f-feedback_redirect_min_score-help" @enderror
                        class="w-full">
                    @foreach(range(1, 5) as $stars)
                        <option value="{{ $stars }}" @selected((int) old('feedback_redirect_min_score', $settings->feedback_redirect_min_score) === $stars)>ab {{ $stars }} {{ $stars === 1 ? 'Stern' : 'Sternen' }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field name="feedback_external_url" label="Link zum Bewertungsportal" type="url" :maxlength="$limits['feedback_url_max']"
                     :value="$settings->feedback_external_url" placeholder="https://g.page/r/…/review"
                     :help="'Ab dieser Sternezahl leitet Swayy zufriedene '.$guests.' dorthin weiter. Ohne Link bleibt jedes Feedback bei dir.'" />
        </div>
        @if($canTemplates)
            <p class="text-sm"><a href="{{ $templates }}#vorlage-feedback_request" class="{{ $linkClass }}">Text der Feedback-Anfrage bearbeiten</a></p>
        @endif
    </x-settings.section>

    <x-settings.section id="betrieb" title="Info an den Betrieb">
        <x-slot:description>
            <p>Eine Mail an dich oder dein Team mit allen Angaben der Buchung, sobald sie eingeht.</p>
        </x-slot:description>
        <x-checkbox name="owner_notification_enabled" :label="$salon ? 'Bei jedem neuen Termin eine Mail an den Betrieb' : 'Bei jeder neuen Reservierung eine Mail an den Betrieb'" :checked="(bool) $settings->owner_notification_enabled" />
        <div data-show-if="owner_notification_enabled:1" @unless($ownerOn) hidden @endunless class="space-y-3">
            <x-field name="owner_notification_email" label="Adresse für die Info" type="email" :maxlength="$limits['owner_email_max']"
                     :value="$settings->owner_notification_email" :placeholder="$location->email ?: 'team@dein-betrieb.de'"
                     :help="$location->email ? 'Leer: an die Kontaktadresse des Standorts ('.$location->email.').' : null" />
            @unless($location->email)
                <div aria-live="polite">
                    <p data-show-if="owner_notification_email:" @if(filled($ownerEmail)) hidden @endif role="note" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                        Bitte eintragen. Ohne Adresse hier und am Standort kommt die Info nirgends an.
                    </p>
                </div>
            @endunless
        </div>
    </x-settings.section>
</form>
@endsection
