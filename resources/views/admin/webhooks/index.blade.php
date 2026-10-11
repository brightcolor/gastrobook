@extends('layouts.admin')
@section('title', 'Webhooks')

@section('content')
@php
    $linkClass = 'font-semibold underline underline-offset-2';
    $eventsError = $errors->first('events');
    // Was die Einstellungen swayy.webhooks.* gerade bewirken.
    $tries = (int) config('swayy.webhooks.tries');
    $backoff = array_values((array) config('swayy.webhooks.backoff'));
    $delays = $tries > 1 && $backoff !== []
        ? array_map(fn (int $i) => (int) $backoff[min($i, count($backoff) - 1)], range(0, $tries - 2))
        : [];
    $duration = fn (int $seconds) => match (true) {
        $seconds % 3600 === 0 => intdiv($seconds, 3600).' Std.',
        $seconds % 60 === 0 => intdiv($seconds, 60).' Min.',
        default => $seconds.' Sek.',
    };
    // Jede Dauer endet auf den Punkt ihrer Abkuerzung; er schliesst
    // zugleich den Satz.
    if ($delays === []) {
        $retryText = 'Jedes Ereignis bekommt einen Zustellversuch.';
    } elseif (min($delays) === max($delays)) {
        $retryText = 'Fehlversuche werden wiederholt: '.$tries.' Versuche je Ereignis, Abstände je '.$duration(min($delays));
    } else {
        $retryText = 'Fehlversuche werden wiederholt: '.$tries.' Versuche je Ereignis, Abstände von '
            .$duration(min($delays)).' bis '.$duration(max($delays));
    }
    $disableAfter = (int) config('swayy.webhooks.disable_after');
@endphp
<x-page-header title="Webhooks" :crumb="['Einstellungen', 'Verbindungen']" scope="tenant"
               description="Swayy schickt bei jedem gewählten Ereignis eine signierte Nachricht an deine Adresse.">
    <x-slot:actions>
        <button type="button" data-drawer-open="webhook-neu" class="btn btn-primary" @unless($webhooksEnabled) disabled aria-describedby="webhook-plan-hint" @endunless>Webhook anlegen</button>
    </x-slot:actions>
</x-page-header>

@if($newSecret)
    <div role="status" class="mb-6 space-y-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <p class="font-semibold">Jetzt kopieren: Swayy zeigt dieses Geheimnis nur einmal.</p>
        @if($newSecretUrl)
            <p class="text-[0.8125rem] [overflow-wrap:anywhere]">Geheimnis für {{ $newSecretUrl }}</p>
        @endif
        <p class="flex flex-wrap items-center gap-2">
            <code class="min-w-0 break-all rounded bg-white px-2 py-1 text-[0.8125rem] text-stone-900">{{ $newSecret }}</code>
            <button type="button" data-copy="{{ $newSecret }}" data-copy-message="Geheimnis kopiert." class="btn btn-secondary">Kopieren<span class="sr-only"> (Geheimnis)</span></button>
        </p>
        <p class="text-[0.8125rem]">Damit prüft die Gegenstelle den Header <code>X-Gastrobook-Signature: sha256=&lt;HMAC-SHA256(Body, Geheimnis)&gt;</code>.</p>
    </div>
@endif

@unless($webhooksEnabled)
    <p id="webhook-plan-hint" role="note" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        Webhooks sind in deinem Tarif nicht enthalten. Bestehende Endpunkte bekommen keine Ereignisse. Unter <a href="{{ route('admin.billing.show') }}" class="{{ $linkClass }}">Abo &amp; Rechnungen</a> kannst du den Tarif wechseln.
    </p>
@endunless

