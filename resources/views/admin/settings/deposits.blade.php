@extends('layouts.admin')
@section('title', 'Anzahlungen')

@section('content')
@php
    $guest = $salon ? 'Kunde' : 'Gast';
    $guests = $salon ? 'Kunden' : 'Gäste';
    $refundModeError = $errors->first('refund_mode');
    $refundProcessingError = $errors->first('refund_processing');
    $refundOff = old('refund_mode', $settings->refund_mode) === 'off';
@endphp
@if($canRefunds)
    <x-save-bar one="1 Einstellung geändert" many=":count Einstellungen geändert" />
@endif
<x-page-header title="Anzahlungen" :crumb="['Einstellungen', 'Zahlungen']" scope="location"
               :description="'Wann '.$guests.' online vorab zahlen und was sie bei einer Absage zurückbekommen.'">
    <x-slot:actions>
        <button type="button" data-drawer-open="regel-neu" class="btn btn-primary">Regel hinzufügen</button>
    </x-slot:actions>
</x-page-header>

<x-settings.section id="regeln" title="Anzahlungsregeln">
    <x-slot:description>
        <p>{{ $salon ? 'Für welche Leistung, an welchen Tagen und zu welcher Uhrzeit Kunden vorab bezahlen.' : 'Ab welcher Gruppe, an welchen Tagen und zu welcher Uhrzeit Gäste vorab bezahlen.' }} Greift keine Regel, ist keine Anzahlung fällig.</p>
        <p>Den Betrag verrechnest du beim Besuch mit der Rechnung. Bezahlt wird über Stripe oder PayPal, die du unter Zahlungsanbieter einrichtest.</p>
    </x-slot:description>
    @if($rules->isEmpty())
        <x-empty-state title="Noch keine Anzahlungsregeln">
            {{ $salon ? 'Mit einer Regel zahlen Kunden Termine vorab, etwa für aufwendige Leistungen.' : 'Mit einer Regel zahlen Gäste Reservierungen vorab, etwa Gruppen ab 8 Personen am Wochenende.' }}
            <x-slot:action>
                <button type="button" data-drawer-open="regel-neu" class="btn btn-primary">Regel hinzufügen</button>
            </x-slot:action>
        </x-empty-state>
    @else
        <ul class="-my-2 divide-y divide-stone-200">
            @foreach($rules as $rule)
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                    <div class="min-w-0 flex-1">
                        <button type="button" data-drawer-open="regel-{{ $rule->id }}"
                                class="text-left font-semibold underline-offset-2 hover:underline [overflow-wrap:anywhere]">{{ $rule->name }}</button>
                        <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ $summaries[$rule->id] }}</p>
                    </div>
                    <x-inline-confirm :id="'regel-loeschen-'.$rule->id" :action="route('admin.settings.deposit-rules.delete', $rule)" method="DELETE"
                                      label="Löschen" confirm="Regel löschen" :subject="$rule->name" :reload="true"
                                      :question="'Regel „'.$rule->name.'“ löschen? Neue Buchungen brauchen danach keine Anzahlung mehr nach dieser Regel; offene Zahlungen bleiben.'" />
                </li>
            @endforeach
        </ul>
    @endif
</x-settings.section>

