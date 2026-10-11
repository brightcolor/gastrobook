@extends('layouts.admin')
@section('title', 'API-Schlüssel')

@section('content')
@php
    $bookings = $salon ? 'Termine' : 'Reservierungen';
    $linkClass = 'font-semibold underline underline-offset-2';
    $scopesError = $errors->first('scopes');
    // Der Name eines Rechts; ein Recht aus einer älteren Fassung erscheint mit seiner Kennung.
    $rightsOf = fn ($token) => collect($token->abilities)
        ->reject(fn ($ability) => str_starts_with($ability, 'tenant:'))
        ->map(fn ($ability) => $scopes[$ability]['label'] ?? $ability)
        ->implode(', ');
@endphp
<x-page-header title="API-Schlüssel" :crumb="['Einstellungen', 'Verbindungen']" scope="tenant"
               :description="'Mit einem Schlüssel greift ein anderes Programm auf deine '.$bookings.' zu, etwa eine Website oder ein Kassensystem.'">
    <x-slot:actions>
        <button type="button" data-drawer-open="schluessel-neu" class="btn btn-primary" @unless($apiEnabled) disabled aria-describedby="api-plan-hint" @endunless>API-Schlüssel anlegen</button>
    </x-slot:actions>
</x-page-header>

@if($newToken)
    <div role="status" class="mb-6 space-y-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
        <p class="font-semibold">Jetzt kopieren: Swayy zeigt diesen Schlüssel nur einmal.</p>
        <p class="flex flex-wrap items-center gap-2">
            <code class="min-w-0 break-all rounded bg-white px-2 py-1 text-[0.8125rem] text-stone-900">{{ $newToken }}</code>
            <button type="button" data-copy="{{ $newToken }}" data-copy-message="Schlüssel kopiert." class="btn btn-secondary">Kopieren<span class="sr-only"> (Schlüssel)</span></button>
        </p>
    </div>
@endif

@unless($apiEnabled)
    <p id="api-plan-hint" role="note" class="mb-6 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">
        Die Schnittstelle ist in deinem Tarif nicht enthalten. Unter <a href="{{ route('admin.billing.show') }}" class="{{ $linkClass }}">Abo &amp; Rechnungen</a> kannst du den Tarif wechseln.
    </p>
@endunless

<x-settings.section id="schluessel" title="Schlüssel">
    <x-slot:description>
        <p>Lege einen Schlüssel nur an, wenn ein Dienstleister ausdrücklich danach fragt.</p>
        <p>Jeder Schlüssel hat genau die Rechte, die du anhakst. Widerrufst du ihn, verliert das Programm sofort den Zugang. Den Schlüssel selbst siehst du direkt nach dem Anlegen, danach nie wieder.</p>
    </x-slot:description>
    @if($tokens->isEmpty())
        <x-empty-state title="Noch keine API-Schlüssel">
            Mit einem Schlüssel greift ein Programm, etwa eine Website oder ein Kassensystem, auf deine {{ $bookings }} zu.
            <x-slot:action>
                <button type="button" data-drawer-open="schluessel-neu" class="btn btn-primary" @unless($apiEnabled) disabled aria-describedby="api-plan-hint" @endunless>API-Schlüssel anlegen</button>
            </x-slot:action>
        </x-empty-state>
    @else
        <ul class="-my-2 divide-y divide-stone-200">
            @foreach($tokens as $token)
                @php
                    $rights = $rightsOf($token);
                    $meta = array_filter([
                        $rights !== '' ? $rights : null,
                        $token->tokenable_id !== $currentUserId ? 'Besitzer: '.($token->tokenable?->name ?? 'unbekannt') : null,
                        $token->last_used_at ? 'zuletzt genutzt '.$token->last_used_at->diffForHumans() : 'noch nie genutzt',
                    ]);
                @endphp
                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-semibold [overflow-wrap:anywhere]">{{ $token->name }}</p>
                        <p class="text-[0.8125rem] text-stone-600 [overflow-wrap:anywhere]">{{ implode(' · ', $meta) }}</p>
                    </div>
                    <x-inline-confirm :id="'schluessel-widerrufen-'.$token->id" :action="route('admin.api-tokens.destroy', $token->id)" method="DELETE"
                                      label="Widerrufen" confirm="Schlüssel widerrufen" :subject="$token->name" :reload="true"
                                      :question="'API-Schlüssel „'.$token->name.'“ widerrufen? Programme mit diesem Schlüssel verlieren sofort den Zugang.'" />
                </li>
            @endforeach
        </ul>
    @endif