<x-settings.section id="endpunkte" title="Endpunkte">
    <x-slot:description>
        <p>Ein Webhook meldet Ereignisse, etwa {{ $salon ? "einen neuen Termin" : "eine neue Reservierung" }}, automatisch an ein anderes Programm, zum Beispiel an deine Website, ein Kassensystem oder einen Automatisierungsdienst.</p>
        <p>Lege einen Webhook nur an, wenn ein Dienstleister ausdrücklich danach fragt.</p>
    </x-slot:description>
    @if($endpoints->isEmpty())
        <x-empty-state title="Noch keine Webhooks">
            Mit einem Webhook erfährt ein anderes Programm sofort, wenn etwas in Swayy passiert.
            <x-slot:action>
                <button type="button" data-drawer-open="webhook-neu" class="btn btn-primary" @unless($webhooksEnabled) disabled aria-describedby="webhook-plan-hint" @endunless>Webhook anlegen</button>
            </x-slot:action>
        </x-empty-state>
    @else
        <ul class="-my-2 divide-y divide-stone-200">
            @foreach($endpoints as $endpoint)
                @php
                    $state = $states[$endpoint->id];
                    $subscribed = in_array('*', $endpoint->events ?? [], true)
                        ? 'Alle Ereignisse'
                        : collect($endpoint->events ?? [])->map(fn ($event) => $eventLabels[$event] ?? $event)->implode(', ');
                    $meta = array_filter([
                        $subscribed,
                        trans_choice('1 Zustellung|:count Zustellungen', $endpoint->deliveries_count, ['count' => $endpoint->deliveries_count]),
                        $endpoint->failure_count > 0 ? trans_choice('1 Fehler in Folge|:count Fehler in Folge', $endpoint->failure_count, ['count' => $endpoint->failure_count]) : null,
                        ! $endpoint->is_active && $endpoint->disabled_at ? 'inaktiv seit '.$endpoint->disabled_at->copy()->setTimezone($tz)->format('d.m.Y H:i').' Uhr' : null,
                    ]);
                @endphp
                <li class="py-4">
                    <p class="font-semibold [overflow-wrap:anywhere]">{{ $endpoint->url }}</p>
                    <p class="mt-1 flex items-center gap-2 text-sm">
                        <x-status-icon :state="$state['state']" :label="$state['label']" :announce="false" />
                        <span class="font-semibold">{{ $state['label'] }}</span>
                    </p>
                    <p class="mt-0.5 text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ implode(' · ', $meta) }}</p>
                    <div class="mt-3 flex flex-wrap items-start gap-x-2 gap-y-2">
                        <form method="POST" action="{{ route('admin.webhooks.toggle', $endpoint) }}" data-ajax>
                            @csrf
                            <button type="submit" class="btn btn-quiet">{{ $endpoint->is_active ? 'Pausieren' : 'Aktivieren' }}<span class="sr-only"> „{{ $endpoint->url }}“</span></button>
                        </form>
                        @if($webhooksEnabled)
                            <form method="POST" action="{{ route('admin.webhooks.ping', $endpoint) }}" data-ajax>
                                @csrf
                                <button type="submit" class="btn btn-quiet">Testereignis senden<span class="sr-only"> an „{{ $endpoint->url }}“</span></button>
                            </form>
                            <x-inline-confirm :id="'webhook-geheimnis-'.$endpoint->id" :action="route('admin.webhooks.rotate', $endpoint)"
                                              label="Geheimnis neu erzeugen" confirm="Neu erzeugen" tone="neutral" :subject="$endpoint->url" :reload="true"
                                              :question="'Geheimnis für „'.$endpoint->url.'“ neu erzeugen? Die Gegenstelle prüft danach mit dem neuen Geheimnis; bis zur Umstellung schlagen Zustellungen fehl.'" />
                        @endif
                        <x-inline-confirm :id="'webhook-loeschen-'.$endpoint->id" :action="route('admin.webhooks.destroy', $endpoint)" method="DELETE"
                                          label="Löschen" confirm="Webhook löschen" :subject="$endpoint->url" :reload="true"
                                          :question="'Webhook „'.$endpoint->url.'“ löschen? Swayy schickt keine Ereignisse mehr dorthin, und sein Zustellprotokoll verschwindet.'" />
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-settings.section>

<x-settings.section id="wiederholungen" title="Wiederholungen und Abschaltung">
    <x-slot:description>
        <p>Was Swayy tut, wenn eine Gegenstelle nicht antwortet. Die Werte stehen in den Einstellungen des Servers.</p>
    </x-slot:description>
    <ul class="list-disc space-y-2 pl-5 text-sm text-stone-700">
        <li>Erlaubt sind öffentlich erreichbare https-Adressen. Vor jeder Zustellung prüft Swayy die Adresse erneut.</li>
        <li>{{ $retryText }} Ein Servername, der sich gerade nicht auflösen lässt, zählt ebenfalls als Fehlversuch.</li>
        <li>Führt die Adresse in ein internes Netz, schaltet sich der Endpunkt sofort ab. Der Grund steht im Zustellprotokoll.</li>
        <li>Nach {{ $disableAfter }} gescheiterten Ereignissen in Folge schaltet sich der Endpunkt selbst ab; hier lässt er sich wieder aktivieren.</li>
    </ul>
</x-settings.section>