<x-settings.section id="erstattung" title="Erstattung der Anzahlung">
    <x-slot:description>
        <p>Gilt bei Storno innerhalb der Frist. Nach der Frist und bei Nichterscheinen behält der Betrieb die Anzahlung.</p>
    </x-slot:description>
    @if($canRefunds)
        <form id="formRefunds" method="POST" action="{{ route('admin.settings.refunds') }}" data-ajax data-savebar class="space-y-5">
            @csrf
            @method('PUT')
            <fieldset id="f-refund_mode" class="grid gap-3 sm:grid-cols-3" @if($refundModeError) aria-describedby="f-refund_mode-error" @endif>
                <legend class="mb-2 text-sm font-semibold text-stone-800">Erstattung bei Storno</legend>
                @foreach([
                    'off' => ['Aus', 'Die Anzahlung bleibt beim Betrieb.'],
                    'manual' => ['Von Hand freigeben', 'Dein Team gibt jede Erstattung frei.'],
                    'auto' => ['Automatisch', 'Swayy erstattet von selbst.'],
                ] as $mode => [$modeLabel, $modeSummary])
                    <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                        <input type="radio" name="refund_mode" value="{{ $mode }}" @checked(old('refund_mode', $settings->refund_mode) === $mode)
                               @if($refundModeError) aria-invalid="true" @endif class="mt-1">
                        <span class="min-w-0 [overflow-wrap:anywhere]">
                            <span class="block font-semibold">{{ $modeLabel }}</span>
                            <span class="block text-[0.8125rem] text-stone-500">{{ $modeSummary }}</span>
                        </span>
                    </label>
                @endforeach
                @if($refundModeError)
                    <p id="f-refund_mode-error" class="text-[0.8125rem] text-red-700 sm:col-span-3">{{ $refundModeError }}</p>
                @endif
            </fieldset>
            <div data-show-if="refund_mode:manual,auto" @if($refundOff) hidden @endif class="space-y-5">
                <x-field name="refund_percent" label="Erstattung in %" type="number" unit="%" required class="max-w-48"
                         :value="$settings->refund_percent" min="0" max="100" inputmode="numeric"
                         :help="'So viel Prozent der Anzahlung bekommt der '.$guest.' zurück. 100 % ist der volle Betrag, 50 % die Hälfte.'" />
                <fieldset id="f-refund_processing" class="grid gap-3 sm:grid-cols-2" @if($refundProcessingError) aria-describedby="f-refund_processing-error" @endif>
                    <legend class="mb-2 text-sm font-semibold text-stone-800">Ausführung</legend>
                    @foreach([
                        'immediate' => ['Sofort', 'Swayy schreibt den Betrag direkt zurück.'],
                        'scheduled' => ['Gesammelt nach Zeitplan', 'Swayy sammelt die Erstattungen und verarbeitet sie in einem Lauf. Praktisch bei vielen Stornos.'],
                    ] as $processing => [$processingLabel, $processingSummary])
                        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-stone-300 p-4 has-[:checked]:border-(--brand) has-[:checked]:ring-1 has-[:checked]:ring-(--brand) has-[:focus-visible]:outline-2 has-[:focus-visible]:outline-offset-2 has-[:focus-visible]:outline-(--brand)">
                            <input type="radio" name="refund_processing" value="{{ $processing }}" @checked(old('refund_processing', $settings->refund_processing) === $processing)
                                   @if($refundProcessingError) aria-invalid="true" @endif class="mt-1">
                            <span class="min-w-0 [overflow-wrap:anywhere]">
                                <span class="block font-semibold">{{ $processingLabel }}</span>
                                <span class="block text-[0.8125rem] text-stone-500">{{ $processingSummary }}</span>
                            </span>
                        </label>
                    @endforeach
                    @if($refundProcessingError)
                        <p id="f-refund_processing-error" class="text-[0.8125rem] text-red-700 sm:col-span-2">{{ $refundProcessingError }}</p>
                    @endif
                </fieldset>
            </div>
        </form>
    @else
        <p class="text-sm text-stone-700">Die Erstattung der Anzahlung stellen Standortleitung und Administratoren des Betriebs ein.</p>
    @endif
</x-settings.section>

@push('drawers')
    <x-drawer id="regel-neu" title="Neue Anzahlungsregel" description="Wer vorab zahlt und wie viel.">
        <form id="form-regel-neu" method="POST" action="{{ route('admin.settings.deposit-rules.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            @include('admin.settings.partials.deposit-rule-fields', ['rule' => null, 'prefix' => 'neu'])
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-regel-neu" class="btn btn-primary">Regel anlegen</button>
        </x-slot:footer>
    </x-drawer>
    @foreach($rules as $rule)
        <x-drawer :id="'regel-'.$rule->id" :title="$rule->name" description="Wer vorab zahlt und wie viel.">
            <form id="form-regel-{{ $rule->id }}" method="POST" action="{{ route('admin.settings.deposit-rules.update', $rule) }}" data-ajax data-reload-after-save class="space-y-5">
                @csrf
                @method('PUT')
                @include('admin.settings.partials.deposit-rule-fields', ['rule' => $rule, 'prefix' => (string) $rule->id])
            </form>
            <x-slot:footer>
                <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
                <button type="submit" form="form-regel-{{ $rule->id }}" class="btn btn-primary">Speichern</button>
            </x-slot:footer>
        </x-drawer>
    @endforeach
@endpush
@endsection