</x-settings.section>

<x-settings.section id="beispiel" title="So nutzt ein Programm den Schlüssel">
    <x-slot:description>
        <p>Das Programm schickt den Schlüssel bei jeder Anfrage im Header „Authorization“ mit.</p>
        <p>Die vollständige Beschreibung der Schnittstelle (OpenAPI) kann dein Dienstleister direkt einlesen.</p>
    </x-slot:description>
    <p class="text-sm text-stone-700">Beispiel für eine Abfrage der {{ $bookings }}:</p>
    <pre tabindex="0" aria-label="Beispielabfrage" class="overflow-x-auto rounded-lg bg-stone-50 p-3 text-[0.8125rem] text-stone-800"><code>curl -H "Authorization: Bearer &lt;SCHLÜSSEL&gt;" {{ url('/api/v1/reservations') }}</code></pre>
    <p class="text-sm text-stone-700">Alle Abfragen beschreibt <a href="{{ url('/api/v1/openapi.yaml') }}" class="{{ $linkClass }}">/api/v1/openapi.yaml</a>.</p>
</x-settings.section>

@push('drawers')
    <x-drawer id="schluessel-neu" title="Neuer API-Schlüssel" description="Name und Rechte des Schlüssels.">
        @unless($apiEnabled)
            <p role="note" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-sm text-amber-900">Mit deinem Tarif lässt sich noch kein Schlüssel anlegen. Der Hinweis oben auf der Seite zeigt den Weg zum Tarifwechsel.</p>
        @endunless
        <form id="form-schluessel-neu" method="POST" action="{{ route('admin.api-tokens.store') }}" data-ajax data-reset-after-save data-reload-after-save class="space-y-5">
            @csrf
            <x-field name="name" label="Name" required :maxlength="$nameMax" autocomplete="off" placeholder="z. B. Kassensystem"
                     help="Das Programm, das den Schlüssel nutzt. So erkennst du ihn später in der Liste." />
            {{-- Von Hand gebaute Kästchen: Ein verstecktes Feld mit 0 wie bei x-checkbox käme als Recht „0“ beim Server an.
                 Ohne eigene ID am Kästchen meldet forms.js einen Fehler an der Gruppe. --}}
            <fieldset id="f-scopes" class="space-y-3" aria-describedby="f-scopes-help{{ $scopesError ? ' f-scopes-error' : '' }}">
                <legend class="mb-1 text-sm font-semibold text-stone-800">Rechte</legend>
                <p id="f-scopes-help" class="text-[0.8125rem] text-stone-600">Hake nur an, was das Programm braucht. Für die meisten Programme reicht „{{ $scopes['reservations:read']['label'] }}“.</p>
                @foreach($scopes as $scope => $info)
                    <label class="flex items-start gap-3">
                        <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, (array) old('scopes', []), true))
                               aria-describedby="f-scopes-{{ $loop->index }}-help" @if($scopesError) aria-invalid="true" @endif class="-mt-0.5 h-6 w-6 shrink-0">
                        <span class="min-w-0">
                            <span class="block text-sm font-semibold text-stone-800">{{ $info['label'] }}</span>
                            <span id="f-scopes-{{ $loop->index }}-help" class="block text-[0.8125rem] text-stone-600">{{ $info['help'] }}</span>
                        </span>
                    </label>
                @endforeach
                @if($scopesError)
                    <p id="f-scopes-error" class="text-[0.8125rem] text-red-700">{{ $scopesError }}</p>
                @endif
            </fieldset>
        </form>
        <x-slot:footer>
            <button type="button" data-drawer-close class="btn btn-secondary">Abbrechen</button>
            <button type="submit" form="form-schluessel-neu" class="btn btn-primary" @unless($apiEnabled) disabled @endunless>Schlüssel anlegen</button>
        </x-slot:footer>
    </x-drawer>
@endpush
@endsection