<x-settings.section id="zustellprotokoll" title="Zustellprotokoll">
    <x-slot:description>
        <p>Die letzten {{ $logEntries }} Versuche. „erfolgreich“ heißt: Die Gegenstelle hat die Nachricht angenommen.</p>
        <p>Bei Fehlern steht hier der Statuscode, den sie zurückgegeben hat, oder der Grund, warum nichts ankam.</p>
    </x-slot:description>
    <div class="-mx-5 overflow-x-auto px-5 sm:-mx-6 sm:px-6">
        <table class="w-full text-left text-sm">
            <caption class="sr-only">Zustellprotokoll der letzten {{ $logEntries }} Versuche</caption>
            <thead class="text-xs uppercase text-stone-500">
                <tr><th scope="col" class="py-2 pr-3">Zeitpunkt</th><th scope="col" class="pr-3">Ereignis</th><th scope="col" class="pr-3">Ziel</th><th scope="col" class="pr-3">Versuch</th><th scope="col">Status</th></tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                @forelse($deliveries as $delivery)
                    <tr>
                        <td class="py-2 pr-3 whitespace-nowrap">{{ $delivery->created_at->copy()->setTimezone($tz)->format('d.m.Y H:i') }} Uhr</td>
                        <td class="pr-3"><code class="text-xs">{{ $delivery->event }}</code></td>
                        <td class="max-w-xs truncate text-xs text-stone-500">{{ $delivery->endpoint?->url }}</td>
                        <td class="px-3 text-xs">{{ $delivery->attempt }}</td>
                        <td class="text-xs">
                            @if($delivery->status === 'success')
                                <span class="font-semibold text-emerald-700">erfolgreich</span>
                            @elseif($delivery->status === 'failed')
                                <span class="font-semibold text-red-700">fehlgeschlagen</span>
                            @else
                                <span class="text-stone-600">unterwegs</span>
                            @endif
                            @if($delivery->response_code) · HTTP {{ $delivery->response_code }} @endif
                            @if($delivery->status === 'failed' && ! $delivery->response_code && filled($delivery->response_body))
                                <span class="mt-0.5 block text-stone-600">{{ $delivery->response_body }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="py-3 text-stone-600">Noch nichts zugestellt.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</x-settings.section>

@push('drawers')
    <x-drawer id="webhook-neu" title="Neuer Webhook" description="Adresse und Ereignisse, die Swayy dorthin meldet.">
        @unless($webhooksEnabled)
            <p role="note" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">Mit deinem Tarif lässt sich noch kein Webhook anlegen. Der Hinweis oben auf der Seite zeigt den Weg zum Tarifwechsel.</p>
        @endunless
        <form id="form-webhook-neu" method="POST" action="{{ route('admin.webhooks.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            <x-field name="url" label="Adresse" type="url" required :maxlength="$urlMax" autocomplete="off" inputmode="url"
                     placeholder="https://example.com/swayy-webhook"
                     help="Eine öffentlich erreichbare https-Adresse. Vor jeder Zustellung prüft Swayy sie erneut." />
            {{-- Von Hand gebaute Kästchen: Ein verstecktes Feld mit 0 wie bei x-checkbox käme als Ereignis „0“ beim Server an.
                 Ohne eigene ID am Kästchen meldet forms.js einen Fehler an der Gruppe. --}}
            <fieldset id="f-events" class="space-y-3 [&_code]:text-xs [&_code]:text-stone-600" aria-describedby="f-events-help{{ $eventsError ? ' f-events-error' : '' }}">
                <legend class="mb-1 text-sm font-semibold text-stone-800">Ereignisse</legend>
                <p id="f-events-help" class="text-[0.8125rem] text-stone-600">Wähle aus, worüber die Gegenstelle informiert wird.</p>
                <label class="flex items-start gap-3">
                    <input type="checkbox" name="events[]" value="*" @checked(in_array('*', (array) old('events', []), true))
                           aria-describedby="f-events-all-help" @if($eventsError) aria-invalid="true" @endif class="-mt-0.5 h-6 w-6 shrink-0">
                    <span class="min-w-0">
                        <span class="block text-sm font-semibold text-stone-800">Alle Ereignisse</span>
                        <span id="f-events-all-help" class="block text-[0.8125rem] text-stone-600">Auch Ereignisse, die Swayy künftig neu meldet.</span>
                    </span>
                </label>
                @foreach($events as $event)
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="events[]" value="{{ $event }}" @checked(in_array($event, (array) old('events', []), true))
                               @if($eventsError) aria-invalid="true" @endif class="-mt-0.5 h-6 w-6 shrink-0">
                        <span class="min-w-0 text-sm font-semibold text-stone-800 [overflow-wrap:anywhere]">{{ $eventLabels[$event] ?? $event }} <code>{{ $event }}</code></span>
                    </label>
                @endforeach
                @if($eventsError)
                    <p id="f-events-error" class="text-[0.8125rem] text-red-700">{{ $eventsError }}</p>
                @endif
            </fieldset>
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-webhook-neu" class="btn btn-primary" @unless($webhooksEnabled) disabled @endunless>Webhook anlegen</button>
        </x-slot:footer>
    </x-drawer>
@endpush
@endsection
