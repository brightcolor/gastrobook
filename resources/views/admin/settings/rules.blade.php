@extends('layouts.admin')
@section('title', 'Buchungsregeln')

@section('content')
<x-save-bar />
<x-page-header title="Buchungsregeln" :crumb="['Einstellungen', 'Buchungen']" scope="location"
               :description="$salon
                   ? 'Wann Gäste online buchen, welche Fristen gelten und wie Buchungen bestätigt werden.'
                   : 'Wann und in welcher Gruppengröße Gäste online buchen, welche Fristen gelten und wie Buchungen bestätigt werden.'" />

<form id="formRules" method="POST" action="{{ route('admin.settings.rules') }}" data-ajax data-savebar>
    @csrf
    @method('PUT')

    <x-settings.section id="zeitraum" title="Wann Gäste buchen können">
        <x-slot:description>
            <p>Der Mindestvorlauf gibt dir Zeit zur Vorbereitung: Bei 60 Minuten bucht um 18:30 niemand mehr für 19:00.</p>
        </x-slot:description>
        <x-field name="min_lead_minutes" label="Mindestvorlauf" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->min_lead_minutes" min="0" :max="$limits['lead_max_minutes']"
                 help="So lange vor der Uhrzeit muss eine Buchung spätestens eingehen." />
        <x-field name="max_advance_days" label="Vorausbuchung" type="number" unit="Tage" required class="max-w-48"
                 :value="$settings->max_advance_days" min="1" :max="$limits['advance_max_days']"
                 help="So weit im Voraus zeigt die Buchungsseite freie Tage." />
    </x-settings.section>

    @unless($salon)
        <x-settings.section id="gruppen" title="Gruppengröße online">
            <x-slot:description>
                <p>Gruppen über der größten Zahl sehen auf der Buchungsseite die Adresse, an die sie schreiben können.</p>
            </x-slot:description>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field name="min_party_online" label="Kleinste Gruppe" type="number" unit="Pers." required
                         :value="$settings->min_party_online" min="1" :max="$limits['party_online_max']" />
                <x-field name="max_party_online" label="Größte Gruppe" type="number" unit="Pers." required
                         :value="$settings->max_party_online" min="1" :max="$limits['party_online_max']" />
            </div>
            <x-field name="large_group_email" label="Adresse für größere Gruppen" type="email" maxlength="200"
                     :value="$settings->large_group_email" placeholder="gruppen@dein-betrieb.de"
                     help="Bleibt das Feld leer, entfällt der Hinweis auf der Buchungsseite." />
        </x-settings.section>
    @endunless

    <x-settings.section id="fristen" title="Fristen für Gäste">
        <x-slot:description>
            <p>Bis zur Frist storniert oder verschiebt ein Gast selbst, über den Link in seiner Bestätigung. Danach meldet er sich bei dir.</p>
        </x-slot:description>
        <x-field name="cancellation_deadline_minutes" label="Stornofrist" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->cancellation_deadline_minutes" min="0" :max="$limits['deadline_max_minutes']"
                 help="So lange vor der Uhrzeit endet das Stornieren über den Link." />
        <x-field name="modification_deadline_minutes" label="Umbuchungsfrist" type="number" unit="Min." :minutes="true" required class="max-w-48"
                 :value="$settings->modification_deadline_minutes" min="0" :max="$limits['deadline_max_minutes']"
                 help="So lange vor der Uhrzeit endet das Umbuchen über den Link." />
    </x-settings.section>

    <x-settings.section id="bestaetigung" title="Bestätigung">
        <x-slot:description>
            <p>Beim Freigeben von Hand landet jede Online-Buchung als Anfrage {{ $salon ? 'unter Termine' : 'im Reservierungsbuch' }}. Der Gast bekommt zuerst eine Eingangsbestätigung und die Zusage, sobald du freigibst.</p>
        </x-slot:description>
        <fieldset class="grid gap-3 sm:grid-cols-2">
            <legend class="mb-2 text-sm font-semibold text-stone-800">Online-Buchungen</legend>
            @foreach([
                'auto' => ['Sofort bestätigen', 'Der Gast bekommt die Zusage direkt nach dem Buchen.'],
                'manual' => ['Von Hand freigeben', 'Du prüfst jede Buchung und gibst sie frei oder lehnst sie ab.'],
            ] as $mode => [$modeLabel, $modeSummary])
                <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                    <input type="radio" name="booking_confirmation_mode" value="{{ $mode }}" @checked(old('booking_confirmation_mode', $settings->freigabeVonHand() ? 'manual' : 'auto') === $mode) class="mt-1">
                    <span class="min-w-0 [overflow-wrap:anywhere]">
                        <span class="block font-semibold">{{ $modeLabel }}</span>
                        <span class="block text-[0.8125rem] text-stone-500">{{ $modeSummary }}</span>
                    </span>
                </label>
            @endforeach
        </fieldset>
        <x-checkbox name="require_email_confirmation" label="E-Mail-Adresse bestätigen lassen" :checked="(bool) $settings->require_email_confirmation"
                    help="Eine Online-Buchung gilt erst, wenn der Gast den Link in seiner E-Mail öffnet. Das schützt vor falschen Adressen und Buchungen auf Vorrat." />
    </x-settings.section>

    <x-settings.section id="warteliste" :title="$salon ? 'Warteliste' : 'Warteliste und Walk-ins'">
        <x-checkbox name="waitlist_enabled" label="Warteliste anbieten" :checked="(bool) $settings->waitlist_enabled"
                    :help="$salon
                        ? 'Ist alles belegt, setzt dein Team Gäste auf die Warteliste.'
                        : 'Ist alles belegt, tragen sich Gäste ein und bekommen ein Angebot, sobald ein Platz frei wird.'" />
        @unless($salon)
            <x-checkbox name="walkins_enabled" label="Walk-ins erfassen" :checked="(bool) $settings->walkins_enabled"
                        help="Dein Team setzt Gäste, die spontan kommen, im Live-Board an einen freien Tisch." />
        @endunless
    </x-settings.section>
</form>
@endsection
